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
 * Started, elapsed, estimated finish and remaining time of an experiment and its runs (#125).
 *
 * The method, in one place (docs/dev/eta-methode.md has the long form):
 *
 * - Started is the first execution the history recorded for the experiment
 *   (attempt history, outcome "started"): persisted, so a reload or a poll
 *   can never move it.
 * - The work left is, per run, the sittings not yet collected or failed for
 *   good, times the mean duration of that run's finished executions. A
 *   sitting in flight counts half. Runs differ — strategies take very
 *   different times — so the mean is the run's own; a run not yet observed
 *   takes its strategy's mean from the other runs, else the experiment's,
 *   and the estimate then says it is provisional.
 * - The capacity is what the experiment actually got: the busy time of the
 *   executions that finished in the last window, divided by the window. It
 *   includes staggered starts, gaps between claims and workers serving other
 *   experiments; the configured worker count does not.
 * - Remaining = work left / capacity. The runs are handed out in queue order
 *   (job_claim: oldest first), so a run's finish is the cumulative work up
 *   to and including it, over the same capacity.
 * - The range is ± 1.96 standard errors of the work estimate, at least 10 %.
 *
 * There is no running estimate while the experiment is held, paused, has no
 * capacity, or has fewer than three finished executions; it says which.
 * Once everything is done, the finish shown is the recorded one.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class eta {
    /** @var string Nothing to estimate: no runs. */
    public const NONE = 'none';

    /** @var string Not enough observed yet. */
    public const CALCULATING = 'calculating';

    /** @var string An estimate resting partly on other runs' durations. */
    public const PROVISIONAL = 'provisional';

    /** @var string An estimate from the experiment's own observations. */
    public const ESTIMATE = 'estimate';

    /** @var string No running estimate: held, paused, or no capacity. */
    public const UNRELIABLE = 'unreliable';

    /** @var string Everything done: the times are the recorded ones. */
    public const FINAL = 'final';

    /** @var int Finished executions needed before there is an estimate. */
    public const MIN_SAMPLE = 3;

    /** @var int The capacity window, in seconds. */
    public const WINDOW = 600;

    /** @var float The smallest relative margin shown. */
    public const MIN_MARGIN = 0.1;

    /**
     * The times of an experiment, from its progress snapshot.
     *
     * @param int $experimentid The experiment.
     * @param array $progress live_progress::snapshot() without the times: state, runs (runid, done, total, failed, inflight).
     * @param int|null $now The clock; null for the real one.
     * @return array
     */
    public static function estimate(int $experimentid, array $progress, ?int $now = null): array {
        global $DB;

        $now = $now ?? time();
        $rows = $progress['runs'] ?? [];
        $out = self::blank($now);
        // Nothing planned yet is nothing to estimate — not "finished".
        $planned = array_sum(array_map(static fn(array $row): int => (int) $row['total'], $rows));
        if ($experimentid <= 0 || $planned === 0) {
            return self::labelled($out);
        }

        $runids = array_map(static fn(array $row): int => (int) $row['runid'], $rows);
        [$insql, $params] = $DB->get_in_or_equal($runids, SQL_PARAMS_NAMED, 'r');

        // When each run's first execution started, and its last one ended.
        $starts = $DB->get_records_sql_menu(
            "SELECT runid, MIN(timecreated)
               FROM {local_catquizlab_attemptlog}
              WHERE runid {$insql} AND outcome = :started
           GROUP BY runid",
            $params + ['started' => attempt_history::STARTED]
        );
        $finished = $DB->get_records_sql(
            "SELECT runid, COUNT(1) AS n, SUM(runtimems) AS s, SUM(runtimems * runtimems) AS ss, MAX(timecreated) AS lastend
               FROM {local_catquizlab_attemptlog}
              WHERE runid {$insql} AND outcome IN (:collected, :failed)
           GROUP BY runid",
            $params + ['collected' => attempt_history::COLLECTED, 'failed' => attempt_history::FAILED]
        );
        $requested = (int) $DB->get_field_sql(
            "SELECT MIN(timecreated) FROM {local_catquizlab_runlog} WHERE runid {$insql} AND event = :event",
            $params + ['event' => run_log::START_REQUESTED]
        );

        $started = $starts === [] ? 0 : (int) min(array_map('intval', $starts));
        $out['requested'] = $requested > 0 ? $requested : null;
        $out['started'] = $started > 0 ? $started : null;

        // Durations per run, per strategy and overall, in seconds.
        $strategies = self::strategies($runids);
        $samples = ['run' => [], 'strategy' => [], 'all' => ['n' => 0, 's' => 0.0, 'ss' => 0.0]];
        $lastend = 0;
        foreach ($finished as $runid => $row) {
            $lastend = max($lastend, (int) $row->lastend);
            $n = (int) $row->n;
            $s = (float) $row->s / 1000;
            $ss = (float) $row->ss / 1e6;
            $samples['run'][(int) $runid] = ['n' => $n, 's' => $s, 'ss' => $ss];
            $strategy = $strategies[(int) $runid] ?? '';
            $samples['strategy'][$strategy] = self::pooled($samples['strategy'][$strategy] ?? null, $n, $s, $ss);
            $samples['all'] = self::pooled($samples['all'], $n, $s, $ss);
        }
        $out['sample'] = (int) $samples['all']['n'];

        // The work left per run, in queue order.
        $pending = 0;
        $work = [];
        foreach ($rows as $row) {
            $runid = (int) $row['runid'];
            $left = max(0, (int) $row['total'] - (int) $row['done'] - (int) $row['failed']);
            $pending += $left;
            $inflight = min($left, (int) ($row['inflight'] ?? 0));
            $units = $left - $inflight + 0.5 * $inflight;
            [$mean, $se, $basis] = self::duration_of($samples, $runid, $strategies[$runid] ?? '');
            $work[$runid] = ['left' => $left, 'units' => $units, 'mean' => $mean, 'se' => $se, 'basis' => $basis,
                'started' => isset($starts[$runid]) ? (int) $starts[$runid] : null,
                'lastend' => isset($finished[$runid]) ? (int) $finished[$runid]->lastend : null];
        }
        ksort($work);

        $state = (string) ($progress['state'] ?? '');
        $end = $out['started'] !== null ? ($pending === 0 ? max($lastend, $out['started']) : $now) : null;
        $out['elapsed'] = $end !== null ? max(0, $end - $out['started']) : null;
        $out['capacity'] = self::capacity($insql, $params, $now, $out['started'], $samples['all']);
        $out['runs'] = [];

        // Done: the recorded end, not a forecast.
        if ($pending === 0) {
            $out['reliability'] = self::FINAL;
            $out['finish'] = $lastend > 0 ? $lastend : null;
            $out['remaining'] = 0;
            foreach ($work as $runid => $w) {
                $out['runs'][$runid] = self::run_times($w, self::FINAL, $now, 0, $w['lastend']);
            }

            return self::labelled($out);
        }

        // Nothing finished in the window, but sittings in flight: sittings
        // longer than the window. What is in flight is the capacity then.
        $inflight = (int) ($progress['now']['inflight'] ?? 0);
        if ($out['capacity']['slots'] <= 0 && $inflight > 0) {
            $out['capacity']['slots'] = (float) $inflight;
            $out['capacity']['basis'] = 'inflight';
        }
        $why = self::no_estimate_reason($state, $out);
        if ($why !== null) {
            [$out['reliability'], $out['reason']] = $why;
            foreach ($work as $runid => $w) {
                $out['runs'][$runid] = self::run_times(
                    $w,
                    $w['left'] === 0 ? self::FINAL : $out['reliability'],
                    $now,
                    $w['left'] === 0 ? 0 : null,
                    $w['left'] === 0 ? $w['lastend'] : null
                );
            }

            return self::labelled($out);
        }

        // Work over capacity, in queue order.
        $capacity = $out['capacity']['slots'];
        $cumulative = 0.0;
        $variance = 0.0;
        $provisional = false;
        foreach ($work as $runid => $w) {
            if ($w['left'] === 0) {
                $out['runs'][$runid] = self::run_times($w, self::FINAL, $now, 0, $w['lastend']);
                continue;
            }
            $cumulative += $w['units'] * $w['mean'];
            $variance += ($w['units'] * $w['se']) ** 2;
            $provisional = $provisional || $w['basis'] === 'experiment';
            $remaining = (int) ceil($cumulative / $capacity);
            $out['runs'][$runid] = self::run_times(
                $w,
                $w['basis'] === 'experiment' ? self::PROVISIONAL : self::ESTIMATE,
                $now,
                $remaining,
                $now + $remaining
            );
        }
        $remaining = (int) ceil($cumulative / $capacity);
        $margin = max(self::MIN_MARGIN, $cumulative > 0 ? 1.96 * sqrt($variance) / $cumulative : 0.0);
        $out['reliability'] = $provisional ? self::PROVISIONAL : self::ESTIMATE;
        $out['remaining'] = $remaining;
        $out['finish'] = $now + $remaining;
        $out['margin'] = $margin;
        $out['range'] = [
            'low'  => (int) floor($remaining * (1 - $margin)),
            'high' => (int) ceil($remaining * (1 + $margin)),
        ];

        return self::labelled($out);
    }

    /**
     * Why there is no running estimate, if there is none.
     *
     * @param string $state The live_progress state.
     * @param array $out The estimate so far.
     * @return array|null [reliability, reason string key] or null.
     */
    protected static function no_estimate_reason(string $state, array $out): ?array {
        if ($state === live_progress::BLOCKED) {
            return [self::UNRELIABLE, 'eta:whyblocked'];
        }
        if ($state === live_progress::PAUSED) {
            return [self::UNRELIABLE, 'eta:whypaused'];
        }
        if ($out['started'] === null) {
            return [self::CALCULATING, 'eta:whynotstarted'];
        }
        if ($out['sample'] < self::MIN_SAMPLE) {
            return [self::CALCULATING, 'eta:whysample'];
        }
        if ($out['capacity']['slots'] <= 0) {
            // Finished executions, but none lately and nothing in flight: a
            // stall, not a slow run. A forecast would go on counting down.
            return [self::UNRELIABLE, 'eta:whynocapacity'];
        }

        return null;
    }

    /**
     * The capacity the experiment got lately: busy seconds finished in the window, per second of window.
     *
     * @param string $insql The run IN clause.
     * @param array $params Its parameters.
     * @param int $now The clock.
     * @param int|null $started The first start.
     * @param array $all The overall duration sample.
     * @return array{slots: float, window: int, executions: int, basis: string}
     */
    protected static function capacity(string $insql, array $params, int $now, ?int $started, array $all): array {
        global $DB;

        if ($started === null) {
            return ['slots' => 0.0, 'window' => 0, 'executions' => 0, 'basis' => 'window'];
        }
        // At least three mean durations, so a long sitting fits in it.
        $mean = $all['n'] > 0 ? $all['s'] / $all['n'] : 0.0;
        $window = (int) max(self::WINDOW, ceil(3 * $mean));
        $span = max(1, min($window, $now - $started));
        $row = $DB->get_record_sql(
            "SELECT COUNT(1) AS n, SUM(runtimems) AS s
               FROM {local_catquizlab_attemptlog}
              WHERE runid {$insql} AND outcome IN (:collected, :failed) AND timecreated > :since",
            $params + ['collected' => attempt_history::COLLECTED, 'failed' => attempt_history::FAILED,
                'since' => $now - $span]
        );
        $executions = (int) ($row->n ?? 0);

        return [
            'slots' => $executions > 0 ? round(((float) $row->s / 1000) / $span, 3) : 0.0,
            'window' => $span,
            'executions' => $executions,
            'basis' => 'window',
        ];
    }

    /**
     * The mean duration of a run's executions, and on what it rests.
     *
     * @param array $samples Per run, strategy and overall.
     * @param int $runid The run.
     * @param string $strategy Its strategy.
     * @return array{0: float, 1: float, 2: string} Mean seconds, its standard error, basis run|strategy|experiment|none.
     */
    protected static function duration_of(array $samples, int $runid, string $strategy): array {
        $candidates = [
            'run' => $samples['run'][$runid] ?? null,
            'strategy' => $samples['strategy'][$strategy] ?? null,
            'experiment' => $samples['all'],
        ];
        foreach ($candidates as $basis => $sample) {
            if ($sample !== null && $sample['n'] >= self::MIN_SAMPLE) {
                $n = $sample['n'];
                $mean = $sample['s'] / $n;
                $variance = max(0.0, ($sample['ss'] - $n * $mean * $mean) / max(1, $n - 1));

                return [$mean, sqrt($variance / $n), $basis];
            }
        }

        return [0.0, 0.0, 'none'];
    }

    /**
     * A duration sample with more added.
     *
     * @param array|null $pool The sample so far.
     * @param int $n Executions.
     * @param float $s Their summed seconds.
     * @param float $ss Their summed squared seconds.
     * @return array{n: int, s: float, ss: float}
     */
    protected static function pooled(?array $pool, int $n, float $s, float $ss): array {
        $pool = $pool ?? ['n' => 0, 's' => 0.0, 'ss' => 0.0];

        return ['n' => $pool['n'] + $n, 's' => $pool['s'] + $s, 'ss' => $pool['ss'] + $ss];
    }

    /**
     * Each run's strategy key, from its manifest.
     *
     * @param int[] $runids The runs.
     * @return array<int, string>
     */
    protected static function strategies(array $runids): array {
        global $DB;

        $out = [];
        foreach ($DB->get_records_list('local_catquizlab_run', 'id', $runids, '', 'id, manifestjson') as $run) {
            $manifest = json_decode((string) $run->manifestjson, true) ?: [];
            $out[(int) $run->id] = (string) ($manifest['config']['strategy']['key'] ?? '');
        }

        return $out;
    }

    /**
     * One run's times.
     *
     * @param array $w The run's work.
     * @param string $reliability How far to trust them.
     * @param int $now The clock.
     * @param int|null $remaining Seconds left.
     * @param int|null $finish When it ends or ended.
     * @return array
     */
    protected static function run_times(array $w, string $reliability, int $now, ?int $remaining, ?int $finish): array {
        $started = $w['started'];
        $end = $reliability === self::FINAL ? ($finish ?? $now) : $now;

        return [
            'started'     => $started,
            'elapsed'     => $started !== null ? max(0, $end - $started) : null,
            'remaining'   => $remaining,
            'finish'      => $finish,
            'reliability' => $reliability,
            'meanseconds' => $w['mean'] > 0 ? round($w['mean'], 1) : null,
            'basis'       => $w['basis'],
        ];
    }

    /**
     * The shape with nothing in it.
     *
     * @param int $now The clock.
     * @return array
     */
    protected static function blank(int $now): array {
        return [
            'reliability' => self::NONE,
            'reason'      => '',
            'requested'   => null,
            'started'     => null,
            'elapsed'     => null,
            'remaining'   => null,
            'finish'      => null,
            'margin'      => null,
            'range'       => null,
            'sample'      => 0,
            'capacity'    => ['slots' => 0.0, 'window' => 0, 'executions' => 0, 'basis' => 'window'],
            'computed'    => $now,
            'runs'        => [],
        ];
    }

    /**
     * Add what a page shows: the four values as text, the status word and the method.
     *
     * @param array $out The estimate.
     * @return array
     */
    protected static function labelled(array $out): array {
        $component = 'local_catquizlab';
        $na = get_string('eta:na', $component);
        $out['text'] = [
            'started'   => $out['started'] !== null ? self::when($out['started'])
                : ($out['requested'] !== null ? get_string('eta:requestedat', $component, self::when($out['requested'])) : $na),
            'elapsed'   => $out['elapsed'] !== null ? self::span($out['elapsed']) : $na,
            'finish'    => $na,
            'remaining' => $na,
            'status'    => get_string('eta:' . $out['reliability'], $component),
            'reason'    => $out['reason'] !== '' ? get_string($out['reason'], $component) : '',
            'method'    => get_string('eta:method', $component, (object) [
                'sample' => $out['sample'],
                'slots' => format_float($out['capacity']['slots'], 1),
                'window' => self::span($out['capacity']['window']),
                'margin' => $out['margin'] !== null ? (int) round($out['margin'] * 100) : '–',
            ]),
            'computed'  => userdate($out['computed'], get_string('strftimetime', 'langconfig')),
        ];
        // The method where there is something it produced; the record where it is history.
        if ($out['reliability'] === self::FINAL) {
            $out['text']['method'] = get_string('eta:methodfinal', $component);
        } else if ($out['reliability'] === self::NONE) {
            $out['text']['method'] = '';
        }
        if ($out['reliability'] === self::FINAL) {
            $out['text']['finish'] = $out['finish'] !== null
                ? get_string('eta:finishedat', $component, self::when($out['finish'])) : $na;
            $out['text']['remaining'] = get_string('eta:nothingleft', $component);
        } else if (in_array($out['reliability'], [self::ESTIMATE, self::PROVISIONAL], true)) {
            $out['text']['finish'] = get_string('eta:approx', $component, self::when((int) $out['finish']));
            $out['text']['remaining'] = get_string('eta:range', $component, (object) [
                'remaining' => self::span((int) $out['remaining']),
                'low' => self::span((int) $out['range']['low']),
                'high' => self::span((int) $out['range']['high']),
            ]);
        } else if ($out['reliability'] === self::CALCULATING) {
            $out['text']['finish'] = $out['text']['remaining'] = get_string('eta:calculating', $component);
        }
        foreach ($out['runs'] as $runid => $run) {
            $out['runs'][$runid]['runid'] = $runid;
            $out['runs'][$runid]['text'] = [
                'started' => $run['started'] !== null ? self::when($run['started']) : $na,
                'elapsed' => $run['elapsed'] !== null ? self::span($run['elapsed']) : $na,
                'remaining' => $run['remaining'] !== null
                    ? ($run['reliability'] === self::FINAL ? get_string('eta:nothingleft', $component)
                        : '≈ ' . self::span($run['remaining']))
                    : get_string('eta:' . $run['reliability'], $component),
                'finish' => $run['finish'] !== null ? self::when($run['finish']) : $na,
            ];
        }
        $out['runs'] = array_values($out['runs']);

        return $out;
    }

    /**
     * A point in time, with the viewer's time zone named.
     *
     * @param int $timestamp When.
     * @return string
     */
    public static function when(int $timestamp): string {
        return userdate($timestamp, get_string('strftimedatetimeshort', 'langconfig')) . ' (' . usertimezone() . ')';
    }

    /**
     * A span rounded to what an estimate can claim: seconds below two minutes,
     * minutes below two hours, five minutes beyond.
     *
     * @param int $seconds How long.
     * @return string
     */
    public static function span(int $seconds): string {
        if ($seconds < 120) {
            return duration::human($seconds, true);
        }
        $step = $seconds < 7200 ? 60 : 300;

        return format_time((int) (round($seconds / $step) * $step));
    }
}
