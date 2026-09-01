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

        // A generated experiment carries a complete, valid definition: a
        // behat scenario that opens the editor needs one that survives
        // validation, and a half-filled stub would fail there rather than in
        // the step that is actually being tested.
        $definition = \local_catquizlab\local\experiment_definition::example_baseline();
        foreach (['strategy', 'model', 'replications', 'seed', 'tier'] as $key) {
            if (isset($record[$key])) {
                $definition[$key] = is_numeric($record[$key]) ? (int) $record[$key] : $record[$key];
                unset($record[$key]);
            }
        }
        if (isset($record['name'])) {
            $definition['name'] = $record['name'];
        }

        $defaults = [
            'name'         => $definition['name'] ?? ('Experiment '
                . ($DB->count_records('local_catquizlab_experiment') + 1)),
            'tier'         => (string) ($definition['tier'] ?? 'baseline'),
            'configjson'   => json_encode($definition, JSON_UNESCAPED_SLASHES),
            'status'       => 0,
            'usermodified' => $USER->id ?? 0,
            'timecreated'  => time(),
            'timemodified' => time(),
        ];
        $experiment = (object) array_merge($defaults, $record);
        $experiment->id = $DB->insert_record('local_catquizlab_experiment', $experiment);

        return $experiment;
    }

    /**
     * Create a run belonging to an experiment.
     *
     * @param array $record Field overrides; 'experimentid' is created on the fly when omitted.
     * @return \stdClass The inserted run record including its id.
     */
    public function create_run(array $record = []): \stdClass {
        global $DB, $USER;

        if (empty($record['experimentid'])) {
            $record['experimentid'] = $this->create_experiment()->id;
        }

        $defaults = [
            'cellkey'      => 'cell-' . ($DB->count_records('local_catquizlab_run') + 1),
            'seed'         => 42,
            'replication'  => 1,
            'status'       => 0,
            'manifestjson' => null,
            'usermodified' => $USER->id ?? 0,
            'timecreated'  => time(),
            'timemodified' => time(),
        ];
        $run = (object) array_merge($defaults, $record);
        $run->id = $DB->insert_record('local_catquizlab_run', $run);

        return $run;
    }

    /**
     * Create a pool variant for an experiment.
     *
     * @param array $record Field overrides; 'experimentid' is created on the fly when omitted.
     * @return \stdClass The inserted pool record including its id.
     */
    public function create_pool(array $record = []): \stdClass {
        global $DB;

        if (empty($record['experimentid'])) {
            $record['experimentid'] = $this->create_experiment()->id;
        }

        $defaults = [
            'variant'            => 'ideal',
            'recipejson'         => json_encode(['seed' => 42]),
            'scaleid'            => null,
            'questioncategoryid' => null,
            'timecreated'        => time(),
            'timemodified'       => time(),
        ];
        $pool = (object) array_merge($defaults, $record);
        $pool->id = $DB->insert_record('local_catquizlab_pool', $pool);

        return $pool;
    }

    /**
     * Create a simulated person belonging to a run.
     *
     * @param array $record Field overrides; 'runid' is created on the fly when omitted.
     * @return \stdClass The inserted person record including its id.
     */
    public function create_person(array $record = []): \stdClass {
        global $DB;

        if (empty($record['runid'])) {
            $record['runid'] = $this->create_run()->id;
        }

        $defaults = [
            'stratum'       => 'conforming',
            'abilityglobal' => 0.0,
            'profilejson'   => json_encode(['global' => 0.0, 'categories' => []]),
            'moodleuserid'  => null,
            'timecreated'   => time(),
            'timemodified'  => time(),
        ];
        $person = (object) array_merge($defaults, $record);
        $person->id = $DB->insert_record('local_catquizlab_person', $person);

        return $person;
    }
}
