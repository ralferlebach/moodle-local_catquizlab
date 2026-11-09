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
     * Put an experiment in the line.
     *
     * @param int $experimentid The experiment.
     * @return array{ok: bool, position: int, reason: string}
     */
    public static function enqueue(int $experimentid): array {
        global $DB, $USER;

        // Only an experiment that is actually ready: queueing one that has not
        // been prepared means it reaches the front of the line and fails there,
        // having held up everything behind it.
        // An experiment being deleted (0.7.50) does not go back into the line.
        if ((int) $DB->get_field('local_catquizlab_experiment', 'timedeleting', ['id' => $experimentid]) > 0) {
            return ['ok' => false, 'position' => 0, 'reason' => 'being-deleted'];
        }
        $state = experiment_runner::state($experimentid);
        if (!$state['canstart']) {
            return ['ok' => false, 'position' => 0, 'reason' => 'not-ready'];
        }

        // The runtime — browser, worker, pipeline — is needed to run, not to
        // wait in line. An entry queued before the worker is set up simply
        // waits; what is still missing is said now, and checked again when its
        // turn comes.
        $setup = setup_wizard::state();
        $warning = empty($setup['ready']) ? 'setup-incomplete: ' . implode(', ', $setup['blockers']) : '';

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
            return ['ok' => true, 'position' => (int) $existing->position, 'reason' => 'already-queued'];
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

        return ['ok' => true, 'position' => $position, 'reason' => '', 'warning' => $warning];
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

        // Close out whatever finished since the last tick, so the line moves.
        $closed = [];
        foreach ($DB->get_records('local_catquizlab_execqueue', ['state' => self::RUNNING]) as $entry) {
            $experimentid = (int) $entry->experimentid;
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
            $done = [experiment_runner::STATE_FINISHED, experiment_runner::STATE_BLOCKED];
            if (in_array($state['state'], $done, true)) {
                self::close(
                    $entry,
                    self::FINISHED,
                    $state['state'] === experiment_runner::STATE_BLOCKED ? 'blocked' : ''
                );
                $closed[] = ['experimentid' => $experimentid, 'reason' => $state['state']];
                continue;
            }

            // Still going: nothing else starts, and which one holds the line is said.
            return ['started' => 0, 'reason' => 'busy', 'holder' => $experimentid,
                'since' => (int) $entry->timestarted, 'closed' => $closed];
        }

        // Waiting entries whose experiment is gone or has no runs left: taken out of the line now, not when
        // they reach its front, so the line shows what will really run.
        foreach ($DB->get_records('local_catquizlab_execqueue', ['state' => self::WAITING]) as $entry) {
            if (!$DB->record_exists('local_catquizlab_experiment', ['id' => $entry->experimentid])) {
                self::close($entry, self::CANCELLED, 'orphaned: experiment deleted');
                $closed[] = ['experimentid' => (int) $entry->experimentid, 'reason' => 'orphaned: experiment deleted'];
            } else if (!$DB->record_exists('local_catquizlab_run', ['experimentid' => $entry->experimentid])) {
                self::close($entry, self::CANCELLED, 'orphaned: experiment has no runs');
                $closed[] = ['experimentid' => (int) $entry->experimentid, 'reason' => 'orphaned: experiment has no runs'];
            }
        }

        $next = $DB->get_records('local_catquizlab_execqueue', ['state' => self::WAITING], 'position ASC', '*', 0, 1);
        if ($next === []) {
            return ['started' => 0, 'reason' => 'empty', 'closed' => $closed];
        }

        $entry = reset($next);
        $experimentid = (int) $entry->experimentid;

        // The installation has to be able to run now. An entry queued before
        // the runtime was set up waits here — it is not skipped, because the
        // thing missing is on the installation, not on the experiment.
        if (empty(setup_wizard::state()['ready'])) {
            return ['started' => 0, 'reason' => 'setup-incomplete', 'holder' => $experimentid, 'closed' => $closed];
        }

        // Ready when it reaches the front, not merely when it was queued: an
        // experiment can be reset or fail while it waits.
        $state = experiment_runner::state($experimentid);
        if (!$state['canstart']) {
            $DB->update_record('local_catquizlab_execqueue', (object) [
                'id'           => $entry->id,
                'state'        => self::FINISHED,
                'reason'       => 'not-ready-at-turn',
                'timefinished' => time(),
            ]);

            return ['started' => 0, 'reason' => 'skipped-not-ready', 'holder' => $experimentid,
                'closed' => $closed];
        }

        $DB->update_record('local_catquizlab_execqueue', (object) [
            'id'          => $entry->id,
            'state'       => self::RUNNING,
            'timestarted' => time(),
        ]);

        return ['started' => $experimentid, 'reason' => '', 'closed' => $closed];
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
        if (in_array($state, [experiment_runner::STATE_DRAFT, experiment_runner::STATE_CREATED], true)) {
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
                'place'        => $place,
                'queuedago'    => duration::ago((int) $row->timecreated),
            ];
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
