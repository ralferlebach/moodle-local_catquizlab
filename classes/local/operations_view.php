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
 * Everything the setup and operations view shows, gathered once.
 *
 * Extracted so the view can be rendered inside the plugin's own page rather
 * than only on a page of its own. Operating a plugin from three separate pages
 * means knowing which page holds which half — which is the knowledge this
 * refactor removes.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class operations_view {
    /**
     * The template context for the setup and operations view.
     *
     * @return array
     */
    public static function context(): array {
        global $DB, $SESSION;

        $component = 'local_catquizlab';
        $pageurl = new \moodle_url('/local/catquizlab/index.php', ['tab' => 'setup']);

        $wizard = \local_catquizlab\local\setup_wizard::state();
        $runtime = \local_catquizlab\local\worker_runtime::verify();
        $health = system_health::health();
        $access = \local_catquizlab\local\worker_access::verify();

        $workers = array_map(static function (\stdClass $worker): array {
            return [
                'workerid'  => $worker->workerid,
                'slot'      => (int) $worker->slot,
                'jobsdone'  => (int) $worker->jobsdone,
                'heartbeat' => userdate((int) $worker->heartbeat, get_string('strftimedatetimeshort')),
                'lasterror' => $worker->lasterror,
            ];
        }, worker_registry::live());

        $queue = [
            'queued'    => $DB->count_records('local_catquizlab_attempt', ['status' => attempt_scheduler::STATUS_QUEUED]),
            'running'   => $DB->count_records('local_catquizlab_attempt', ['status' => attempt_scheduler::STATUS_RUNNING]),
            'collected' => $DB->count_records('local_catquizlab_attempt', ['status' => attempt_scheduler::STATUS_COLLECTED]),
            'failed'    => $DB->count_records('local_catquizlab_attempt', ['status' => attempt_scheduler::STATUS_FAILED]),
        ];

        // The claims held longest: "which attempt is stuck, and since when" was one of
        // the questions that needed a database client.
        $stuck = array_values(array_map(static function (\stdClass $row): array {
            return [
                'attemptid' => (int) $row->id,
                'runid'     => (int) $row->runid,
                'owner'     => $row->leaseowner,
                'tries'     => (int) $row->tries,
                'since'     => format_time(time() - (int) $row->timemodified),
                'lasterror' => $row->lasterror,
            ];
        }, $DB->get_records(
            'local_catquizlab_attempt',
            ['status' => attempt_scheduler::STATUS_RUNNING],
            'timemodified ASC',
            'id, runid, leaseowner, tries, timemodified, lasterror',
            0,
            10
        )));

        $inflight = array_values(array_map(static function (\stdClass $run) use ($component, $pageurl): array {
            $counts = run_lifecycle::attempt_counts((int) $run->id);
            $paused = run_lifecycle::is_paused((int) $run->id);

            return [
                'runid'     => (int) $run->id,
                'cellkey'   => $run->cellkey,
                'status'    => \local_catquizlab\local\run_registry::status_label((int) $run->status),
                'total'     => $counts['total'],
                'open'      => $counts['open'],
                'collected' => $counts['collected'],
                'failed'    => $counts['failed'],
                'paused'    => $paused,
                'pauseurl'  => (new \moodle_url($pageurl, [
                    'action'  => $paused ? 'resumerun' : 'pauserun',
                    'runid'   => (int) $run->id,
                    'sesskey' => sesskey(),
                ]))->out(false),
            ];
        }, $DB->get_records_select(
            'local_catquizlab_run',
            'status IN (:scheduled, :ready, :running, :aggregating)',
            [
                'scheduled'   => registry::STATUS_SCHEDULED,
                'ready'       => registry::STATUS_READY,
                'running'     => registry::STATUS_RUNNING,
                'aggregating' => registry::STATUS_AGGREGATING,
            ],
            'id ASC',
            'id, cellkey, status',
            0,
            20
        )));

        return [
        'health'     => $health,
        'workers'    => ['hasany' => $workers !== [], 'rows' => $workers],
        'queue'      => $queue,
        'stuck'      => ['hasany' => $stuck !== [], 'rows' => $stuck],
        'inflight'   => ['hasany' => $inflight !== [], 'rows' => $inflight],
        'sesskey'    => sesskey(),
        // Actions post to operations.php, which performs them and sends the
        // person back to this tab. Keeping the handler separate from the view
        // is what lets the same view render inside the tabbed page.
        'formurl'    => (new \moodle_url('/local/catquizlab/operations.php'))->out(false),
        'actionurl'  => $pageurl->out(false),
        'wizard'     => $wizard,
        'runtime'    => $runtime,
        'canruntime' => !$runtime['ok'],
        'access'     => $access,
        'cansetup'   => !$access['ok'],
        'empty'      => (static function (): ?array {
            $rows = \local_catquizlab\local\engine_hygiene::list_empty_attempts();

            return $rows === [] ? null : ['count' => count($rows), 'rows' => $rows];
        })(),
        // Kept for one page load: a self-test result is worth reading once, and
        // storing it would turn a diagnostic into state to maintain.
        'selftest'   => (static function () {
            global $SESSION;
            $result = $SESSION->catquizlab_selftest ?? null;
            unset($SESSION->catquizlab_selftest);
            if ($result === null) {
                return null;
            }

            return [
                'ok'      => (int) $result['exitcode'] === 0,
                'output'  => $result['output'],
                'command' => $result['command'],
            ];
        })(),
        ];
    }
}
