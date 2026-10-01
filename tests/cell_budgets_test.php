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

use local_catquizlab\form\experiment_form;
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\run_lifecycle;
use local_catquizlab\local\run_registry;
use local_catquizlab\local\sweep;
use local_catquizlab\local\test_provisioner;

/**
 * CAT parameters per concrete cell of a sweep, and reproduction (#96).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\sweep
 * @covers     \local_catquizlab\local\run_lifecycle
 */
final class cell_budgets_test extends \advanced_testcase {
    /**
     * A sweep over three strategies, and the key of each strategy's cell.
     *
     * @return array [definition, cell keys by strategy]
     */
    protected function sweep(): array {
        $definition = experiment_definition::example_baseline();
        $definition['budgets']['global'] = ['minitems' => 10, 'maxitems' => 35];
        $definition['sweep']['factors']['strategy'] = ['fastest', 'allsubs', 'classic'];
        $keys = [];
        foreach (sweep::expand(experiment_service::sweep_spec($definition))['runs'] as $run) {
            $keys[$run['definition']['strategy']] = $run['cellkey'];
        }

        return [$definition, $keys];
    }

    /**
     * The effective options of each cell.
     *
     * @param array $definition The definition.
     * @return array strategy => options
     */
    protected function options(array $definition): array {
        $out = [];
        foreach (sweep::expand(experiment_service::sweep_spec($definition))['runs'] as $run) {
            $out[$run['definition']['strategy']] = test_provisioner::options_from_definition($run['definition']);
        }

        return $out;
    }

    /**
     * A cell's budget applies to that cell alone, over its strategy's.
     *
     * @return void
     */
    public function test_a_cell_budget_applies_to_its_cell_alone(): void {
        $this->resetAfterTest();
        [$definition, $keys] = $this->sweep();
        $definition['budgetsbystrategy'] = ['allsubs' => ['global' => ['maxitems' => 80]]];
        $definition['budgetsbycell'] = [
            $keys['allsubs'] => ['global' => ['maxitems' => 25], 'se' => ['min' => 0.3]],
            $keys['classic'] => ['global' => ['maxitems' => 12]],
        ];

        $options = $this->options($definition);
        $this->assertSame(25, $options['allsubs']['maxquestions'], "the cell over its strategy's 80");
        $this->assertSame(0.3, $options['allsubs']['se_min']);
        $this->assertSame(35, $options['fastest']['maxquestions'], 'the other cells untouched');
        // A cell maximum for the classical test wins over its "unlimited" default.
        $this->assertSame(12, $options['classic']['maxquestions']);

        unset($definition['budgetsbycell'][$keys['classic']]);
        $this->assertSame(-1, $this->options($definition)['classic']['maxquestions']);
    }

    /**
     * Inconsistent cell budgets are refused.
     *
     * @return void
     */
    public function test_cell_budgets_are_validated(): void {
        [$definition, $keys] = $this->sweep();
        $definition['budgetsbycell'] = [$keys['fastest'] => ['global' => ['minitems' => 30, 'maxitems' => 20]]];
        $this->assertFalse((new experiment_definition($definition))->validate()['valid']);

        $definition['budgetsbycell'] = [$keys['fastest'] => ['global' => ['maxitems' => 'unlimited']]];
        $this->assertTrue((new experiment_definition($definition))->validate()['valid']);
    }

    /**
     * The preview names the cell, the model and the SE bounds, and marks a cell budget.
     *
     * @return void
     */
    public function test_the_preview_shows_every_parameter_of_a_cell(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$definition, $keys] = $this->sweep();
        $definition['budgetsbycell'] = [$keys['allsubs'] => ['global' => ['maxitems' => 25]]];

        $rows = [];
        foreach (experiment_service::preview($definition)['budgetrows'] as $row) {
            $rows[$row['cellkey']] = $row;
        }
        $allsubs = $rows[$keys['allsubs']];
        $this->assertTrue($allsubs['cellbudget']);
        $this->assertSame('25', $allsubs['globalmax']);
        $this->assertNotSame('', $allsubs['model']);
        $this->assertStringContainsString('strategy allsubs', $allsubs['factors']);
        $this->assertSame(get_string('form:na_short', 'local_catquizlab'), $rows[$keys['classic']]['semin']);
        $this->assertFalse($rows[$keys['fastest']]['cellbudget']);
    }

    /**
     * The form writes and reads the cell budgets.
     *
     * @return void
     */
    public function test_the_form_keeps_cell_budgets(): void {
        $this->resetAfterTest();
        [$definition, $keys] = $this->sweep();
        $definition['budgetsbycell'] = [$keys['allsubs'] => ['global' => ['maxitems' => 25], 'se' => ['min' => 0.3]]];

        $data = experiment_form::to_form_data((new experiment_definition($definition))->get_normalised(), 0);
        $prefix = experiment_form::cell_prefix($keys['allsubs']);
        $this->assertSame('25', $data[$prefix . 'globalmax']);
        $data[$prefix . 'key'] = $keys['allsubs'];

        $back = experiment_form::to_definition($data);
        $this->assertEquals(['global' => ['maxitems' => 25.0], 'se' => ['min' => 0.3]], $back['budgetsbycell'][$keys['allsubs']]);
    }

    /**
     * A reproduction is provisioned with exactly the parameters of its original.
     *
     * @return void
     */
    public function test_a_reproduction_keeps_the_effective_parameters(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$definition, $keys] = $this->sweep();
        $definition['budgetsbycell'] = [$keys['allsubs'] => ['global' => ['maxitems' => 25]]];
        $id = (int) experiment_service::save($definition)['id'];
        experiment_service::create_sweep($id);
        $original = null;
        foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => $id]) as $run) {
            if ((run_registry::definition_for($run)['strategy'] ?? '') === 'allsubs') {
                $original = $run;
            }
        }
        $this->assertNotNull($original);

        // The experiment changes afterwards; the reproduction does not follow it.
        $DB->set_field(
            'local_catquizlab_experiment',
            'configjson',
            json_encode(['budgets' => ['global' => ['maxitems' => 5]]]),
            ['id' => $id]
        );
        $copy = $DB->get_record('local_catquizlab_run', ['id' => run_lifecycle::reproduce((int) $original->id)]);

        $this->assertSame(
            test_provisioner::effective_parameters(run_registry::definition_for($original)),
            test_provisioner::effective_parameters(run_registry::definition_for($copy))
        );
        $this->assertSame(25, test_provisioner::options_from_definition(run_registry::definition_for($copy))['maxquestions']);
        $this->assertSame($original->seed, $copy->seed);
        $this->assertSame(
            (int) $original->id,
            (int) json_decode($copy->manifestjson, true)['config']['reproducedfrom']
        );
    }
}
