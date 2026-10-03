<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * The floor under the whole suite's line coverage.
 *
 * CI holds each pull request's own changed lines to 80% (diff-cover does
 * that), which keeps a phase from landing untested. What it cannot see is the
 * slow slide: a hundred small PRs that each clear the bar can still leave the
 * codebase as a whole worse covered than it was. This reads the project total
 * from a Clover report and says whether it is still at or above the floor.
 *
 * PHPUnit has no threshold option of its own, so this is it. Called by
 * `bin/console coverage:check` (which CI runs) and by CoverageFloorTest.
 */
final class CoverageFloor
{
    /** The floor CI enforces, in whole percent. */
    public const MINIMUM_PERCENT = 85;

    private function __construct(
        public readonly int $statements,
        public readonly int $covered,
    ) {
    }

    /**
     * Reads the project-wide totals from a Clover XML report.
     *
     * A report with no statements at all is refused rather than read as 100%:
     * it means the suite ran without a coverage driver, or measured nothing,
     * and a floor that passes on that has checked nothing.
     */
    public static function fromCloverFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf(
                'No coverage report at %s. Run the suite with --coverage-clover.',
                $path,
            ));
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($path);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw new RuntimeException(sprintf('%s is not a readable Clover report.', $path));
        }

        $metrics = $xml->xpath('/coverage/project/metrics');

        if ($metrics === false || $metrics === null || $metrics === []) {
            throw new RuntimeException(sprintf('%s has no project metrics. Is it a Clover report?', $path));
        }

        $statements = (int) $metrics[0]['statements'];
        $covered = (int) $metrics[0]['coveredstatements'];

        if ($statements <= 0) {
            throw new RuntimeException(sprintf(
                '%s measured no statements. Was a coverage driver (PCOV or Xdebug) loaded?',
                $path,
            ));
        }

        return new self($statements, $covered);
    }

    /**
     * Compared in integers, so a total of exactly the floor passes and a
     * rounding error cannot let 84.999% through.
     */
    public function meets(int $minimumPercent): bool
    {
        return $this->covered * 100 >= $minimumPercent * $this->statements;
    }

    public function percent(): float
    {
        return 100 * $this->covered / $this->statements;
    }
}
