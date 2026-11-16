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
 * Experiments waiting their turn, one at a time.
 *
 * Two reasons this is a table and not a list in memory. A queue a task holds
 * forgets everything the moment cron restarts, and somebody who lines up five
 * experiments before going home would find none of them had run. And the order
 * matters: it is the order they asked for, and a queue that reorders itself is
 * one nobody can plan around.
 *
 * Strictly one at a time, on purpose. Two experiments running together share
 * the worker pool, so each takes twice as long and neither can be said to have
 * had the machine to itself — which for a timing-sensitive simulation is not a
 * scheduling preference but a measurement error.
 *
 * The one rule, for everything that hands out work (#163): a worker claims only sittings of the experiment holding
 * the line (holder()). Until 0.7.65 the claim read every ready run of the installation in one global order, and the
 * line was a statement nothing enforced: on a live installation the line was held by one experiment for a day while
 * eleven workers played another one's sittings and the holder's 300 waited untouched. Now:
 * - the line has one holder, the running entry; job_claim hands out its sittings and nobody else's;
 * - an experiment whose runs were started outside the line (a run started on its own, a sweep started from the runs
 *   page, runs from before 0.7.65) is not played beside it: the line adds it at its end (adopt(), reason "adopted") and
 *   it gets its turn after everything that was in the line before it;
 * - a holder that has handed out its last sitting and has none in play leaves the line at once (sittings_done()),
 *   whatever is still to be aggregated; one that needs a person (blocked) leaves it as before;
 * - an operator tool that must play now — the smoke and load tests — takes the front openly and for a limited time
 *   (take_front()), and the holder it displaced waits at the front until it is done.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class execution_queue {
    /** @var string Waiting for its turn. */
    public const WAITING = 'waiting';

    /** @var string Running now. */
    public const RUNNING = 'running';

    /** @var string Done, however it ended. */
    public const FINISHED = 'finished';

    /** @var string Taken out of the line before it ran. */
    public const CANCELLED = 'cancelled';

    /**
     * @var string The reason of a running entry whose experiment the line prepares itself (#151). Kept with the
     *      entry, where an entry that has stopped keeps why, until the entry ends.
     */
    public const PREPARING = 'preparing';

    /** @var string The reason of an entry the line added for an experiment started outside it (#163). */
    public const ADOPTED = 'adopted';

    /** @var string The reason of an entry that held the line and waits again because a tool took the front (#163). */
    public const PREEMPTED = 'preempted';

    /** @var string Between "preempted: <by>" and the front a displaced tool still holds: " | front:<until>:<by>". */
    protected const KEPT = ' | ';

    /** @var string The reason prefix of an entry a tool took to the front, as front:<until>:<by> (#163). */
    public const FRONT = 'front';

    /** @var string The reason of a holder that left the line with every sitting handed out and none in play (#163). */
    public const SITTINGS_DONE = 'sittings-done';

    /** @var int How long take_front() holds the front at most, in seconds. */
    public const FRONT_MAX = 6 * HOURSECS;

    /**
     * The experiment whose sittings may be claimed now: the one holding the line (#163).
     *
     * A read and nothing else: the line moves in advance() and in take_turn().
     *
     * @return int The experiment, 0 when nobody holds the line.
     */
    public static function holder(): int {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_catquizlab_execqueue')) {
            return 0;
        }
        $rows = $DB->get_records(
            'local_catquizlab_execqueue',
            ['state' => self::RUNNING],
            'position ASC, id ASC',
            'id, experimentid, reason',
            0,
            1
        );
        if ($rows === []) {
            return 0;
        }
        $entry = reset($rows);
        // A tool's time at the front is up: it holds nothing any more, and the next move closes the entry.
        $until = self::front_until($entry);

        return $until > 0 && $until < time() ? 0 : (int) $entry->experimentid;
    }

    /**
     * Move the line for a claim, when nobody holds it or its holder has nothing left to hand out (#163).
     *
     * A claim must not wait for the next tick to find the line moved: a run started outside it, or the next
     * experiment once the holder is through, would stand idle for a tick each time. Only what needs no preparation
     * moves here — a front entry whose runs are ready; preparing an experiment stays with the tick.
     *
     * @return int The holder afterwards, 0 when nobody holds the line.
     */
    public static function take_turn(): int {
        $lock = \core\lock\lock_config::get_lock_factory('local_catquizlab_queue')->get_lock('advance', 5);
        if (!$lock) {
            return self::holder();
        }
        try {
            self::advance_locked(true);
        } finally {
            $lock->release();
        }

        return self::holder();
    }

    /**
     * Whether an experiment has handed out its last sitting and has none in play (#163).
     *
     * Counted over its runs that hand out work: a sitting queued in a failed run is nothing a worker can take, and it
     * is the run's failure that says so. A run being set up is not done whatever it counts. A run in draft is not
     * counted: the line prepares a holder's drafts while it says so (advance() keeps such a holder), and a draft beside
     * runs started on their own waits for a person, not for the line.
     *
     * @param int $experimentid The experiment.
     * @return bool
     */
    public static function sittings_done(int $experimentid): bool {
        global $DB;

        if ($DB->record_exists('local_catquizlab_run', ['experimentid' => $experimentid, 'status' => registry::STATUS_SCHEDULED])) {
            return false;
        }
        // In play anywhere: not done. Queued: not done where the run hands out work — a run the circuit breaker holds
        // waits for a person, and holding the line for it would hold everything behind it (a run paused by a person
        // is a decision, and the line waits for it).
        [$notheld, $params] = self::not_held_sql();

        return !$DB->record_exists_sql(
            "SELECT 1
               FROM {local_catquizlab_attempt} a
               JOIN {local_catquizlab_run} r ON r.id = a.runid
              WHERE r.experimentid = :e AND r.status IN (:ready, :running)
                AND (a.status = :inflight OR (a.status = :queued AND {$notheld}))",
            $params + ['e' => $experimentid, 'ready' => registry::STATUS_READY, 'running' => registry::STATUS_RUNNING,
                'queued' => attempt_scheduler::STATUS_QUEUED, 'inflight' => attempt_scheduler::STATUS_RUNNING]
        );
    }

    /**
     * Whether an experiment has sittings queued in a run that hands out work now: ready or running, and not paused —
     * neither by a person nor by the circuit breaker (#163). What the line needs to give an experiment its turn.
     *
     * @param int $experimentid The experiment.
     * @return bool
     */
    public static function has_work(int $experimentid): bool {
        global $DB;

        [$notheld, $params] = self::not_held_sql(true);

        return $DB->record_exists_sql(
            "SELECT 1
               FROM {local_catquizlab_attempt} a
               JOIN {local_catquizlab_run} r ON r.id = a.runid
              WHERE r.experimentid = :e AND r.status IN (:ready, :running) AND a.status = :queued AND {$notheld}",
            $params + ['e' => $experimentid, 'ready' => registry::STATUS_READY, 'running' => registry::STATUS_RUNNING,
                'queued' => attempt_scheduler::STATUS_QUEUED]
        );
    }

    /**
     * The condition "run r is not held", for a run that is ready or running: not held by the circuit breaker, or with
     * $anypause not paused at all (by a person either).
     *
     * Not NOT circuit_breaker::held_sql(): a run without a manifest makes that condition NULL, and NOT NULL is not true.
     *
     * @param bool $anypause Any pause holds, not only the circuit breaker's.
     * @return array [string $sql, array $params]
     */
    protected static function not_held_sql(bool $anypause = false): array {
        global $DB;

        return [
            'NOT (' . $DB->sql_like("COALESCE(r.manifestjson, '')", ':notheldpattern') . ')',
            ['notheldpattern' => $anypause ? '%"paused":true%' : '%"pausedreason":"' . circuit_breaker::PAUSE_REASON . '%'],
        ];
    }

    /**
     * Put every experiment with sittings to hand out that is not in the line at the end of it (#163).
     *
     * Runs started outside the line — on their own, from the runs page, before 0.7.65 — used to be played beside the
     * line's holder, by whoever claimed first. They are given a place instead, after everything in the line: the
     * experiment whose sittings were queued first comes first. Only sittings a worker could take count: those of a
     * paused run — paused by a person, or held by the circuit breaker — wait for whoever paused it, and the experiment
     * is given a place once its run goes on. (Its holder keeps the line through a pause by a person: that is how the
     * line is held still on purpose. It does not keep it for the circuit breaker, sittings_done().)
     *
     * Called under the line's lock.
     *
     * @return int[] The experiments added.
     */
    protected static function adopt(): array {
        global $DB;

        $added = [];
        foreach (self::adoption_candidates() as $experimentid => $firstqueued) {
            $position = 1 + (int) $DB->get_field_sql('SELECT COALESCE(MAX(position), 0) FROM {local_catquizlab_execqueue}');
            $DB->insert_record('local_catquizlab_execqueue', (object) [
                'experimentid' => $experimentid,
                'state'        => self::WAITING,
                'position'     => $position,
                'reason'       => self::ADOPTED,
                'userid'       => 0,
                'timecreated'  => time(),
            ]);
            debug_trace::record(debug_trace::LIFECYCLE, 'queue_adopted', ['experimentid' => $experimentid,
                'position' => $position, 'firstqueued' => $firstqueued]);
            $added[] = $experimentid;
        }

        return $added;
    }

    /**
     * The experiments adopt() would add, in the order it would add them.
     *
     * @param int $limit At most this many, 0 for all.
     * @return array<int, int> Experiment id => when its first queued sitting was queued.
     */
    protected static function adoption_candidates(int $limit = 0): array {
        global $DB;

        [$notheld, $heldparams] = self::not_held_sql(true);
        $rows = $DB->get_records_sql(
            "SELECT r.experimentid, MIN(a.timecreated) AS firstqueued
               FROM {local_catquizlab_attempt} a
               JOIN {local_catquizlab_run} r ON r.id = a.runid
              WHERE a.status = :queued AND r.status IN (:ready, :running) AND {$notheld}
                AND NOT EXISTS (SELECT 1 FROM {local_catquizlab_execqueue} q
                                 WHERE q.experimentid = r.experimentid AND q.state IN (:waiting, :inline))
           GROUP BY r.experimentid
           ORDER BY MIN(a.timecreated) ASC, r.experimentid ASC",
            $heldparams + ['queued' => attempt_scheduler::STATUS_QUEUED, 'ready' => registry::STATUS_READY,
                'running' => registry::STATUS_RUNNING, 'waiting' => self::WAITING, 'inline' => self::RUNNING]
        );
        $out = [];
        foreach ($rows as $row) {
            $experimentid = (int) $row->experimentid;
            $experiment = $DB->get_record('local_catquizlab_experiment', ['id' => $experimentid], 'id, timedeleting');
            if (!$experiment || (int) $experiment->timedeleting > 0) {
                continue;
            }
            $out[$experimentid] = (int) $row->firstqueued;
            if ($limit > 0 && count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * The experiment whose sittings a worker asking now would be handed (#163): the holder, or, where nobody holds the
     * line, the one a claim would make the holder (take_turn()). A read; nothing moves.
     *
     * @return int The experiment, 0 when a claim now would be handed nothing.
     */
    public static function next_holder(): int {
        global $DB;

        $holder = self::holder();
        if ($holder > 0 || !$DB->get_manager()->table_exists('local_catquizlab_execqueue')) {
            return $holder;
        }
        $front = $DB->get_records(
            'local_catquizlab_execqueue',
            ['state' => self::WAITING],
            'position ASC, id ASC',
            'id, experimentid, reason',
            0,
            1
        );
        if ($front !== []) {
            $entry = reset($front);

            return self::takes_turn_now($entry) ? (int) $entry->experimentid : 0;
        }
        $first = array_keys(self::adoption_candidates(1));

        return $first === [] ? 0 : (int) $first[0];
    }

    /**
     * Take the front of the line, openly and for a limited time: for a tool that must play now (#163).
     *
     * The smoke and load tests play their own experiment on an installation that may be busy. Until 0.7.65 they
     * deferred every other queued sitting for the time (queue_deferral); with claims bound to the line's holder that
     * is not enough and not needed: the test's experiment holds the line instead. Whatever held it waits at the front
     * again (reason "preempted") and goes on when the test is done, or when its time is up. Its sittings in play are
     * not touched.
     *
     * @param int $experimentid The experiment that must play now.
     * @param string $by Who took the front, for the line and the log.
     * @param int $seconds For how long at most (capped at FRONT_MAX).
     * @return array{ok: bool, reason: string, preempted: int[], until: int}
     */
    public static function take_front(int $experimentid, string $by, int $seconds): array {
        global $DB;

        $until = time() + min(self::FRONT_MAX, max(60, $seconds));
        $by = clean_param(\core_text::substr($by, 0, 100), PARAM_NOTAGS);
        $lock = \core\lock\lock_config::get_lock_factory('local_catquizlab_queue')->get_lock('advance', 30);
        if (!$lock) {
            return ['ok' => false, 'reason' => 'advancing', 'preempted' => [], 'until' => 0];
        }
        try {
            $preempted = [];
            foreach ($DB->get_records('local_catquizlab_execqueue', ['state' => self::RUNNING]) as $entry) {
                if ((int) $entry->experimentid === $experimentid) {
                    continue;
                }
                // A tool's front displaced by another tool stays its front: it goes on with its own time limit when
                // the other gives the front back, and its own tool can give it back meanwhile (release_front()).
                $kept = self::front_until($entry) > 0 ? self::KEPT . self::front_reason($entry) : '';
                $DB->update_record('local_catquizlab_execqueue', (object) ['id' => $entry->id, 'state' => self::WAITING,
                    'reason' => self::PREEMPTED . ': ' . $by . $kept]);
                $preempted[] = (int) $entry->experimentid;
            }
            $front = (int) $DB->get_field_sql(
                'SELECT COALESCE(MIN(position), 1) FROM {local_catquizlab_execqueue} WHERE state IN (:waiting, :inline)',
                ['waiting' => self::WAITING, 'inline' => self::RUNNING]
            ) - 1;
            $reason = self::FRONT . ':' . $until . ':' . $by;
            $mine = $DB->get_records_select(
                'local_catquizlab_execqueue',
                'experimentid = :e AND state IN (:waiting, :inline)',
                ['e' => $experimentid, 'waiting' => self::WAITING, 'inline' => self::RUNNING],
                'position ASC'
            );
            if ($mine !== []) {
                $entry = array_shift($mine);
                $DB->update_record('local_catquizlab_execqueue', (object) ['id' => $entry->id, 'state' => self::RUNNING,
                    'position' => $front, 'reason' => $reason, 'timestarted' => time()]);
                foreach ($mine as $double) {
                    self::close($double, self::CANCELLED, 'duplicate');
                }
            } else {
                $DB->insert_record('local_catquizlab_execqueue', (object) [
                    'experimentid' => $experimentid,
                    'state'        => self::RUNNING,
                    'position'     => $front,
                    'reason'       => $reason,
                    'userid'       => 0,
                    'timecreated'  => time(),
                    'timestarted'  => time(),
                ]);
            }
            debug_trace::record(debug_trace::LIFECYCLE, 'queue_front_taken', ['experimentid' => $experimentid, 'by' => $by,
                'until' => $until, 'preempted' => $preempted]);

            return ['ok' => true, 'reason' => '', 'preempted' => $preempted, 'until' => $until];
        } finally {
            $lock->release();
        }
    }

    /**
     * Give the front back: the experiment a tool took it for leaves the line, the one it displaced goes on (#163).
     *
     * @param int $experimentid The experiment that held the front.
     * @param string $why Why, for the line.
     * @return bool Whether it held the front.
     */
    public static function release_front(int $experimentid, string $why): bool {
        global $DB;

        // At the front, or displaced from it by another tool and waiting with its front kept.
        $entries = $DB->get_records_select(
            'local_catquizlab_execqueue',
            'experimentid = :e AND state IN (:inline, :waiting) AND (' . $DB->sql_like('reason', ':front') . ' OR '
                . $DB->sql_like('reason', ':kept') . ')',
            ['e' => $experimentid, 'inline' => self::RUNNING, 'waiting' => self::WAITING, 'front' => self::FRONT . ':%',
                'kept' => '%' . self::KEPT . self::FRONT . ':%']
        );
        foreach ($entries as $entry) {
            self::close($entry, self::FINISHED, 'front released: ' . \core_text::substr($why, 0, 200));
        }
        if ($entries !== []) {
            debug_trace::record(debug_trace::LIFECYCLE, 'queue_front_released', ['experimentid' => $experimentid,
                'why' => $why]);
        }

        return $entries !== [];
    }

    /**
     * Until when a tool holds the front, from an entry's reason; 0 for an entry that is not such.
     *
     * @param \stdClass $entry The entry.
     * @return int
     */
    protected static function front_until(\stdClass $entry): int {
        $parts = explode(':', self::front_reason($entry), 3);

        return $parts[0] === self::FRONT && count($parts) === 3 ? (int) $parts[1] : 0;
    }

    /**
     * The front part of an entry's reason: the reason itself, or the front a displaced tool keeps; '' for none.
     *
     * @param \stdClass $entry The entry.
     * @return string
     */
    protected static function front_reason(\stdClass $entry): string {
        $reason = (string) ($entry->reason ?? '');
        if (strpos($reason, self::FRONT . ':') === 0) {
            return $reason;
        }
        $at = strpos($reason, self::KEPT . self::FRONT . ':');

        return $at === false ? '' : substr($reason, $at + strlen(self::KEPT));
    }

    /**
     * Put an experiment in the line.
     *
     * @param int $experimentid The experiment.
     * @return array{ok: bool, position: int, place?: int, reason: string, warning?: string}
     */
    public static function enqueue(int $experimentid): array {
        global $DB;

        // An experiment being deleted (0.7.50) does not go back into the line.
        if ((int) $DB->get_field('local_catquizlab_experiment', 'timedeleting', ['id' => $experimentid]) > 0) {
            return ['ok' => false, 'position' => 0, 'reason' => 'being-deleted'];
        }
        // Prepared or not (#151): an experiment that has not been prepared is prepared when its turn comes, in the
        // background (advance()). What cannot join is one that is running, finished, stopped or needs recovery: it
        // would reach the front and have nothing to do there.
        $state = experiment_runner::state($experimentid);
        if (!in_array($state['state'], experiment_runner::STARTABLE, true)) {
            return ['ok' => false, 'position' => 0, 'reason' => 'not-ready'];
        }

        // The runtime — browser, worker, pipeline — is needed to run, not to
        // wait in line. An entry queued before the worker is set up simply
        // waits; what is still missing is said now, and checked again when its
        // turn comes.
        $setup = setup_wizard::state();
        $warning = empty($setup['ready']) ? 'setup-incomplete: ' . implode(', ', $setup['blockers']) : '';

        // Two clicks, two browsers, at once (#151 AK7): the check and the insert under one lock, so that the second
        // finds the first one's entry. Without the lock both read "not in the line" and both insert.
        $lock = \core\lock\lock_config::get_lock_factory('local_catquizlab_queue')->get_lock('enqueue', 10);
        try {
            return self::enqueue_once($experimentid, $warning);
        } finally {
            if ($lock) {
                $lock->release();
            }
        }
    }

    /**
     * Put an experiment in the line unless it is there already; under the lock of enqueue().
     *
     * @param int $experimentid The experiment.
     * @param string $warning What is missing on the installation, if anything.
     * @return array{ok: bool, position: int, place: int, reason: string, warning: string}
     */
    protected static function enqueue_once(int $experimentid, string $warning): array {
        global $DB, $USER;

        // Already in the line: asking twice should not mean running twice.
        $existing = $DB->get_record_select(
            'local_catquizlab_execqueue',
            'experimentid = :experimentid AND state IN (:waiting, :running)',
            [
                'experimentid' => $experimentid,
                'waiting'      => self::WAITING,
                'running'      => self::RUNNING,
            ]
        );

        if ($existing) {
            return ['ok' => true, 'position' => (int) $existing->position, 'place' => self::place((int) $existing->position),
                'reason' => 'already-queued', 'warning' => $warning];
        }

        $position = 1 + (int) $DB->get_field_sql(
            'SELECT COALESCE(MAX(position), 0) FROM {local_catquizlab_execqueue}'
        );

        $DB->insert_record('local_catquizlab_execqueue', (object) [
            'experimentid' => $experimentid,
            'state'        => self::WAITING,
            'position'     => $position,
            'userid'       => (int) ($USER->id ?? 0),
            'timecreated'  => time(),
        ]);

        return ['ok' => true, 'position' => $position, 'place' => self::place($position), 'reason' => '',
            'warning' => $warning];
    }

    /**
     * Where an entry stands among those still in the line: 1 for the first. The position itself only orders, and
     * counts every entry there ever was (#151: "number 10 in the line" for the only one in it).
     *
     * @param int $position The entry's position.
     * @return int
     */
    public static function place(int $position): int {
        global $DB;

        return (int) $DB->count_records_select(
            'local_catquizlab_execqueue',
            'state IN (:waiting, :running) AND position <= :position',
            ['waiting' => self::WAITING, 'running' => self::RUNNING, 'position' => $position]
        );
    }

    /**
     * Take an experiment out of the line.
     *
     * @param int $experimentid The experiment.
     * @return bool Whether anything was waiting.
     */
    public static function cancel(int $experimentid): bool {
        global $DB;

        $entries = $DB->get_records('local_catquizlab_execqueue', [
            'experimentid' => $experimentid,
            'state'        => self::WAITING,
        ]);

        foreach ($entries as $entry) {
            $DB->set_field('local_catquizlab_execqueue', 'state', self::CANCELLED, ['id' => $entry->id]);
        }

        return $entries !== [];
    }

    /**
     * Start the next experiment, if nothing is running.
     *
     * Called from the pipeline tick. Does nothing at all while something runs,
     * which is what "one at a time" means in practice.
     *
     * @return array{started: int, reason: string, holder?: int, since?: int, closed?: array[]} What it decided:
     *      started (the experiment started, or 0), reason (busy, empty, setup-incomplete, skipped-not-ready),
     *      holder (the experiment holding the line or at its front), closed (entries it took out, and why).
     */
    public static function advance(): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_catquizlab_execqueue')) {
            return ['started' => 0, 'reason' => 'no-queue'];
        }
        // One at a time (#151 AK7): the scheduled tick and a tick asked for by a start or a ready run can meet; two
        // advances at once could both find the front entry waiting and both start it. The second one waits its turn
        // for a moment, then leaves the line to the first.
        $lock = \core\lock\lock_config::get_lock_factory('local_catquizlab_queue')->get_lock('advance', 5);
        if (!$lock) {
            return ['started' => 0, 'reason' => 'advancing'];
        }
        try {
            return self::advance_locked();
        } finally {
            $lock->release();
        }
    }

    /**
     * advance(), under its lock.
     *
     * @param bool $forclaim Called for a claim (take_turn()): only a front entry whose runs are ready moves up, and
     *      neither preparation nor the installation's setup is looked at — a worker is claiming, so the setup works.
     * @return array See advance().
     */
    protected static function advance_locked(bool $forclaim = false): array {
        global $DB;

        // Runs started outside the line get a place at its end before anything moves (#163).
        $adopted = self::adopt();

        // Close out whatever finished since the last tick, so the line moves.
        $closed = [];
        foreach ($DB->get_records('local_catquizlab_execqueue', ['state' => self::RUNNING], 'position ASC') as $entry) {
            $experimentid = (int) $entry->experimentid;
            // A tool's time at the front is up (#163): the entry goes, and the experiment, if it still has sittings to
            // hand out, gets a place at the end like any experiment started outside the line.
            $until = self::front_until($entry);
            if ($until > 0 && $until < time()) {
                self::close($entry, self::CANCELLED, 'front expired');
                $closed[] = ['experimentid' => $experimentid, 'reason' => 'front expired'];
                continue;
            }
            // Being prepared (#151): no runs yet, or runs not started yet, is what preparing looks like. The
            // preparation is asked for again on every tick until it has started every run — a task lost or failed
            // for good is replaced, a running one is not doubled (prepare_experiment::queue() checks).
            if (self::preparing($entry) && $DB->record_exists('local_catquizlab_experiment', ['id' => $experimentid])) {
                $state = experiment_runner::state($experimentid);
                if (in_array($state['state'], [experiment_runner::STATE_DRAFT, experiment_runner::STATE_CREATED], true)) {
                    \local_catquizlab\task\prepare_experiment::queue($experimentid, (int) $entry->userid);

                    return ['started' => 0, 'reason' => 'busy', 'holder' => $experimentid,
                        'since' => (int) $entry->timestarted, 'closed' => $closed, 'preparing' => true, 'adopted' => $adopted];
                }
                if ($state['state'] !== experiment_runner::STATE_PREPARING) {
                    // Prepared: the entry no longer says so.
                    $DB->set_field('local_catquizlab_execqueue', 'reason', '', ['id' => $entry->id]);
                    $entry->reason = '';
                }
            }
            $orphan = self::orphaned($experimentid);
            if ($orphan !== '') {
                // An entry whose experiment has no runs any more, or whose runs were never started, is not
                // running anything — and held the whole line for 48 days on an installation (incident
                // 2026-11-09), every tick answering "busy" and nothing saying so.
                self::close($entry, self::CANCELLED, $orphan);
                $closed[] = ['experimentid' => $experimentid, 'reason' => $orphan];
                continue;
            }

            $state = experiment_runner::state($experimentid);
            if ($state['state'] === experiment_runner::STATE_FINISHED) {
                self::close($entry, self::FINISHED, '');
                $closed[] = ['experimentid' => $experimentid, 'reason' => $state['state']];
                continue;
            }
            // Every sitting a worker could take handed out and none in play (#163): what is left — collecting,
            // aggregating, a run that needs a person — needs no worker, and the next experiment's sittings would
            // otherwise wait for it. Blocked (a run failed or is held by the circuit breaker) it is said as such; its
            // runs that hand out work are played to the end first, as they were before 0.7.65.
            if (self::sittings_done($experimentid)) {
                $blocked = $state['state'] === experiment_runner::STATE_BLOCKED;
                $reason = $blocked ? 'blocked' : self::SITTINGS_DONE;
                self::close($entry, self::FINISHED, $until > 0 ? 'front released: ' . $reason : $reason);
                $closed[] = ['experimentid' => $experimentid, 'reason' => $blocked ? $state['state'] : self::SITTINGS_DONE];
                continue;
            }

            // Still going: nothing else starts, and which one holds the line is said.
            return ['started' => 0, 'reason' => 'busy', 'holder' => $experimentid,
                'since' => (int) $entry->timestarted, 'closed' => $closed, 'adopted' => $adopted];
        }

        // Waiting entries whose experiment is gone: taken out of the line now, not when they reach its front, so
        // the line shows what will really run. One without runs is not gone (#151): the line prepares it at its turn.
        foreach ($DB->get_records('local_catquizlab_execqueue', ['state' => self::WAITING]) as $entry) {
            if (!$DB->record_exists('local_catquizlab_experiment', ['id' => $entry->experimentid])) {
                self::close($entry, self::CANCELLED, 'orphaned: experiment deleted');
                $closed[] = ['experimentid' => (int) $entry->experimentid, 'reason' => 'orphaned: experiment deleted'];
            }
        }

        while (true) {
            $next = $DB->get_records('local_catquizlab_execqueue', ['state' => self::WAITING], 'position ASC', '*', 0, 1);
            if ($next === []) {
                return ['started' => 0, 'reason' => 'empty', 'closed' => $closed, 'adopted' => $adopted];
            }
            $entry = reset($next);
            $experimentid = (int) $entry->experimentid;
            // A tool's front, displaced by another tool, whose time ran out while it waited: it holds nothing now.
            $until = self::front_until($entry);
            if ($until > 0 && $until < time()) {
                self::close($entry, self::CANCELLED, 'front expired');
                $closed[] = ['experimentid' => $experimentid, 'reason' => 'front expired'];
                continue;
            }
            // Its turn would be empty (#163): nothing to prepare and nothing left to hand out — one that held the line
            // before a tool took the front and is through meanwhile, one the line added whose runs are held or done. It
            // leaves the line, and the next one comes up now, not a tick later each.
            $runnerstate = experiment_runner::state($experimentid)['state'];
            $nothingtoprepare = (string) ($entry->reason ?? '') === self::ADOPTED || in_array(
                $runnerstate,
                [experiment_runner::STATE_READY, experiment_runner::STATE_RUNNING, experiment_runner::STATE_FINISHED],
                true
            );
            if ($nothingtoprepare && self::sittings_done($experimentid)) {
                self::close($entry, self::FINISHED, self::SITTINGS_DONE);
                $closed[] = ['experimentid' => $experimentid, 'reason' => self::SITTINGS_DONE];
                continue;
            }
            break;
        }

        if ($forclaim || (string) ($entry->reason ?? '') === self::ADOPTED) {
            // For a claim, only what can hand out sittings now (#163); everything else waits for the tick. An entry the
            // line added for runs started on their own takes its turn as it is, by either way: nothing of it is
            // prepared — its drafts, if any, wait for whoever started the others.
            if (!self::takes_turn_now($entry)) {
                if ((string) ($entry->reason ?? '') === self::ADOPTED) {
                    // Nothing a worker could take any more since it was added (held, stopped, played): out of the
                    // line; the line adds it again once it has.
                    self::close($entry, self::FINISHED, self::SITTINGS_DONE);
                    $closed[] = ['experimentid' => $experimentid, 'reason' => self::SITTINGS_DONE];

                    return ['started' => 0, 'reason' => 'skipped-not-ready', 'holder' => $experimentid,
                        'closed' => $closed, 'adopted' => $adopted];
                }

                return ['started' => 0, 'reason' => 'front-not-ready', 'holder' => $experimentid, 'closed' => $closed,
                    'adopted' => $adopted];
            }
            $DB->update_record('local_catquizlab_execqueue', (object) ['id' => $entry->id, 'state' => self::RUNNING,
                'reason' => self::reason_at_turn($entry), 'timestarted' => time()]);
            debug_trace::record(debug_trace::LIFECYCLE, 'queue_turn', ['experimentid' => $experimentid,
                'by' => $forclaim ? 'claim' : 'tick', 'closed' => $closed]);

            return ['started' => $experimentid, 'reason' => '', 'closed' => $closed, 'adopted' => $adopted];
        }

        // The installation has to be able to run now. An entry queued before
        // the runtime was set up waits here — it is not skipped, because the
        // thing missing is on the installation, not on the experiment.
        if (empty(setup_wizard::state()['ready'])) {
            return ['started' => 0, 'reason' => 'setup-incomplete', 'holder' => $experimentid, 'closed' => $closed,
                'adopted' => $adopted];
        }

        // Ready when it reaches the front, not merely when it was queued: an
        // experiment can be reset or fail while it waits.
        $state = experiment_runner::state($experimentid);
        // Not prepared yet (#151): prepared now, in the background, holding the line meanwhile — so that one
        // experiment is prepared and run at a time, and the ones behind it wait as they were saved.
        if (
            in_array($state['state'], [experiment_runner::STATE_DRAFT, experiment_runner::STATE_CREATED,
                experiment_runner::STATE_PREPARING], true)
        ) {
            $DB->update_record('local_catquizlab_execqueue', (object) [
                'id'          => $entry->id,
                'state'       => self::RUNNING,
                'reason'      => self::PREPARING,
                'timestarted' => time(),
            ]);
            if ($state['state'] !== experiment_runner::STATE_PREPARING) {
                \local_catquizlab\task\prepare_experiment::queue($experimentid, (int) $entry->userid);
            }

            return ['started' => $experimentid, 'reason' => 'preparing', 'closed' => $closed, 'adopted' => $adopted];
        }
        if (!$state['canstart'] && $state['state'] !== experiment_runner::STATE_RUNNING) {
            $DB->update_record('local_catquizlab_execqueue', (object) [
                'id'           => $entry->id,
                'state'        => self::FINISHED,
                'reason'       => 'not-ready-at-turn',
                'timefinished' => time(),
            ]);

            return ['started' => 0, 'reason' => 'skipped-not-ready', 'holder' => $experimentid,
                'closed' => $closed, 'adopted' => $adopted];
        }

        $DB->update_record('local_catquizlab_execqueue', (object) [
            'id'          => $entry->id,
            'state'       => self::RUNNING,
            'reason'      => self::reason_at_turn($entry),
            'timestarted' => time(),
        ]);

        return ['started' => $experimentid, 'reason' => '', 'closed' => $closed, 'adopted' => $adopted];
    }

    /**
     * What an entry says once its turn has come: that the line added it, if it did; the front a tool still holds, if
     * another tool displaced it; otherwise nothing of how it got there.
     *
     * @param \stdClass $entry The entry.
     * @return string
     */
    protected static function reason_at_turn(\stdClass $entry): string {
        if ((string) ($entry->reason ?? '') === self::ADOPTED) {
            return self::ADOPTED;
        }

        // A tool's front displaced by another: its front again, with the time limit it had.
        return self::front_reason($entry);
    }

    /**
     * Whether a waiting entry can take its turn as it is, without preparing anything (#163): ready or running for an
     * entry somebody queued; for one the line added, sittings a worker could take (has_work()).
     *
     * @param \stdClass $entry The entry.
     * @return bool
     */
    protected static function takes_turn_now(\stdClass $entry): bool {
        if ((string) ($entry->reason ?? '') === self::ADOPTED) {
            return self::has_work((int) $entry->experimentid);
        }
        $state = experiment_runner::state((int) $entry->experimentid)['state'];

        return in_array($state, [experiment_runner::STATE_READY, experiment_runner::STATE_RUNNING], true);
    }

    /**
     * Whether the line prepares this running entry's experiment itself (#151).
     *
     * @param \stdClass $entry The entry.
     * @return bool
     */
    public static function preparing(\stdClass $entry): bool {
        return $entry->state === self::RUNNING && strpos((string) ($entry->reason ?? ''), self::PREPARING) === 0;
    }

    /**
     * Say how the preparation of a running entry's experiment went, on the entry, where the line shows it (#151).
     *
     * @param int $experimentid The experiment.
     * @param string $how 'preparing' and what is missing or failed, if anything.
     * @return void
     */
    public static function note_preparation(int $experimentid, string $how): void {
        global $DB;

        $entry = $DB->get_record('local_catquizlab_execqueue', ['experimentid' => $experimentid, 'state' => self::RUNNING]);
        if ($entry && self::preparing($entry)) {
            $DB->set_field('local_catquizlab_execqueue', 'reason', \core_text::substr($how, 0, 1000), ['id' => $entry->id]);
        }
    }

    /**
     * Why a running entry is not running anything, if it is not.
     *
     * @param int $experimentid The experiment of the entry.
     * @return string The reason, or '' where the entry is genuine.
     */
    public static function orphaned(int $experimentid): string {
        global $DB;

        if (!$DB->record_exists('local_catquizlab_experiment', ['id' => $experimentid])) {
            return 'orphaned: experiment deleted';
        }
        if (!$DB->record_exists('local_catquizlab_run', ['experimentid' => $experimentid])) {
            return 'orphaned: experiment has no runs';
        }
        $state = experiment_runner::state($experimentid)['state'];
        // Created and never started; one with a run started on its own (#163: given a place by the line) is not.
        $started = $DB->record_exists_select(
            'local_catquizlab_run',
            'experimentid = :e AND status <> :draft',
            ['e' => $experimentid, 'draft' => registry::STATUS_DRAFT]
        );
        if (in_array($state, [experiment_runner::STATE_DRAFT, experiment_runner::STATE_CREATED], true) && !$started) {
            return 'orphaned: runs were never started';
        }

        return '';
    }

    /**
     * Take every entry of an experiment out of the line: it was deleted, or its runs were.
     *
     * @param int $experimentid The experiment.
     * @param string $reason Why.
     * @return int Entries taken out.
     */
    public static function forget(int $experimentid, string $reason): int {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_catquizlab_execqueue')) {
            return 0;
        }
        $entries = $DB->get_records_select(
            'local_catquizlab_execqueue',
            'experimentid = :experimentid AND state IN (:waiting, :running)',
            ['experimentid' => $experimentid, 'waiting' => self::WAITING, 'running' => self::RUNNING]
        );
        foreach ($entries as $entry) {
            self::close($entry, self::CANCELLED, $reason);
        }

        return count($entries);
    }

    /**
     * End an entry.
     *
     * @param \stdClass $entry The entry.
     * @param string $state FINISHED or CANCELLED.
     * @param string $reason Why.
     * @return void
     */
    protected static function close(\stdClass $entry, string $state, string $reason): void {
        global $DB;

        $DB->update_record('local_catquizlab_execqueue', (object) [
            'id'           => $entry->id,
            'state'        => $state,
            'reason'       => $reason,
            'timefinished' => time(),
        ]);
    }

    /**
     * The line, as somebody waiting in it would want to see it.
     *
     * @return array[]
     */
    public static function entries(): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_catquizlab_execqueue')) {
            return [];
        }

        $rows = $DB->get_records_select(
            'local_catquizlab_execqueue',
            'state IN (:waiting, :running)',
            ['waiting' => self::WAITING, 'running' => self::RUNNING],
            'position ASC'
        );

        $out = [];
        $place = 0;
        foreach ($rows as $row) {
            $place++;
            $name = $DB->get_field('local_catquizlab_experiment', 'name', ['id' => $row->experimentid]);

            $out[] = [
                'id'           => (int) $row->id,
                'experimentid' => (int) $row->experimentid,
                'name'         => $name === false ? '' : format_string($name),
                'state'        => $row->state,
                'running'      => $row->state === self::RUNNING,
                'waiting'      => $row->state === self::WAITING,
                // Prepared by the line itself (#151), and how that goes: "preparing", or what it waits for.
                'preparing'    => self::preparing($row),
                'reason'       => self::preparing($row) ? (string) $row->reason : '',
                'place'        => $place,
                'queuedago'    => duration::ago((int) $row->timecreated),
                // How it came to stand where it stands (#163): added by the line, displaced, or at the front for a tool.
                'note'         => self::note($row),
            ];
        }

        return $out;
    }

    /**
     * How an entry came to stand where it stands, in words; empty for an entry somebody queued with "Start experiment".
     * Plain text: the template escapes it.
     *
     * @param \stdClass $row The entry.
     * @return string
     */
    public static function note(\stdClass $row): string {
        $reason = (string) ($row->reason ?? '');
        $component = 'local_catquizlab';
        if ($reason === self::ADOPTED) {
            return get_string('queue:noteadopted', $component);
        }
        if (strpos($reason, self::PREEMPTED . ': ') === 0) {
            $by = explode(self::KEPT, substr($reason, strlen(self::PREEMPTED) + 2))[0];

            return get_string('queue:notepreempted', $component, $by);
        }
        $until = self::front_until($row);
        if ($until > 0) {
            return get_string('queue:notefront', $component, (object) ['by' => explode(':', $reason, 3)[2],
                'until' => userdate($until, get_string('strftimedatetimeshort', 'langconfig'))]);
        }

        return '';
    }

    /**
     * What a start outside the line has to add (#163): that its sittings wait while another experiment holds the line.
     *
     * @param int $experimentid The experiment whose runs were started.
     * @return string A sentence, or '' when nothing else holds the line.
     */
    public static function started_outside_note(int $experimentid): string {
        $holder = self::holder();
        if ($holder <= 0 || $holder === $experimentid) {
            return '';
        }

        return get_string('queue:startedoutside', 'local_catquizlab', $holder);
    }

    /**
     * Where an experiment stands in the line, for a page that shows it (#163).
     *
     * @param int $experimentid The experiment.
     * @return array{state: ?string, place: int, holder: int, note: string} state: waiting, running or null; place: 1 for
     *      the holder, 0 when not in the line; holder: the experiment holding the line, 0 for none.
     */
    public static function turn(int $experimentid): array {
        $out = ['state' => null, 'place' => 0, 'holder' => 0, 'note' => ''];
        foreach (self::entries() as $entry) {
            if ($entry['running'] && $out['holder'] === 0) {
                $out['holder'] = $entry['experimentid'];
            }
            if ($entry['experimentid'] === $experimentid && $out['state'] === null) {
                $out = ['state' => $entry['state'], 'place' => $entry['place'], 'holder' => $out['holder'],
                    'note' => $entry['note']];
            }
        }
        if ($out['holder'] === 0) {
            $out['holder'] = self::holder();
        }

        return $out;
    }

    /**
     * Whether this experiment is waiting or running.
     *
     * @param int $experimentid The experiment.
     * @return string|null The state, or null when it is not in the line.
     */
    public static function state_of(int $experimentid): ?string {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_catquizlab_execqueue')) {
            return null;
        }

        $row = $DB->get_record_select(
            'local_catquizlab_execqueue',
            'experimentid = :experimentid AND state IN (:waiting, :running)',
            [
                'experimentid' => $experimentid,
                'waiting'      => self::WAITING,
                'running'      => self::RUNNING,
            ]
        );

        return $row ? $row->state : null;
    }
}
