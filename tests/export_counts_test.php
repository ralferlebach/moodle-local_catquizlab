<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_catquizlab;

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\results_export;
use local_catquizlab\local\results_query;

/**
 * The export tab's row counts are the downloads' row counts, for every validity (#115, audit 11.11.2026).
 *
 * The provenance snapshot of 0.7.59 found the tab saying 605 000 item rows for a download of 1 250 (it counted the
 * items each sitting used, the download has one row per materialised item) and 0 run rows for a download of 2 (it
 * counted runs with a sitting of the chosen validity, the download has every run of the selection).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\results_export
 */
final class export_counts_test extends \advanced_testcase {
    /**
     * Tab count = rows of the download, for run, attempt, subscale and item, and every validity.
     *
     * @return void
     */
    public function test_the_tab_counts_what_the_download_has(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        results_export::reset_caches();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $experiment = $generator->create_experiment();
        $runs = [];
        foreach ([1, 2] as $n) {
            $run = $generator->create_run(['experimentid' => $experiment->id]);
            $runs[] = (int) $run->id;
            // A pool of five materialised items per run.
            for ($q = 1; $q <= 5; $q++) {
                $DB->insert_record('local_catquizlab_item', (object) ['runid' => $run->id, 'questionid' => 100 * $n + $q,
                    'itemname' => 'I' . $q, 'model' => '2pl', 'truedifficulty' => 0, 'storeddifficulty' => 0,
                    'discrimination' => 1, 'guessing' => 0, 'truecategory' => 1, 'truesubscale' => 1,
                    'truecatscaleid' => 0, 'assignedcatscaleid' => 0, 'miscalibrated' => 0, 'mistagged' => 0,
                    'timecreated' => time()]);
            }
        }
        // Three sittings in the first run (two of three items, one of one item), none in the second.
        foreach ([[101, 102, 103], [101, 102], [101]] as $i => $items) {
            $person = $generator->create_person(['runid' => $runs[0], 'abilityglobal' => 0.1 * $i]);
            $responses = array_fill_keys($items, 1.0);
            $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid' => $runs[0], 'personid' => $person->id, 'status' => attempt_scheduler::STATUS_COLLECTED,
                'tries' => 1, 'timecreated' => time(), 'timemodified' => time(),
                'tracejson' => json_encode(['finaltheta' => 0.1, 'finalse' => $i === 2 ? 2.5 : 0.3, 'items' => $items,
                    'responses' => $responses, 'nitems' => count($items), 'stopreason' => 'se', 'scaleabilities' => []]),
            ]);
        }

        $levels = [results_export::LEVEL_RUN, results_export::LEVEL_ATTEMPT, results_export::LEVEL_SUBSCALE,
            results_export::LEVEL_ITEM];
        $sum = 0;
        foreach (['valid', 'invalid', 'unknown', 'all'] as $validity) {
            $filter = ['experimentid' => (int) $experiment->id, 'validity' => $validity];
            $counts = results_export::all_row_counts(new results_query($filter))['counts'];
            foreach ($levels as $level) {
                $rows = 0;
                foreach (results_export::iterate(new results_query($filter), $level)['rows'] as $unused) {
                    $rows++;
                }
                $this->assertSame($rows, $counts[$level], "$validity/$level: the tab must count the download's rows");
                $this->assertSame(
                    $rows,
                    results_export::row_count(new results_query($filter), $level),
                    "$validity/$level: row_count() as well"
                );
            }
            if ($validity !== 'all') {
                $sum += $counts[results_export::LEVEL_ATTEMPT];
            }
            // One row per run of the selection, one per materialised item of its runs, whatever the validity.
            $this->assertSame(2, $counts[results_export::LEVEL_RUN]);
            $this->assertSame(10, $counts[results_export::LEVEL_ITEM]);
        }
        $all = results_export::all_row_counts(new results_query(['experimentid' => (int) $experiment->id,
            'validity' => 'all']))['counts'][results_export::LEVEL_ATTEMPT];
        $this->assertSame(3, $all);
        $this->assertSame($all, $sum, 'valid + invalid + unknown = all');
    }
}
