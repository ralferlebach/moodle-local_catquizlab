<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_catquizlab\local;

/**
 * Two actions: prepare an experiment, and run it.
 *
 * Everything this plugin does for a person reduces to those two. Which
 * background task advances which run, which worker claims which sitting, and
 * what state a queue is in are all things the plugin has to know and nobody
 * should have to decide — and the interface asked anyway, one button per
 * internal step, so operating an experiment meant understanding its machinery.
 *
 * The internals below stay exactly as they were: separate services, separate
 * tasks, separate stages. This is a façade over them, not a rewrite of them.
 * What changes is that a person presses "prepare" and then "run", and the
 * plugin decides the rest.
 *
 * An experiment has three states a person needs: it is being defined, it is
 * ready, or it is blocked. Everything else — scheduled, provisioning, ready,
 * running, aggregating, finished, per run, times thirty runs — is the plugin's
 * bookkeeping, and reporting it as the experiment's state is how a person ends
 * up reading a table of thirty statuses to answer one question.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class experiment_runner {
    /** @var string Nothing has been built from the definition yet. */
    public const STATE_DRAFT = 'draft';

    /** @var string Preparation is under way. */
    public const STATE_PREPARING = 'preparing';

    /** @var string Every run is prepared and waiting to be started. */
    public const STATE_READY = 'ready';

    /** @var string Queued or running. */
    public const STATE_RUNNING = 'running';

    /** @var string Everything finished. */
    public const STATE_FINISHED = 'finished';

    /** @var string Something needs a person before anything else can happen. */
    public const STATE_BLOCKED = 'blocked';

    /**
     * Prepare every run of an experiment, from definition to queued sittings.
     *
     * Idempotent by construction: each stage underneath already checks whether
     * its work exists before doing it, and a run that is past preparation is
     * skipped rather than rebuilt. Pressing the button twice is a thing people
     * do when the first press seemed not to work.
     *
     * @param int $experimentid The experiment.
     * @return array{ok: bool, state: string, prepared: int, total: int, blockers: array[]}
     */
    public static function prepare(int $experimentid): array {
        global $DB;

        $experiment = $DB->get_record('local_catquizlab_experiment', ['id' => $experimentid]);
        if (!$experiment) {
            return self::verdict(false, self::STATE_BLOCKED, 0, 0, [
                ['reason' => 'experiment-not-found'],
            ]);
        }

        // Validate before mutating: a definition that cannot be read should not
        // leave half an experiment built behind it.
        $validation = self::validate($experimentid);
        if (!$validation['ok']) {
            return self::verdict(false, self::STATE_BLOCKED, 0, 0, $validation['blockers']);
        }

        // The runs themselves, created from the design if they do not exist.
        // Creating them twice is what the sweep's own idempotency prevents.
        if (!$DB->record_exists('local_catquizlab_run', ['experimentid' => $experimentid])) {
            experiment_service::create_sweep($experimentid);
        }

        $runs = $DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC');
        if ($runs === []) {
            return self::verdict(false, self::STATE_BLOCKED, 0, 0, [
                ['reason' => 'no-runs-created'],
            ]);
        }

        $prepared = 0;
        $blockers = [];

        foreach ($runs as $run) {
            $runid = (int) $run->id;
            $status = (int) $run->status;

            // Already past preparation: nothing to do, and rebuilding would
            // discard data somebody may be reading.
            $past = [
                registry::STATUS_READY,
                registry::STATUS_RUNNING,
                registry::STATUS_AGGREGATING,
                registry::STATUS_FINISHED,
            ];

            if (in_array($status, $past, true)) {
                $prepared++;
                continue;
            }

            if ($status === registry::STATUS_DRAFT) {
                $start = run_lifecycle::start($runid);
                if (empty($start['started'])) {
                    $blockers[] = [
                        'runid'   => $runid,
                        'cellkey' => (string) $run->cellkey,
                        'stage'   => 'preflight',
                        'reason'  => (string) ($start['reason'] ?? ''),
                    ];
                    continue;
                }
            }

            $outcome = run_lifecycle::provision_now($runid);
            if (empty($outcome['ok'])) {
                // Named to the run and the stage: "provisioning failed" for an
                // experiment of thirty runs is not something anybody can act on.
                $blockers[] = [
                    'runid'   => $runid,
                    'cellkey' => (string) $run->cellkey,
                    'stage'   => (string) ($outcome['stage'] ?? ''),
                    'reason'  => (string) ($outcome['reason'] ?? ''),
                ];
                continue;
            }

            $prepared++;
        }

        $ok = $blockers === [];

        return self::verdict(
            $ok,
            $ok ? self::STATE_READY : self::STATE_BLOCKED,
            $prepared,
            count($runs),
            $blockers
        );
    }

    /**
     * Whether an experiment's definition can be built at all.
     *
     * @param int $experimentid The experiment.
     * @return array{ok: bool, blockers: array[]}
     */
    public static function validate(int $experimentid): array {
        global $DB;

        $experiment = $DB->get_record('local_catquizlab_experiment', ['id' => $experimentid]);
        if (!$experiment) {
            return ['ok' => false, 'blockers' => [['reason' => 'experiment-not-found']]];
        }

        try {
            experiment_definition::from_json((string) $experiment->configjson)->get_normalised();
        } catch (\Throwable $e) {
            return ['ok' => false, 'blockers' => [['stage' => 'definition', 'reason' => $e->getMessage()]]];
        }

        // The installation has to be able to run anything at all, and finding
        // that out after building thirty courses is finding it out too late.
        $situation = setup_wizard::state();
        if (empty($situation['ready'])) {
            return ['ok' => false, 'blockers' => [['stage' => 'preparation', 'reason' => 'setup-incomplete']]];
        }

        return ['ok' => true, 'blockers' => []];
    }

    /**
     * Where an experiment stands, in the terms a person asked in.
     *
     * @param int $experimentid The experiment.
     * @return array{state: string, label: string, runs: array, attempts: array}
     */
    public static function state(int $experimentid): array {
        global $DB;

        $component = 'local_catquizlab';

        $runs = $DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid]);
        $counts = ['total' => count($runs), 'ready' => 0, 'running' => 0, 'finished' => 0, 'failed' => 0];

        foreach ($runs as $run) {
            $status = (int) $run->status;
            if ($status === registry::STATUS_FINISHED) {
                $counts['finished']++;
            } else if ($status === registry::STATUS_FAILED || $status === registry::STATUS_CANCELLED) {
                $counts['failed']++;
            } else if (in_array($status, [registry::STATUS_RUNNING, registry::STATUS_AGGREGATING], true)) {
                $counts['running']++;
            } else if ($status === registry::STATUS_READY) {
                $counts['ready']++;
            }
        }

        $attempts = ['planned' => 0, 'collected' => 0];
        if ($runs !== []) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($runs), SQL_PARAMS_NAMED, 'r');
            $attempts['planned'] = $DB->count_records_select('local_catquizlab_attempt', 'runid ' . $insql, $params);
            $attempts['collected'] = $DB->count_records_select(
                'local_catquizlab_attempt',
                'runid ' . $insql . ' AND status = :collected',
                $params + ['collected' => attempt_scheduler::STATUS_COLLECTED]
            );
        }

        $state = self::derive_state($counts, $runs);

        return [
            'state'    => $state,
            'label'    => get_string('runner:state' . $state, $component),
            'runs'     => $counts,
            'attempts' => $attempts,
            // The two questions a person actually has, answered as yes or no
            // rather than as a table of statuses.
            'canprepare' => in_array($state, [self::STATE_DRAFT, self::STATE_BLOCKED], true),
            'canstart'   => $state === self::STATE_READY,
        ];
    }

    /**
     * The experiment's state from its runs.
     *
     * @param array $counts Run counts by outcome.
     * @param array $runs The runs themselves.
     * @return string
     */
    protected static function derive_state(array $counts, array $runs): string {
        if ($counts['total'] === 0) {
            return self::STATE_DRAFT;
        }

        if ($counts['finished'] === $counts['total']) {
            return self::STATE_FINISHED;
        }

        if ($counts['running'] > 0) {
            return self::STATE_RUNNING;
        }

        // A failure anywhere blocks the experiment: an experiment whose runs
        // partly failed produces a result over the runs that happened to work,
        // which is a different quantity from the one that was designed.
        if ($counts['failed'] > 0) {
            return self::STATE_BLOCKED;
        }

        if ($counts['ready'] + $counts['finished'] === $counts['total']) {
            return self::STATE_READY;
        }

        return self::STATE_PREPARING;
    }

    /**
     * Assemble a preparation verdict.
     *
     * @param bool $ok Whether it worked.
     * @param string $state The resulting state.
     * @param int $prepared How many runs are ready.
     * @param int $total How many there are.
     * @param array[] $blockers What stopped it, per run.
     * @return array
     */
    protected static function verdict(bool $ok, string $state, int $prepared, int $total, array $blockers): array {
        return [
            'ok'       => $ok,
            'state'    => $state,
            'prepared' => $prepared,
            'total'    => $total,
            'blockers' => $blockers,
        ];
    }
}
