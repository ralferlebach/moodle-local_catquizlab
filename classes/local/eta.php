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
 * Started, elapsed, a run's own remaining work, and when it and its experiment will be done (#125, #142, #160).
 *
 * Two different questions, answered apart (docs/dev/eta-methode.md has the long form):
 *
 * - **A run's own remaining work** comes from that run alone: its sittings not yet collected or failed for good, the
 *   questions still ahead of them, times the run's own duration per sitting (its strategy's or its experiment's
 *   while it has none of its own). Adding, changing or renumbering other runs does not change it (#160 AK02/AK03).
 *   Divided by the workers the run itself has right now, it is the run's own processing time.
 * - **When a run will be done** depends on what is ahead of it. Workers claim sittings site-wide in the order
 *   job_claim uses: due time (nextruntime), then creation, then id — not by run id. The forecast plays that queue
 *   through: the work in flight first, then each group of queued sittings in claim order, over the capacity the site
 *   actually has (busy time inside the window, at most the workers there are). A run is done when its last sitting
 *   is; the experiment when its last run is (#160 AK04, AK07, AK09).
 *
 * The range is ± 1.96 standard errors of the work up to that point (Student's t where the sample is small, widened
 * by any drift of the latest durations), at least 10 %. There is no running forecast
 * while an experiment is held or paused, before anything has been measured, or without capacity; it says which.
 * Once everything is done, the times are the recorded ones.
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

    /** @var int Questions answered in the sittings being played before their pace is an estimate (#142). */
    public const MIN_QUESTIONS = 5;

    /** @var int The capacity window, in seconds. */
    public const WINDOW = run_figures::WINDOW;

    /** @var float The smallest relative margin shown. */
    public const MIN_MARGIN = 0.1;

    /**
     * @var float The smallest relative uncertainty of a sitting's length taken from the pace of its questions. The
     *      engine re-estimates over every answer so far, so later questions take longer than the first: measured
     *      (docs/dev/eta-methode.md) 22 to 51 % too short for a 120-question fixed form while none had finished.
     */
    public const MIN_PACE_SE = 0.3;

    /** @var float The relative uncertainty of a sitting's length from other strategies' time per question. */
    public const BORROWED_SE = 0.35;

    /** @var float The relative uncertainty of one sitting's length from the site's changing load (see duration_of). */
    public const LOAD_SE = 0.3;

    /** @var float The two-sided 95 % quantile of the normal distribution. */
    public const Z975 = 1.96;

    /** @var float What a sitting in flight is assumed to have left at least, as a share of its mean duration. */
    public const MIN_LEFT = 0.1;

    /**
     * The times of an experiment, and of each of its runs.
     *
     * @param int $experimentid The experiment.
     * @param array $progress live_progress::snapshot() without the times: state, runs (runid, done, total, failed,
     *      inflight, questions).
     * @param int|null $now The clock; null for the real one.
     * @return array
     */
    public static function estimate(int $experimentid, array $progress, ?int $now = null): array {
        $now = $now ?? time();
        $rows = $progress['runs'] ?? [];
        $out = self::blank($now);
        // Nothing planned yet is nothing to estimate — not "finished".
        $planned = array_sum(array_map(static fn(array $row): int => (int) $row['total'], $rows));
        if ($planned === 0) {
            return self::labelled($out);
        }
        $runids = array_map(static fn(array $row): int => (int) $row['runid'], $rows);
        // The forecast the progress rows were made from, where the caller has it: one moment for bar and times.
        $forecast = $progress['forecast'] ?? self::forecast($runids, $now, $progress['figures'] ?? null);
        $figures = $forecast['figures'];

        // Started: the first execution of any of these runs; requested: the first start request.
        $starts = array_filter(array_map(static fn(int $id): ?int => $figures['runs'][$id]['firststart'] ?? null, $runids));
        $out['started'] = $starts === [] ? null : min($starts);
        $requests = array_filter(array_map(static fn(int $id): ?int => $figures['runs'][$id]['requested'] ?? null, $runids));
        $out['requested'] = $requests === [] ? null : min($requests);
        $out['capacity'] = $forecast['capacity'];

        // Each run: its own work, and its completion where the forecast has one.
        $pending = 0;
        $lastend = 0;
        $sample = 0;
        $answered = 0;
        $pacesum = 0.0;
        foreach ($rows as $row) {
            $runid = (int) $row['runid'];
            $own = self::own_work($runid, $row, $forecast, $now);
            $f = $figures['runs'][$runid] ?? null;
            $sample += $f ? (int) $f['sample']['n'] : 0;
            $lastend = max($lastend, (int) ($f['lastend'] ?? 0));
            $pending += $own['sittings'];
            if ($own['pace'] !== null) {
                $answered += $own['pace']['questions'];
                $pacesum += $own['pace']['seconds'] * $own['pace']['questions'];
            }
            $out['runs'][$runid] = self::run_times($runid, $own, $forecast, $f, $now);
        }
        $out['sample'] = $sample;
        $out['pace'] = $answered >= self::MIN_QUESTIONS
            ? ['seconds' => round($pacesum / $answered, 3), 'questions' => $answered] : null;
        $out['own'] = self::sum_own(array_column($out['runs'], 'own'));

        $state = (string) ($progress['state'] ?? '');
        $end = $out['started'] !== null ? ($pending === 0 ? max($lastend, $out['started']) : $now) : null;
        $out['elapsed'] = $end !== null ? max(0, $end - $out['started']) : null;

        // Done: the recorded end, not a forecast.
        if ($pending === 0) {
            $out['reliability'] = self::FINAL;
            $out['finish'] = $lastend > 0 ? $lastend : null;
            $out['remaining'] = 0;

            return self::labelled($out);
        }

        $why = self::no_estimate_reason($state, $out, $out['runs']);
        if ($why !== null) {
            [$out['reliability'], $out['reason']] = $why;

            return self::labelled($out);
        }

        // The experiment is done when its last open run is.
        $last = null;
        $provisional = false;
        foreach ($out['runs'] as $run) {
            if ($run['reliability'] === self::FINAL) {
                continue;
            }
            $provisional = $provisional || $run['reliability'] === self::PROVISIONAL;
            if ($last === null || $run['finish'] > $last['finish']) {
                $last = $run;
            }
        }
        $out['reliability'] = $provisional ? self::PROVISIONAL : self::ESTIMATE;
        $out['remaining'] = (int) $last['remaining'];
        $out['finish'] = (int) $last['finish'];
        $out['margin'] = (float) $last['margin'];
        $out['range'] = [
            'low'  => (int) floor($out['remaining'] * (1 - $out['margin'])),
            'high' => (int) ceil($out['remaining'] * (1 + $out['margin'])),
        ];

        return self::labelled($out);
    }

    /**
     * The site's queue played through: when each claimable run will be done (#160 AK04).
     *
     * @param int[] $runids Runs a page shows, read together with every claimable one.
     * @param int $now The clock.
     * @param array|null $figures A reading from run_figures::read() for these runs at this clock, or null to read.
     * @return array{figures: array, capacity: array, durations: array<int, array>, completion: array<int, array>}
     */
    public static function forecast(array $runids, int $now, ?array $figures = null): array {
        $figures = $figures ?? run_figures::read($runids, $now);
        $durations = [];
        foreach (array_keys($figures['runs']) as $runid) {
            $durations[$runid] = self::duration_of($figures, $runid);
        }
        $questions = self::questions($figures);
        foreach ($durations as $runid => [$mean, $se, $basis, $sd]) {
            $q = $questions[$runid] ?? null;
            $persitting = (float) ($q['persitting'] ?? 0);
            if ($persitting <= 0 || !in_array($basis, ['none', 'experiment'], true)) {
                continue;
            }
            $bound = ($q['kind'] ?? '') === question_progress::BOUND;
            $pace = self::pace_of($figures['runs'][$runid], $q, $now);
            if ($pace !== null && !($basis === 'experiment' && $bound)) {
                // Nothing of its own or its strategy's finished yet: the pace of what is being played, times the
                // questions a sitting has, before other strategies' durations. A fastest run's twenty-question
                // sittings are not a classic run's hundred and twenty (#160 DoD05: borrowing those made the
                // forecast of a fastest run five times too long).
                $mean = $pace['seconds'] * $persitting;
                // The pace of k questions is surer the more there are, never below MIN_PACE_SE. An upper bound of
                // the questions says little about the actual length: half a sitting either way.
                $relative = $bound ? 0.5
                    : max(self::MIN_PACE_SE, 0.5 * sqrt(self::MIN_QUESTIONS / max(1, (int) $pace['questions'])));
                $durations[$runid] = [$mean, $mean * $relative, 'questions', $mean * $relative];
                continue;
            }
            if ($basis !== 'experiment') {
                continue;
            }
            // Borrowed from other strategies: their time per question, times this run's questions per sitting,
            // not their time per sitting. With only an upper bound of its questions, no longer than the bound
            // allows, at their pace or at its own.
            $scaled = [];
            $experimentpace = self::experiment_pace($figures, $questions, $runid);
            if ($experimentpace !== null) {
                $scaled[] = $experimentpace * $persitting;
            }
            if ($pace !== null) {
                $scaled[] = $pace['seconds'] * $persitting;
            }
            if ($scaled === [] || $mean <= 0) {
                continue;
            }
            $borrowed = $bound ? min($mean, min($scaled)) : min($scaled);
            $factor = $borrowed / $mean;
            $durations[$runid] = [$borrowed, max($se * $factor, ($bound ? 0.25 : self::BORROWED_SE) * $borrowed),
                'experiment', $sd * $factor];
        }
        $capacity = self::capacity($figures);

        $forecast = ['figures' => $figures, 'capacity' => $capacity, 'durations' => $durations,
            'questions' => $questions, 'completion' => []];
        $slots = (float) $capacity['slots'];
        if ($slots <= 0) {
            return $forecast;
        }

        // The work in flight first: it is on the slots already. Then the queued groups in claim order.
        $work = [];
        $inflightwork = 0.0;
        $inflightvar = 0.0;
        foreach ($figures['claimable'] as $runid) {
            [$mean, $se, , $sd] = $durations[$runid];
            $left = self::inflight_left($figures['runs'][$runid], $questions[$runid] ?? null, $mean, $now);
            $work[$runid] = ['inflight' => $left * $mean, 'left' => $left, 'mean' => $mean, 'se' => $se, 'sd' => $sd,
                'last' => null];
            $inflightwork += $left * $mean;
            $inflightvar += ($left * $se) ** 2;
        }
        $clock = (float) $now + $inflightwork / $slots;
        $done = $inflightwork;
        $variance = $inflightvar;
        foreach ($figures['groups'] as $group) {
            $runid = $group['runid'];
            if (!isset($work[$runid])) {
                continue;
            }
            // Not yet due: the workers wait for it (everything behind it is due later still).
            $clock = max($clock, (float) $group['due']);
            $units = (float) self::queued_units($group['n']);
            $clock += $units * $work[$runid]['mean'] / $slots;
            $done += $units * $work[$runid]['mean'];
            $variance += ($units * $work[$runid]['se']) ** 2;
            $work[$runid]['last'] = ['clock' => $clock, 'done' => $done, 'variance' => $variance];
        }

        // The run whose last sitting is the last work of the site: when it runs out, the site empties. Whether its
        // last sittings then speed up depends on whether the site is bound by its processors (they do: fewer
        // sittings share them) or by the slots (they do not). The forecast takes the middle and the range covers
        // both (#160 DoD05: on a two-processor site the last run's end came out up to half too late).
        $lastrun = $figures['groups'] !== [] ? end($figures['groups'])['runid'] : null;
        foreach ($work as $runid => $w) {
            $run = $figures['runs'][$runid];
            // Its sittings in flight are each on a slot of their own: they are done when the one begun last is.
            $ownflight = self::slowest_left($run, $w['left'], $w['mean'], $now) * $w['mean'];
            $alone = false;
            if ($w['last'] === null) {
                // Only sittings in flight: nothing of other runs is ahead of them.
                $alone = $lastrun === null;
                // Bound by the processors, everything in flight is shared out over the slots instead.
                $fluid = $inflightwork / $slots;
                $slotbound = $ownflight;
                $finish = $now + ($alone ? ($slotbound + min($fluid, $slotbound)) / 2 : $slotbound);
                $tailgap = $alone ? max(0.0, $slotbound - $fluid) / 2 : 0.0;
                $upto = $w['inflight'];
                $var = ($w['left'] * $w['se']) ** 2;
            } else {
                // The last sitting is claimed when the fluid queue reaches it and then takes a whole sitting:
                // a run of three long sittings on four workers takes one sitting's time, not three quarters of it.
                $alone = $lastrun === $runid;
                $tail = $w['mean'] * max(0.0, 1 - 1 / $slots);
                $finish = $w['last']['clock'] + ($alone ? $tail / 2 : $tail);
                $tailgap = $alone ? $tail / 2 : 0.0;
                $finish = max($finish, $now + ($alone ? $ownflight / 2 : $ownflight));
                $upto = $w['last']['done'];
                $var = $w['last']['variance'];
            }
            // The range: the work up to this run's end over the slots, plus the run's last sitting itself, which
            // takes as long as it takes on one slot (the spread of single sittings), plus, for the last run of the
            // site, half the gap between a processor-bound and a slot-bound end.
            $remaining = max(1.0, $finish - $now);
            $spread = sqrt($var / ($slots * $slots) + $w['sd'] * $w['sd'] + $tailgap * $tailgap);
            $forecast['completion'][$runid] = [
                'finish' => (int) ceil($finish),
                'remaining' => (int) max(0, ceil($finish - $now)),
                'margin' => min(1.0, max(self::MIN_MARGIN, self::Z975 * $spread / $remaining)),
                // Work of other runs claimed before this run's last sitting: what it waits behind.
                'ahead' => max(0.0, $upto - ($w['inflight'] + self::queued_units($run['queued'] + $run['stale']) * $w['mean'])),
                'alone' => $alone,
            ];
        }

        return $forecast;
    }

    /**
     * A run's own remaining work, from that run alone (#160 AK02, AK06).
     *
     * @param int $runid The run.
     * @param array $row Its progress row.
     * @param array $forecast From forecast().
     * @param int $now The clock.
     * @return array sittings, inflight, questions (remaining, null where unknown), questionkind, workseconds (null without
     *      a duration), basis, slots (the workers it has now), seconds (its own processing time at those, null without
     *      them), pace.
     */
    protected static function own_work(int $runid, array $row, array $forecast, int $now): array {
        $f = $forecast['figures']['runs'][$runid] ?? null;
        [$mean, $se, $basis] = $forecast['durations'][$runid] ?? [0.0, 0.0, 'none'];
        $q = $forecast['questions'][$runid] ?? ($row['questions'] ?? null);
        $left = max(0, (int) $row['total'] - (int) $row['done'] - (int) $row['failed']);
        $inflight = min($left, (int) ($row['inflight'] ?? 0));
        $pace = $f !== null ? self::pace_of($f, $q, $now) : null;
        $work = null;
        if ($mean > 0 && $f !== null) {
            $units = self::inflight_left($f, $q, $mean, $now)
                + self::queued_units(max(0, $left - $inflight - (int) $f['tracepending']));
            $work = $units * $mean;
        }
        $span = $f !== null && $f['busyfrom'] !== null ? max(1, min((int) $f['window'], $now - (int) $f['busyfrom'])) : 0;
        $slots = $span > 0 ? min((float) $f['busy'] / $span, (float) max(1, (int) $f['inflight'])) : 0.0;
        // A run nobody is playing has no own pace now; it is not "zero seconds".
        $working = $f !== null && (int) $f['inflight'] > 0;

        return [
            'sittings' => $left,
            'inflight' => $inflight,
            'questions' => $q['remaining'] ?? null,
            'questionkind' => $q['kind'] ?? question_progress::UNKNOWN,
            'workseconds' => $work !== null ? (int) round($work) : null,
            'workse' => $work !== null && $mean > 0 ? $work * $se / $mean : null,
            'mean' => $mean,
            'basis' => $basis,
            'slots' => round($slots, 2),
            'seconds' => $work !== null && $working && $slots > 0 ? (int) ceil($work / max(1.0, $slots)) : null,
            'pace' => $pace,
        ];
    }

    /**
     * One run's times: its own work, and when it will be done.
     *
     * @param int $runid The run.
     * @param array $own From own_work().
     * @param array $forecast From forecast().
     * @param array|null $f Its figures.
     * @param int $now The clock.
     * @return array
     */
    protected static function run_times(int $runid, array $own, array $forecast, ?array $f, int $now): array {
        $started = $f['firststart'] ?? null;
        $out = ['runid' => $runid, 'sample' => (int) ($f['sample']['n'] ?? 0), 'started' => $started, 'elapsed' => null,
            'remaining' => null, 'finish' => null,
            'margin' => null, 'ahead' => null, 'reliability' => self::CALCULATING, 'reason' => '',
            'meanseconds' => $own['mean'] > 0 ? round($own['mean'], 1) : null, 'basis' => $own['basis'], 'own' => $own];
        if ($own['sittings'] === 0) {
            $out['reliability'] = self::FINAL;
            $out['remaining'] = 0;
            $out['finish'] = $f['lastend'] ?? null;
            $out['elapsed'] = $started !== null ? max(0, (int) ($f['lastend'] ?? $now) - $started) : null;

            return $out;
        }
        $out['elapsed'] = $started !== null ? max(0, $now - $started) : null;
        if ($f !== null && $f['held']) {
            [$out['reliability'], $out['reason']] = [self::UNRELIABLE, 'eta:whyblocked'];
        } else if ($f !== null && $f['paused']) {
            [$out['reliability'], $out['reason']] = [self::UNRELIABLE, 'eta:whypaused'];
        } else if ($f === null || !in_array($runid, $forecast['figures']['claimable'], true)) {
            [$out['reliability'], $out['reason']] = [self::CALCULATING, 'eta:whyrunnotready'];
        } else if ($own['mean'] <= 0) {
            [$out['reliability'], $out['reason']] = [self::CALCULATING, 'eta:whysample'];
        } else if (!isset($forecast['completion'][$runid])) {
            [$out['reliability'], $out['reason']] = $forecast['capacity']['physical'] > 0
                ? [self::CALCULATING, 'eta:whynothroughput'] : [self::UNRELIABLE, 'eta:whynocapacity'];
        } else {
            $c = $forecast['completion'][$runid];
            $out['remaining'] = $c['remaining'];
            $out['finish'] = $c['finish'];
            $out['margin'] = $c['margin'];
            $out['ahead'] = (int) round($c['ahead']);
            $out['reliability'] = in_array($own['basis'], ['run', 'strategy'], true) && !self::borrows($forecast, $runid)
                ? self::ESTIMATE : self::PROVISIONAL;
        }

        return $out;
    }

    /**
     * Whether any work ahead of a run rests on a borrowed or missing duration.
     *
     * @param array $forecast From forecast().
     * @param int $runid The run.
     * @return bool
     */
    protected static function borrows(array $forecast, int $runid): bool {
        $groups = $forecast['figures']['groups'];
        if (!in_array($runid, array_column($groups, 'runid'), true)) {
            // Nothing of it queued: only its own sittings in flight decide, on its own duration.
            return false;
        }
        foreach ($groups as $group) {
            $basis = $forecast['durations'][$group['runid']][2] ?? 'none';
            if (!in_array($basis, ['run', 'strategy'], true)) {
                return true;
            }
            if ($group['runid'] === $runid) {
                break;
            }
        }

        return false;
    }

    /**
     * Why there is no running estimate for the experiment, if there is none.
     *
     * @param string $state The live_progress state.
     * @param array $out The estimate so far.
     * @param array $runs The runs' times.
     * @return array|null [reliability, reason string key] or null.
     */
    protected static function no_estimate_reason(string $state, array $out, array $runs): ?array {
        if ($state === live_progress::BLOCKED) {
            return [self::UNRELIABLE, 'eta:whyblocked'];
        }
        if ($state === live_progress::PAUSED) {
            return [self::UNRELIABLE, 'eta:whypaused'];
        }
        if ($out['started'] === null) {
            return [self::CALCULATING, 'eta:whynotstarted'];
        }
        // The first open run without a forecast says why the experiment has none.
        foreach ([self::UNRELIABLE, self::CALCULATING] as $reliability) {
            foreach ($runs as $run) {
                if ($run['reliability'] === $reliability) {
                    return [$reliability, $run['reason']];
                }
            }
        }

        return null;
    }

    /**
     * Mean duration of a run's sittings, its standard error, and on what it rests.
     *
     * Its own (three finished executions or more), else its strategy's within its experiment, else its experiment's.
     * Pools are per experiment, so the same run gets the same duration whichever page asks (#160 AK09).
     *
     * @param array $figures From run_figures::read().
     * @param int $runid The run.
     * @return array{0: float, 1: float, 2: string, 3: float} Mean seconds, its uncertainty (standard error; for a
     *      duration borrowed from other strategies the spread of single sittings), basis run|strategy|experiment|none,
     *      and the standard deviation of a single sitting.
     */
    protected static function duration_of(array $figures, int $runid): array {
        $run = $figures['runs'][$runid];
        $empty = ['n' => 0, 's' => 0.0, 'ss' => 0.0];
        $pools = ['run' => [$run['sample'], $run['recent'] ?? $empty], 'strategy' => [$empty, $empty],
            'experiment' => [$empty, $empty]];
        foreach ($figures['runs'] as $other) {
            if ($other['experimentid'] !== $run['experimentid']) {
                continue;
            }
            $kinds = $other['strategy'] === $run['strategy'] ? ['strategy', 'experiment'] : ['experiment'];
            foreach ($kinds as $kind) {
                $pools[$kind] = [self::pooled($pools[$kind][0], $other['sample']),
                    self::pooled($pools[$kind][1], $other['recent'] ?? $empty)];
            }
        }
        foreach ($pools as $basis => [$sample, $recent]) {
            if ($sample['n'] >= self::MIN_SAMPLE) {
                $n = $sample['n'];
                $mean = $sample['s'] / $n;
                $sd = sqrt(max(0.0, ($sample['ss'] - $n * $mean * $mean) / max(1, $n - 1)));
                // Three or five sittings say little about their spread: Student's t for n - 1 degrees of freedom
                // instead of the normal 1.96, folded into the spread so the range stays "± 1.96 of these" (#160 DoD05).
                $sd *= self::t975($n - 1) / self::Z975;
                // Borrowed from other strategies, the mean is not this run's mean estimated with error: it may lie
                // anywhere in the spread of single sittings, and the range has to say so (DoD05 of #160).
                $se = $basis === 'experiment' ? max($sd, 0.25 * $mean) : $sd / sqrt($n);
                // Sittings take as long as the site lets them: a run's first ones, played beside another run's, took
                // half as long again as its later ones (docs/dev/eta-methode.md). Beyond the spread seen so far,
                // LOAD_SE of a sitting, less the more sittings there are.
                $se = max($se, self::LOAD_SE * $mean / sqrt($n));
                if ($recent['n'] >= run_figures::RECENT && $recent['n'] < $n) {
                    // Durations that drift make the whole sample a poor guide to the next sittings: the latest ones
                    // are, and the gap between them and all of them stays in the range.
                    $latest = $recent['s'] / $recent['n'];
                    $se = sqrt($se * $se + ($latest - $mean) ** 2);
                    $mean = $latest;
                }

                return [$mean, $se, $basis, $sd];
            }
        }

        return [0.0, 0.0, 'none', 0.0];
    }

    /**
     * Seconds per question in the finished sittings of a run's experiment, all its runs together.
     *
     * @param array $figures From run_figures::read().
     * @param array $questions From questions().
     * @param int $runid The run.
     * @return float|null Null without any.
     */
    protected static function experiment_pace(array $figures, array $questions, int $runid): ?float {
        $experimentid = $figures['runs'][$runid]['experimentid'];
        $seconds = 0.0;
        $answered = 0;
        foreach ($figures['runs'] as $id => $run) {
            $q = $questions[$id] ?? null;
            if ($run['experimentid'] !== $experimentid || $q === null || $q['secondsperquestion'] === null) {
                continue;
            }
            $seconds += (float) $q['secondsperquestion'] * (int) $q['answered'];
            $answered += (int) $q['answered'];
        }

        return $answered > 0 ? $seconds / $answered : null;
    }

    /**
     * Two duration samples together.
     *
     * @param array $a One.
     * @param array $b The other.
     * @return array{n: int, s: float, ss: float}
     */
    protected static function pooled(array $a, array $b): array {
        return ['n' => $a['n'] + $b['n'], 's' => $a['s'] + $b['s'], 'ss' => $a['ss'] + $b['ss']];
    }

    /**
     * The two-sided 95 % quantile of Student's t distribution.
     *
     * Exact to three decimals up to ten degrees of freedom (the usual table), 1.96 + 2.4 / df beyond (within 0.03
     * of the true value from 11 on, and tending to 1.96).
     *
     * @param int $df Degrees of freedom.
     * @return float
     */
    public static function t975(int $df): float {
        $table = [1 => 12.706, 2 => 4.303, 3 => 3.182, 4 => 2.776, 5 => 2.571, 6 => 2.447, 7 => 2.365, 8 => 2.306,
            9 => 2.262, 10 => 2.228];

        return $df < 1 ? $table[1] : ($table[$df] ?? self::Z975 + 2.4 / $df);
    }

    /**
     * Question figures of every run read, in one go.
     *
     * @param array $figures From run_figures::read().
     * @return array<int, array> From question_progress::for_runs().
     */
    protected static function questions(array $figures): array {
        $sittings = [];
        foreach ($figures['runs'] as $runid => $run) {
            $left = max(0, $run['total'] - $run['collected'] - $run['failed']);
            $sittings[$runid] = ['inflight' => min($left, $run['inflight']),
                'queued' => max(0, $left - $run['inflight'] - $run['tracepending'])];
        }

        return question_progress::for_runs($sittings);
    }

    /**
     * What is left of the sittings a run has in flight, in sittings.
     *
     * In questions where they are known: a sitting 600 of 625 questions in is 1/25 of a sitting from its end. Where
     * they are not, from the time the sittings have been running against the run's mean duration.
     *
     * @param array $run Its figures.
     * @param array|null $q Its question figures.
     * @param float $mean Its mean duration.
     * @param int $now The clock.
     * @return float
     */
    protected static function inflight_left(array $run, ?array $q, float $mean, int $now): float {
        $n = (int) $run['inflight'];
        if ($n === 0) {
            return 0.0;
        }
        $persitting = (float) ($q['persitting'] ?? 0);
        if ($persitting > 0) {
            $left = max(0.0, $n * $persitting - (int) ($q['inflight'] ?? 0)) / $persitting;

            // A sitting still being played has at least its next question ahead, even past its expected length.
            return max($left, $n / $persitting);
        }
        $flying = max(1, (int) $run['flying']);
        $elapsed = (float) ($flying * $now - (int) $run['flyingstarts']) / $flying;
        $share = $mean > 0 ? max(self::MIN_LEFT, 1 - $elapsed / $mean) : 0.5;

        return $n * $share;
    }

    /**
     * What the sitting begun last of a run's sittings in flight has left, in sittings: at least their average, and at
     * least its mean duration less the time since it began. Four sittings begun a minute apart are not done when the
     * average of them is (#160 DoD05: the forecast came out too early by half near the end of a run).
     *
     * @param array $run Its figures.
     * @param float $left What all of them have left, in sittings (inflight_left()).
     * @param float $mean Its mean duration.
     * @param int $now The clock.
     * @return float
     */
    protected static function slowest_left(array $run, float $left, float $mean, int $now): float {
        $n = (int) $run['inflight'];
        if ($n === 0) {
            return 0.0;
        }
        $share = $left / $n;
        if (($run['flyinglast'] ?? null) !== null && $mean > 0) {
            // The floor of what is left stays inflight_left()'s: questions where they are known.
            $share = max($share, 1 - ($now - (int) $run['flyinglast']) / $mean);
        }

        return $share;
    }

    /**
     * Queued sittings in sitting units: each a whole sitting, it has answered nothing yet.
     *
     * @param int $n Queued sittings.
     * @return float
     */
    protected static function queued_units(int $n): float {
        return (float) max(0, $n);
    }

    /**
     * The pace of a run's sittings in flight, in seconds per question, from the run alone (#142, #160).
     *
     * @param array $run Its figures.
     * @param array|null $q Its question figures.
     * @param int $now The clock.
     * @return array{seconds: float, questions: int}|null Null below MIN_QUESTIONS.
     */
    protected static function pace_of(array $run, ?array $q, int $now): ?array {
        $answered = (int) ($q['inflight'] ?? 0);
        $n = (int) $run['flying'];
        if ($answered < self::MIN_QUESTIONS || $n === 0) {
            return null;
        }
        $elapsed = $n * $now - (int) $run['flyingstarts'];
        if ($elapsed <= 0) {
            return null;
        }

        return ['seconds' => round($elapsed / $answered, 3), 'questions' => $answered];
    }

    /**
     * The capacity the site has: busy time inside the window, at most the workers there are (#160 AK07).
     *
     * @param array $figures From run_figures::read().
     * @return array{slots: float, measured: float, physical: int, window: int, inflight: int, basis: string}
     */
    protected static function capacity(array $figures): array {
        $busy = 0.0;
        $window = 0;
        $inflight = 0;
        $from = null;
        foreach ($figures['runs'] as $run) {
            $busy += (float) $run['busy'];
            $window = max($window, (int) $run['window']);
            $inflight += (int) $run['inflight'];
            if ($run['busyfrom'] !== null) {
                $from = min($from ?? PHP_INT_MAX, (int) $run['busyfrom']);
            }
        }
        // Slots that exist: workers up, or at least the sittings being played right now.
        // The workers up (live includes the starting ones: up, and taking work once their staggered start has
        // passed). Only where none reports, the sittings held under a lease: a lease nobody renews is not a slot.
        $live = (int) $figures['workers']['live'];
        $physical = $live > 0 ? $live : $inflight;
        // Over the part of the window there was work in: five minutes into a start, five minutes, not ten.
        $span = $from !== null ? max(1, min($window, (int) $figures['now'] - $from)) : $window;
        $measured = $span > 0 ? $busy / $span : 0.0;
        // Never fewer slots than sittings being played right now (the window may hold idle time from before the
        // workers came), never more than there are workers.
        $slots = min((float) $physical, max($measured, (float) $inflight));
        $basis = $measured >= $inflight ? 'window' : 'inflight';

        return ['slots' => round($slots, 3), 'measured' => round($measured, 3), 'physical' => $physical,
            'window' => $span, 'inflight' => $inflight, 'basis' => $basis];
    }

    /**
     * The own work of several runs together.
     *
     * @param array[] $owns From own_work().
     * @return array sittings, questions (null where any is unknown), questionkind (the least certain), workseconds
     *      (null where any is unknown).
     */
    protected static function sum_own(array $owns): array {
        $rank = [question_progress::EXACT => 0, question_progress::ESTIMATE => 1, question_progress::BOUND => 2,
            question_progress::UNKNOWN => 3];
        $out = ['sittings' => 0, 'questions' => 0, 'questionkind' => question_progress::EXACT, 'workseconds' => 0];
        foreach ($owns as $own) {
            $out['sittings'] += $own['sittings'];
            if ($own['sittings'] === 0) {
                continue;
            }
            $out['questions'] = $out['questions'] === null || $own['questions'] === null ? null
                : $out['questions'] + (int) $own['questions'];
            $out['workseconds'] = $out['workseconds'] === null || $own['workseconds'] === null ? null
                : $out['workseconds'] + (int) $own['workseconds'];
            if ($rank[$own['questionkind']] > $rank[$out['questionkind']]) {
                $out['questionkind'] = $own['questionkind'];
            }
        }

        return $out;
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
            'pace'        => null,
            'capacity'    => ['slots' => 0.0, 'measured' => 0.0, 'physical' => 0, 'window' => 0, 'inflight' => 0,
                'basis' => 'window'],
            'own'         => ['sittings' => 0, 'questions' => 0, 'questionkind' => question_progress::EXACT,
                'workseconds' => 0],
            'computed'    => $now,
            'runs'        => [],
        ];
    }

    /**
     * A run's own work in words: questions, sittings, worker time (#160 AK01, AK10).
     *
     * @param array $own From own_work() or sum_own().
     * @return string
     */
    public static function own_text(array $own): string {
        $component = 'local_catquizlab';
        if ((int) $own['sittings'] === 0) {
            return get_string('eta:nothingleft', $component);
        }
        $parts = [];
        $parts[] = $own['questions'] === null
            ? get_string('eta:ownquestionsunknown', $component)
            : get_string('eta:ownquestions' . $own['questionkind'], $component, (int) $own['questions']);
        $parts[] = get_string('eta:ownsittings', $component, (int) $own['sittings']);
        if ($own['workseconds'] !== null) {
            $parts[] = get_string('eta:ownworktime', $component, self::span((int) $own['workseconds']));
        }

        return implode(' · ', $parts);
    }

    /**
     * Add what a page shows: the values as text, the status word and the method.
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
            'own'       => $out['reliability'] === self::NONE ? $na : self::own_text($out['own']),
            'status'    => get_string('eta:' . $out['reliability'], $component),
            'reason'    => $out['reason'] !== '' ? get_string($out['reason'], $component) : '',
            'method'    => get_string('eta:method', $component, (object) [
                'sample' => $out['sample'],
                'slots' => format_float($out['capacity']['slots'], 1),
                'physical' => $out['capacity']['physical'],
                'window' => self::span($out['capacity']['window']),
                'margin' => $out['margin'] !== null ? (int) round($out['margin'] * 100) : '–',
            ]),
            'computed'  => userdate($out['computed'], get_string('strftimetime', 'langconfig')),
        ];
        if (($out['pace'] ?? null) !== null && $out['reliability'] !== self::FINAL) {
            $out['text']['method'] .= ' ' . get_string('eta:methodpace', $component, (object) [
                'seconds' => format_float($out['pace']['seconds'], 1),
                'questions' => $out['pace']['questions'],
            ]);
        }
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
            $own = $run['own'];
            $out['runs'][$runid]['text'] = [
                'started' => $run['started'] !== null ? self::when($run['started']) : $na,
                'elapsed' => $run['elapsed'] !== null ? self::span($run['elapsed']) : $na,
                'own' => self::own_text($own),
                'owntime' => $run['reliability'] === self::FINAL ? get_string('eta:nothingleft', $component)
                    : ($own['seconds'] !== null
                        ? get_string('eta:ownat', $component, (object) ['time' => self::span($own['seconds']),
                            'slots' => format_float($own['slots'], 1)])
                        : get_string('eta:ownnoworker', $component)),
                'remaining' => $run['remaining'] !== null
                    ? ($run['reliability'] === self::FINAL ? get_string('eta:nothingleft', $component)
                        : get_string('eta:range', $component, (object) [
                            'remaining' => self::span((int) $run['remaining']),
                            'low' => self::span((int) floor($run['remaining'] * (1 - (float) $run['margin']))),
                            'high' => self::span((int) ceil($run['remaining'] * (1 + (float) $run['margin']))),
                        ]))
                    : get_string('eta:' . $run['reliability'], $component),
                // On what its duration rests, and how sure the figure is (#160 AK10).
                'basis' => $run['reliability'] === self::FINAL || $own['basis'] === 'none' ? ''
                    : get_string('eta:basis' . $own['basis'], $component, (object) [
                        'n' => (int) ($run['sample'] ?? 0), 'status' => get_string('eta:' . $run['reliability'], $component)]),
                'finish' => $run['finish'] !== null ? self::when($run['finish']) : $na,
                'ahead' => $run['ahead'] !== null && $run['ahead'] > 0
                    ? get_string('eta:ahead', $component, self::span((int) $run['ahead'])) : '',
                'reason' => $run['reason'] !== '' ? get_string($run['reason'], $component) : '',
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
