<?php

declare(strict_types=1);

namespace App\Service;

use App\Application\Api\Resource;
use App\Application\Api\SubscriptionPayload;
use App\Domain\AuditAction;
use App\Domain\Currency;
use App\Domain\Money;
use App\Domain\Entity\Attachment;
use App\Domain\Entity\Budget;
use App\Domain\Entity\Category;
use App\Domain\Entity\Subscription;
use App\Domain\Entity\Tag;
use App\Domain\Entity\User;
use App\Repository\AttachmentRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\TagRepository;
use App\Security\Scope;
use App\Support\Clock;
use DateTimeImmutable;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Exporting a household and putting it back.
 *
 * A backup here is not a database dump, and the difference is the whole point.
 * It is built by reading through the scoping layer, so what lands in the archive
 * is exactly what the person asking could already see — in ISOLATED mode a
 * member exports their own subscriptions and nobody else's — and it is restored
 * by calling the same services the forms call, so a restored row is validated,
 * gets its price-history entry and obeys the isolation rules of the instance it
 * is landing in, which may not be the one it left.
 *
 * What is deliberately *not* in it: instance settings, user accounts, passwords,
 * API tokens, the audit log. Those belong to the instance rather than to the
 * household, and a household Owner who could export and re-import them would be
 * able to reconfigure the server through the backup screen. Members are
 * referenced by email address only, which is enough to put a subscription back
 * with the right owner and not enough to create an account.
 *
 * One asymmetry is deliberate: the household's *name* is written into the
 * archive and is not read back. A restore lands in a household that already
 * exists and already has a name its members chose; silently renaming it to
 * whatever the archive said would be a surprising side effect of importing some
 * subscriptions. It is exported so that a person opening the file can tell
 * whose data it is.
 *
 * The archive is treated as hostile on the way back in, whatever it claims
 * about where it came from: entry names are checked against a strict pattern
 * before anything is extracted, and every file in it is re-validated by its
 * magic bytes exactly as an upload would be.
 *
 * @phpstan-type RestoreSummary array{subscriptions: int, categories: int, tags: int,
 *     budgets: int, attachments: int, skipped: int}
 */
final class BackupService
{
    public const FORMAT = 'renovo-household-backup';
    public const FORMAT_VERSION = 1;

    /**
     * What an entry inside the archive may be called. Anything else — an
     * absolute path, a `..`, a name with a separator in it — is refused
     * outright rather than sanitised, because a name that needed sanitising was
     * not produced by this application.
     */
    private const ENTRY_PATTERN = '#^(manifest\.json|data\.json|files/(logos|attachments)/[A-Za-z0-9._-]{1,128})$#';

    private const MAX_ENTRIES = 20000;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly CategoryService $categories,
        private readonly TagService $tags,
        private readonly TagRepository $tagRepository,
        private readonly BudgetService $budgets,
        private readonly AttachmentRepository $attachments,
        private readonly AttachmentStorage $attachmentStorage,
        private readonly LogoStorage $logos,
        private readonly HouseholdRepository $households,
        private readonly MembershipRepository $memberships,
        private readonly AuditLogService $audit,
        private readonly Clock $clock,
        private readonly string $logoDirectory,
    ) {
    }

    /**
     * Write a backup archive and return the path to it.
     *
     * The caller is responsible for streaming and then deleting it; it is
     * written to a temporary file rather than built in memory because a
     * household with a few hundred invoices in it would not fit comfortably.
     */
    public function export(Scope $scope, User $actor, string $instanceName): string
    {
        $path = tempnam(sys_get_temp_dir(), 'renovo-backup');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the backup.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the backup archive.');
        }

        $today = $this->clock->today();
        $emails = $this->memberEmails($scope);

        $subscriptions = [];
        $logos = [];
        // Attachments refer to their subscription by its position in this list,
        // not by its id. Ids are not preserved across a restore — the rows are
        // created fresh — so a positional reference is the only one that still
        // means something on the way back in.
        $positions = [];
        foreach ($this->subscriptions->allForStats($scope, activeOnly: false) as $subscription) {
            $positions[$subscription->id] = count($subscriptions);
            $subscriptions[] = $this->exportSubscription($subscription, $today, $emails, $logos);
        }

        $attachments = [];
        foreach ($this->attachments->findAllForHousehold($scope) as $attachment) {
            $position = $positions[$attachment->subscriptionId] ?? null;
            if ($position === null) {
                // Its subscription was not exported, so neither is it.
                continue;
            }

            $entry = $this->addAttachment($zip, $attachment, $position);
            if ($entry !== null) {
                $attachments[] = $entry;
            }
        }

        foreach ($logos as $storedPath => $entryName) {
            $absolute = $this->logoAbsolutePath($storedPath);
            if ($absolute !== null) {
                $zip->addFile($absolute, $entryName);
            }
        }

        $data = [
            'household' => [
                'name' => $scope->householdId === null
                    ? ''
                    : ($this->households->findById($scope->householdId)->name ?? ''),
            ],
            'categories' => array_map(
                static fn (Category $category): array => ['name' => $category->name, 'colour' => $category->colour],
                $this->categories->all($scope),
            ),
            'tags' => array_map(
                static fn (Tag $tag): array => ['name' => $tag->name],
                $this->tags->all($scope),
            ),
            'subscriptions' => $subscriptions,
            'budgets' => array_map(
                fn (Budget $budget): array => $this->exportBudget($budget, $emails),
                $this->budgets->all($scope, activeOnly: false),
            ),
            'attachments' => $attachments,
        ];

        $zip->addFromString('manifest.json', $this->encode([
            'format' => self::FORMAT,
            'version' => self::FORMAT_VERSION,
            'generated_at' => $this->clock->now()->format(DATE_ATOM),
            'instance' => $instanceName,
            'isolation_mode' => $scope->isolationMode->value,
            'counts' => [
                'subscriptions' => count($data['subscriptions']),
                'categories' => count($data['categories']),
                'tags' => count($data['tags']),
                'budgets' => count($data['budgets']),
                'attachments' => count($data['attachments']),
            ],
        ]));

        $zip->addFromString('data.json', $this->encode($data));
        $zip->close();

        $this->audit->record(AuditAction::BackupExported, $actor, [
            'subscriptions' => count($data['subscriptions']),
            'attachments' => count($data['attachments']),
        ], $scope->householdId);

        return $path;
    }

    public function suggestedFilename(string $instanceName): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $instanceName));
        $slug = trim($slug, '-');

        return sprintf('%s-backup-%s.zip', $slug === '' ? 'renovo' : $slug, $this->clock->today()->format('Y-m-d'));
    }

    /**
     * Restore an archive into the current household.
     *
     * Additive, never destructive: it creates rows, it does not delete or
     * overwrite any. Restoring the same archive twice leaves two copies of
     * everything, which is an obvious and recoverable outcome. The alternative —
     * "replace everything" — is one mis-click away from destroying a household's
     * data with a stale file, and nothing in this phase asked for it.
     *
     * @return RestoreSummary
     * @throws ValidationException
     */
    public function restore(Scope $scope, User $actor, string $archivePath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
            throw ValidationException::field('file', 'error.backup.not_zip');
        }

        $written = [];

        try {
            $this->assertSafeEntries($zip);

            $data = $this->readJson($zip, 'data.json');
            $manifest = $this->readJson($zip, 'manifest.json');

            if (($manifest['format'] ?? null) !== self::FORMAT) {
                throw ValidationException::field('file', 'error.backup.foreign');
            }

            $summary = $this->restoreData($scope, $zip, $data, $written);
        } catch (Throwable $exception) {
            // Whatever went wrong, the files this run had already written are
            // removed. The rows are not: each one was created by a service call
            // that either succeeded or threw, so there is no half-written row
            // to undo, and a partial restore that reports its counts is more
            // use than one that silently discards the rows it managed.
            $this->removeWritten($written);
            $zip->close();

            throw $exception;
        }

        $zip->close();

        $this->audit->record(AuditAction::BackupRestored, $actor, $summary, $scope->householdId);

        return $summary;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array{kind: string, path: string}> $written
     * @return RestoreSummary
     */
    private function restoreData(Scope $scope, ZipArchive $zip, array $data, array &$written): array
    {
        $summary = [
            'subscriptions' => 0,
            'categories' => 0,
            'tags' => 0,
            'budgets' => 0,
            'attachments' => 0,
            'skipped' => 0,
        ];

        $categoryIds = $this->restoreCategories($scope, $data['categories'] ?? [], $summary);
        $summary['tags'] = $this->restoreTags($scope, $data['tags'] ?? []);
        $owners = $this->memberIdsByEmail($scope);

        /** @var array<int, int> $subscriptionIds Archive index => new id. */
        $subscriptionIds = [];

        $rows = is_array($data['subscriptions'] ?? null) ? $data['subscriptions'] : [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                $summary['skipped']++;
                continue;
            }

            $input = $this->subscriptionInput($scope, $row, $categoryIds, $owners);

            $logo = $this->restoreLogo($zip, $row, $written);
            if ($logo !== null) {
                $input['logo_path'] = $logo;
            }

            try {
                $subscriptionIds[(int) $index] = $this->subscriptions->create($scope, $input);
                $summary['subscriptions']++;
            } catch (ValidationException) {
                $summary['skipped']++;
            }
        }

        $this->restoreBudgets($scope, $data['budgets'] ?? [], $categoryIds, $owners, $summary);
        $this->restoreAttachments($scope, $zip, $data['attachments'] ?? [], $subscriptionIds, $written, $summary);

        return $summary;
    }

    /**
     * @param mixed $rows
     * @param RestoreSummary $summary
     * @return array<string, int> Lower-cased name => id.
     */
    private function restoreCategories(Scope $scope, mixed $rows, array &$summary): array
    {
        $ids = [];

        // Whatever the household already has keeps its id: restoring must not
        // create a second "Streaming" beside the existing one.
        foreach ($this->categories->all($scope) as $existing) {
            $ids[mb_strtolower($existing->name)] = $existing->id;
        }

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row) || !is_string($row['name'] ?? null)) {
                continue;
            }

            $key = mb_strtolower(trim($row['name']));
            if ($key === '' || isset($ids[$key])) {
                continue;
            }

            try {
                $colour = is_string($row['colour'] ?? null) ? $row['colour'] : null;
                $ids[$key] = $this->categories->create($scope, trim($row['name']), $colour);
                $summary['categories']++;
            } catch (ValidationException) {
                $summary['skipped']++;
            }
        }

        return $ids;
    }

    /**
     * Put the household's tag vocabulary back.
     *
     * Tags carried *on* a subscription are restored with it, so this covers the
     * ones that were not in use at the time — a label somebody made and has not
     * applied yet. `resolveOrCreate` is the same call the subscription form
     * makes, so an existing tag is matched rather than duplicated.
     *
     * @param mixed $rows
     */
    private function restoreTags(Scope $scope, mixed $rows): int
    {
        $names = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && is_string($row['name'] ?? null) && trim($row['name']) !== '') {
                $names[] = trim($row['name']);
            }
        }

        if ($names === []) {
            return 0;
        }

        $before = count($this->tags->all($scope));
        $this->tagRepository->resolveOrCreate($scope, $names);

        return count($this->tags->all($scope)) - $before;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int> $categoryIds
     * @param array<string, int> $owners
     * @return array<string, mixed>
     */
    private function subscriptionInput(Scope $scope, array $row, array $categoryIds, array $owners): array
    {
        $input = SubscriptionPayload::toServiceInput($row);

        $categoryName = is_string($row['category_name'] ?? null) ? mb_strtolower(trim($row['category_name'])) : '';
        $input['category_id'] = $categoryIds[$categoryName] ?? '';

        // The archive names members by email. In ISOLATED mode the service
        // forces the owner to the restoring user anyway; in SHARED mode a
        // member who is still here keeps their rows, and one who has gone
        // hands them to whoever is doing the restoring.
        $input['owner_user_id'] = $this->resolveMember($row['owner_email'] ?? null, $owners) ?? $scope->userId;
        $input['payer_user_id'] = $this->resolveMember($row['payer_email'] ?? null, $owners) ?? '';

        return $input;
    }

    /**
     * @param mixed $rows
     * @param array<string, int> $categoryIds
     * @param array<string, int> $owners
     * @param RestoreSummary $summary
     */
    private function restoreBudgets(
        Scope $scope,
        mixed $rows,
        array $categoryIds,
        array $owners,
        array &$summary,
    ): void {
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $categoryName = is_string($row['category_name'] ?? null) ? mb_strtolower(trim($row['category_name'])) : '';

            try {
                $this->budgets->create($scope, [
                    'name' => (string) ($row['name'] ?? ''),
                    'amount' => $this->decimal($row['amount_minor'] ?? null, (string) ($row['currency'] ?? 'GBP')),
                    'currency' => (string) ($row['currency'] ?? ''),
                    'period' => (string) ($row['period'] ?? ''),
                    'category_id' => $categoryIds[$categoryName] ?? '',
                    'warn_threshold_percent' => (string) ($row['warn_threshold_percent'] ?? ''),
                    'is_active' => ($row['is_active'] ?? true) ? '1' : '0',
                    'owner_user_id' => $this->resolveMember($row['owner_email'] ?? null, $owners) ?? $scope->userId,
                ]);
                $summary['budgets']++;
            } catch (ValidationException) {
                $summary['skipped']++;
            }
        }
    }

    /**
     * @param mixed $rows
     * @param array<int, int> $subscriptionIds
     * @param list<array{kind: string, path: string}> $written
     * @param RestoreSummary $summary
     */
    private function restoreAttachments(
        Scope $scope,
        ZipArchive $zip,
        mixed $rows,
        array $subscriptionIds,
        array &$written,
        array &$summary,
    ): void {
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row) || !is_string($row['entry'] ?? null)) {
                continue;
            }

            $subscriptionId = $subscriptionIds[(int) ($row['subscription_index'] ?? -1)] ?? null;
            if ($subscriptionId === null) {
                $summary['skipped']++;
                continue;
            }

            $subscription = $this->subscriptions->find($scope, $subscriptionId);
            if ($subscription === null) {
                $summary['skipped']++;
                continue;
            }

            $temporary = $this->extractToTemporaryFile($zip, $row['entry']);
            if ($temporary === null) {
                $summary['skipped']++;
                continue;
            }

            try {
                // Re-validated by magic bytes, exactly like an upload. An
                // archive is untrusted input however it was produced.
                $stored = $this->attachmentStorage->storeFile($temporary, $subscription->householdId);
                $written[] = ['kind' => 'attachment', 'path' => $stored['path']];

                $this->attachments->create(
                    $scope,
                    $subscriptionId,
                    $subscription->ownerUserId,
                    $this->date($row['period_date'] ?? null),
                    is_string($row['filename'] ?? null) ? $row['filename'] : 'attachment',
                    $stored['path'],
                    $stored['mime'],
                    $stored['size'],
                    $scope->userId,
                );
                $summary['attachments']++;
            } catch (ValidationException) {
                $summary['skipped']++;
            } finally {
                @unlink($temporary);
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array{kind: string, path: string}> $written
     */
    private function restoreLogo(ZipArchive $zip, array $row, array &$written): ?string
    {
        $entry = $row['logo_entry'] ?? null;
        if (!is_string($entry) || $entry === '') {
            return null;
        }

        $temporary = $this->extractToTemporaryFile($zip, $entry);
        if ($temporary === null) {
            return null;
        }

        try {
            // LogoStorage validates the image type from the bytes; a file that
            // is not really an image is dropped and the subscription restores
            // without it.
            $stored = $this->logos->storeFile($temporary);
            if ($stored !== null) {
                $written[] = ['kind' => 'logo', 'path' => $stored];
            }

            return $stored;
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * Refuse an archive whose entries are not all names this application would
     * have written.
     *
     * This runs before a single byte is extracted. `..` and absolute paths are
     * the classic zip-slip payload, but the check is an allow-list rather than a
     * list of things to reject, so a name nobody has thought of yet is also
     * refused.
     *
     * @throws ValidationException
     */
    private function assertSafeEntries(ZipArchive $zip): void
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw ValidationException::field('file', 'error.backup.too_many_files');
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);

            if (!is_string($name) || preg_match(self::ENTRY_PATTERN, $name) !== 1) {
                throw ValidationException::field(
                    'file',
                    'error.backup.unexpected_file',
                );
            }
        }
    }

    private function extractToTemporaryFile(ZipArchive $zip, string $entry): ?string
    {
        if (preg_match(self::ENTRY_PATTERN, $entry) !== 1) {
            return null;
        }

        $stream = $zip->getStream($entry);
        if (!is_resource($stream)) {
            return null;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'renovo-restore');
        if ($temporary === false) {
            fclose($stream);

            return null;
        }

        $out = fopen($temporary, 'wb');
        if ($out === false) {
            fclose($stream);

            return null;
        }

        stream_copy_to_stream($stream, $out, $this->attachmentStorage->maxBytes() + 1);
        fclose($out);
        fclose($stream);

        return $temporary;
    }

    /**
     * @param array<string, string> $emails
     * @param array<string, string> $logos Stored path => archive entry name.
     * @return array<string, mixed>
     */
    private function exportSubscription(
        Subscription $subscription,
        DateTimeImmutable $today,
        array $emails,
        array &$logos,
    ): array {
        $row = Resource::subscription($subscription, $today);

        // Ids mean nothing in another instance; email addresses do.
        $row['owner_email'] = $emails[(string) $subscription->ownerUserId] ?? null;
        $row['payer_email'] = $subscription->payerUserId === null
            ? null
            : ($emails[(string) $subscription->payerUserId] ?? null);

        if ($subscription->logoPath !== null && $this->logoAbsolutePath($subscription->logoPath) !== null) {
            $entry = 'files/logos/' . basename($subscription->logoPath);
            $logos[$subscription->logoPath] = $entry;
            $row['logo_entry'] = $entry;
        }

        return $row;
    }

    /**
     * @param array<string, string> $emails
     * @return array<string, mixed>
     */
    private function exportBudget(Budget $budget, array $emails): array
    {
        return [
            'name' => $budget->name,
            'category_name' => $budget->categoryName,
            'period' => $budget->period->value,
            'amount_minor' => $budget->amount->amountMinor,
            'currency' => $budget->amount->currency,
            'warn_threshold_percent' => $budget->warnThresholdPercent,
            'is_active' => $budget->isActive,
            'owner_email' => $emails[(string) $budget->ownerUserId] ?? null,
        ];
    }

    /**
     * @return array{entry: string, filename: string, period_date: string|null,
     *               subscription_index: int}|null
     */
    private function addAttachment(ZipArchive $zip, Attachment $attachment, int $position): ?array
    {
        $absolute = $this->attachmentStorage->absolutePath($attachment->storedPath);
        if ($absolute === null) {
            return null;
        }

        $entry = 'files/attachments/' . $attachment->id . '-' . basename($attachment->storedPath);
        $zip->addFile($absolute, $entry);

        return [
            'entry' => $entry,
            'filename' => $attachment->originalFilename,
            'period_date' => $attachment->periodDate?->format('Y-m-d'),
            'subscription_index' => $position,
        ];
    }

    /**
     * @return array<string, string> User id (as a string key) => email.
     */
    private function memberEmails(Scope $scope): array
    {
        $emails = [];
        foreach ($this->householdMembers($scope) as $member) {
            $emails[(string) $member['id']] = (string) $member['email'];
        }

        return $emails;
    }

    /**
     * @return array<string, int> Lower-cased email => user id.
     */
    private function memberIdsByEmail(Scope $scope): array
    {
        $ids = [];
        foreach ($this->householdMembers($scope) as $member) {
            $ids[mb_strtolower((string) $member['email'])] = (int) $member['id'];
        }

        return $ids;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function householdMembers(Scope $scope): array
    {
        if (!$scope->hasHousehold()) {
            return [];
        }

        return $this->memberships->findMembersOfHousehold((int) $scope->householdId);
    }

    /**
     * @param array<string, int> $owners
     */
    private function resolveMember(mixed $email, array $owners): ?int
    {
        if (!is_string($email) || $email === '') {
            return null;
        }

        return $owners[mb_strtolower($email)] ?? null;
    }

    private function decimal(mixed $minor, string $currency): string
    {
        if (!is_numeric($minor)) {
            return '';
        }

        return Currency::isValidCode(Currency::normalise($currency))
            ? Money::of((int) $minor, $currency)->toDecimalString()
            : (string) (int) $minor;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return $date === false ? null : $date;
    }

    private function logoAbsolutePath(string $storedPath): ?string
    {
        if (!str_starts_with($storedPath, 'assets/logos/')) {
            return null;
        }

        $name = basename($storedPath);
        if (preg_match('/^[0-9a-f]{32}\.(png|jpg|gif|webp)$/', $name) !== 1) {
            return null;
        }

        $absolute = rtrim($this->logoDirectory, '/') . '/' . $name;

        return is_file($absolute) ? $absolute : null;
    }

    /**
     * @return array<string, mixed>
     * @throws ValidationException
     */
    private function readJson(ZipArchive $zip, string $entry): array
    {
        $contents = $zip->getFromName($entry);
        if ($contents === false) {
            throw ValidationException::field('file', 'error.backup.missing_entry', ['entry' => $entry]);
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            throw ValidationException::field('file', 'error.backup.invalid_json', ['entry' => $entry]);
        }

        return $decoded;
    }

    /**
     * Undo the files a failed restore had already written.
     *
     * @param list<array{kind: string, path: string}> $written
     */
    private function removeWritten(array $written): void
    {
        foreach ($written as $file) {
            if ($file['kind'] === 'attachment') {
                $this->attachmentStorage->delete($file['path']);
            } else {
                $this->logos->delete($file['path']);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        return (string) json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
