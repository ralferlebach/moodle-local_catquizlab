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
}
