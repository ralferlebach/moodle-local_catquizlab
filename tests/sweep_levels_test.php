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

/**
 * Levels of the varied factors: read, or refused with their line; saved and shown back without loss (#126).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\form\experiment_form
 */
final class sweep_levels_test extends \advanced_testcase {
    /**
     * Item budgets: a readable level kept; every other non-empty line refused with its number and why.
     *
     * @return void
     */
    public function test_item_budgets(): void {
        $this->resetAfterTest();

        $parsed = experiment_form::parse_levels("10/15\n40-15\n20/10\n\n10/15\n0/0\n 25 / 30 ", 'items');
        $this->assertSame([['minitems' => 10, 'maxitems' => 15], ['minitems' => 25, 'maxitems' => 30]], $parsed['levels']);
        $this->assertCount(4, $parsed['errors']);
        $this->assertStringContainsString('Line 2 ("40-15"): not min/max in whole numbers', $parsed['errors'][0]);
        $this->assertStringContainsString('Line 3 ("20/10"): the minimum is above the maximum', $parsed['errors'][1]);
        $this->assertStringContainsString('Line 5 ("10/15"): the same level twice', $parsed['errors'][2]);
        $this->assertStringContainsString('Line 6 ("0/0"): at least one item', $parsed['errors'][3]);
        // Decimals are not item counts.
        $this->assertNotEmpty(experiment_form::parse_levels('2.5/10', 'items')['errors']);
        // Empty is "not varied", not an error.
        $this->assertSame(['levels' => [], 'errors' => []], experiment_form::parse_levels("\n  \n", 'items'));
    }

    /**
     * SE windows and strengths, with the local decimal comma.
     *
     * @return void
     */
    public function test_se_windows_and_strengths(): void {
        $this->resetAfterTest();

        $se = experiment_form::parse_levels("0,3/0,5\n0/0.5\n0.4/x", 'se');
        $this->assertSame([['min' => 0.3, 'max' => 0.5]], $se['levels']);
        $this->assertStringContainsString('above 0', $se['errors'][0]);
        $this->assertStringContainsString('not min/max', $se['errors'][1]);

        $strengths = experiment_form::parse_levels("10\nshift=1.0\nfoo=2\n150\nfactor=x\n0.5\nsd=0,2", 'strength');
        $this->assertSame([['fraction' => 0.1], ['shift' => 1.0], ['fraction' => 0.5], ['sd' => 0.2]], $strengths['levels']);
        $this->assertCount(3, $strengths['errors']);
        $this->assertStringContainsString('unknown key; possible: fraction, shift, factor, sd', $strengths['errors'][0]);
        $this->assertStringContainsString('at most 100', $strengths['errors'][1]);
        $this->assertStringContainsString('not a number', $strengths['errors'][2]);
    }

    /**
     * The real form refuses a non-empty unreadable level on its field, with the line (AK 2).
     *
     * @return void
     */
    public function test_the_form_refuses_an_unreadable_level(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        experiment_form::mock_submit([
            'name' => 'Levels', 'strategy' => 'classic', 'globalmin' => 10, 'globalmax' => 20,
            'subscalemin' => 1, 'subscalemax' => 5,
            'sweepglobalbudgets' => "10/15\n40-15", 'sweepstrengths' => 'foo=1',
        ]);
        $form = new experiment_form(null, []);
        $this->assertNull($form->get_data());
        $property = new \ReflectionProperty(\moodleform::class, '_form');
        $property->setAccessible(true);
        $errors = (array) $property->getValue($form)->_errors;
        $this->assertArrayHasKey('sweepglobalbudgets', $errors);
        $this->assertStringContainsString('Line 2', $errors['sweepglobalbudgets']);
        $this->assertArrayHasKey('sweepstrengths', $errors);
    }

    /**
     * Saved and shown back without loss; the preview counts the runs the sweep creates (AK 3, AK 5).
     *
     * @return void
     */
    public function test_levels_survive_save_reload_and_match_the_runs(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $definition = experiment_definition::example_baseline();
        $definition['name'] = 'Levels round trip';
        $definition['sweep'] = ['factors' => [
            'strategy' => ['classic', 'fastest'],
            'globalbudget' => experiment_form::parse_pairs("10/15\n20/30", 'items'),
            'se' => experiment_form::parse_pairs("0,3/0,5", 'se'),
        ]];
        $id = (int) experiment_service::save($definition)['id'];
        $stored = json_decode((string) $DB->get_field('local_catquizlab_experiment', 'configjson', ['id' => $id]), true);

        $form = experiment_form::to_form_data($stored, $id);
        $this->assertSame("10/15\n20/30", $form['sweepglobalbudgets']);
        $this->assertSame('0.3/0.5', $form['sweepse']);
        $this->assertSame('', $form['sweepsubscalebudgets'], 'not varied stays empty');
        // Shown back, read again: the same levels.
        $this->assertSame(
            $stored['sweep']['factors']['globalbudget'],
            experiment_form::parse_pairs($form['sweepglobalbudgets'], 'items')
        );

        $preview = experiment_service::preview($stored);
        experiment_service::create_sweep($id);
        $runs = $DB->count_records('local_catquizlab_run', ['experimentid' => $id]);
        $this->assertSame($preview['runs'], $runs);
        $this->assertSame(2 * 2 * (int) $preview['replications'], $runs, 'two strategies × two budgets');
    }
}
