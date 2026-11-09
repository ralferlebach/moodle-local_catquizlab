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

use local_catquizlab\local\environment;
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\provisioning_check;
use local_catquizlab\local\registry;
use local_catquizlab\local\run_contract;
use local_catquizlab\local\run_lifecycle;
use local_catquizlab\local\run_orchestrator;

/**
 * Configuration → engine settings, read back from a real engine after a real setup (#123, 0.7.50).
 *
 * The contract checks had been tested against settings written by hand; the read-back after a real provisioning
 * existed only in the worker end-to-end run. Here every run is set up through the orchestrator against the
 * installed CAT engine, and what the engine holds is read back and compared with what the definition asked for —
 * for several configurations of each kind of override: strategy, global budget, subscale budget, SE target.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\run_orchestrator
 * @covers     \local_catquizlab\local\provisioning_check
 * @covers     \local_catquizlab\local\run_contract
 */
final class provisioning_readback_test extends \advanced_testcase {
    /**
     * The configurations: at least two per kind of override.
     *
     * @return array<string, array{0: string, 1: array}>
     */
    public static function configurations(): array {
        $budget = static fn(int $gmin, int $gmax, int $smin, int $smax, float $semin, float $semax): array => [
            'global' => ['minitems' => $gmin, 'maxitems' => $gmax],
            'subscale' => ['minitems' => $smin, 'maxitems' => $smax],
            'se' => ['min' => $semin, 'max' => $semax],
        ];

        return [
            'strategy fastest' => ['fastest', $budget(2, 4, 1, 2, 0.35, 1.0)],
            'strategy allsubs' => ['allsubs', $budget(2, 4, 1, 2, 0.35, 1.0)],
            'strategy classic' => ['classic', $budget(2, 4, 1, 2, 0.35, 1.0)],
            'global budget 3–6' => ['allsubs', $budget(3, 6, 1, 2, 0.35, 1.0)],
            'global budget 1–8' => ['allsubs', $budget(1, 8, 1, 2, 0.35, 1.0)],
            'subscale budget 1–3' => ['lowestsub', $budget(2, 8, 1, 3, 0.35, 1.0)],
            'subscale budget 2–2' => ['lowestsub', $budget(2, 8, 2, 2, 0.35, 1.0)],
            'SE target 0.30' => ['allsubs', $budget(2, 6, 1, 2, 0.30, 1.0)],
            'SE target 0.50' => ['allsubs', $budget(2, 6, 1, 2, 0.50, 0.9)],
        ];
    }

    /**
     * Set up a run of a definition against the engine and read back what the engine holds.
     *
     * @dataProvider configurations
     * @param string $strategy The strategy.
     * @param array $budgets The budgets.
     * @return void
     */
    public function test_the_engine_holds_what_was_asked_for(string $strategy, array $budgets): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        if (!environment::engine_available()) {
            $this->markTestSkipped('The CAT engine (local_catquiz, mod_adaptivequiz) is not installed.');
        }

        // The course the experiments live in, as the preparation step configures it.
        $course = $this->getDataGenerator()->create_course();
        set_config('experimentcourseid', $course->id, 'local_catquizlab');

        $definition = experiment_definition::example_baseline();
        $definition['name'] = 'read-back ' . $strategy;
        $definition['strategy'] = $strategy;
        $definition['persons']['count'] = 2;
        $definition['pool']['scales'] = ['categories' => 2, 'subcategories' => 2, 'itemspersubscale' => 4];
        $definition['budgets'] = $budgets;

        $saved = experiment_service::save($definition);
        $sweep = experiment_service::create_sweep((int) $saved['id']);
        $runid = (int) reset($sweep['runs']);
        run_lifecycle::set_status($runid, registry::STATUS_SCHEDULED, 'test');

        $setup = run_orchestrator::setup($runid);
        $this->assertTrue($setup['ok'], 'set up against the engine: ' . json_encode($setup));
        $this->assertGreaterThan(0, (int) $DB->get_field('local_catquizlab_run', 'testcmid', ['id' => $runid]));

        // What the engine holds, read back from the engine's own records.
        $check = provisioning_check::compare($runid);
        $this->assertTrue($check['ok'], 'differences: ' . implode('; ', $check['differences']));
        $this->assertNotEmpty($check['checked'], 'something was read back');

        // The chain from request to engine: nothing provisioned differs from what was in force.
        $chain = run_contract::chain($runid);
        foreach ($chain['rows'] as $row) {
            if ($row['provisioned'] === null || $row['effective'] === null) {
                continue;
            }
            $this->assertEquals($row['effective'], $row['provisioned'], 'parameter ' . $row['param']);
        }
    }
}
