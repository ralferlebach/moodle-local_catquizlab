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
use local_catquizlab\local\results_query;
use local_catquizlab\output\results_page;

/**
 * The factor menus offer every value of the experiment, whatever is selected (#122).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\results_query
 * @covers     \local_catquizlab\output\results_page
 */
final class filter_factors_test extends \advanced_testcase {
    /**
     * An experiment of three runs: (classic, 2pl), (fastest, 2pl), (classic, 1pl) — no (fastest, 1pl).
     *
     * @return int The experiment.
     */
    protected function experiment(): int {
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $experiment = $generator->create_experiment();
        foreach ([['classic', '2pl'], ['fastest', '2pl'], ['classic', '1pl']] as [$strategy, $model]) {
            $definition = experiment_definition::example_baseline();
            $definition['strategy'] = $strategy;
            $definition['model'] = $model;
            $generator->create_run([
                'experimentid' => $experiment->id,
                'manifestjson' => json_encode(['config' => ['definition' => $definition]]),
            ]);
        }

        return (int) $experiment->id;
    }

    /**
     * A chosen strategy leaves the other strategies in its own menu, and counts the rest.
     *
     * @return void
     */
    public function test_a_factor_does_not_trim_its_own_menu(): void {
        $this->resetAfterTest();
        $id = $this->experiment();

        $query = new results_query(['experimentid' => $id, 'strategy' => 'classic']);
        $this->assertSame(['classic', 'fastest'], array_keys($query->factor_options('strategy')));
        // Under model "any": classic has two runs, fastest one.
        $this->assertSame([2, 1], array_column($query->factor_options('strategy'), 'runs'));
        // The model menu keeps both models; with classic chosen, each has one run.
        $this->assertSame(['1pl', '2pl'], array_keys($query->factor_options('model')));
        $this->assertSame([1, 1], array_column($query->factor_options('model'), 'runs'));
        // The filtered selection itself is unchanged: two classic runs.
        $this->assertCount(2, $query->runs());
    }

    /**
     * A combination without data is offered and says so: 0 runs.
     *
     * @return void
     */
    public function test_an_empty_combination_is_visible_and_choosable(): void {
        $this->resetAfterTest();
        $id = $this->experiment();

        $query = new results_query(['experimentid' => $id, 'strategy' => 'fastest', 'model' => '2pl']);
        $models = $query->factor_options('model');
        $this->assertSame(0, $models['1pl']['runs'], 'fastest was never run with 1pl');
        $this->assertSame(1, $models['2pl']['runs']);
        $strategies = $query->factor_options('strategy');
        $this->assertSame(['classic' => 1, 'fastest' => 1], array_combine(
            array_keys($strategies),
            array_column($strategies, 'runs')
        ));

        // A value in the address the experiment does not have stays the selected one, with no runs.
        $stale = (new results_query(['experimentid' => $id, 'strategy' => 'relsubs']))->factor_options('strategy');
        $this->assertSame(0, $stale['relsubs']['runs']);
    }

    /**
     * The filter bar renders every value, with its count, and keeps the selection.
     *
     * @return void
     */
    public function test_the_filter_bar_offers_every_value(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = $this->experiment();
        $filter = ['experimentid' => $id, 'strategy' => 'fastest', 'validity' => 'valid', 'validityrule' => 'uniform'];

        $html = (new results_page(new results_query($filter), 'overview', $filter))->render_filter_bar();

        $this->assertMatchesRegularExpression('/<option value="classic"[^>]*>[^<]*\(2\)/', $html);
        $this->assertMatchesRegularExpression('/<option selected="selected" value="fastest">[^<]*\(1\)/', $html);
        $this->assertMatchesRegularExpression('/<option value="1pl"[^>]*>[^<]*\(0\)/', $html);
    }

    /**
     * A combination that was never run says so, with the way back — not "no results yet".
     *
     * @return void
     */
    public function test_an_impossible_combination_is_named(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = $this->experiment();
        $filter = ['experimentid' => $id, 'strategy' => 'fastest', 'model' => '1pl', 'validity' => 'valid',
            'validityrule' => 'uniform'];

        $html = (new results_page(new results_query($filter), 'overview', $filter))->render_provenance();

        $this->assertStringContainsString('No observations match these filters', $html);
        $this->assertStringContainsString('1PL', $html);
        $this->assertStringNotContainsString('yet', strip_tags($html));
        $this->assertMatchesRegularExpression('/href="[^"]*experimentid=' . $id . '[^"]*"[^>]*>Reset filter/', $html);
    }
}
