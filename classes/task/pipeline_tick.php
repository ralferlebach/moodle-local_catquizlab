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

/**
 * Scheduled task that keeps the attempt pipeline moving (E3.1/E3.2).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\task;

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\worker_launcher;

/**
 * Periodic maintenance: reclaim crashed attempts, then dispatch the worker pool.
 *
 * Reclaiming is always safe (lab-store bookkeeping). Dispatching only happens when
 * the exec worker is enabled and configured, so on hubs, worker-less nodes and CI
 * the task simply reclaims and returns.
 */
class pipeline_tick extends \core\task\scheduled_task {
    /** @var int Consider a running attempt crashed after this many seconds. */
    public const STALE_SECONDS = 1800;

    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:pipelinetick', 'local_catquizlab');
    }

    /**
     * Run one maintenance tick.
     *
     * @return void
     */
    public function execute(): void {
        // Announce which task this is, and continue the id of the click that
        // queued it: a failure inside a task otherwise reads as a failure from
        // nowhere.
        // A scheduled task starts its own sequence: nothing queued it, so
        // there is no id to continue.
        \local_catquizlab\local\debug_trace::enter_task('\\local_catquizlab\\task\\pipeline_tick');

        // The switch comes first. A disabled plugin that still advanced its
        // execution queue was starting experiments while saying it was off,
        // which is the one thing a switch must not do.
        if (!get_config('local_catquizlab', 'enabled')) {
            return;
        }

        // The execution queue moves here, because this is the thing that runs
        // by itself. Somebody who queued five experiments before going home
        // should find five results, not five experiments still waiting for a
        // button.
        $advanced = \local_catquizlab\local\execution_queue::advance();
        if ($advanced['started'] > 0) {
            mtrace('local_catquizlab: started queued experiment ' . $advanced['started'] . '.');
        }

        // Dead workers first, then their claims, then the timeout fallback.
        // The order matters: reaping a worker hands its attempts back with a
        // known reason, while the timeout can only guess that something went
        // wrong somewhere.
        $reaped = \local_catquizlab\local\worker_registry::reap();
        if ($reaped['workers'] > 0) {
            mtrace("local_catquizlab: reaped {$reaped['workers']} worker(s), "
                . "released {$reaped['attempts']} attempt(s).");
        }

        // Engine attempts that never got a first question. Every retry of a
        // failing attempt makes another, so they accumulate rather than appear.
        $purged = \local_catquizlab\local\engine_hygiene::purge_empty_attempts();
        if ($purged > 0) {
            mtrace("local_catquizlab: removed {$purged} engine attempt(s) that never started.");
        }

        $reclaimed = attempt_scheduler::reclaim_stale(null, self::STALE_SECONDS);
        if ($reclaimed > 0) {
            mtrace("local_catquizlab: reclaimed {$reclaimed} stale attempt(s).");
        }

        $result = worker_launcher::launch_pool(worker_launcher::config_from_settings());

        // Kept where the interface can read it. The tick's output goes to
        // cron's own log, which the person looking at a page that says nothing
        // is happening cannot see — and "nothing is happening" is exactly when
        // they need to know what the tick decided and why.
        set_config('lastdispatch', json_encode([
            'time'     => time(),
            'launched' => (int) ($result['launched'] ?? 0),
            'reason'   => (string) ($result['reason'] ?? ''),
            'claimable' => (int) \local_catquizlab\local\attempt_scheduler::queue_breakdown()['claimable'],
        ]), 'local_catquizlab');

        if ($result === null) {
            mtrace('local_catquizlab: worker pool not dispatched.');
        } else if ((int) $result['launched'] > 0) {
            mtrace("local_catquizlab: dispatched worker pool ({$result['launched']}).");
        } else if ((string) $result['reason'] !== '') {
            mtrace("local_catquizlab: no worker started: {$result['reason']}.");
        }
    }
}
