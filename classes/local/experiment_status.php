<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_catquizlab\local;

/**
 * One experiment's status, once per request and per poll, for every place that shows it (#128).
 *
 * The header's status bar, the progress card, the experiment line above the
 * runs and the live poll used to ask three different classes three slightly
 * different questions a few milliseconds apart — and could show "Running",
 * "Blocked" and "working" side by side. They now read this snapshot:
 *
 * - **phase**: where the experiment is in its scientific life — planned,
 *   preparing, ready, queued, starting, collecting, waiting, paused, blocked,
 *   stopped, aggregating, complete, complete with failures — with a label in words;
 * - **sittings**: done of planned, percent, and the problems (sittings failed
 *   for good, runs held);
 * - **next**: the one action the phase calls for, and only one that is allowed
 *   in it — create runs, start, view progress, review problems, view results;
 *   never "start" for an experiment that is queued or running;
 * - **technical**: the worker figures, kept apart from the experiment's own;
 * - **computed**: when it was taken.
 *
 * Built on live_progress::snapshot() (the sittings) and
 * experiment_runner::state() (what may be done), so it adds no count of its own.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class experiment_status {
    /** @var string[] The phases, in the order of an experiment's life. */
    public const PHASES = ['planned', 'created', 'preparing', 'ready', 'queued', 'starting', 'collecting', 'waiting', 'paused',
        'blocked', 'stopped', 'aggregating', 'complete', 'completefailures'];

    /** @var array<int, array> This request's snapshots. */
    protected static array $memo = [];

    /**
     * The snapshot, taken once per request.
     *
     * @param int $experimentid The experiment.
     * @param bool $fresh Take it again (a poll is a new request anyway).
     * @return array|null Null for no experiment.
     */
    public static function snapshot(int $experimentid, bool $fresh = false): ?array {
        global $DB;

        if ($experimentid <= 0) {
            return null;
        }
        if (!$fresh && isset(self::$memo[$experimentid])) {
            return self::$memo[$experimentid];
        }
        $experiment = $DB->get_record('local_catquizlab_experiment', ['id' => $experimentid], 'id, name');
        if (!$experiment) {
            return null;
        }

        $progress = live_progress::snapshot($experimentid);
        $runner = experiment_runner::state($experimentid);
        $queued = execution_queue::state_of($experimentid);
        $turn = null;
        if (
            $queued === null && (int) ($progress['now']['queued'] ?? 0) > 0
            && $runner['state'] !== experiment_runner::STATE_BLOCKED
            && execution_queue::next_holder() !== $experimentid
        ) {
            // Sittings to hand out and no place in the line: not claimable (#163). The line gives it a place at its
            // next move; until then it is shown as what it is, waiting.
            $queued = execution_queue::WAITING;
        }
        if ($queued === execution_queue::WAITING) {
            $turn = execution_queue::turn($experimentid);
        }
        $held = count(array_filter($progress['runs'], static fn(array $run): bool => !empty($run['held'])));
        // On hold (resumable after a decision) apart from failed for good (#175 AK05, #181 AK06).
        $onhold = (int) ($progress['now']['heldsittings'] ?? 0);
        $failed = (int) $progress['now']['failed'];
        $phase = self::phase($progress, $runner, $queued, $held);

        $component = 'local_catquizlab';
        $snapshot = [
            'experimentid' => $experimentid,
            'name'         => format_string($experiment->name),
            'phase'        => $phase,
            'label'        => get_string('status:phase' . $phase, $component),
            'reason'       => $phase === 'queued' && $turn !== null ? self::turn_reason($turn)
                : (in_array($phase, ['waiting', 'starting', 'paused', 'blocked'], true) ? $progress['reason'] : ''),
            'done'         => (int) $progress['done'],
            'total'        => (int) $progress['total'],
            'percent'      => (int) $progress['percent'],
            'sittings'     => get_string('status:sittings', $component, (object) [
                'done' => $progress['done'], 'total' => $progress['total'], 'percent' => $progress['percent'],
            ]),
            'problems'     => $failed + $held + $onhold,
            'hasproblems'  => $failed + $held + $onhold > 0,
            'problemtext'  => $failed + $held + $onhold > 0
                ? get_string('status:problemsonhold', $component, (object) ['failed' => $failed, 'held' => $held,
                    'onhold' => $onhold])
                : get_string('status:noproblems', $component),
            'next'         => self::next($phase, $experimentid, $runner, $queued),
            'technical'    => get_string('status:technical', $component, (object) [
                'workers' => $progress['now']['workers'], 'inflight' => $progress['now']['inflight'],
                'queued' => $progress['now']['queued'],
            ]),
            'tone'         => self::tone($phase),
            'computed'     => time(),
            'computedtext' => get_string('eta:asof', $component, userdate(time(), get_string('strftimetime', 'langconfig'))),
            'progress'     => $progress,
            'runner'       => $runner,
            'queued'       => $queued !== null,
        ];

        return self::$memo[$experimentid] = $snapshot;
    }

    /**
     * Why an experiment waits in the execution line, in words (#163).
     *
     * @param array $turn execution_queue::turn().
     * @return string
     */
    public static function turn_reason(array $turn): string {
        $component = 'local_catquizlab';
        if ((int) $turn['place'] === 0) {
            return get_string('status:turnpending', $component);
        }
        $text = get_string(
            (int) $turn['holder'] > 0 ? 'status:turnplace' : 'status:turnplacefree',
            $component,
            (object) ['place' => (int) $turn['place'], 'holder' => (int) $turn['holder']]
        );

        return (string) $turn['note'] !== '' ? $text . ' (' . $turn['note'] . ')' : $text;
    }

    /**
     * Phase and sittings of many experiments at once, for the library (#129).
     *
     * Three grouped queries for the whole list instead of a snapshot each:
     * the library shows where every experiment stands, not its live detail.
     * The phase uses the same names and labels as the snapshot.
     *
     * @param int[] $experimentids The experiments.
     * @return array<int, array{phase: string, label: string, done: int, total: int, failed: int, percent: int, tone: string}>
     */
    public static function library(array $experimentids): array {
        global $DB;

        $out = [];
        if ($experimentids === []) {
            return $out;
        }
        [$insql, $params] = $DB->get_in_or_equal($experimentids, SQL_PARAMS_NAMED, 'e');
        $runs = $DB->get_records_sql(
            "SELECT experimentid, COUNT(1) AS n,
                    SUM(CASE WHEN status IN (:running, :aggregating) THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN status = :ready THEN 1 ELSE 0 END) AS ready,
                    SUM(CASE WHEN status = :draft THEN 1 ELSE 0 END) AS draft,
                    SUM(CASE WHEN status = :scheduled THEN 1 ELSE 0 END) AS scheduled,
                    SUM(CASE WHEN status = :cancelled THEN 1 ELSE 0 END) AS cancelled,
                    SUM(CASE WHEN status = :finished THEN 1 ELSE 0 END) AS finished
               FROM {local_catquizlab_run} WHERE experimentid {$insql} GROUP BY experimentid",
            $params + ['running' => registry::STATUS_RUNNING, 'aggregating' => registry::STATUS_AGGREGATING,
                'ready' => registry::STATUS_READY, 'draft' => registry::STATUS_DRAFT,
                'scheduled' => registry::STATUS_SCHEDULED, 'cancelled' => registry::STATUS_CANCELLED,
                'finished' => registry::STATUS_FINISHED]
        );
        [$heldsql, $heldparams] = circuit_breaker::held_sql();
        $held = $DB->get_records_sql_menu(
            "SELECT experimentid, COUNT(1) FROM {local_catquizlab_run}
              WHERE experimentid {$insql} AND {$heldsql} GROUP BY experimentid",
            $params + $heldparams
        );
        // Where each stands in the execution line (#151): one query for the list.
        $inline = $DB->get_manager()->table_exists('local_catquizlab_execqueue') ? $DB->get_records_sql_menu(
            "SELECT experimentid, MAX(state) FROM {local_catquizlab_execqueue}
              WHERE experimentid {$insql} AND state IN (:qwaiting, :qrunning) GROUP BY experimentid",
            $params + ['qwaiting' => execution_queue::WAITING, 'qrunning' => execution_queue::RUNNING]
        ) : [];
        $sittings = [];
        $rows = $DB->get_recordset_sql(
            "SELECT r.experimentid, a.status, COUNT(1) AS n
               FROM {local_catquizlab_attempt} a JOIN {local_catquizlab_run} r ON r.id = a.runid
              WHERE r.experimentid {$insql} GROUP BY r.experimentid, a.status",
            $params
        );
        foreach ($rows as $row) {
            $e = (int) $row->experimentid;
            $sittings[$e] = $sittings[$e] ?? ['total' => 0, 'done' => 0, 'failed' => 0, 'cancelled' => 0, 'inflight' => 0];
            $n = (int) $row->n;
            $sittings[$e]['total'] += $n;
            $status = (int) $row->status;
            if (in_array($status, [attempt_scheduler::STATUS_COLLECTED, attempt_scheduler::STATUS_VALIDATED], true)) {
                $sittings[$e]['done'] += $n;
            } else if ($status === attempt_scheduler::STATUS_FAILED) {
                $sittings[$e]['failed'] += $n;
            } else if ($status === attempt_scheduler::STATUS_CANCELLED) {
                // Closed by an operator (#166): ended, not failed.
                $sittings[$e]['cancelled'] += $n;
            } else if ($status === attempt_scheduler::STATUS_RUNNING) {
                $sittings[$e]['inflight'] += $n;
            }
        }
        $rows->close();

        foreach ($experimentids as $id) {
            $run = $runs[$id] ?? null;
            $count = $sittings[$id] ?? ['total' => 0, 'done' => 0, 'failed' => 0, 'cancelled' => 0, 'inflight' => 0];
            $line = $inline[$id] ?? null;
            if ($run === null || (int) $run->n === 0) {
                $phase = $line === execution_queue::WAITING ? 'queued' : ($line === execution_queue::RUNNING ? 'preparing'
                    : 'planned');
            } else if (!empty($held[$id])) {
                $phase = 'blocked';
            } else if ((int) $run->cancelled > 0 && (int) $run->cancelled + (int) $run->finished === (int) $run->n) {
                // Stopped (0.7.49): not "complete with failures" — the sittings it closed did not fail.
                $phase = 'stopped';
            } else if ($count['total'] > 0 && $count['done'] + $count['failed'] + $count['cancelled'] >= $count['total']) {
                $phase = $count['failed'] > 0 ? 'completefailures' : 'complete';
            } else if ($line === execution_queue::WAITING) {
                // Behind the holder of the line (#163): what it has in play may still finish, nothing new is handed
                // out to it — "collecting" would say the opposite.
                $phase = 'queued';
            } else if ($count['inflight'] > 0 || (int) $run->active > 0) {
                $phase = $count['done'] + $count['inflight'] > 0 ? 'collecting' : 'starting';
            } else if ((int) $run->ready === (int) $run->n && $count['done'] === 0) {
                $phase = $line === execution_queue::RUNNING ? 'starting' : 'ready';
            } else if ((int) $run->draft > 0 && (int) $run->scheduled === 0 && $line === null) {
                $phase = 'created';
            } else {
                $phase = 'preparing';
            }
            $out[$id] = [
                'phase' => $phase,
                'label' => get_string('status:phase' . $phase, 'local_catquizlab'),
                'done' => $count['done'],
                'total' => $count['total'],
                'failed' => $count['failed'],
                'cancelled' => $count['cancelled'],
                'percent' => $count['total'] > 0 ? (int) floor(100 * $count['done'] / $count['total']) : 0,
                'tone' => self::tone($phase),
            ];
        }

        return $out;
    }

    /**
     * Forget this request's snapshots.
     *
     * @return void
     */
    public static function reset(): void {
        self::$memo = [];
    }

    /**
     * The phase, from the sittings and the runs.
     *
     * @param array $progress live_progress::snapshot().
     * @param array $runner experiment_runner::state().
     * @param string|null $queued The execution queue state, or null.
     * @param int $held Runs held.
     * @return string One of PHASES.
     */
    public static function phase(array $progress, array $runner, ?string $queued, int $held): string {
        $state = (string) $progress['state'];
        // In the line (#151): waiting for its turn, or being prepared by the line — said as such, not as "planned"
        // or "runs created", which would read as if nobody had started it.
        $preparing = $queued === execution_queue::RUNNING && in_array((string) $runner['state'], [
            experiment_runner::STATE_DRAFT, experiment_runner::STATE_CREATED, experiment_runner::STATE_PREPARING], true);
        if ((int) ($runner['runs']['total'] ?? 0) === 0) {
            return $queued === execution_queue::WAITING ? 'queued' : ($preparing ? 'preparing' : 'planned');
        }
        if ($runner['state'] === experiment_runner::STATE_STOPPED || $state === live_progress::STOPPED) {
            return 'stopped';
        }
        if ($state === live_progress::BLOCKED || $held > 0 || $runner['state'] === experiment_runner::STATE_BLOCKED) {
            return 'blocked';
        }
        if ($state === live_progress::AGGREGATING) {
            return 'aggregating';
        }
        if ($state === live_progress::FINISHED) {
            return (int) $progress['now']['failed'] > 0 ? 'completefailures' : 'complete';
        }
        $untouched = (int) $progress['done'] === 0 && (int) $progress['now']['inflight'] === 0
            && ($progress['time']['started'] ?? null) === null;
        // Waiting in the line, played before or not (#163): a worker hands out only the holder's sittings, so an
        // experiment behind it is queued, whatever it still has in play from before.
        if ($queued === execution_queue::WAITING) {
            return 'queued';
        }
        if ($preparing) {
            return 'preparing';
        }
        // Its turn, prepared, nothing played yet: the workers are being started (#151 AK9).
        if ($untouched && $queued === execution_queue::RUNNING && $runner['state'] === experiment_runner::STATE_READY) {
            return 'starting';
        }
        if ($runner['state'] === experiment_runner::STATE_CREATED) {
            return 'created';
        }
        if ($runner['state'] === experiment_runner::STATE_PREPARING) {
            return 'preparing';
        }
        if ($untouched && $runner['state'] === experiment_runner::STATE_READY) {
            return 'ready';
        }

        return [
            live_progress::STARTING => 'starting',
            live_progress::RUNNING  => 'collecting',
            live_progress::PAUSED   => 'paused',
        ][$state] ?? 'waiting';
    }

    /**
     * The one next step a phase calls for.
     *
     * @param string $phase The phase.
     * @param int $experimentid The experiment.
     * @param array $runner experiment_runner::state().
     * @param string|null $queued The execution queue state, or null when it is not in the line.
     * @return array{kind: string, label: string, url: string, post: bool, action: string, step: string}
     */
    public static function next(string $phase, int $experimentid, array $runner, ?string $queued = null): array {
        $component = 'local_catquizlab';
        $runs = new \moodle_url('/local/catquizlab/runs.php', ['experimentid' => $experimentid]);
        $make = static fn(string $kind, \moodle_url $url, string $step, string $action = ''): array => [
            'kind' => $kind,
            'label' => get_string('status:next' . $kind, $component),
            'url' => $url->out(false),
            'post' => $action !== '',
            'action' => $action,
            'step' => $step,
            'experimentid' => $experimentid,
            'sesskey' => sesskey(),
        ];

        // One start (#151): from a saved definition, whatever is prepared already, "Start experiment" — once. Creating
        // runs, starting them, preparing and running are what follows by itself; they are no longer steps of their
        // own. Not offered again while the experiment is in the line.
        $startable = in_array((string) ($runner['state'] ?? ''), experiment_runner::STARTABLE, true);
        if ($queued === null && $startable && in_array($phase, ['planned', 'created', 'preparing', 'ready'], true)) {
            return $make('start', new \moodle_url('/local/catquizlab/runs.php'), 'progress', 'startexperiment');
        }
        if ($phase === 'planned') {
            return $make('plan', new \moodle_url('/local/catquizlab/index.php', ['tab' => 'experiments',
                'experimentid' => $experimentid]), 'plan');
        }
        if ($phase === 'blocked') {
            return $make('problems', new \moodle_url(
                '/local/catquizlab/runs.php',
                ['experimentid' => $experimentid],
                'catquizlab-recovery'
            ), 'progress');
        }
        // Stopped: go on, keeping what it collected (#165) — shown first, run by run, then confirmed.
        if ($phase === 'stopped' && !empty($runner['canresume'])) {
            return $make('resume', new \moodle_url('/local/catquizlab/runs.php'), 'progress', 'resumeexperiment');
        }
        $stoppedwithresults = $phase === 'stopped' && (int) ($runner['runs']['finished'] ?? 0) > 0;
        if (in_array($phase, ['complete', 'completefailures'], true) || $stoppedwithresults) {
            return $make(
                'results',
                new \moodle_url('/local/catquizlab/results.php', ['experimentid' => $experimentid]),
                'results'
            );
        }

        return $make('progress', $runs, 'progress');
    }

    /**
     * How the bar should look: fine, in progress, needs a look.
     *
     * @param string $phase The phase.
     * @return string ok|active|warn|problem
     */
    protected static function tone(string $phase): string {
        if ($phase === 'blocked') {
            return 'problem';
        }
        if (in_array($phase, ['completefailures', 'paused', 'waiting', 'stopped'], true)) {
            return 'warn';
        }

        return in_array($phase, ['complete'], true) ? 'ok' : 'active';
    }
}
