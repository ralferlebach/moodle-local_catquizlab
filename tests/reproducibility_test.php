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
 * What somebody needs to believe a result.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\registry;
use local_catquizlab\local\reproducibility;

/**
 * Reproducibility tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\reproducibility
 */
final class reproducibility_test extends \advanced_testcase {
    /**
     * A run with attempts in a given state.
     *
     * @param int $runstatus The run status.
     * @param int $collected How many collected attempts.
     * @param int $failed How many failed ones.
     * @return int The experiment id.
     */
    protected function make_experiment(int $runstatus, int $collected, int $failed = 0): int {
        global $DB;

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $generator->create_run()->id;
        $DB->set_field('local_catquizlab_run', 'status', $runstatus, ['id' => $runid]);

        $wanted = [
            attempt_scheduler::STATUS_COLLECTED => $collected,
            attempt_scheduler::STATUS_FAILED    => $failed,
        ];

        foreach ($wanted as $status => $count) {
            for ($i = 0; $i < $count; $i++) {
                $DB->insert_record('local_catquizlab_attempt', (object) [
                    'runid' => $runid, 'personid' => 0, 'status' => $status, 'tries' => 1,
                    'nextruntime' => 0, 'timecreated' => time(), 'timemodified' => time(),
                ]);
            }
        }

        return (int) $DB->get_field('local_catquizlab_run', 'experimentid', ['id' => $runid]);
    }

    /**
     * A finished experiment with every attempt collected says so.
     *
     * @return void
     */
    public function test_a_complete_experiment_is_complete(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $experimentid = $this->make_experiment(registry::STATUS_FINISHED, 4);

        $completeness = reproducibility::completeness($experimentid);

        $this->assertTrue($completeness['complete']);
        $this->assertSame(4, $completeness['attempts']['collected']);
        $this->assertFalse($completeness['hasunfinished']);
    }

    /**
     * Missing attempts are named before the analyses, not implied by them.
     *
     * @return void
     */
    public function test_missing_attempts_are_named(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $experimentid = $this->make_experiment(registry::STATUS_FAILED, 2, 3);

        $completeness = reproducibility::completeness($experimentid);

        // A mean over 2 of 5 attempts is not a worse version of the same
        // number, it is a different number.
        $this->assertFalse($completeness['complete']);
        $this->assertSame(2, $completeness['attempts']['collected']);
        $this->assertSame(3, $completeness['attempts']['failed']);
        $this->assertTrue($completeness['hasunfinished']);
        $this->assertNotSame('', $completeness['progressurl']);
    }

    /**
     * The package carries what makes a result checkable.
     *
     * @return void
     */
    public function test_the_package_carries_the_evidence(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $experimentid = $this->make_experiment(registry::STATUS_FINISHED, 2);

        $package = reproducibility::package($experimentid);

        foreach (['generated', 'experiment', 'versions', 'moodle', 'runs', 'completeness'] as $key) {
            $this->assertArrayHasKey($key, $package);
        }

        // The seeds are the whole claim to reproducibility: without them the
        // design is a description and not an instruction.
        $this->assertArrayHasKey('seed', $package['runs'][0]);
        $this->assertArrayHasKey('masterseed', $package['runs'][0]);

        // A result from a version nobody can name is a result nobody can
        // reproduce.
        $this->assertArrayHasKey('local_catquizlab', $package['versions']);
        $this->assertNotSame('absent', $package['versions']['local_catquizlab']);

        // And the definition as the plugin understood it, not only as it was
        // typed: what ran is what matters.
        $this->assertArrayHasKey('normalised', $package['experiment']);
    }

    /**
     * The package says so when it can no longer be replayed.
     *
     * @return void
     */
    public function test_an_unreadable_definition_is_reported(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $experimentid = $this->make_experiment(registry::STATUS_FINISHED, 1);
        $DB->set_field('local_catquizlab_experiment', 'configjson', 'not json at all', ['id' => $experimentid]);

        $package = reproducibility::package($experimentid);

        // A definition the plugin can no longer read means this package cannot
        // be replayed as it stands, and saying so beats a package that looks
        // complete. A definition it *can* read but with fields missing is a
        // different case: the plugin fills its own defaults, and those defaults
        // are what ran.
        $this->assertArrayHasKey('error', $package['experiment']['normalised']);
    }

    /**
     * Each run carries what the engine was given: definition, effective parameters, stop rules (#121).
     *
     * @return void
     */
    public function test_each_run_carries_its_effective_parameters(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $experimentid = $this->make_experiment(registry::STATUS_FINISHED, 1);
        $definition = \local_catquizlab\local\experiment_definition::example_baseline();
        $definition['strategy'] = 'fastest';
        $definition['budgets']['global'] = ['minitems' => 7, 'maxitems' => 23];
        $DB->set_field(
            'local_catquizlab_run',
            'manifestjson',
            json_encode(['config' => ['definition' => $definition]]),
            ['experimentid' => $experimentid]
        );

        $run = reproducibility::package($experimentid)['runs'][0];

        $this->assertSame('fastest', $run['definition']['strategy']);
        $this->assertIsArray($run['effective']);
        $this->assertNotEmpty($run['stoprules']);
        $this->assertStringContainsString('23', implode(' ', $run['stoprules']), 'the maximum the run was given');
    }

    /**
     * The file is JSON, parseable, without secrets; an unknown experiment is an error that says so.
     *
     * @return void
     */
    public function test_the_package_file_is_json_without_secrets(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $experimentid = $this->make_experiment(registry::STATUS_FINISHED, 1);
        $runid = (int) $DB->get_field('local_catquizlab_run', 'id', ['experimentid' => $experimentid]);
        \local_catquizlab\local\run_log::record($runid, 'start_requested', ['url' => 'https://x/?token=abcdef123&sesskey=XyZ987']);

        $json = reproducibility::package_json($experimentid);
        $this->assertIsArray(json_decode($json, true), 'parseable');
        $this->assertStringNotContainsString('abcdef123', $json);
        $this->assertStringNotContainsString('XyZ987', $json);
        $this->assertStringContainsString('token=[redacted]', $json);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('There is no experiment with the id 987654');
        reproducibility::package_json(987654);
    }
}
