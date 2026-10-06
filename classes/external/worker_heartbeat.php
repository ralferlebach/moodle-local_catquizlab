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

namespace local_catquizlab\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_catquizlab\local\worker_launcher;
use local_catquizlab\local\worker_registry;

/**
 * A worker saying it is alive, and what it is doing.
 *
 * Until now a worker was only heard from when it claimed a job and when it
 * finished one. An attempt takes minutes, so a working worker went quiet for
 * exactly as long as the timeout that declares it dead — and the registry could
 * not tell "playing an attempt" from "process gone" without guessing.
 *
 * The reply carries a stop flag, which is how a worker gets told to finish the
 * attempt it is on and then exit. Killing the process would leave the claimed
 * attempt with nobody to finish it, which is the state all of this exists to
 * prevent.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class worker_heartbeat extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'workerid'  => new external_value(PARAM_TEXT, 'The worker instance reporting in.'),
            'attemptid' => new external_value(PARAM_INT, 'The attempt it is playing, or 0.', VALUE_DEFAULT, 0),
            'state'     => new external_value(
                PARAM_ALPHA,
                'What it is doing: working, idle or stopping.',
                VALUE_DEFAULT,
                'working'
            ),
            'reason'    => new external_value(
                PARAM_ALPHAEXT,
                'Why it is stopping: queue-empty, max-jobs, stop-requested or fatal-error.',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Record the heartbeat and answer whether to keep going.
     *
     * @param string $workerid The worker instance.
     * @param int $attemptid The attempt being played, or 0.
     * @param string $state What the worker is doing.
     * @param string $reason Why it is stopping, when it is: queue-empty, max-jobs, stop-requested or fatal-error.
     * @return array
     */
    public static function execute(
        string $workerid,
        int $attemptid = 0,
        string $state = 'working',
        string $reason = ''
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'workerid'  => $workerid,
            'attemptid' => $attemptid,
            'state'     => $state,
            'reason'    => $reason,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/catquizlab:worker', $context);

        $known = worker_registry::report(
            $params['workerid'],
            (int) $params['attemptid'],
            (string) $params['state']
        );

        // A worker the registry has never seen, presenting a valid token: it
        // was started by hand, or by a CI job, rather than by the launcher.
        // Telling it to stop made every such worker play exactly nothing. It
        // takes a free slot instead; only when none is free does it stop, since
        // the slot count is the one limit that has to hold.
        if (!$known && (string) $params['state'] !== 'stopping') {
            $known = worker_registry::adopt((string) $params['workerid']);
        }

        $stopping = in_array((string) $params['state'], ['stopping', 'stopped'], true);
        $why = (string) $params['reason'];

        if ($stopping) {
            worker_registry::record_stop((string) $params['workerid'], $why);

            // Replaced only when it left because of its rotation limit: that is
            // a resource setting ending a process, and the work goes on. A
            // worker that stopped because it was asked to, or because the
            // queue was empty, is not replaced — starting one then would undo
            // the stop somebody requested, which it did for a release.
            if ($why === 'max-jobs' && worker_registry::replacement_needed()['needed']) {
                worker_launcher::launch_pool(worker_launcher::config_from_settings());
            }
        }

        return [
            // A worker the registry does not know has outlived its own record —
            // its slot was reaped and possibly given away. Telling it to stop is
            // better than letting two workers believe they hold one slot.
            'stop'  => !$known || worker_registry::stop_requested($params['workerid']),
            'known' => $known,
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'stop'  => new external_value(PARAM_BOOL, 'Whether the worker should finish up and exit.'),
            'known' => new external_value(PARAM_BOOL, 'Whether the registry still knows this worker.'),
        ]);
    }
}
