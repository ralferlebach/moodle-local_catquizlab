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
 * Test data generator for local_catquizlab.
 *
 * @package    local_catquizlab
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Generator for local_catquizlab test data.
 */
class local_catquizlab_generator extends component_generator_base {
    /**
     * Create an experiment definition record.
     *
     * @param array $record Optional field overrides.
     * @return \stdClass The inserted experiment record including its id.
     */
    public function create_experiment(array $record = []): \stdClass {
        global $DB, $USER;

        $defaults = [
            'name'         => 'Experiment ' . ($DB->count_records('local_catquizlab_experiment') + 1),
            'tier'         => 'baseline',
            'configjson'   => json_encode(['model' => '2PL', 'replications' => 1, 'seed' => 42]),
            'status'       => 0,
            'usermodified' => $USER->id ?? 0,
            'timecreated'  => time(),
            'timemodified' => time(),
        ];
        $experiment = (object) array_merge($defaults, $record);
        $experiment->id = $DB->insert_record('local_catquizlab_experiment', $experiment);

        return $experiment;
    }
}
