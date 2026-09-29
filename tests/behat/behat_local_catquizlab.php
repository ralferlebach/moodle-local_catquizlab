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
 * Behat steps for the CAT experiment suite.
 *
 * @package    local_catquizlab
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Steps that set up experiment state a scenario needs.
 *
 * Expanding a sweep goes through the same service the UI and the CLI use, so a
 * scenario cannot accidentally test against runs that were built differently
 * from the ones a real user would get.
 */
class behat_local_catquizlab extends behat_base {
    /**
     * Expand a named experiment into runs.
     *
     * @Given /^the experiment "(?P<name_string>(?:[^"]|\\")*)" has been expanded into runs$/
     * @param string $name The experiment name.
     * @return void
     * @throws \coding_exception If no experiment of that name exists.
     */
    public function the_experiment_has_been_expanded_into_runs(string $name): void {
        global $DB;

        $id = $DB->get_field('local_catquizlab_experiment', 'id', ['name' => $name]);
        if (!$id) {
            throw new \coding_exception('No experiment named "' . $name . '".');
        }

        \local_catquizlab\local\experiment_service::create_sweep((int) $id);
    }

    /**
     * Give every run of an experiment collected sittings, as if they had been played.
     *
     * The results page shows provenance — and with it the stop rules in force —
     * only for runs with collected sittings; a scenario cannot wait for a
     * browser worker to play them.
     *
     * @Given /^the runs of "(?P<name_string>(?:[^"]|\\")*)" have (?P<count_number>\d+) collected sittings each$/
     * @param string $name The experiment name.
     * @param int $count Sittings per run.
     * @return void
     * @throws \coding_exception If no experiment of that name exists.
     */
    public function the_runs_have_collected_sittings(string $name, int $count): void {
        global $DB;

        $id = $DB->get_field('local_catquizlab_experiment', 'id', ['name' => $name]);
        if (!$id) {
            throw new \coding_exception('No experiment named "' . $name . '".');
        }

        /** @var \local_catquizlab_generator $generator */
        $generator = \testing_util::get_data_generator()->get_plugin_generator('local_catquizlab');
        foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => $id]) as $run) {
            $DB->set_field('local_catquizlab_run', 'status', \local_catquizlab\local\registry::STATUS_FINISHED, ['id' => $run->id]);
            for ($i = 0; $i < $count; $i++) {
                $person = $generator->create_person(['runid' => $run->id]);
                $DB->insert_record('local_catquizlab_attempt', (object) [
                    'runid' => $run->id, 'personid' => $person->id,
                    'status' => \local_catquizlab\local\attempt_scheduler::STATUS_COLLECTED,
                    'tries' => 1, 'runtimems' => 4000,
                    'tracejson' => json_encode([
                        'finaltheta' => 0.1 * $i, 'finalse' => 0.34, 'items' => range(1, 15),
                        'nitems' => 15, 'steps' => 15, 'stopreason' => 'se', 'scaleabilities' => [],
                    ]),
                    'timecreated' => time(), 'timemodified' => time(),
                ]);
            }
        }
    }

    /**
     * Open the results of one experiment.
     *
     * @Given /^I open the results of "(?P<name_string>(?:[^"]|\\")*)"$/
     * @param string $name The experiment name.
     * @return void
     * @throws \coding_exception If no experiment of that name exists.
     */
    public function i_open_the_results_of(string $name): void {
        global $DB;

        $id = $DB->get_field('local_catquizlab_experiment', 'id', ['name' => $name]);
        if (!$id) {
            throw new \coding_exception('No experiment named "' . $name . '".');
        }
        $this->execute('behat_general::i_visit', ['/local/catquizlab/results.php?experimentid=' . $id]);
    }

    /**
     * Give the first run of an experiment a failed sitting with artefacts, as a worker leaves them.
     *
     * @Given /^the first run of "(?P<name_string>(?:[^"]|\\")*)" has a failed sitting with artefacts$/
     * @param string $name The experiment name.
     * @return void
     * @throws \coding_exception If no experiment of that name exists.
     */
    public function the_first_run_has_a_failed_sitting_with_artefacts(string $name): void {
        global $DB;

        $id = $DB->get_field('local_catquizlab_experiment', 'id', ['name' => $name]);
        if (!$id) {
            throw new \coding_exception('No experiment named "' . $name . '".');
        }
        $run = $DB->get_record_sql(
            'SELECT * FROM {local_catquizlab_run} WHERE experimentid = ? ORDER BY id ASC',
            [$id],
            IGNORE_MULTIPLE
        );
        $attemptid = (int) $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $run->id, 'personid' => 0,
            'status' => \local_catquizlab\local\attempt_scheduler::STATUS_FAILED, 'tries' => 1,
            'lasterror' => 'Execution context was destroyed, most likely because of a navigation.',
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $dir = \local_catquizlab\local\artefact_store::execution_dir((int) $run->experimentid, (int) $run->id, $attemptid, 1);
        check_dir_exists($dir, true, true);
        file_put_contents($dir . '/screenshot-last.jpg', 'jpeg');
        file_put_contents($dir . '/dom.html', '<p>page</p>');
        \local_catquizlab\local\attempt_history::record($attemptid, \local_catquizlab\local\attempt_history::FAILED, [
            'tryno' => 1, 'detail' => 'Execution context was destroyed, most likely because of a navigation.',
        ]);
    }

    /**
     * Open the detail page of an experiment's first run.
     *
     * Run ids are database ids, so a scenario cannot know them in advance;
     * matching on a literal "1" only worked while the table happened to start
     * at one.
     *
     * @Given /^I open the first run of "(?P<name_string>(?:[^"]|\\")*)"$/
     * @param string $name The experiment name.
     * @return void
     * @throws \coding_exception If the experiment or its runs are missing.
     */
    public function i_open_the_first_run_of(string $name): void {
        global $DB;

        $experimentid = $DB->get_field('local_catquizlab_experiment', 'id', ['name' => $name]);
        if (!$experimentid) {
            throw new \coding_exception('No experiment named "' . $name . '".');
        }
        $runs = $DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC', 'id', 0, 1);
        if (!$runs) {
            throw new \coding_exception('The experiment "' . $name . '" has no runs.');
        }

        $url = new \moodle_url('/local/catquizlab/runs.php', ['runid' => (int) reset($runs)->id]);
        $this->execute('behat_general::i_visit', [$url]);
    }

    /**
     * Configure a course as the experiment course by its short name.
     *
     * A scenario cannot know the course id in advance, and hard-coding one
     * works only until another fixture is added before it.
     *
     * @Given /^the course "(?P<shortname_string>(?:[^"]|\\")*)" is the experiment course$/
     * @param string $shortname The course short name.
     * @return void
     * @throws \coding_exception If no course with that short name exists.
     */
    public function the_course_is_the_experiment_course(string $shortname): void {
        global $DB;

        $courseid = $DB->get_field('course', 'id', ['shortname' => $shortname]);
        if (!$courseid) {
            throw new \coding_exception('No course with the short name "' . $shortname . '".');
        }

        set_config('experimentcourseid', (int) $courseid, 'local_catquizlab');
    }
}
