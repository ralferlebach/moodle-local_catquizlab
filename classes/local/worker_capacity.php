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
 * How many workers may play at once, how many do, and the way down when that is too many (#124).
 *
 * Three limits, the smallest wins:
 *
 * - **configured**: `worker_concurrency`, what somebody asked for;
 * - **safety cap**: `worker_safety_cap`, optional, set from a measurement of
 *   what the web server carries (0 = none) — a configured 250 on a server whose
 *   Apache reaches MaxRequestWorkers at 60 is not a plan but an outage;
 * - **load guard**: halves the limit for a cooldown when the share of
 *   capacity-type failures (HTTP 5xx, connection timeouts and failures) among
 *   the executions of the last five minutes rises above a threshold.
 *
 * A limit going down is enforced by **draining**: workers in slots above it
 * are asked to finish the sitting they are playing and stop, and get no new
 * claim — never killed, so no claim is stranded. The launcher starts nothing
 * above the limit. A worker's slot is its place in that order.
 *
 * Every change of the limit, every drain and every throttle is written to the
 * lifecycle log with runid 0 (the installation), where the logs page shows it.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class worker_capacity {
    /** @var int The load guard's window, in seconds. */
    public const GUARD_WINDOW = 300;

    /** @var int Capacity failures needed in the window before the guard acts. */
    public const GUARD_MIN_FAILURES = 5;

    /** @var float Share of capacity failures among the window's executions that throttles. */
    public const GUARD_SHARE = 0.2;

    /** @var int How long a throttle holds after its last trigger, in seconds. */
    public const GUARD_COOLDOWN = 600;

    /** @var int How often the guard is evaluated at most, in seconds. */
    public const GUARD_EVERY = 30;

    /**
     * The configured concurrency.
     *
     * @return int At least 1.
     */
    public static function configured(): int {
        return max(1, (int) get_config('local_catquizlab', 'worker_concurrency'));
    }

    /**
     * The safety cap, if one is set.
     *
     * @return int 0 for none.
     */
    public static function safety_cap(): int {
        return max(0, (int) get_config('local_catquizlab', 'worker_safety_cap'));
    }

    /**
     * The limit in force now, and which of the three set it.
     *
     * @param int|null $now The clock.
     * @return array{limit: int, configured: int, cap: int, guard: array, by: string}
     */
    public static function effective(?int $now = null): array {
        $configured = self::configured();
        $cap = self::safety_cap();
        $limit = $cap > 0 ? min($configured, $cap) : $configured;
        $by = $cap > 0 && $cap < $configured ? 'cap' : 'configured';

        $guard = self::load_guard($now, $limit);
        if ($guard['throttled'] && $guard['limit'] < $limit) {
            $limit = $guard['limit'];
            $by = 'loadguard';
        }

        return ['limit' => max(1, $limit), 'configured' => $configured, 'cap' => $cap, 'guard' => $guard, 'by' => $by];
    }

    /**
     * The load guard: whether capacity-type failures are piling up, and the reduced limit if so.
     *
     * Evaluated at most every GUARD_EVERY seconds; the verdict is kept in the
     * plugin config, so every claim does not count the history again.
     *
     * @param int|null $now The clock.
     * @param int $limit The limit before the guard.
     * @return array{throttled: bool, limit: int, failures: int, executions: int, share: float, until: int, causes: array}
     */
    public static function load_guard(?int $now = null, int $limit = 0): array {
        $now = $now ?? time();
        $limit = $limit > 0 ? $limit : self::configured();
        $kept = json_decode((string) get_config('local_catquizlab', 'loadguard'), true) ?: [];

        if (($kept['checked'] ?? 0) <= $now - self::GUARD_EVERY || ($kept['checked'] ?? 0) > $now) {
            $kept = self::evaluate($now, $kept);
            set_config('loadguard', json_encode($kept), 'local_catquizlab');
        }
        $throttled = ($kept['until'] ?? 0) > $now;

        return [
            'throttled'  => $throttled,
            'limit'      => $throttled ? max(1, intdiv($limit, 2)) : $limit,
            'failures'   => (int) ($kept['failures'] ?? 0),
            'executions' => (int) ($kept['executions'] ?? 0),
            'share'      => (float) ($kept['share'] ?? 0.0),
            'until'      => (int) ($kept['until'] ?? 0),
            'causes'     => (array) ($kept['causes'] ?? []),
        ];
    }

    /**
     * Count the window and decide.
     *
     * @param int $now The clock.
     * @param array $kept The previous verdict.
     * @return array The new verdict.
     */
    protected static function evaluate(int $now, array $kept): array {
        global $DB;

        $since = $now - self::GUARD_WINDOW;
        [$insql, $params] = $DB->get_in_or_equal(circuit_breaker::CAPACITY_CAUSES, SQL_PARAMS_NAMED, 'c');
        // Failed executions and reconciled submissions alike: a 5xx the worker
        // recovered from is still a 5xx the server sent.
        $causes = $DB->get_records_sql_menu(
            "SELECT reasoncode, COUNT(1)
               FROM {local_catquizlab_attemptlog}
              WHERE timecreated > :since AND outcome IN (:failed, :recovered) AND reasoncode {$insql}
           GROUP BY reasoncode",
            $params + ['since' => $since, 'failed' => attempt_history::FAILED, 'recovered' => attempt_history::RECOVERED]
        );
        $failures = (int) array_sum($causes);
        $executions = (int) $DB->count_records_select(
            'local_catquizlab_attemptlog',
            'timecreated > :since AND outcome IN (:collected, :failed)',
            ['since' => $since, 'collected' => attempt_history::COLLECTED, 'failed' => attempt_history::FAILED]
        );
        $share = $failures / max(1, $executions + $failures);
        $trips = $failures >= self::GUARD_MIN_FAILURES && $share >= self::GUARD_SHARE;
        $until = (int) ($kept['until'] ?? 0);
        $was = $until > $now;

        if ($trips) {
            $until = $now + self::GUARD_COOLDOWN;
            if (!$was) {
                run_log::record(0, run_log::LOAD_THROTTLED, ['failures' => $failures, 'executions' => $executions,
                    'share' => round($share, 2), 'causes' => $causes, 'cooldown' => self::GUARD_COOLDOWN]);
            }
        } else if (!$was && $until > 0) {
            // Released by time: said once, then forgotten.
            run_log::record(0, run_log::LOAD_RELEASED, ['failures' => $failures, 'executions' => $executions]);
            $until = 0;
        }

        return ['checked' => $now, 'until' => $until, 'failures' => $failures, 'executions' => $executions,
            'share' => round($share, 3), 'causes' => $causes];
    }

    /**
     * Ask the workers above the limit to finish their sitting and stop.
     *
     * Idempotent: a worker already asked is not asked again, and nothing is
     * logged when nothing changed.
     *
     * @param int|null $now The clock.
     * @return int How many were newly asked.
     */
    public static function drain(?int $now = null): int {
        global $DB;

        $effective = self::effective($now);
        $asked = [];
        foreach (worker_registry::live() as $worker) {
            if ((int) $worker->slot > $effective['limit'] && (int) $worker->stoprequested === 0) {
                $DB->set_field('local_catquizlab_worker', 'stoprequested', $now ?? time(), ['id' => $worker->id]);
                $asked[] = (string) $worker->workerid;
            }
        }
        if ($asked !== []) {
            run_log::record(0, run_log::WORKERS_DRAINING, [
                'limit' => $effective['limit'], 'by' => $effective['by'], 'workers' => count($asked),
                'first' => array_slice($asked, 0, 5),
            ]);
        }

        return count($asked);
    }

    /**
     * Whether a worker may claim a sitting now: not asked to stop, and in a slot within the limit.
     *
     * @param string $workerid The worker.
     * @return bool
     */
    public static function may_claim(string $workerid): bool {
        global $DB;

        $worker = $DB->get_record('local_catquizlab_worker', ['workerid' => $workerid], 'id, slot, stoprequested');
        if (!$worker) {
            // Unknown here: job_claim has always served these, and the heartbeat adopts them.
            return true;
        }
        if ((int) $worker->stoprequested > 0) {
            return false;
        }

        return (int) $worker->slot <= self::effective()['limit'];
    }

    /**
     * Note a change of the configured limit or the cap, and drain what is now above it.
     *
     * Called where settings are saved and by every launcher tick, so a value
     * set from the command line is noted too. Logged once per change.
     *
     * @return int Workers newly asked to stop.
     */
    public static function note_change(): int {
        $seen = (string) get_config('local_catquizlab', 'capacity_seen');
        $now = self::configured() . '|' . self::safety_cap();
        if ($seen !== $now) {
            [$from, $fromcap] = array_map('intval', explode('|', $seen !== '' ? $seen : $now) + [1 => 0]);
            set_config('capacity_seen', $now, 'local_catquizlab');
            if ($seen !== '') {
                run_log::record(0, run_log::CAPACITY_CHANGED, ['from' => $from, 'to' => self::configured(),
                    'capfrom' => $fromcap, 'cap' => self::safety_cap()]);
            }
        }

        return self::drain();
    }

    /**
     * Configured, effective, live, starting, waiting, draining, above the limit, free — for one installation.
     *
     * @param int|null $now The clock.
     * @return array
     */
    public static function snapshot(?int $now = null): array {
        $now = $now ?? time();
        $effective = self::effective($now);
        $counts = ['live' => 0, 'starting' => 0, 'waiting' => 0, 'draining' => 0, 'playing' => 0, 'above' => 0];
        foreach (worker_registry::live() as $worker) {
            $counts['live']++;
            if ((int) $worker->status === worker_registry::STATUS_STARTING) {
                $counts['starting']++;
            }
            if (worker_registry::waits_for($worker, $now) > 0) {
                $counts['waiting']++;
            }
            if ((int) $worker->stoprequested > 0) {
                $counts['draining']++;
            }
            if ((int) $worker->currentattempt > 0) {
                $counts['playing']++;
            }
            if ((int) $worker->slot > $effective['limit']) {
                $counts['above']++;
            }
        }
        $free = max(0, $effective['limit'] - ($counts['live'] - $counts['above']));

        return $effective + $counts + [
            'free'  => $free,
            'text'  => self::describe($effective, $counts, $free),
        ];
    }

    /**
     * The snapshot in words: one line, and the reason for a limit below the configured one.
     *
     * @param array $effective The effective limit.
     * @param array $counts The worker counts.
     * @param int $free Free slots.
     * @return array{line: string, why: string}
     */
    protected static function describe(array $effective, array $counts, int $free): array {
        $component = 'local_catquizlab';
        $line = get_string('capacity:line', $component, (object) [
            'configured' => $effective['configured'], 'limit' => $effective['limit'], 'live' => $counts['live'],
            'playing' => $counts['playing'], 'starting' => $counts['starting'], 'waiting' => $counts['waiting'],
            'draining' => $counts['draining'], 'free' => $free,
        ]);
        $why = '';
        if ($effective['by'] === 'cap') {
            $why = get_string('capacity:bycap', $component, $effective['cap']);
        } else if ($effective['by'] === 'loadguard') {
            $guard = $effective['guard'];
            $why = get_string('capacity:byguard', $component, (object) [
                'failures' => $guard['failures'], 'executions' => $guard['executions'],
                'share' => (int) round($guard['share'] * 100), 'causes' => implode(', ', array_keys($guard['causes'])),
                'until' => userdate($guard['until'], get_string('strftimetime', 'langconfig')),
            ]);
        }
        if ($counts['above'] > 0) {
            $why = trim($why . ' ' . get_string('capacity:above', $component, $counts['above']));
        }

        return ['line' => $line, 'why' => $why];
    }
}
