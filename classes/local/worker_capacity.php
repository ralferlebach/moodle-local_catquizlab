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
     * @var int After a release by a person, how long a new trip counts as "the same problem again" (#162): the
     *      throttle then holds for twice the cooldown and cannot be released again before it ends.
     */
    public const RELEASE_PROBATION = 1800;

    /** @var int The longest a throttle holds after a trip in probation, in seconds. */
    public const RETRIP_COOLDOWN_MAX = 7200;

    /**
     * The guard's thresholds as set by the administrator, the constants above where nothing is set (#124).
     *
     * Each is held to a sane range: a window under a minute measures noise, a share of 0 throttles on the first
     * failure, and a share above 100 % never throttles at all — which is what switching the guard off looks like,
     * so it is said by its own setting (minimum failures 0) rather than by an impossible share.
     *
     * @return array{window: int, minfailures: int, share: float, cooldown: int, enabled: bool}
     */
    public static function guard_thresholds(): array {
        $read = static function (string $name, $default) {
            $value = get_config('local_catquizlab', $name);
            return ($value === false || $value === '' || $value === null) ? $default : $value;
        };
        $minfailures = max(0, (int) $read('loadguard_minfailures', self::GUARD_MIN_FAILURES));

        return [
            'window' => min(3600, max(60, (int) $read('loadguard_window', self::GUARD_WINDOW))),
            'minfailures' => $minfailures,
            'share' => min(1.0, max(0.01, (float) $read('loadguard_share', 100 * self::GUARD_SHARE) / 100)),
            'cooldown' => min(7200, max(60, (int) $read('loadguard_cooldown', self::GUARD_COOLDOWN))),
            'enabled' => $minfailures > 0,
        ];
    }

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
            // One evaluation at a time (CI d23b39c0: two heartbeats in the same second each found the guard untripped,
            // each logged "load_throttled"). Whoever does not get the lock at once uses the verdict as it stands; the
            // one holding it re-reads it first, in case it was renewed meanwhile.
            $lock = \core\lock\lock_config::get_lock_factory('local_catquizlab_loadguard')->get_lock('evaluate', 0);
            if ($lock) {
                try {
                    global $DB;
                    $kept = json_decode((string) $DB->get_field(
                        'config_plugins',
                        'value',
                        ['plugin' => 'local_catquizlab', 'name' => 'loadguard']
                    ), true) ?: [];
                    if (($kept['checked'] ?? 0) <= $now - self::GUARD_EVERY || ($kept['checked'] ?? 0) > $now) {
                        $kept = self::evaluate($now, $kept);
                        set_config('loadguard', json_encode($kept), 'local_catquizlab');
                    }
                } finally {
                    $lock->release();
                }
            }
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
            // When it was counted, from when (a release starts a fresh count), and the release in force (#162).
            'checked'    => (int) ($kept['checked'] ?? 0),
            'since'      => (int) ($kept['since'] ?? 0),
            'released'   => (array) ($kept['released'] ?? []),
            'noreleaseuntil' => (int) ($kept['noreleaseuntil'] ?? 0),
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

        $thresholds = self::guard_thresholds();
        // Counted from a release at the earliest (#162): the failures that tripped the throttle a person released are
        // not counted again; new ones are.
        $released = (array) ($kept['released'] ?? []);
        $since = max($now - $thresholds['window'], (int) ($released['at'] ?? 0));
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
        $trips = $thresholds['enabled'] && $failures >= $thresholds['minfailures'] && $share >= $thresholds['share'];
        $until = (int) ($kept['until'] ?? 0);
        $was = $until > $now;
        $noreleaseuntil = (int) ($kept['noreleaseuntil'] ?? 0);
        $inprobation = (int) ($released['probation'] ?? 0) > $now;

        if ($trips) {
            // The same problem again soon after a person released the throttle (#162 AK7): twice the cooldown, and no
            // second release before it ends — not a release and a trip every few minutes.
            $cooldown = $inprobation ? min(self::RETRIP_COOLDOWN_MAX, 2 * $thresholds['cooldown']) : $thresholds['cooldown'];
            $until = max($until, $now + $cooldown);
            if ($inprobation) {
                $noreleaseuntil = $until;
            }
            if (!$was) {
                run_log::record(0, run_log::LOAD_THROTTLED, ['failures' => $failures, 'executions' => $executions,
                    'share' => round($share, 2), 'causes' => $causes, 'cooldown' => $cooldown,
                    'thresholds' => $thresholds, 'afterrelease' => $inprobation]);
            }
        } else if (!$thresholds['enabled'] && $was) {
            // Switched off (minimum failures 0): off now, not at the end of a cooldown it set before (#162 AK4).
            run_log::record(0, run_log::LOAD_RELEASED, ['failures' => $failures, 'executions' => $executions,
                'by' => 'guard switched off']);
            $until = 0;
        } else if (!$was && $until > 0) {
            // Released by time: said once, then forgotten.
            run_log::record(0, run_log::LOAD_RELEASED, ['failures' => $failures, 'executions' => $executions]);
            $until = 0;
        }

        return ['checked' => $now, 'since' => $since, 'until' => $until, 'failures' => $failures,
            'executions' => $executions, 'share' => round($share, 3), 'causes' => $causes,
            'released' => $released, 'noreleaseuntil' => $noreleaseuntil > $now ? $noreleaseuntil : 0];
    }

    /**
     * Whether a person may release the throttle now, and if not, why (#162).
     *
     * @param array $guard load_guard().
     * @param int $now The clock.
     * @return string '' when a release is possible; else not-throttled, retripped.
     */
    public static function release_refusal(array $guard, int $now): string {
        if (empty($guard['throttled'])) {
            return 'not-throttled';
        }
        if ((int) ($guard['noreleaseuntil'] ?? 0) > $now) {
            return 'retripped';
        }

        return '';
    }

    /**
     * Release the load guard's throttle, by a person, on purpose, recorded (#162).
     *
     * What it does: ends the cooldown in force now; the guard counts again from this moment, so the failures that
     * tripped it are not counted twice, and new ones trip it again. What it does not do: switch the guard off
     * (minimum failures 0 does, as a setting), raise anything above the configured limit or the safety cap, stop or
     * restart a worker, or touch a lease. Workers for the slots it frees are started by the pipeline, one after
     * another (#120), as for any free slot.
     *
     * Within RELEASE_PROBATION after a release, a new trip holds twice the cooldown and cannot be released before it
     * ends. Two people releasing at once release once: the second finds nothing to release.
     *
     * The caller checks the permission (moodle/site:config) and the session key.
     *
     * @param int $userid Who releases it.
     * @param string $reason Why, in their words (required).
     * @param int|null $now The clock.
     * @return array{ok: bool, reason: string, before: array, after: array}
     */
    public static function release_guard(int $userid, string $reason, ?int $now = null): array {
        $now = $now ?? time();
        $reason = trim(\core_text::substr(clean_param($reason, PARAM_TEXT), 0, 500));
        if ($reason === '') {
            return ['ok' => false, 'reason' => 'no-reason', 'before' => [], 'after' => []];
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_catquizlab_loadguard')->get_lock('release', 10);
        if (!$lock) {
            return ['ok' => false, 'reason' => 'busy', 'before' => [], 'after' => []];
        }
        try {
            $before = self::effective($now);
            $refusal = self::release_refusal($before['guard'], $now);
            if ($refusal !== '') {
                return ['ok' => false, 'reason' => $refusal, 'before' => $before, 'after' => $before];
            }
            $kept = json_decode((string) get_config('local_catquizlab', 'loadguard'), true) ?: [];
            $kept['until'] = 0;
            $kept['checked'] = $now;
            // Who released it is in the site log (the event below), not here: no user id in the plugin's own tables.
            $kept['released'] = ['at' => $now, 'reason' => $reason, 'probation' => $now + self::RELEASE_PROBATION,
                'until' => (int) $before['guard']['until'], 'failures' => (int) $before['guard']['failures'],
                'executions' => (int) $before['guard']['executions'], 'causes' => (array) $before['guard']['causes']];
            set_config('loadguard', json_encode($kept), 'local_catquizlab');
            $after = self::effective($now);

            $detail = ['reason' => $reason, 'limitbefore' => $before['limit'], 'limitafter' => $after['limit'],
                'configured' => $after['configured'], 'cap' => $after['cap'], 'until' => (int) $before['guard']['until'],
                'failures' => (int) $before['guard']['failures'], 'executions' => (int) $before['guard']['executions'],
                'causes' => (array) $before['guard']['causes'], 'probation' => $now + self::RELEASE_PROBATION];
            run_log::record(0, run_log::LOAD_RELEASED, $detail + ['manual' => true]);
            \local_catquizlab\event\loadguard_released::create([
                'context' => \context_system::instance(),
                'userid' => $userid,
                'other' => $detail,
            ])->trigger();
            // The slots it frees are filled by the pipeline, staggered, now rather than at the next five-minute mark.
            \local_catquizlab\task\kick_pipeline::queue('loadguard-released');

            return ['ok' => true, 'reason' => '', 'before' => $before, 'after' => $after];
        } finally {
            $lock->release();
        }
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
        // One drain at a time (CI d23b39c0: three heartbeats in the same second asked 15, 6 and 21 workers to stop
        // at a limit of 25 — 42 "asked" for 25 workers, the same ones counted twice). Busy: another drain is asking
        // them now.
        $lock = \core\lock\lock_config::get_lock_factory('local_catquizlab_loadguard')->get_lock('drain', 5);
        if (!$lock) {
            return 0;
        }
        $asked = [];
        try {
            // Read under the lock: a worker another drain asked a moment ago shows it here.
            foreach (worker_registry::live() as $worker) {
                if ((int) $worker->slot > $effective['limit'] && (int) $worker->stoprequested === 0) {
                    $DB->set_field('local_catquizlab_worker', 'stoprequested', $now ?? time(), ['id' => $worker->id]);
                    $asked[] = (string) $worker->workerid;
                }
            }
        } finally {
            $lock->release();
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
            // One reading, one time (#162 AK1): every figure above is of this moment.
            'asof'  => gmdate('Y-m-d H:i:s', $now) . ' UTC',
            'guardview' => self::guard_view($effective, $now),
        ];
    }

    /**
     * The load guard as a person deciding about it needs to see it (#162 AK1, AK2, AK9, AK10).
     *
     * @param array $effective effective().
     * @param int $now The clock.
     * @return array
     */
    public static function guard_view(array $effective, int $now): array {
        $component = 'local_catquizlab';
        $guard = $effective['guard'];
        $thresholds = self::guard_thresholds();
        $utc = static fn(int $t): string => $t > 0 ? gmdate('Y-m-d H:i:s', $t) . ' UTC' : '–';
        $refusal = self::release_refusal($guard, $now);
        $released = (array) ($guard['released'] ?? []);
        $causes = [];
        foreach ((array) $guard['causes'] as $cause => $n) {
            $causes[] = ['cause' => (string) $cause, 'n' => (int) $n];
        }

        return [
            'enabled' => $thresholds['enabled'],
            'throttled' => !empty($guard['throttled']),
            'counted' => get_string('loadguard:counted', $component, (object) [
                'failures' => (int) $guard['failures'], 'executions' => (int) $guard['executions'],
                'share' => (int) round(100 * (float) $guard['share']), 'from' => $utc((int) ($guard['since'] ?? 0)),
                'checked' => $utc((int) ($guard['checked'] ?? 0)),
            ]),
            'causes' => $causes,
            'thresholds' => get_string('loadguard:thresholds', $component, (object) [
                'minfailures' => $thresholds['minfailures'], 'share' => (int) round(100 * $thresholds['share']),
                'window' => (int) round($thresholds['window'] / 60), 'cooldown' => (int) round($thresholds['cooldown'] / 60),
            ]),
            // The share is of the guard's own count, not of sittings or people (#162 AK10).
            'denominator' => get_string('loadguard:denominator', $component),
            'until' => $utc((int) $guard['until']),
            'limits' => get_string('loadguard:limits', $component, (object) ['configured' => $effective['configured'],
                'cap' => $effective['cap'] > 0 ? $effective['cap'] : '–', 'limit' => $effective['limit'],
                'halved' => !empty($guard['throttled']) ? max(1, intdiv($effective['cap'] > 0
                    ? min($effective['configured'], $effective['cap']) : $effective['configured'], 2)) : '–']),
            'released' => $released === [] ? '' : get_string('loadguard:lastrelease', $component, (object) [
                'at' => $utc((int) $released['at']), 'reason' => (string) ($released['reason'] ?? ''),
                'probation' => $utc((int) ($released['probation'] ?? 0)),
            ]),
            'canrelease' => $refusal === '' && has_capability('moodle/site:config', \context_system::instance()),
            'refusal' => $refusal === 'retripped' ? get_string(
                'loadguard:retripped',
                $component,
                $utc((int) $guard['noreleaseuntil'])
            ) : '',
            'sesskey' => sesskey(),
            'url' => (new \moodle_url('/local/catquizlab/operations.php'))->out(false),
            'settingsurl' => (new \moodle_url('/admin/settings.php', ['section' => registry::SETTINGS_SECTION]))->out(false),
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
                'minutes' => (int) round(self::guard_thresholds()['window'] / 60),
            ]);
        }
        if ($counts['above'] > 0) {
            $why = trim($why . ' ' . get_string('capacity:above', $component, $counts['above']));
        }

        // What a slot costs the web server (#124): said beside the figures, every time, not only when it hurts.
        return ['line' => $line, 'why' => $why, 'slots' => get_string('capacity:slotsexplain', $component)];
    }
}
