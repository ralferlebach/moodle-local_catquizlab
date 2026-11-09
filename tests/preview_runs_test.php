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
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\run_registry;
use local_catquizlab\local\strategy_catalog;
use local_catquizlab\local\test_provisioner;

/**
 * What the preview promises is what the created runs carry; save, edit and copy lose nothing (#126).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\experiment_service
 * @covers     \local_catquizlab\local\manifest
 * @covers     \local_catquizlab\local\test_provisioner
 */
final class preview_runs_test extends \advanced_testcase {
    /**
     * A 2 × 2 × 2 sweep: strategy × global budget × SE target, two replications — sixteen runs.
     *
     * @return array
     */
    protected function definition(): array {
        $definition = experiment_definition::example_baseline();
        $definition['name'] = 'Preview versus runs';
        $definition['replications'] = 2;
        $definition['sweep'] = ['factors' => [
            'strategy' => ['fastest', 'lowestsub'],
            'globalbudget' => [['minitems' => 5, 'maxitems' => 12], ['minitems' => 8, 'maxitems' => 30]],
            'se' => [['min' => 0.2, 'max' => 0.35], ['min' => 0.25, 'max' => 0.5]],
        ]];

        return $definition;
    }

    /**
     * The definition as the service stores it, normalised.
     *
     * @param int $id The experiment.
     * @return array
     */
    protected function stored(int $id): array {
        global $DB;

        $json = (string) $DB->get_field('local_catquizlab_experiment', 'configjson', ['id' => $id]);

        return (new experiment_definition(json_decode($json, true)))->get_normalised();
    }

    /**
     * Every cell of the preview is a set of created runs, and every effective parameter agrees with it.
     *
     * @return void
     */
    public function test_each_preview_cell_is_what_the_runs_carry(): void {
        global $DB;
        $this->resetAfterTest();

        $definition = $this->definition();
        $preview = experiment_service::preview($definition);
        $this->assertSame([], $preview['errors']);
        $this->assertSame(8, $preview['cells']);
        $this->assertSame(16, $preview['runs']);
        $rows = array_column($preview['budgetrows'], null, 'cellkey');
        $this->assertCount(8, $rows);

        $id = (int) experiment_service::save($definition)['id'];
        $created = experiment_service::create_sweep($id);
        $this->assertSame($preview['runs'], $created['created']);
        $this->assertSame($preview['cells'], $created['cells']);

        $percell = [];
        $combinations = [];
        foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => $id], 'id') as $run) {
            $key = (string) $run->cellkey;
            $this->assertArrayHasKey($key, $rows, "run {$run->id}: a cell the preview did not show");
            $percell[$key] = ($percell[$key] ?? 0) + 1;

            $row = $rows[$key];
            $definitionofrun = run_registry::definition_for($run);
            $effective = test_provisioner::effective_parameters($definitionofrun);
            $manifest = json_decode((string) $run->manifestjson, true);

            // What the manifest recorded is what the definition it carries gives — nothing lost on the way.
            $this->assertSame(
                json_encode($effective),
                json_encode($manifest['config']['cat']),
                "run {$run->id}: the recorded effective parameters"
            );
            // And it is what the preview promised for that cell.
            $strategy = $effective['strategy']['key'];
            $this->assertSame(strategy_catalog::label($strategy), $row['strategy'], "cell {$key}: strategy");
            $this->assertSame((string) $effective['budgets']['global']['minitems'], $row['globalmin'], "cell {$key}: min");
            $this->assertSame((string) $effective['budgets']['global']['maxitems'], $row['globalmax'], "cell {$key}: max");
            $this->assertSame(format_float($effective['se']['min'], 2), $row['semin'], "cell {$key}: SE min");
            $this->assertSame(format_float($effective['se']['max'], 2), $row['semax'], "cell {$key}: SE max");
            // The options handed to the engine are the effective ones.
            $options = test_provisioner::options_from_definition($definitionofrun);
            $this->assertSame($effective['budgets']['global']['maxitems'], $options['maxquestions']);
            $this->assertSame($effective['strategy']['engineid'], $options['teststrategy']);

            $combinations[$strategy . '|' . $options['maxquestions'] . '|' . $effective['se']['max']] = true;
        }
        // Every cell twice (two replications), and all eight combinations really exist.
        $this->assertSame(array_fill_keys(array_keys($rows), 2), $percell);
        $this->assertCount(8, $combinations);
    }

    /**
     * Saving, editing and copying keep every field: the stored definitions differ only in the name.
     *
     * @return void
     */
    public function test_save_edit_and_copy_are_lossless(): void {
        $this->resetAfterTest();

        $definition = $this->definition();
        $original = (new experiment_definition($definition))->get_normalised();

        $id = (int) experiment_service::save($definition)['id'];
        $this->assertSame($original, $this->stored($id), 'save');

        // Edit: one field changed, every other one kept.
        $edited = $definition;
        $edited['replications'] = 3;
        experiment_service::save($edited, $id);
        $afteredit = $this->stored($id);
        $this->assertSame(3, (int) $afteredit['replications']);
        $afteredit['replications'] = $original['replications'];
        $this->assertSame($original, $afteredit, 'edit: nothing but the edited field changed');

        // Copy: the same definition under another name.
        experiment_service::save($definition, $id);
        $copyid = experiment_service::duplicate($id);
        $copy = $this->stored($copyid);
        $this->assertNotSame($original['name'], $copy['name']);
        $copy['name'] = $original['name'];
        $this->assertSame($original, $copy, 'copy');

        // And the copy previews to the same cells with the same parameters.
        global $DB;
        $copydefinition = json_decode((string) $DB->get_field(
            'local_catquizlab_experiment',
            'configjson',
            ['id' => $copyid]
        ), true);
        $this->assertSame(
            array_column(experiment_service::preview($definition)['budgetrows'], null, 'cellkey'),
            array_column(experiment_service::preview($copydefinition)['budgetrows'], null, 'cellkey')
        );
    }
}
