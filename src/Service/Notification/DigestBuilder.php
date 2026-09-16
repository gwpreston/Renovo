<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Domain\AlertType;
use App\Domain\DigestMode;
use App\I18n\Translator;
use App\Notification\Alert;
use DateTimeImmutable;

/**
 * Folds a run of alerts into the single message a digest user gets.
 *
 * Grouped by alert type and then by date, because that is how somebody reads
 * it: everything renewing, then everything about to stop being free, then the
 * deadlines, then the budgets. A flat chronological list of twenty lines is
 * technically the same information and nobody finishes it.
 *
 * The result is an ordinary Alert, which matters more than it looks: the
 * dispatcher, the ledger and every channel treat a digest exactly like any
 * other notification, so digest mode adds no second delivery path that could
 * drift out of step with the first.
 */
final class DigestBuilder
{
    public function __construct(private readonly Translator $translator)
    {
    }

    /**
     * @param list<Alert> $alerts
     */
    public function build(array $alerts, DigestMode $mode, DateTimeImmutable $today, string $appUrl = ''): ?Alert
    {
        if ($alerts === [] || !$mode->isDigest()) {
            return null;
        }

        $grouped = [];
        foreach ($alerts as $alert) {
            $grouped[$alert->type->value][] = $alert;
        }

        $lines = [];
        foreach (AlertType::all() as $type) {
            $ofType = $grouped[$type->value] ?? [];
            if ($ofType === []) {
                continue;
            }

            if ($lines !== []) {
                $lines[] = '';
            }

            $lines[] = $this->translator->trans('digest.section', [
                'label' => $this->translator->trans($type->labelKey()),
            ]);
            foreach ($ofType as $alert) {
                $lines[] = '- ' . $alert->summaryLine();
            }
        }

        $title = $this->translator->trans(
            $mode === DigestMode::Weekly ? 'digest.title.weekly' : 'digest.title.monthly',
            ['count' => count($alerts)],
        );

        return new Alert(
            // Fixed, not taken from the contents. A digest is identified in the
            // ledger by its subject type and its period key, and those are
            // enough; letting the alert type vary with whatever happened to
            // come first would mean two runs on the same day producing two
            // different identities, and therefore two digests. Routing does not
            // read this either — a digest goes to the union of the channels its
            // contents are routed to, which the runner works out.
            AlertType::Renewal,
            Alert::SUBJECT_DIGEST,
            0,
            $mode->periodKey($today),
            $title,
            $lines,
            $appUrl === '' ? null : rtrim($appUrl, '/') . '/',
            null,
            5,
        );
    }
}
