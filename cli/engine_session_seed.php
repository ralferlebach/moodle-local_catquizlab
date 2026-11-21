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
 * Two adaptive quizzes and two learners for the engine's session tests (#136 AK 2, AK 3, AK 7; #137 AK 5), and the
 * read-back of what the engine and the activity stored.
 *
 * Seeding (default): two experiments through e2e_prepare.php — each provisions a real mod_adaptivequiz activity with the
 * CAT model, its scales and its items, through the same path every lab run takes — with sittings of --items questions;
 * then two learners with known passwords, enrolled as students in the experiment course. Prints session=FILE: a JSON
 * file with the course, the two activities (cmid, the run's item question ids = the pool) and the two learners.
 *
 *     php local/catquizlab/cli/engine_session_seed.php [--items=6] [--file=/tmp/engine-session.json]
 *
 * Item change (--change-items --session=FILE): the stored difficulty of every item of activity A's pool shifted by
 * +0.25 logit in the engine's item parameters, with attempts open — what an operator's recalibration does mid-test
 * (#136: "Parameter-/Itemwechsel") — and the engine's caches invalidated as its own save does; and one item of A no one
 * was asked yet made inactive in its scale through the engine's own call (#136 AK 3: no inadmissible question after a
 * change). Prints {"changed": rows, "deactivated": question id}.
 *
 * Read-back (--report --session=FILE --user=ID): per activity, every attempt of that learner — id, state, questions
 * attempted, stop criterion and stop code where the version has them, standard error, ability — every question of its
 * question usage in order with its question id, whether a question was asked twice, whether every question is in the
 * activity's pool, and the engine's own attempt record (local_catquiz_attempts) where there is one. Read only.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/user/lib.php');

[$options] = cli_get_params(['items' => 6, 'file' => '', 'report' => false, 'session' => '', 'user' => 0,
    'change-items' => false, 'usage-probe' => false, 'verify-learners' => false, 'help' => false]);
if ($options['help']) {
    echo "php cli/engine_session_seed.php [--items=6] [--file=PATH] | --report --session=FILE --user=ID"
        . " | --change-items --session=FILE | --usage-probe --session=FILE | --verify-learners --session=FILE\n";
    exit(0);
}

/**
 * The engine's runtime pool of activity A as the next question would see it (local_catquiz catscale::get_testitems(),
 * from its application cache while that holds it), keyed by question id.
 *
 * @param array $session The seed's session file.
 * @return array<int, object>
 */
function local_catquizlab_engine_session_pool(array $session): array {
    global $DB;
    $test = $DB->get_record('local_catquiz_tests', ['componentid' => (int) $session['activities']['A']['instance'],
        'component' => 'mod_adaptivequiz'], 'id, catscaleid, contextid', MUST_EXIST);
    return (new \local_catquiz\catscale((int) $test->catscaleid))->get_testitems((int) $test->contextid, true);
}

if ($options['verify-learners']) {
    // Why a learner could not sign in (CI 20.11.2026, Moodle 5.2, candidate engine: "Your login info may be incorrect"
    // three times, the seed had reported nothing wrong): each learner of the session as the database has them.
    $session = json_decode((string) file_get_contents((string) $options['session']), true);
    $out = [];
    foreach ((array) ($session['learners'] ?? []) as $learner) {
        $user = $DB->get_record('user', ['username' => $learner['username'], 'mnethostid' => $CFG->mnet_localhost_id]);
        $out[] = ['username' => $learner['username'], 'exists' => (bool) $user,
            'id' => $user ? (int) $user->id : null, 'sessionid' => (int) $learner['id'],
            'auth' => $user->auth ?? null, 'enabled' => $user ? is_enabled_auth($user->auth) : null,
            'suspended' => $user ? (int) $user->suspended : null, 'deleted' => $user ? (int) $user->deleted : null,
            'confirmed' => $user ? (int) $user->confirmed : null,
            'passwordvalid' => $user ? validate_internal_user_password($user, (string) $learner['password']) : null,
            'lockedout' => $user ? login_is_lockedout($user) : null,
            'failedlogins' => $user ? (int) get_user_preferences('login_failed_count', 0, $user) : null];
    }
    echo json_encode(['learners' => $out]) . "\n";
    exit(0);
}

if ($options['usage-probe']) {
    // How old the engine's usage numbers are (#136 AK 3): the runtime pool's answers per item as cached, against the
    // same pool built anew from the database. The cache is purged for that, as an item change would: at the end of a
    // test, never in the middle of one.
    $session = json_decode((string) file_get_contents((string) $options['session']), true);
    $cached = local_catquizlab_engine_session_pool($session);
    \cache_helper::purge_by_event('changesintestitems');
    $fresh = local_catquizlab_engine_session_pool($session);
    $behind = [];
    $ahead = 0;
    foreach ($fresh as $id => $item) {
        $was = (int) ($cached[$id]->attempts ?? 0);
        $now = (int) ($item->attempts ?? 0);
        if ($now > $was) {
            $behind[(int) $id] = $now - $was;
        } else if ($now < $was) {
            $ahead++;
        }
    }
    echo json_encode(['items' => count($fresh), 'behind' => count($behind), 'answersbehind' => array_sum($behind),
        'maxbehind' => $behind ? max($behind) : 0, 'ahead' => $ahead]) . PHP_EOL;
    exit($ahead === 0 ? 0 : 1);
}

if ($options['change-items']) {
    $session = json_decode((string) file_get_contents((string) $options['session']), true);
    if (!$session || !$DB->get_manager()->table_exists('local_catquiz_itemparams')) {
        cli_error('--session=FILE: the file seeding wrote, on an installation with local_catquiz.');
    }
    $pool = $session['activities']['A']['pool'] ?: [0];
    // The runtime pool as the attempts see it now, warm in the engine's cache.
    $warm = local_catquizlab_engine_session_pool($session);
    [$in, $params] = $DB->get_in_or_equal($pool, SQL_PARAMS_NAMED);
    $changed = 0;
    $rows = $DB->get_records_select('local_catquiz_itemparams', "componentid {$in}", $params, 'id', 'id, difficulty');
    foreach ($rows as $row) {
        $DB->update_record('local_catquiz_itemparams', (object) ['id' => $row->id,
            'difficulty' => round((float) $row->difficulty + 0.25, 4), 'timemodified' => time()]);
        $changed++;
    }
    // What the engine's own save of parameters does after writing them (model_item_param_list): its caches of items
    // and parameters, the runtime pool among them, are invalidated by event (#136 AK 3).
    \cache_helper::purge_by_event('changesinitemparams');
    \cache_helper::purge_by_event('changesintestitems');
    // And one item of A nobody was asked yet made inactive in its scale, through the engine's own call — the one its
    // item table's switch makes (execute_method_from_webservice): from now on it must never be asked.
    $asked = $DB->get_fieldset_sql(
        "SELECT DISTINCT qa.questionid
           FROM {adaptivequiz_attempt} aa
           JOIN {question_attempts} qa ON qa.questionusageid = aa.uniqueid
          WHERE aa.instance = ?",
        [(int) $session['activities']['A']['instance']]
    );
    require_once($CFG->dirroot . '/local/catquiz/lib.php');
    $deactivated = 0;
    foreach (array_diff($pool, array_map('intval', $asked)) as $questionid) {
        $item = $DB->get_record_select(
            'local_catquiz_items',
            'componentid = ? AND componentname = ?',
            [$questionid, 'question'],
            'id, catscaleid',
            IGNORE_MULTIPLE
        );
        if ($item && method_exists('\\local_catquiz\\catscale', 'add_or_update_testitem_to_scale')) {
            \local_catquiz\catscale::add_or_update_testitem_to_scale(
                (int) $item->catscaleid,
                (int) $questionid,
                LOCAL_CATQUIZ_TESTITEM_STATUS_INACTIVE,
                'question'
            );
            $deactivated = (int) $questionid;
            break;
        }
    }
    // The runtime pool right after the change, as the next question will see it: the inactive item gone, every
    // difficulty the new one — not what the warm cache held (#136 AK 3: no stale pool after a change).
    $after = local_catquizlab_engine_session_pool($session);
    $stale = 0;
    foreach ($after as $item) {
        // The parameter row the pool says is the item's active one, as stored now.
        $stored = $DB->get_field('local_catquiz_itemparams', 'difficulty', ['id' => (int) ($item->activeparamid ?? 0)]);
        if ($stored !== false && abs((float) $item->difficulty - (float) $stored) > 1e-6) {
            $stale++;
        }
    }
    echo json_encode(['changed' => $changed, 'deactivated' => $deactivated, 'warmpool' => count($warm),
        'pool' => count($after), 'inactiveinpool' => isset($after[$deactivated]), 'staledifficulties' => $stale,
        'inactivewaspooled' => isset($warm[$deactivated])]) . PHP_EOL;
    exit($changed > 0 && $deactivated > 0 && !isset($after[$deactivated]) && $stale === 0 ? 0 : 1);
}

if ($options['report']) {
    $session = json_decode((string) file_get_contents((string) $options['session']), true);
    if (!$session) {
        cli_error('--session=FILE: the file seeding wrote.');
    }
    $report = local_catquizlab_engine_session_report($session, (int) $options['user']);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

\core\session\manager::set_user(get_admin());
$items = max(2, (int) $options['items']);
$activities = [];
foreach (['A', 'B'] as $key) {
    $cmd = [PHP_BINARY, __DIR__ . '/e2e_prepare.php', '--persons=1', '--items=' . $items . ',' . $items,
        '--name=Engine session ' . $key];
    exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>&1', $lines, $code);
    $values = [];
    foreach ($lines as $line) {
        if (preg_match('/^([a-z_]+)=(.*)$/', $line, $m)) {
            $values[$m[1]] = $m[2];
        }
    }
    if ($code !== 0 || empty($values['runid'])) {
        cli_error('e2e_prepare.php failed for ' . $key . ': ' . implode(' | ', array_slice($lines, -5)));
    }
    $lines = [];
    $run = $DB->get_record('local_catquizlab_run', ['id' => (int) $values['runid']], '*', MUST_EXIST);
    // The run's own sittings are not played here: closed, so that no worker of this installation takes them.
    $cancelled = \local_catquizlab\local\attempt_scheduler::STATUS_CANCELLED;
    $DB->set_field('local_catquizlab_attempt', 'status', $cancelled, ['runid' => $run->id]);
    $cm = get_coursemodule_from_id('adaptivequiz', (int) $run->testcmid, 0, false, MUST_EXIST);
    $activities[$key] = ['cmid' => (int) $cm->id, 'instance' => (int) $cm->instance, 'courseid' => (int) $cm->course,
        'runid' => (int) $run->id, 'items' => $items,
        'pool' => array_values(array_map('intval', $DB->get_fieldset_select(
            'local_catquizlab_item',
            'questionid',
            'runid = ? AND questionid > 0',
            [$run->id]
        )))];
}

$courseid = $activities['A']['courseid'];
$manual = enrol_get_plugin('manual');
$instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual']);
if (!$instance) {
    $instanceid = $manual->add_default_instance(get_course($courseid));
    $instance = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
}
$student = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
$learners = [];
foreach ([1, 2] as $n) {
    $username = 'enginesession' . $n . '_' . time();
    $password = 'Engine-' . $n . '-' . random_string(10) . '!';
    // Through core_compat: user_create_user() is deprecated from Moodle 5.3 (#139, core_compat_test).
    $user = (object) ['username' => $username, 'password' => $password, 'firstname' => 'Engine',
        'lastname' => 'Learner ' . $n, 'email' => $username . '@example.invalid', 'auth' => 'manual', 'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id];
    $userid = \local_catquizlab\local\core_compat::create_user($user, true, false);
    $manual->enrol_user($instance, $userid, $student);
    $learners[] = ['id' => (int) $userid, 'username' => $username, 'password' => $password];
}

$file = (string) $options['file'] !== '' ? (string) $options['file'] : make_temp_directory('local_catquizlab')
    . '/engine-session-' . time() . '.json';
$json = json_encode(['courseid' => $courseid, 'activities' => $activities, 'learners' => $learners,
    'wwwroot' => $CFG->wwwroot], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (file_put_contents($file, $json) === false) {
    // Never leave an older file of the same name to be read as this seed.
    cli_error('Could not write ' . $file . '.');
}
cli_writeln('session=' . $file);
cli_writeln('cmid_a=' . $activities['A']['cmid']);
cli_writeln('cmid_b=' . $activities['B']['cmid']);

/**
 * What the activity and the engine stored for one learner, per activity.
 *
 * @param array $session The seeded session file.
 * @param int $userid The learner.
 * @return array
 */
function local_catquizlab_engine_session_report(array $session, int $userid): array {
    global $DB;

    $out = ['userid' => $userid, 'activities' => []];
    $engine = $DB->get_manager()->table_exists('local_catquiz_attempts');
    foreach ($session['activities'] as $key => $activity) {
        $attempts = [];
        $mine = $DB->get_records('adaptivequiz_attempt', ['instance' => $activity['instance'], 'userid' => $userid], 'id ASC');
        foreach ($mine as $attempt) {
            $questions = [];
            if ((int) $attempt->uniqueid > 0) {
                $usage = $DB->get_records_sql(
                    'SELECT qa.id, qa.slot, qa.questionid, qa.responsesummary,
                            (SELECT COUNT(1) FROM {question_attempt_steps} s WHERE s.questionattemptid = qa.id) AS steps
                       FROM {question_attempts} qa
                      WHERE qa.questionusageid = ?
                   ORDER BY qa.slot ASC',
                    [(int) $attempt->uniqueid]
                );
                foreach ($usage as $qa) {
                    $questions[] = ['slot' => (int) $qa->slot, 'questionid' => (int) $qa->questionid,
                        'answered' => trim((string) $qa->responsesummary) !== '', 'steps' => (int) $qa->steps];
                }
            }
            $ids = array_column($questions, 'questionid');
            $record = [];
            if ($engine) {
                $row = $DB->get_record('local_catquiz_attempts', ['attemptid' => $attempt->id], '*', IGNORE_MULTIPLE);
                if ($row) {
                    $kept = ['status', 'teststrategy', 'total_number_of_testitems', 'number_of_testitems_used',
                        'personability_before_attempt', 'personability_after_attempt', 'endtime'];
                    foreach ($kept as $field) {
                        if (property_exists($row, $field)) {
                            $record[$field] = $row->$field;
                        }
                    }
                }
            }
            $fields = [];
            $kept = ['attemptstate', 'attemptstopcriteria', 'questionsattempted', 'standarderror', 'measure', 'stopcode',
                'timefinished', 'uniqueid', 'timecreated', 'timemodified'];
            foreach ($kept as $field) {
                if (property_exists($attempt, $field)) {
                    $fields[$field] = $attempt->$field;
                }
            }
            $attempts[] = ['id' => (int) $attempt->id] + $fields + [
                'questions' => $questions,
                'answered' => count(array_filter($questions, static fn(array $q): bool => $q['answered'])),
                'asked_twice' => array_values(array_keys(array_filter(
                    array_count_values($ids),
                    static fn(int $n): bool => $n > 1
                ))),
                'outside_pool' => array_values(array_diff($ids, $activity['pool'])),
                'engine' => $record,
            ];
        }
        $out['activities'][$key] = ['cmid' => $activity['cmid'], 'attempts' => $attempts];
    }

    return $out;
}
