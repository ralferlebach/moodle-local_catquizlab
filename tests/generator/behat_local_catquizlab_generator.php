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
 * Behat data generator for local_catquizlab.
 *
 * @package    local_catquizlab
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Creates experiments and runs from a Behat "the following ... exists" table.
 *
 * It delegates to the PHPUnit generator rather than writing rows itself, so a
 * scenario and a unit test start from the same fixture.
 */
class behat_local_catquizlab_generator extends behat_generator_base {
    /**
     * Declare the entities a scenario may create.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'experiments' => [
                'singular'      => 'experiment',
                'datagenerator' => 'experiment',
                'required'      => ['name'],
            ],
            'runs'        => [
                'singular'      => 'run',
                'datagenerator' => 'run',
                'required'      => ['cellkey'],
                'switchids'     => ['experiment' => 'experimentid'],
            ],
        ];
    }

    /**
     * Resolve an experiment name to its id.
     *
     * @param string $name The experiment name.
     * @return int The experiment id.
     * @throws Exception If no experiment of that name exists.
     */
    protected function get_experiment_id(string $name): int {
        global $DB;

        $id = $DB->get_field('local_catquizlab_experiment', 'id', ['name' => $name]);
        if (!$id) {
            throw new Exception('No experiment named "' . $name . '" exists.');
        }

        return (int) $id;
    }
}
