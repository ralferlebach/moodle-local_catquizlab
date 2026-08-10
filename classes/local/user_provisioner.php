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
 * User provisioner: turns ground-truth person rows into real Moodle users.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Creates the Moodle users that embody a run's simulated persons (E2.3, part 2).
 *
 * For each person row of a run that has no linked user yet, it creates a real
 * Moodle user via the core user API (requirement 2.6.B), records the id on the
 * person row, and optionally gathers the users into a cohort. It uses only core
 * APIs — no CAT engine and no host activity — so it runs on any Moodle. Course
 * enrolment and the CAT test binding (2.6.C) are a separate step, since they
 * need a course and the adaptivequiz activity. Login credentials are not set
 * here; the worker-login mechanism is a distinct, later decision.
 *
 * Usernames and human names are derived from each person's stored label (from
 * the naming engine), made unique and valid, so the users are traceable back to
 * their ground truth.
 */
class user_provisioner {
    /**
     * Provision Moodle users for the persons of a run.
     *
     * @param int $runid The run whose persons to provision.
     * @param array $options Optional: 'usernameprefix' (default 'catlab'),
     *                       'emaildomain' (default 'catlab.invalid'),
     *                       'cohortname' (when set, users are added to that system cohort).
     * @return array{created: int, cohortid: int} How many users were created and the cohort id (0 if none).
     */
    public static function provision(int $runid, array $options = []): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/user/lib.php');

        $prefix = (string) ($options['usernameprefix'] ?? 'catlab');
        $domain = (string) ($options['emaildomain'] ?? 'catlab.invalid');
        $cohortid = self::resolve_cohort($options['cohortname'] ?? null);

        $persons = $DB->get_records_select(
            'local_catquizlab_person',
            'runid = :runid AND moodleuserid IS NULL',
            ['runid' => $runid],
            'id ASC'
        );

        $created = 0;
        foreach ($persons as $person) {
            $userid = self::create_user($person, $runid, $prefix, $domain);
            $DB->set_field('local_catquizlab_person', 'moodleuserid', $userid, ['id' => $person->id]);
            if ($cohortid > 0) {
                self::add_to_cohort($cohortid, $userid);
            }
            $created++;
        }

        return ['created' => $created, 'cohortid' => $cohortid];
    }

    /**
     * Create one Moodle user for a person row.
     *
     * @param \stdClass $person The person row.
     * @param int $runid The owning run id (used to keep usernames unique across runs).
     * @param string $prefix Username prefix.
     * @param string $domain Email domain.
     * @return int The new user id.
     */
    protected static function create_user(\stdClass $person, int $runid, string $prefix, string $domain): int {
        $label = self::person_label($person);

        $base = clean_param(
            \core_text::strtolower($prefix . '_r' . $runid . '_' . $label),
            PARAM_USERNAME
        );
        $username = self::unique_username($base, $person->id);

        $user = (object) [
            'auth'      => 'manual',
            'confirmed' => 1,
            'mnethostid' => 1,
            'username'  => $username,
            'firstname' => $label,
            'lastname'  => ucfirst((string) $person->stratum),
            'email'     => $username . '@' . $domain,
        ];

        return user_create_user($user, false, false);
    }

    /**
     * Resolve the human-readable label of a person from its profile, or a fallback.
     *
     * @param \stdClass $person The person row.
     * @return string
     */
    protected static function person_label(\stdClass $person): string {
        $profile = json_decode((string) $person->profilejson, true);
        if (is_array($profile) && !empty($profile['label'])) {
            return (string) $profile['label'];
        }
        return 'person-' . $person->id;
    }

    /**
     * Make a username unique by appending the person id when needed.
     *
     * @param string $base The cleaned base username.
     * @param int $personid The person id, used as a stable disambiguator.
     * @return string A username not currently present in the user table.
     */
    protected static function unique_username(string $base, int $personid): string {
        global $DB;

        if ($base === '') {
            $base = 'catlab_person';
        }
        $username = $base;
        if ($DB->record_exists('user', ['username' => $username, 'mnethostid' => 1])) {
            $username = $base . '_' . $personid;
        }
        return \core_text::substr($username, 0, 100);
    }

    /**
     * Resolve (creating if necessary) a system cohort by name.
     *
     * @param string|null $cohortname The cohort name, or null to skip cohorts.
     * @return int The cohort id, or 0 when no cohort was requested.
     */
    protected static function resolve_cohort(?string $cohortname): int {
        global $DB, $CFG;

        if ($cohortname === null || trim($cohortname) === '') {
            return 0;
        }
        require_once($CFG->dirroot . '/cohort/lib.php');

        $systemcontext = \context_system::instance();
        $existing = $DB->get_record('cohort', [
            'contextid' => $systemcontext->id,
            'name'      => $cohortname,
        ]);
        if ($existing) {
            return (int) $existing->id;
        }

        return (int) cohort_add_cohort((object) [
            'contextid'   => $systemcontext->id,
            'name'        => $cohortname,
            'idnumber'    => '',
            'description' => '',
        ]);
    }

    /**
     * Add a user to a cohort unless already a member.
     *
     * @param int $cohortid The cohort id.
     * @param int $userid The user id.
     * @return void
     */
    protected static function add_to_cohort(int $cohortid, int $userid): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');

        if (!$DB->record_exists('cohort_members', ['cohortid' => $cohortid, 'userid' => $userid])) {
            cohort_add_member($cohortid, $userid);
        }
    }
}
