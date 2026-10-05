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
use local_catquizlab\local\results_query;
use local_catquizlab\output\results_page;

/**
 * The local-deviation plot only where the true deviation varies (#105, section 7).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\output\results_page
 */
final class local_deviation_variation_test extends \advanced_testcase {
    /**
     * A run of one category with two subscales, its people at the given subscale offsets.
     *
     * @param float[] $offsets True subscale ability minus true global ability, per subscale.
     * @return int The run id.
     */
    protected function run_with(array $offsets): int {
        global $DB;

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $scales = [];
        foreach ([1, 2] as $subscale) {
            $catscaleid = 2000 + $subscale;
            $DB->insert_record('local_catquizlab_scalemap', (object) [
                'runid' => $run->id, 'level' => \local_catquizlab\local\scale_provisioner::LEVEL_SUBSCALE,
                'catscaleid' => $catscaleid, 'parentcatscaleid' => 2000, 'contextid' => 1,
                'nodekey' => 'c1s' . $subscale, 'generation' => 1, 'categoryindex' => 1, 'subscaleindex' => $subscale,
                'timecreated' => time(),
            ]);
            $scales[] = $catscaleid;
        }
        for ($i = 0; $i < 12; $i++) {
            $global = -1.5 + 0.25 * $i;
            $subscales = [];
            foreach ([1, 2] as $subscale) {
                // Each person a different deviation where there is variation.
                $subscales[] = ['index' => $subscale, 'theta' => $global + $offsets[$subscale - 1] * (($i % 3) - 1)];
            }
            $person = $generator->create_person(['runid' => $run->id, 'abilityglobal' => $global]);
            $DB->set_field('local_catquizlab_person', 'profilejson', json_encode([
                'global' => $global, 'categories' => [['index' => 1, 'theta' => $global, 'subscales' => $subscales]],
            ]), ['id' => $person->id]);
            $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid' => $run->id, 'personid' => $person->id, 'status' => attempt_scheduler::STATUS_COLLECTED,
                'tries' => 1, 'timecreated' => time(), 'timemodified' => time(),
                'tracejson' => json_encode(['finaltheta' => $global + 0.1, 'finalse' => 0.4, 'items' => [1, 2, 3],
                    'nitems' => 3, 'stopreason' => 'se', 'scaleabilities' => array_fill_keys($scales, $global + 0.2)]),
            ]);
        }

        return (int) $run->id;
    }

    /**
     * The subscales tab of a run.
     *
     * @param int $runid The run.
     * @return string
     */
    protected function tab(int $runid): string {
        $filter = ['runid' => $runid];
        return (new results_page(new results_query($filter), 'subscales', $filter))->render_tab();
    }

    /**
     * No variation: a note instead of a plot with every point on x = 0.
     *
     * @return void
     */
    public function test_without_variation_there_is_a_note_not_a_plot(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->tab($this->run_with([0.0, 0.0]));
        $this->assertStringContainsString('data-region="catquizlab-novariation"', $html);
        $this->assertStringContainsString('No variation in true local deviation for this selection', $html);
        $this->assertStringNotContainsString(get_string('chart:identity', 'local_catquizlab'), $html);
    }

    /**
     * With variation: the comparison plot, on symmetric axes with y = x.
     *
     * @return void
     */
    public function test_with_variation_there_is_the_plot(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->tab($this->run_with([0.6, -0.4]));
        $this->assertStringNotContainsString('data-region="catquizlab-novariation"', $html);
        $this->assertStringContainsString(get_string('chart:identity', 'local_catquizlab'), $html);
        $this->assertStringContainsString('<svg', $html);
    }
}
