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
 *   aggregating, complete, complete with failures — with a label in words;
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
    public const PHASES = ['planned', 'preparing', 'ready', 'queued', 'starting', 'collecting', 'waiting', 'paused',
        'blocked', 'aggregating', 'complete', 'completefailures'];

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
        $held = count(array_filter($progress['runs'], static fn(array $run): bool => !empty($run['held'])));
        $failed = (int) $progress['now']['failed'];
        $phase = self::phase($progress, $runner, $queued, $held);

        $component = 'local_catquizlab';
        $snapshot = [
            'experimentid' => $experimentid,
            'name'         => format_string($experiment->name),
            'phase'        => $phase,
            'label'        => get_string('status:phase' . $phase, $component),
            'reason'       => in_array($phase, ['waiting', 'starting', 'paused', 'blocked'], true) ? $progress['reason'] : '',
            'done'         => (int) $progress['done'],
            'total'        => (int) $progress['total'],
            'percent'      => (int) $progress['percent'],
            'sittings'     => get_string('status:sittings', $component, (object) [
                'done' => $progress['done'], 'total' => $progress['total'], 'percent' => $progress['percent'],
            ]),
            'problems'     => $failed + $held,
            'hasproblems'  => $failed + $held > 0,
            'problemtext'  => $failed + $held > 0
                ? get_string('status:problems', $component, (object) ['failed' => $failed, 'held' => $held])
                : get_string('status:noproblems', $component),
            'next'         => self::next($phase, $experimentid, $runner),
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
                    SUM(CASE WHEN status = :ready THEN 1 ELSE 0 END) AS ready
               FROM {local_catquizlab_run} WHERE experimentid {$insql} GROUP BY experimentid",
            $params + ['running' => registry::STATUS_RUNNING, 'aggregating' => registry::STATUS_AGGREGATING,
                'ready' => registry::STATUS_READY]
        );
        [$heldsql, $heldparams] = circuit_breaker::held_sql();
        $held = $DB->get_records_sql_menu(
            "SELECT experimentid, COUNT(1) FROM {local_catquizlab_run}
              WHERE experimentid {$insql} AND {$heldsql} GROUP BY experimentid",
            $params + $heldparams
        );
        $sittings = [];
        $rows = $DB->get_recordset_sql(
            "SELECT r.experimentid, a.status, COUNT(1) AS n
               FROM {local_catquizlab_attempt} a JOIN {local_catquizlab_run} r ON r.id = a.runid
              WHERE r.experimentid {$insql} GROUP BY r.experimentid, a.status",
            $params
        );
        foreach ($rows as $row) {
            $e = (int) $row->experimentid;
            $sittings[$e] = $sittings[$e] ?? ['total' => 0, 'done' => 0, 'failed' => 0, 'inflight' => 0];
            $n = (int) $row->n;
            $sittings[$e]['total'] += $n;
            $status = (int) $row->status;
            if (in_array($status, [attempt_scheduler::STATUS_COLLECTED, attempt_scheduler::STATUS_VALIDATED], true)) {
                $sittings[$e]['done'] += $n;
            } else if ($status === attempt_scheduler::STATUS_FAILED) {
                $sittings[$e]['failed'] += $n;
            } else if ($status === attempt_scheduler::STATUS_RUNNING) {
                $sittings[$e]['inflight'] += $n;
            }
        }
        $rows->close();

        foreach ($experimentids as $id) {
            $run = $runs[$id] ?? null;
            $count = $sittings[$id] ?? ['total' => 0, 'done' => 0, 'failed' => 0, 'inflight' => 0];
            if ($run === null || (int) $run->n === 0) {
                $phase = 'planned';
            } else if (!empty($held[$id])) {
                $phase = 'blocked';
            } else if ($count['total'] > 0 && $count['done'] + $count['failed'] >= $count['total']) {
                $phase = $count['failed'] > 0 ? 'completefailures' : 'complete';
            } else if ($count['inflight'] > 0 || (int) $run->active > 0) {
                $phase = $count['done'] + $count['inflight'] > 0 ? 'collecting' : 'starting';
            } else if ((int) $run->ready === (int) $run->n && $count['done'] === 0) {
                $phase = 'ready';
            } else {
                $phase = 'preparing';
            }
            $out[$id] = [
                'phase' => $phase,
                'label' => get_string('status:phase' . $phase, 'local_catquizlab'),
                'done' => $count['done'],
                'total' => $count['total'],
                'failed' => $count['failed'],
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
        if ((int) ($runner['runs']['total'] ?? 0) === 0) {
            return 'planned';
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
        if ($untouched && $queued !== null) {
            return 'queued';
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
     * @return array{kind: string, label: string, url: string, post: bool, action: string, step: string}
     */
    public static function next(string $phase, int $experimentid, array $runner): array {
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

        if ($phase === 'planned') {
            return !empty($runner['canprepare'])
                ? $make('create', new \moodle_url('/local/catquizlab/runs.php'), 'progress', 'prepareexperiment')
                : $make('plan', new \moodle_url('/local/catquizlab/index.php', ['tab' => 'experiments',
                    'experimentid' => $experimentid]), 'plan');
        }
        if ($phase === 'ready' && !empty($runner['canstart'])) {
            return $make('start', new \moodle_url('/local/catquizlab/runs.php'), 'progress', 'startexperiment');
        }
        if ($phase === 'blocked') {
            return $make('problems', new \moodle_url(
                '/local/catquizlab/runs.php',
                ['experimentid' => $experimentid],
                'catquizlab-recovery'
            ), 'progress');
        }
        if (in_array($phase, ['complete', 'completefailures'], true)) {
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
        if (in_array($phase, ['completefailures', 'paused', 'waiting'], true)) {
            return 'warn';
        }

        return in_array($phase, ['complete'], true) ? 'ok' : 'active';
    }
}
