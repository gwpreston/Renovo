<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\SessionInterface;
use App\Service\BackupService;
use App\Service\InstanceSettingsService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Views\Twig;

/**
 * Whole-household export and restore.
 *
 * Both routes are behind ManageBackups, which maps to the household-management
 * role rather than to ordinary write access. An export is every price and note
 * in the household in one file, and a restore adds rows in bulk; neither is
 * something an Editor should be able to do on their own initiative.
 */
final class BackupController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        private readonly BackupService $backups,
        private readonly InstanceSettingsService $settings,
        private readonly StreamFactoryInterface $streams,
    ) {
        parent::__construct($view, $session);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, 'backup/index.twig', [
            'format_version' => BackupService::FORMAT_VERSION,
        ]);
    }

    public function export(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $instanceName = $this->settings->instanceName();

        $path = $this->backups->export($this->scope($request), $this->user($request), $instanceName);
        $size = (int) filesize($path);

        // Read into a stream and unlink immediately: the file is already open,
        // so the bytes survive, and nothing is left in the temporary directory
        // if the client disconnects halfway through.
        $handle = fopen($path, 'rb');
        @unlink($path);

        if ($handle === false) {
            $this->flash('error', 'The backup could not be prepared.');

            return $this->redirectAfterWrite($request, $response, '/settings/backup');
        }

        return $response
            ->withBody($this->streams->createStreamFromResource($handle))
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Length', (string) $size)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Content-Disposition', sprintf(
                'attachment; filename="%s"',
                $this->backups->suggestedFilename($instanceName),
            ));
    }

    public function restore(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $files = $request->getUploadedFiles();
        $file = $files['archive'] ?? null;

        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            $this->flash('error', 'Choose a backup archive to restore.');

            return $this->redirectAfterWrite($request, $response, '/settings/backup');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'renovo-upload');
        if ($temporary === false) {
            $this->flash('error', 'The archive could not be read.');

            return $this->redirectAfterWrite($request, $response, '/settings/backup');
        }

        $file->moveTo($temporary);

        try {
            $summary = $this->backups->restore($this->scope($request), $this->user($request), $temporary);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $message) {
                $this->flash('error', $message);
            }

            return $this->redirectAfterWrite($request, $response, '/settings/backup');
        } finally {
            @unlink($temporary);
        }

        $this->flash('success', sprintf(
            'Restored %d subscription(s), %d categor(y/ies), %d tag(s), %d budget(s) and %d attachment(s).%s',
            $summary['subscriptions'],
            $summary['categories'],
            $summary['tags'],
            $summary['budgets'],
            $summary['attachments'],
            $summary['skipped'] > 0 ? sprintf(' %d item(s) were skipped.', $summary['skipped']) : '',
        ));

        return $this->redirectAfterWrite($request, $response, '/settings/backup');
    }
}
