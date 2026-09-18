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
 * The worker's access to Moodle, set up and checked in one place.
 *
 * Getting a worker running took ten steps across four areas of the Moodle
 * administration: enable web services, enable REST, enable the external
 * service, create a technical user, create a system role, grant two
 * capabilities to it, assign the role, authorise the user for the restricted
 * service, mint a token for exactly that pair, and paste it back into the
 * plugin setting. Any one of those can be missing, and the failure then looks
 * the same as the other twelve: a worker that claims nothing.
 *
 * None of it is a configuration decision. The service exists for this worker,
 * its three functions are this plugin's, and `local/catquizlab:worker` is
 * granted to no role by default precisely because it is not meant to be handed
 * around. That makes this internal infrastructure, and internal infrastructure
 * should be set up by the thing that needs it.
 *
 * Two entry points, and the distinction matters: {@see self::verify()} only
 * looks, so it can run on every page load; {@see self::ensure()} changes the
 * site and is called when somebody asks for it.
 *
 * A dedicated account rather than the administrator's: the token carries
 * exactly the three functions the worker calls, and if it leaks it is worth
 * exactly that. An administrator's token is worth the administrator.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class worker_access {
    /** @var string Username of the technical account. */
    public const USERNAME = 'catquizlab_worker';

    /** @var string Shortname of the system role the account holds. */
    public const ROLE = 'catquizlabworker';

    /** @var string Shortname of the external service. */
    public const SERVICE = 'local_catquizlab_worker';

    /**
     * What is in place and what is not.
     *
     * Read-only: this runs on page loads, and a check that changes the site is
     * not a check.
     *
     * @return array{ok: bool, steps: array[], missing: string[]}
     */
    public static function verify(): array {
        global $DB;

        $component = 'local_catquizlab';
        $steps = [];

        $steps[] = self::step(
            'webservices',
            get_string('access:webservices', $component),
            (int) get_config('core', 'enablewebservices') === 1
        );

        $protocols = array_filter(explode(',', (string) get_config('core', 'webserviceprotocols')));
        $steps[] = self::step(
            'rest',
            get_string('access:rest', $component),
            in_array('rest', $protocols, true)
        );

        $service = $DB->get_record('external_services', ['shortname' => self::SERVICE]);
        $steps[] = self::step(
            'service',
            get_string('access:service', $component),
            $service && (int) $service->enabled === 1
        );

        $auths = array_filter(explode(',', (string) get_config('core', 'auth')));
        $steps[] = self::step(
            'authplugin',
            get_string('access:authplugin', $component),
            in_array('webservice', $auths, true)
        );

        $user = $DB->get_record('user', ['username' => self::USERNAME, 'deleted' => 0]);
        $steps[] = self::step(
            'user',
            get_string('access:user', $component),
            $user && (int) $user->suspended === 0 && $user->auth === 'webservice'
        );

        $role = $DB->get_record('role', ['shortname' => self::ROLE]);
        $steps[] = self::step('role', get_string('access:role', $component), (bool) $role);

        $context = \context_system::instance();
        $hascaps = false;
        if ($role) {
            // Both capabilities, not one: the endpoints check
            // local/catquizlab:worker, and Moodle refuses the REST call itself
            // without webservice/rest:use. Missing either produces the same
            // silence.
            $hascaps = $DB->record_exists('role_capabilities', [
                'roleid' => $role->id, 'capability' => 'local/catquizlab:worker',
                'permission' => CAP_ALLOW, 'contextid' => $context->id,
            ]) && $DB->record_exists('role_capabilities', [
                'roleid' => $role->id, 'capability' => 'webservice/rest:use',
                'permission' => CAP_ALLOW, 'contextid' => $context->id,
            ]);
        }
        $steps[] = self::step('capabilities', get_string('access:capabilities', $component), $hascaps);

        $assigned = $role && $user && $DB->record_exists('role_assignments', [
            'roleid' => $role->id, 'userid' => $user->id, 'contextid' => $context->id,
        ]);
        $steps[] = self::step('assignment', get_string('access:assignment', $component), (bool) $assigned);

        $authorised = $service && $user && ((int) $service->restrictedusers !== 1
            || $DB->record_exists('external_services_users', [
                'externalserviceid' => $service->id, 'userid' => $user->id,
            ]));
        $steps[] = self::step('authorisation', get_string('access:authorisation', $component), (bool) $authorised);

        // The token has to belong to this user *and* this service. A token for
        // the right service and the wrong account fails with the same silence
        // as no token at all, and it is the mistake the manual sequence invites.
        $token = null;
        if ($service && $user) {
            $tokens = $DB->get_records('external_tokens', [
                'externalserviceid' => $service->id,
                'userid'            => $user->id,
            ], 'id DESC', '*', 0, 1);
            $token = $tokens ? reset($tokens) : null;
        }
        $steps[] = self::step('token', get_string('access:token', $component), (bool) $token);

        $stored = (string) get_config($component, 'worker_token');
        $steps[] = self::step(
            'storedtoken',
            get_string('access:storedtoken', $component),
            $token !== null && $stored === (string) $token->token
        );

        $missing = [];
        foreach ($steps as $step) {
            if (!$step['ok']) {
                $missing[] = $step['id'];
            }
        }

        return ['ok' => $missing === [], 'steps' => $steps, 'missing' => $missing];
    }

    /**
     * Put everything in place, skipping what already is.
     *
     * Idempotent by construction: every step checks before it acts, so running
     * it twice is the same as running it once, and running it after a partial
     * manual setup completes that setup rather than duplicating it.
     *
     * @return array{ok: bool, changed: string[], steps: array[]}
     */
    public static function ensure(): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/webservice/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/accesslib.php');

        $changed = [];
        $context = \context_system::instance();

        if ((int) get_config('core', 'enablewebservices') !== 1) {
            set_config('enablewebservices', 1);
            $changed[] = 'webservices';
        }

        $protocols = array_filter(explode(',', (string) get_config('core', 'webserviceprotocols')));
        if (!in_array('rest', $protocols, true)) {
            $protocols[] = 'rest';
            set_config('webserviceprotocols', implode(',', $protocols));
            $changed[] = 'rest';
        }

        // The account's authentication type has to be an enabled one, or its
        // calls are refused however correct everything else is.
        $auths = array_filter(explode(',', (string) get_config('core', 'auth')));
        if (!in_array('webservice', $auths, true)) {
            $auths[] = 'webservice';
            set_config('auth', implode(',', $auths));
            \core\session\manager::gc();
            $changed[] = 'authplugin';
        }

        $service = $DB->get_record('external_services', ['shortname' => self::SERVICE]);
        if (!$service) {
            // Declared in db/services.php, so its absence means the plugin is
            // not installed properly — nothing here can repair that.
            return ['ok' => false, 'changed' => $changed, 'steps' => self::verify()['steps']];
        }
        if ((int) $service->enabled !== 1) {
            $DB->set_field('external_services', 'enabled', 1, ['id' => $service->id]);
            $service->enabled = 1;
            $changed[] = 'service';
        }

        $user = self::ensure_user($changed);
        $role = self::ensure_role($context, $changed);

        if (
            $role && $user && !$DB->record_exists('role_assignments', [
            'roleid' => $role->id, 'userid' => $user->id, 'contextid' => $context->id,
            ])
        ) {
            role_assign($role->id, $user->id, $context->id);
            $changed[] = 'assignment';
        }

        if (
            (int) $service->restrictedusers === 1 && $user && !$DB->record_exists('external_services_users', [
            'externalserviceid' => $service->id, 'userid' => $user->id,
            ])
        ) {
            $DB->insert_record('external_services_users', (object) [
                'externalserviceid' => $service->id,
                'userid'            => $user->id,
                'timecreated'       => time(),
            ]);
            $changed[] = 'authorisation';
        }

        if ($user) {
            self::ensure_token($service, $user, $context, $changed);
        }

        $verified = self::verify();

        return ['ok' => $verified['ok'], 'changed' => $changed, 'steps' => $verified['steps']];
    }

    /**
     * The technical account, created if it is not there.
     *
     * @param string[] $changed Collects what was done.
     * @return \stdClass|null
     */
    protected static function ensure_user(array &$changed): ?\stdClass {
        global $DB, $CFG;

        $user = $DB->get_record('user', ['username' => self::USERNAME, 'deleted' => 0]);
        if ($user) {
            if ((int) $user->suspended === 1) {
                $DB->set_field('user', 'suspended', 0, ['id' => $user->id]);
                $changed[] = 'user';
            }

            // An account created by an earlier version, or by hand, with an
            // authentication type web services refuse.
            if ($user->auth !== 'webservice') {
                $DB->set_field('user', 'auth', 'webservice', ['id' => $user->id]);
                $user->auth = 'webservice';
                $changed[] = 'user';
            }

            return $user;
        }

        $new = new \stdClass();
        $new->username = self::USERNAME;
        $new->firstname = 'CATLab';
        $new->lastname = 'Worker';
        // A dedicated technical account: the token it carries is worth exactly
        // the three functions the worker calls, where an administrator's token
        // is worth the administrator.
        $new->email = 'catquizlab-worker@' . parse_url($CFG->wwwroot, PHP_URL_HOST);
        // Moodle has an authentication type for exactly this: a token-only
        // account. 'nologin' looks equivalent and is not — web service calls
        // under it are refused with wsaccessusernologin, which reads like a
        // permission problem and is an account-type problem.
        $new->auth = 'webservice';
        $new->confirmed = 1;
        $new->mnethostid = $CFG->mnet_localhost_id;
        $new->policyagreed = 1;
        $new->description = get_string('access:userdescription', 'local_catquizlab');

        $id = user_create_user($new, false, false);
        $changed[] = 'user';

        return $DB->get_record('user', ['id' => $id]);
    }

    /**
     * The system role with the two capabilities the worker needs.
     *
     * @param \context $context The system context.
     * @param string[] $changed Collects what was done.
     * @return \stdClass|null
     */
    protected static function ensure_role(\context $context, array &$changed): ?\stdClass {
        global $DB;

        $role = $DB->get_record('role', ['shortname' => self::ROLE]);
        if (!$role) {
            $roleid = create_role(
                get_string('access:rolename', 'local_catquizlab'),
                self::ROLE,
                get_string('access:roledescription', 'local_catquizlab')
            );
            // System context only. The worker has no business anywhere else,
            // and a role assignable in courses would invite exactly that.
            set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
            $role = $DB->get_record('role', ['id' => $roleid]);
            $changed[] = 'role';
        }

        foreach (['local/catquizlab:worker', 'webservice/rest:use'] as $capability) {
            $existing = $DB->get_record('role_capabilities', [
                'roleid' => $role->id, 'capability' => $capability, 'contextid' => $context->id,
            ]);
            if (!$existing || (int) $existing->permission !== CAP_ALLOW) {
                assign_capability($capability, CAP_ALLOW, $role->id, $context->id, true);
                $changed[] = 'capabilities';
            }
        }

        return $role;
    }

    /**
     * A token for this user and this service, stored where the worker reads it.
     *
     * @param \stdClass $service The external service.
     * @param \stdClass $user The technical account.
     * @param \context $context The system context.
     * @param string[] $changed Collects what was done.
     * @return void
     */
    protected static function ensure_token(
        \stdClass $service,
        \stdClass $user,
        \context $context,
        array &$changed
    ): void {
        global $DB;

        $tokens = $DB->get_records('external_tokens', [
            'externalserviceid' => $service->id,
            'userid'            => $user->id,
        ], 'id DESC', '*', 0, 1);

        $token = $tokens ? (string) reset($tokens)->token : null;
        if ($token === null) {
            // The low-level call deliberately: the convenience wrapper mints a
            // token for the *current* user, and the whole point here is that it
            // belongs to the technical account instead.
            $token = \core_external\util::generate_token(
                EXTERNAL_TOKEN_PERMANENT,
                $service,
                (int) $user->id,
                $context,
                0,
                '',
                'CATLab worker'
            );
            $changed[] = 'token';
        }

        if ((string) get_config('local_catquizlab', 'worker_token') !== $token) {
            // The step most often forgotten in the manual sequence: a token
            // created correctly and never pasted back leaves everything looking
            // right and nothing working.
            set_config('worker_token', $token, 'local_catquizlab');
            $changed[] = 'storedtoken';
        }
    }

    /**
     * One verification step.
     *
     * @param string $id Stable identifier.
     * @param string $label What it checks.
     * @param bool $ok Whether it holds.
     * @return array
     */
    protected static function step(string $id, string $label, bool $ok): array {
        return ['id' => $id, 'label' => $label, 'ok' => $ok, 'missing' => !$ok];
    }
}
