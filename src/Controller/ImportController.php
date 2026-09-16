<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Security\SessionInterface;
use App\Service\ImportService;
use App\Service\Import\ImportField;
use App\Service\Import\ImportPreset;
use App\Service\Import\SourceFile;
use App\Service\InstanceSettingsService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Views\Twig;

/**
 * The importer: upload, map, preview, commit.
 *
 * The staging id lives in the session and never in a URL or a form field. Put
 * it in either and one user could step through another's upload simply by
 * holding the id — the file is on the server's disk, not theirs, and nothing
 * else identifies who it belongs to.
 */
final class ImportController extends Controller
{
    private const SESSION_IMPORT_ID = 'import_id';

    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly ImportService $imports,
        private readonly InstanceSettingsService $settings,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function start(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, 'import/start.twig', [
            'presets' => ImportPreset::choices(),
            'max_rows' => SourceFile::MAX_ROWS,
        ]);
    }

    /**
     * Step one: take the file and show the mapping screen.
     */
    public function upload(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;

        if (!$file instanceof UploadedFileInterface) {
            $this->flash('error', 'error.import.file_required');

            return $this->redirectAfterWrite($request, $response, '/import');
        }

        try {
            $id = $this->imports->stage($file);
        } catch (ValidationException $exception) {
            $this->flashErrors($exception);

            return $this->redirectAfterWrite($request, $response, '/import');
        }

        $this->discardCurrent();
        $this->session->set(self::SESSION_IMPORT_ID, $id);

        $body = $this->body($request);
        $preset = is_scalar($body['preset'] ?? null) ? (string) $body['preset'] : ImportPreset::AUTOMATIC;
        $this->session->set('import_preset', $preset);

        return $this->redirectAfterWrite($request, $response, '/import/map');
    }

    /**
     * Step two: choose which column feeds which field.
     */
    public function map(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $id = $this->currentId();
        if ($id === null) {
            return $this->redirect($response, '/import');
        }

        try {
            $file = $this->imports->read($id);
        } catch (ValidationException $exception) {
            return $this->expired($request, $response, $exception);
        }

        $preset = $this->session->get('import_preset');

        return $this->render($request, $response, 'import/map.twig', [
            'headers' => $file->headers,
            'row_count' => count($file->rows),
            'sample' => array_slice($file->rows, 0, 3),
            'fields' => ImportField::cases(),
            'mapping' => $this->imports->suggestMapping(
                is_string($preset) ? $preset : ImportPreset::AUTOMATIC,
                $file->headers,
            ),
            'preset_label' => ImportPreset::label(is_string($preset) ? $preset : ImportPreset::AUTOMATIC),
            'filename' => $this->imports->originalName($id),
        ]);
    }

    /**
     * Step three: show exactly what will be written, row by row.
     */
    public function preview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $id = $this->currentId();
        if ($id === null) {
            return $this->redirect($response, '/import');
        }

        $mapping = $this->mappingFrom($this->body($request));
        $this->session->set('import_mapping', $mapping);

        try {
            $rows = $this->imports->preview($this->scope($request), $id, $mapping, $this->settings->baseCurrency());
        } catch (ValidationException $exception) {
            return $this->expired($request, $response, $exception);
        }

        $valid = array_filter($rows, static fn (array $row): bool => $row['errors'] === []);

        return $this->render($request, $response, 'import/preview.twig', [
            'rows' => $rows,
            'valid_count' => count($valid),
            'invalid_count' => count($rows) - count($valid),
            'filename' => $this->imports->originalName($id),
        ]);
    }

    /**
     * Step four: write them.
     */
    public function commit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $id = $this->currentId();
        if ($id === null) {
            return $this->redirect($response, '/import');
        }

        $mapping = $this->session->get('import_mapping');

        try {
            $result = $this->imports->commit(
                $this->scope($request),
                $this->user($request),
                $id,
                is_array($mapping) ? $mapping : [],
                $this->settings->baseCurrency(),
            );
        } catch (ValidationException $exception) {
            return $this->expired($request, $response, $exception);
        }

        $this->clearSession();

        $this->flash('success', 'flash.import_finished', ['count' => $result['imported']]);

        if ($result['skipped'] > 0) {
            // Its own message rather than a clause tacked onto the first: a
            // sentence that only sometimes has a second half is one a
            // translator has to guess the shape of.
            $this->flash('warning', 'flash.import_skipped', ['count' => $result['skipped']]);
        }

        return $this->redirectAfterWrite($request, $response, '/subscriptions');
    }

    public function cancel(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->discardCurrent();
        $this->clearSession();

        return $this->redirectAfterWrite($request, $response, '/import');
    }

    private function currentId(): ?string
    {
        $id = $this->session->get(self::SESSION_IMPORT_ID);

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function discardCurrent(): void
    {
        $id = $this->currentId();
        if ($id !== null) {
            $this->imports->discard($id);
        }
    }

    private function clearSession(): void
    {
        $this->session->remove(self::SESSION_IMPORT_ID);
        $this->session->remove('import_mapping');
        $this->session->remove('import_preset');
    }

    private function expired(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ValidationException $exception,
    ): ResponseInterface {
        $this->clearSession();

        $this->flashErrors($exception);

        return $this->redirectAfterWrite($request, $response, '/import');
    }

    /**
     * The mapping as the form submits it: field name => chosen header.
     *
     * @param array<string, mixed> $body
     * @return array<string, string>
     */
    private function mappingFrom(array $body): array
    {
        $raw = $body['mapping'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $mapping = [];
        foreach ($raw as $field => $header) {
            if (is_string($field) && is_string($header) && $header !== '') {
                $mapping[$field] = $header;
            }
        }

        return $mapping;
    }
}
