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
 * Setting a worker up, from the plugin rather than from a shell.
 *
 * The worker token used to be the one piece that forced an operator out of the
 * workflow: the service existed, the setting expected a token, and creating one
 * meant finding the right admin pages and enabling web services by hand. Every
 * step of that is mechanical, which is a reason to do it rather than to
 * document it.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class worker_setup {
    /** @var string The shortname of the worker web service. */
    public const SERVICE = 'local_catquizlab_worker';

    /**
     * Make sure a usable worker token exists, creating one if not.
     *
     * @return bool Whether a token was created now.
     */
    public static function ensure_token(): bool {
        global $DB, $USER, $CFG;
        require_once($CFG->dirroot . '/webservice/lib.php');

        if (system_health::worker_token() !== null) {
            return false;
        }

        // Web services and REST have to be on, or the token is a string that
        // cannot be used. Turning them on is part of the setup, not a
        // precondition for it.
        set_config('enablewebservices', 1);
        $protocols = array_filter(explode(',', (string) get_config('core', 'webserviceprotocols')));
        if (!in_array('rest', $protocols, true)) {
            $protocols[] = 'rest';
            set_config('webserviceprotocols', implode(',', $protocols));
        }

        $service = $DB->get_record('external_services', ['shortname' => self::SERVICE]);
        if (!$service) {
            return false;
        }
        if ((int) $service->enabled !== 1) {
            $DB->set_field('external_services', 'enabled', 1, ['id' => $service->id]);
            $service->enabled = 1;
        }

        // The service is restricted to authorised users on purpose, so the
        // account creating the token has to be enrolled in it explicitly.
        if (
            (int) $service->restrictedusers === 1
            && !$DB->record_exists('external_services_users', [
                'externalserviceid' => (int) $service->id,
                'userid'            => (int) $USER->id,
            ])
        ) {
            $DB->insert_record('external_services_users', (object) [
                'externalserviceid' => (int) $service->id,
                'userid'            => (int) $USER->id,
                'timecreated'       => time(),
            ]);
        }

        // The low-level call on purpose. The convenience wrapper checks
        // moodle/webservice:createtoken for the current user, which is the
        // right question when a person mints a token for themselves — here the
        // token belongs to the worker, and the permission that matters
        // (local/catquizlab:execute) was checked before this was reached.
        $token = \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            $service,
            (int) $USER->id,
            \context_system::instance(),
            0,
            '',
            'CATLab worker'
        );

        // Written into the plugin's own setting too, so the worker command the
        // interface shows is complete and can be copied as it stands.
        set_config('worker_token', $token, 'local_catquizlab');

        return true;
    }
}
