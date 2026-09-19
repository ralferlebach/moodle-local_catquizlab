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
 * What is wrong, and the one thing to do about it.
 *
 * Five recovery buttons side by side ask a person to diagnose the problem
 * before they can act on it — to know that a lease is not a claim, that reaping
 * is not releasing, and which of the five applies today. Somebody running an
 * experiment should not have to learn the plumbing to get past a stuck queue.
 *
 * So: name the problem in the words of the thing that is not working, recommend
 * one action, and keep the rest behind a fold for whoever actually wants them.
 *
 * Nothing here is new capability. Every action it recommends already existed;
 * what it adds is the judgement about which one applies, which is the part that
 * was being left to the reader.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recovery_advisor {
    /**
     * The first thing standing in the way, if anything is.
     *
     * @param int $experimentid The experiment in view, or 0.
     * @return array|null problem, action and command, or null when all is well.
     */
    public static function advise(int $experimentid = 0): ?array {
        global $DB;

        $component = 'local_catquizlab';
        $breakdown = attempt_scheduler::queue_breakdown();
        $workers = worker_registry::summary();

        // A held run comes first: everything else is downstream of it, and
        // restarting a worker would not help.
        if ($experimentid > 0) {
            $held = $DB->get_records('local_catquizlab_run', [
                'experimentid' => $experimentid,
                'status'       => registry::STATUS_FAILED,
            ], 'id ASC', 'id', 0, 1);

            if ($held !== []) {
                $run = reset($held);

                return self::problem(
                    get_string('recovery:problemheld', $component, (int) $run->id),
                    get_string('circuit:showdetail', $component),
                    null,
                    (new \moodle_url('/local/catquizlab/logs.php', ['runid' => (int) $run->id]))->out(false)
                );
            }
        }

        // Claims held by workers that stopped reporting. Named as what it
        // looks like — sittings stuck — rather than as orphaned leases.
        $stranded = $DB->count_records_select(
            'local_catquizlab_attempt',
            'status = :running AND leaseexpires < :now',
            ['running' => attempt_scheduler::STATUS_RUNNING, 'now' => time()]
        );

        if ($stranded > 0) {
            return self::problem(
                get_string('recovery:problemstranded', $component, $stranded),
                get_string('recovery:actionstranded', $component),
                'releaseorphans'
            );
        }

        // Work waiting with nobody to do it.
        if ($breakdown['claimable'] > 0 && (int) $workers['live'] === 0) {
            $cron = task_overview::cron_state();

            // Cron not running is the cause; starting a worker by hand would
            // fix this minute and not the next one.
            if (empty($cron['ok'])) {
                return self::problem(
                    get_string('recovery:problemnocron', $component),
                    get_string('recovery:actionnocron', $component),
                    null,
                    (new \moodle_url('/admin/settings.php', ['section' => 'systempaths']))->out(false)
                );
            }

            return self::problem(
                get_string('recovery:problemnoworker', $component, (int) $breakdown['claimable']),
                get_string('recovery:actionnoworker', $component),
                'startworkers'
            );
        }

        // Workers that died holding nothing: tidy, not urgent, and only worth
        // mentioning because their rows make the worker list confusing.
        if ((int) $workers['crashed'] > 0 && (int) $workers['live'] === 0) {
            return self::problem(
                get_string('recovery:problemcrashed', $component, (int) $workers['crashed']),
                get_string('recovery:actioncrashed', $component),
                'reap'
            );
        }

        return null;
    }

    /**
     * Assemble one problem and its recommended action.
     *
     * @param string $problem What is wrong, in plain words.
     * @param string $label What the button should say.
     * @param string|null $command The posted action, where it is one.
     * @param string|null $url Where it goes, for actions that only navigate.
     * @return array
     */
    protected static function problem(
        string $problem,
        string $label,
        ?string $command = null,
        ?string $url = null
    ): array {
        return [
            'problem' => $problem,
            'label'   => $label,
            'command' => $command,
            'url'     => $url ?? (new \moodle_url('/local/catquizlab/runs.php'))->out(false),
            'sesskey' => sesskey(),
        ];
    }
}
