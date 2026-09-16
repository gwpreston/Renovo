<?php

declare(strict_types=1);

namespace App\Service;

use App\Persistence\Database;
use App\Repository\MigrationRepository;
use App\Support\Clock;
use Throwable;

/**
 * Whether this instance is alive, and whether it is ready to serve.
 *
 * The two are deliberately different questions, and conflating them is the
 * classic way to make an outage worse. *Liveness* asks whether the process is
 * running, and touches nothing else — an orchestrator restarts a container
 * that fails it, so a liveness probe that queried the database would turn a
 * thirty-second database blip into a restart loop. *Readiness* asks whether
 * this instance should be sent traffic, and that does depend on the database,
 * on the schema being complete, and on the scheduler still being alive.
 *
 * A failing check never throws. The endpoint's job is to report, and an
 * exception escaping it would produce a 500 whose body says nothing about
 * which check failed.
 */
final class HealthService
{
    /**
     * How long the scheduler may be silent before readiness says so. It runs
     * daily; two days is a missed run plus the room a restart or a clock skew
     * needs, so a single late night does not page anybody.
     */
    private const SCHEDULER_STALE_AFTER = '-2 days';

    public function __construct(
        private readonly Database $db,
        private readonly MigrationRepository $migrations,
        private readonly InstanceSettingsService $settings,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{status: string, checks: array<string, array{status: string, detail?: string}>}
     */
    public function readiness(): array
    {
        $checks = [
            'database' => $this->database(),
        ];

        // Only worth asking once the connection is there: every one of these
        // reads from it, and a cascade of identical driver errors tells an
        // operator less than the one that matters.
        if ($checks['database']['status'] === 'ok') {
            $checks['migrations'] = $this->migrations();
            $checks['scheduler'] = $this->scheduler();
        }

        $failed = array_filter($checks, static fn (array $check): bool => $check['status'] === 'fail');

        return [
            'status' => $failed === [] ? 'ok' : 'fail',
            'checks' => $checks,
        ];
    }

    /**
     * @return array{status: string, detail?: string}
     */
    private function database(): array
    {
        try {
            $this->db->fetchValue('SELECT 1');
        } catch (Throwable $exception) {
            // The message, not the exception: a readiness body is read by
            // whoever is on call, and a stack trace on an unauthenticated
            // endpoint is a gift to somebody else.
            return ['status' => 'fail', 'detail' => 'the database did not answer'];
        }

        return ['status' => 'ok'];
    }

    /**
     * @return array{status: string, detail?: string}
     */
    private function migrations(): array
    {
        if (!$this->migrations->hasBeenMigrated()) {
            return ['status' => 'fail', 'detail' => 'no migrations have been applied'];
        }

        if ($this->migrations->hasIncompleteMigration()) {
            return ['status' => 'fail', 'detail' => 'a migration started and did not finish'];
        }

        return ['status' => 'ok', 'detail' => 'version ' . (string) $this->migrations->latestVersion()];
    }

    /**
     * @return array{status: string, detail?: string}
     */
    private function scheduler(): array
    {
        $lastRun = $this->settings->schedulerLastRunAt();

        if ($lastRun === null) {
            // Not a failure. A freshly installed instance has never run it, and
            // reporting that as broken would keep a new deployment out of the
            // load balancer for a day.
            return ['status' => 'unknown', 'detail' => 'the scheduler has not run yet'];
        }

        if ($lastRun < $this->clock->now()->modify(self::SCHEDULER_STALE_AFTER)) {
            return ['status' => 'fail', 'detail' => 'the scheduler last ran ' . $lastRun->format('c')];
        }

        return ['status' => 'ok', 'detail' => $lastRun->format('c')];
    }
}
