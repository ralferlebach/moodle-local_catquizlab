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

use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\results_export;
use local_catquizlab\local\results_query;

/**
 * The export says how the true abilities were drawn and in which range (#102).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\results_export
 */
final class export_ability_test extends \advanced_testcase {
    /**
     * Every sitting row carries its run's distribution and range; the JSON metadata the full block.
     *
     * @return void
     */
    public function test_the_export_carries_the_ability_distribution(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        // Run ids are reused between tests; a lookup cached by id must not outlive one.
        results_export::reset_caches();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $definition = experiment_definition::example_baseline();
        $definition['persons'] = ['distribution' => 'truncated_normal', 'abilitymean' => 0.5, 'abilitysd' => 1.5,
            'abilityrange' => ['min' => -4, 'max' => 4]] + $definition['persons'];
        $DB->set_field(
            'local_catquizlab_run',
            'manifestjson',
            json_encode(['config' => ['definition' => $definition]]),
            ['id' => $run->id]
        );
        for ($i = 1; $i <= 2; $i++) {
            $person = $generator->create_person(['runid' => $run->id, 'abilityglobal' => 0.2 * $i]);
            $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid' => $run->id, 'personid' => $person->id,
                'status' => \local_catquizlab\local\attempt_scheduler::STATUS_COLLECTED, 'tries' => 1,
                'timecreated' => time(), 'timemodified' => time(),
                'tracejson' => json_encode(['finaltheta' => 0.1 * $i, 'finalse' => 0.4, 'items' => [11, 12],
                    'nitems' => 2, 'stopreason' => 'se', 'scaleabilities' => []]),
            ]);
        }

        $query = new results_query(['runid' => (int) $run->id]);
        $rows = iterator_to_array(results_export::iterate($query, results_export::LEVEL_ATTEMPT)['rows'], false);
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(['truncated_normal', 0.5, 1.5, -4.0, 4.0], [
                $row['abilitydistribution'], $row['abilitymean'], $row['abilitysd'], $row['abilitymin'], $row['abilitymax'],
            ]);
        }
        // Appended, so that no existing column moves.
        $columns = results_export::ATTEMPT_COLUMNS;
        // Appended after it since: finaltiatn (#106), again without moving a column.
        // Appended since, each time without moving a column: finaltiatn (#106), the validity (#118).
        $at = array_search('abilitymax', $columns);
        $this->assertSame(['abilitymax', 'finaltiatn', 'valid'], array_slice($columns, $at, 3));
        $this->assertSame('activescalesatend', $columns[array_search('abilitydistribution', $columns) - 1]);

        $metadata = results_export::metadata($query, results_export::LEVEL_ATTEMPT);
        $block = $metadata['ability'][(int) $run->id];
        $this->assertSame(-4.0, (float) $block['lower_bound']);
        $this->assertSame(4.0, (float) $block['upper_bound']);
        $this->assertArrayHasKey('engine_scale_range', $block);
    }
}
