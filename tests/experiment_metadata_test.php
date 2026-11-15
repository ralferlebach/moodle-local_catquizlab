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
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_metadata;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\registry;
use local_catquizlab\local\reproducibility;
use local_catquizlab\local\run_labels;

/**
 * Name and description changed after runs exist, the design untouched (#152).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\experiment_metadata
 * @covers     \local_catquizlab\event\experiment_metadata_updated
 */
final class experiment_metadata_test extends \advanced_testcase {
    /**
     * An experiment with a run in the given state, its definition in the run's manifest, a sitting and a result.
     *
     * @param int|null $status The run status, or null for no run.
     * @return array{id: int, runid: int}
     */
    protected function experiment(?int $status): array {
        global $DB;

        $definition = experiment_definition::example_baseline();
        $definition['name'] = 'Original';
        $definition['description'] = 'First words';
        $id = (int) experiment_service::save($definition)['id'];
        $runid = 0;
        if ($status !== null) {
            /** @var \local_catquizlab_generator $generator */
            $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
            $runid = (int) $generator->create_run(['experimentid' => $id, 'status' => $status,
                'manifestjson' => json_encode(['config' => ['definition' => $definition,
                    'strategy' => ['key' => $definition['strategy']]]])])->id;
            $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $runid, 'personid' => 0,
                'status' => attempt_scheduler::STATUS_COLLECTED, 'tries' => 1, 'tracejson' => '{"steps":[1,2]}',
                'timecreated' => 1, 'timemodified' => 1]);
            $DB->insert_record('local_catquizlab_result', (object) ['runid' => $runid, 'metric' => 'mae', 'scope' => 'run',
                'value' => 0.3, 'timecreated' => 1]);
        }

        return ['id' => $id, 'runid' => $runid];
    }

    /**
     * Everything about an experiment that is not its name and description, as one string.
     *
     * @param int $id The experiment.
     * @return string
     */
    protected function provenance(int $id): string {
        global $DB;

        $runs = $DB->get_records('local_catquizlab_run', ['experimentid' => $id], 'id');
        $runids = array_keys($runs) ?: [0];
        [$in, $params] = $DB->get_in_or_equal($runids);

        return json_encode([
            'status' => $DB->get_field('local_catquizlab_experiment', 'status', ['id' => $id]),
            'tier' => $DB->get_field('local_catquizlab_experiment', 'tier', ['id' => $id]),
            'design' => experiment_metadata::design_hash((string) $DB->get_field(
                'local_catquizlab_experiment',
                'configjson',
                ['id' => $id]
            )),
            'runs' => array_map(static fn($r) => [$r->status, $r->seed, $r->masterseed, $r->manifestjson, $r->cellkey,
                $r->timemodified], $runs),
            'attempts' => $DB->get_records_select('local_catquizlab_attempt', "runid $in", $params, 'id'),
            'results' => $DB->get_records_select('local_catquizlab_result', "runid $in", $params, 'id'),
        ]);
    }

    /**
     * The version the editor sees.
     *
     * @param int $id The experiment.
     * @return int
     */
    protected function seen(int $id): int {
        global $DB;

        return (int) $DB->get_field('local_catquizlab_experiment', 'timemodified', ['id' => $id]);
    }

    /**
     * AK1–AK3, AK8, DoD: in every state of its runs the name and description change, nothing else does — the design
     * hash, the runs, their manifests, sittings and results are the same before and after.
     *
     * @return void
     */
    public function test_every_state_renames_without_touching_the_design(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        foreach (
            [null, registry::STATUS_SCHEDULED, registry::STATUS_RUNNING, registry::STATUS_FAILED,
                registry::STATUS_FINISHED] as $status
        ) {
            $e = $this->experiment($status);
            $before = $this->provenance($e['id']);
            $result = experiment_metadata::update($e['id'], 'Renamed Ü 😀 <b>', "New\r\nwords", $this->seen($e['id']));
            $this->assertTrue($result['ok'], 'state ' . var_export($status, true));
            $record = $DB->get_record('local_catquizlab_experiment', ['id' => $e['id']]);
            $this->assertSame('Renamed Ü 😀 <b>', $record->name);
            $stored = json_decode($record->configjson, true);
            $this->assertSame('Renamed Ü 😀 <b>', $stored['name']);
            $this->assertSame("New\nwords", $stored['description']);
            // Everything else exactly as it was.
            $this->assertSame($before, $this->provenance($e['id']), 'state ' . var_export($status, true));
            if ($e['runid'] > 0) {
                // The run keeps the name it was made under; lists show the current one, escaped.
                $package = reproducibility::package($e['id'], false);
                $this->assertSame('Renamed Ü 😀 <b>', $package['experiment']['name']);
                $this->assertSame('Original', $package['runs'][0]['experimentnameatrun']);
                $label = run_labels::for_runs([$e['runid']])[$e['runid']];
                $this->assertStringNotContainsString('<b>', $label['experimentname']);
            }
        }
    }

    /**
     * AK6: two editors; the second, who saw the old version, is refused and overwrites nothing.
     *
     * @return void
     */
    public function test_a_concurrent_edit_is_refused_not_lost(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $e = $this->experiment(registry::STATUS_RUNNING);
        $seen = $this->seen($e['id']);
        $this->assertTrue(experiment_metadata::update($e['id'], 'First editor', '', $seen)['ok']);
        $second = experiment_metadata::update($e['id'], 'Second editor', '', $seen);
        $this->assertFalse($second['ok']);
        $this->assertSame('metadata:errorconflict', $second['error']);
        $this->assertSame('First editor', $DB->get_field('local_catquizlab_experiment', 'name', ['id' => $e['id']]));
    }

    /**
     * AK6: empty, too long; unicode and special characters kept as typed.
     *
     * @return void
     */
    public function test_validation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $e = $this->experiment(registry::STATUS_FINISHED);
        $empty = experiment_metadata::update($e['id'], "  \t ", '', $this->seen($e['id']));
        $this->assertSame('metadata:errorempty', $empty['error']);
        $this->assertSame('metadata:errorlongname', experiment_metadata::update(
            $e['id'],
            str_repeat('x', 256),
            '',
            $this->seen($e['id'])
        )['error']);
        $this->assertSame('metadata:errorlongdescription', experiment_metadata::update(
            $e['id'],
            'ok',
            str_repeat('x', 4001),
            $this->seen($e['id'])
        )['error']);
        $this->assertTrue(experiment_metadata::update($e['id'], str_repeat('ä', 255), '', $this->seen($e['id']))['ok']);
    }

    /**
     * AK5: without local/catquizlab:edit, no change.
     *
     * @return void
     */
    public function test_without_the_capability_nothing_changes(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $e = $this->experiment(registry::STATUS_RUNNING);
        $this->setUser($this->getDataGenerator()->create_user());
        try {
            experiment_metadata::update($e['id'], 'Hijacked', '', $this->seen($e['id']));
            $this->fail('changed without the capability');
        } catch (\required_capability_exception $ex) {
            $this->assertSame('Original', $DB->get_field('local_catquizlab_experiment', 'name', ['id' => $e['id']]));
        }
    }

    /**
     * AK4: the change is an event with old and new values and the design hash, and the history lists it.
     *
     * @return void
     */
    public function test_the_change_is_logged_and_listed(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);
        $e = $this->experiment(registry::STATUS_RUNNING);
        $sink = $this->redirectEvents();
        experiment_metadata::update($e['id'], 'Second name', 'Changed', $this->seen($e['id']));
        $events = $sink->get_events();
        $sink->close();
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf(\local_catquizlab\event\experiment_metadata_updated::class, $event);
        $this->assertSame('Original', $event->other['oldname']);
        $this->assertSame('Second name', $event->other['newname']);
        $this->assertSame(64, strlen($event->other['designhash']));

        // Through the log store: the history the page shows.
        experiment_metadata::update($e['id'], 'Third name', 'Changed', $this->seen($e['id']));
        $history = experiment_metadata::history($e['id']);
        $this->assertSame('Third name', $history[0]['newname']);
        $this->assertSame('Second name', $history[0]['oldname']);
        $this->assertNotSame('—', $history[0]['who']);
    }
}
