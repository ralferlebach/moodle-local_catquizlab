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
 * How far an experiment has got, and what it is doing right now.
 *
 * One source for all of it. The shell, the progress step, the run list and the
 * live poll each used to work the numbers out themselves, which is why a header
 * could say an experiment was running while the table below it showed nothing
 * in flight — both were right about the question they had asked, and they had
 * asked different questions seconds apart.
 *
 * The operational state is deliberately narrower than "running": a worker that
 * has not reported yet is STARTING, an experiment with nothing in flight is
 * WAITING with a reason, and a held run is BLOCKED. Saying RUNNING when nothing
 * is being played is the claim that made every other number suspect.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class live_progress {
    /** @var string Nothing built yet. */
    public const DRAFT = 'draft';

    /** @var string Workers asked for, none reporting yet. */
    public const STARTING = 'starting';

    /** @var string Sittings are being played right now. */
    public const RUNNING = 'running';

    /** @var string Ready, with nothing in flight. */
    public const WAITING = 'waiting';

    /** @var string Held by a person. */
    public const PAUSED = 'paused';

    /** @var string Held because something needs fixing. */
    public const BLOCKED = 'blocked';

    /** @var string Sittings done, results being computed. */
    public const AGGREGATING = 'aggregating';

    /** @var string Everything done. */
    public const FINISHED = 'finished';

    /**
     * Everything a page needs to show progress, in one call.
     *
     * @param int $experimentid The experiment.
     * @return array
     */
    public static function snapshot(int $experimentid): array {
        global $DB;

        $component = 'local_catquizlab';

        $runs = $experimentid > 0
            ? $DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC')
            : [];

        if ($runs === []) {
            return self::empty_snapshot($experimentid);
        }

        $rows = [];
        $totals = ['done' => 0, 'total' => 0, 'running' => 0, 'queued' => 0, 'failed' => 0];

        foreach ($runs as $run) {
            $counts = run_lifecycle::attempt_counts((int) $run->id);
            $inflight = self::attempts_in_flight((int) $run->id);

            $done = (int) ($counts['collected'] ?? 0);
            $total = (int) ($counts['total'] ?? 0);
            $failed = (int) ($counts['failed'] ?? 0);

            $totals['done'] += $done;
            $totals['total'] += $total;
            $totals['failed'] += $failed;
            $totals['running'] += $inflight;
            $totals['queued'] += max(0, (int) ($counts['open'] ?? 0) - $inflight);

            $rows[] = [
                'runid'    => (int) $run->id,
                'cellkey'  => (string) $run->cellkey,
                'done'     => $done,
                'total'    => $total,
                'percent'  => self::percent($done, $total),
                'inflight' => $inflight,
                'failed'   => $failed,
                'complete' => $total > 0 && $done >= $total,
                'held'     => (int) $run->status === registry::STATUS_FAILED,
            ];
        }

        $workers = worker_registry::summary();
        $state = self::derive($runs, $totals, $workers, $experimentid);

        return [
            'experimentid' => $experimentid,
            'state'        => $state['state'],
            'label'        => get_string('progress:state' . $state['state'], $component),
            'reason'       => $state['reason'],
            'done'         => $totals['done'],
            'total'        => $totals['total'],
            'percent'      => self::percent($totals['done'], $totals['total']),
            'runs'         => $rows,
            // What is happening this second, which is the part somebody watches
            // rather than reads.
            'now'          => [
                'workers'   => (int) $workers['live'],
                'starting'  => (int) $workers['starting'],
                'inflight'  => $totals['running'],
                'queued'    => $totals['queued'],
                'collected' => $totals['done'],
                'failed'    => $totals['failed'],
            ],
        ];
    }

    /**
     * Which of the operational states this experiment is in, and why.
     *
     * @param array $runs The runs.
     * @param array $totals Attempt totals across them.
     * @param array $workers The worker summary.
     * @param int $experimentid The experiment.
     * @return array{state: string, reason: string}
     */
    protected static function derive(array $runs, array $totals, array $workers, int $experimentid): array {
        $component = 'local_catquizlab';

        $statuses = array_map(static function (\stdClass $run): int {
            return (int) $run->status;
        }, $runs);

        if (in_array(registry::STATUS_FAILED, $statuses, true)) {
            $held = 0;
            foreach ($runs as $run) {
                if ((int) $run->status === registry::STATUS_FAILED) {
                    $held = (int) $run->id;
                    break;
                }
            }

            return [
                'state'  => self::BLOCKED,
                'reason' => get_string('progress:reasonblocked', $component, $held),
            ];
        }

        if ($totals['total'] > 0 && $totals['done'] >= $totals['total']) {
            $aggregating = in_array(registry::STATUS_AGGREGATING, $statuses, true);

            return [
                'state'  => $aggregating ? self::AGGREGATING : self::FINISHED,
                'reason' => '',
            ];
        }

        // Actual work, not the existence of a worker somewhere. "Simulation
        // running" beside "0 in flight" is the contradiction this rule exists
        // to prevent.
        if ($totals['running'] > 0) {
            return ['state' => self::RUNNING, 'reason' => ''];
        }

        if ((int) $workers['starting'] > 0) {
            return ['state' => self::STARTING, 'reason' => get_string('progress:reasonstarting', $component)];
        }

        // Paused by a person: per run, which is where the pause lives.
        foreach ($runs as $run) {
            if (run_lifecycle::is_paused((int) $run->id)) {
                return [
                    'state'  => self::PAUSED,
                    'reason' => get_string('progress:reasonpaused', $component),
                ];
            }
        }

        if ($totals['queued'] > 0) {
            // Ready, queued, and nobody playing them. Naming why is the
            // difference between a stall somebody can act on and a page that
            // simply sits there.
            $reason = (int) $workers['live'] === 0
                ? get_string('progress:reasonnoworker', $component)
                : get_string('progress:reasonbusy', $component);

            return ['state' => self::WAITING, 'reason' => $reason];
        }

        return ['state' => self::WAITING, 'reason' => get_string('progress:reasonidle', $component)];
    }

    /**
     * How many of a run's attempts a worker is holding right now.
     *
     * @param int $runid The run.
     * @return int
     */
    protected static function attempts_in_flight(int $runid): int {
        global $DB;

        return (int) $DB->count_records_select(
            'local_catquizlab_attempt',
            'runid = :runid AND status = :running AND leaseexpires > :now',
            ['runid' => $runid, 'running' => attempt_scheduler::STATUS_RUNNING, 'now' => time()]
        );
    }

    /**
     * A whole-number percentage that never reads as finished before it is.
     *
     * @param int $done How many.
     * @param int $total Out of how many.
     * @return int
     */
    protected static function percent(int $done, int $total): int {
        if ($total <= 0) {
            return 0;
        }

        $percent = (int) floor(($done / $total) * 100);

        // 99 until it really is 100: rounding 249 of 250 up to 100% tells
        // somebody the run is over while a sitting is still playing.
        return $done < $total ? min(99, $percent) : 100;
    }

    /**
     * The shape of a snapshot with nothing in it yet.
     *
     * @param int $experimentid The experiment.
     * @return array
     */
    protected static function empty_snapshot(int $experimentid): array {
        return [
            'experimentid' => $experimentid,
            'state'        => self::DRAFT,
            'label'        => get_string('progress:statedraft', 'local_catquizlab'),
            'reason'       => '',
            'done'         => 0,
            'total'        => 0,
            'percent'      => 0,
            'runs'         => [],
            'now'          => [
                'workers'   => 0,
                'starting'  => 0,
                'inflight'  => 0,
                'queued'    => 0,
                'collected' => 0,
                'failed'    => 0,
            ],
        ];
    }
}
