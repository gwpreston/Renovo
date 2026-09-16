<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Service\ValidationException;

/**
 * A staged upload, parsed into headers and rows.
 *
 * CSV and JSON both end up as a list of string-keyed string rows, so everything
 * downstream — the guesser, the mapping screen, the translator — deals with one
 * shape rather than two.
 *
 * The row cap is not a performance guard so much as a promise the preview can
 * keep: the importer shows every row it is about to write, and a screen cannot
 * meaningfully show a hundred thousand of them. A file over the limit is
 * refused with a message that says so, rather than silently truncated, because
 * a partial import nobody was told about is the worst of the three outcomes.
 */
final class SourceFile
{
    public const MAX_ROWS = 2000;

    /**
     * No escape character, which is what RFC 4180 actually specifies — a quote
     * inside a quoted field is written by doubling it, not by backslashing it.
     *
     * Passed explicitly rather than left to default: PHP's historical default
     * was a backslash, which mangles any field containing a Windows path, and
     * PHP 8.4 deprecates relying on the default while it changes to this.
     */
    private const NO_ESCAPE = '';

    /**
     * @param list<string> $headers
     * @param list<array<string, string>> $rows
     */
    private function __construct(
        public readonly array $headers,
        public readonly array $rows,
    ) {
    }

    /**
     * @throws ValidationException
     */
    public static function parse(string $path, string $originalName): self
    {
        $contents = @file_get_contents($path);
        if ($contents === false || trim($contents) === '') {
            throw ValidationException::field('file', 'error.import.empty');
        }

        return str_ends_with(strtolower($originalName), '.json') || str_starts_with(ltrim($contents), '[')
            || str_starts_with(ltrim($contents), '{')
                ? self::fromJson($contents)
                : self::fromCsv($path);
    }

    /**
     * @throws ValidationException
     */
    private static function fromCsv(string $path): self
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw ValidationException::field('file', 'error.import.unreadable');
        }

        try {
            $delimiter = self::detectDelimiter($path);

            $headerRow = fgetcsv($handle, 0, $delimiter, '"', self::NO_ESCAPE);
            if (!is_array($headerRow)) {
                throw ValidationException::field('file', 'error.import.no_header');
            }

            $headers = self::cleanHeaders($headerRow);

            $rows = [];
            while (($line = fgetcsv($handle, 0, $delimiter, '"', self::NO_ESCAPE)) !== false) {
                if ($line === [null]) {
                    // fgetcsv reports a blank line this way. It is noise, not a row.
                    continue;
                }

                $row = [];
                foreach ($headers as $index => $header) {
                    $value = $line[$index] ?? null;
                    $row[$header] = is_string($value) ? trim($value) : '';
                }

                if (implode('', $row) === '') {
                    continue;
                }

                $rows[] = $row;

                if (count($rows) > self::MAX_ROWS) {
                    throw ValidationException::field(
                        'file',
                        'error.import.too_many_rows',
                        ['max' => self::MAX_ROWS],
                    );
                }
            }
        } finally {
            fclose($handle);
        }

        return new self($headers, $rows);
    }

    /**
     * @throws ValidationException
     */
    private static function fromJson(string $contents): self
    {
        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            throw ValidationException::field('file', 'error.import.invalid_json');
        }

        // A bare list, or an object wrapping one under a name this application
        // itself produces.
        foreach (['subscriptions', 'data'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                $decoded = $decoded[$key];
                break;
            }
        }

        $rows = [];
        $headers = [];

        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $row = [];
            foreach ($entry as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }

                $row[$key] = self::stringify($value);
                if (!in_array($key, $headers, true)) {
                    $headers[] = $key;
                }
            }

            if ($row !== []) {
                $rows[] = $row;
            }

            if (count($rows) > self::MAX_ROWS) {
                throw ValidationException::field(
                    'file',
                    'error.import.too_many_entries',
                    ['max' => self::MAX_ROWS],
                );
            }
        }

        if ($rows === []) {
            throw ValidationException::field('file', 'error.import.no_subscriptions');
        }

        return new self($headers, $rows);
    }

    private static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            // Tags arrive as a list; everything else that is an array is not
            // something a single column can carry, and joining it is the least
            // surprising thing to show in the preview.
            $parts = array_map(
                static fn (mixed $item): string => is_scalar($item) ? (string) $item : '',
                $value,
            );

            return implode(', ', array_filter($parts, static fn (string $part): bool => $part !== ''));
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param array<int, string|null> $row
     * @return list<string>
     */
    private static function cleanHeaders(array $row): array
    {
        $headers = [];
        $seen = [];

        foreach ($row as $index => $value) {
            // Strip a UTF-8 BOM from the first header: Excel writes one, and a
            // header called "\u{FEFF}Name" matches nothing.
            $header = trim((string) ($value ?? ''));
            if ($index === 0) {
                $header = (string) preg_replace('/^\xEF\xBB\xBF/', '', $header);
            }

            if ($header === '') {
                $header = 'Column ' . ($index + 1);
            }

            // Duplicate headers would silently overwrite each other.
            $candidate = $header;
            $suffix = 2;
            while (isset($seen[$candidate])) {
                $candidate = $header . ' (' . $suffix++ . ')';
            }

            $seen[$candidate] = true;
            $headers[] = $candidate;
        }

        return $headers;
    }

    /**
     * Guess the delimiter from the header line.
     *
     * European exports are semicolon-separated as often as not, and tab-separated
     * files are what you get when somebody pastes from a spreadsheet.
     */
    private static function detectDelimiter(string $path): string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return ',';
        }

        $line = (string) fgets($handle, 8192);
        fclose($handle);

        $counts = [
            ',' => substr_count($line, ','),
            ';' => substr_count($line, ';'),
            "\t" => substr_count($line, "\t"),
        ];

        arsort($counts);
        $best = array_key_first($counts);

        return is_string($best) && $counts[$best] > 0 ? $best : ',';
    }
}
