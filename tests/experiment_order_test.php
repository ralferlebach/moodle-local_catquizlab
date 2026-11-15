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

use local_catquizlab\local\experiment_order;
use local_catquizlab\local\progress_overview;
use local_catquizlab\output\shell;

/**
 * Experiments A–Z everywhere (#153): library, selector, overview; stable, whatever changes.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\experiment_order
 * @covers     \local_catquizlab\output\shell
 */
final class experiment_order_test extends \advanced_testcase {
    /**
     * Names with umlauts, case, numbers and duplicates, and 55 more.
     *
     * @return array<int, string> Id => name, in creation order.
     */
    protected function experiments(): array {
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $names = ['zebra', 'Äpfel', 'apfel', 'Apfel', 'Öl', 'oel', 'Run 10', 'Run 9', 'beta', 'Beta', 'ätzend', 'Zürich'];
        for ($i = 1; $i <= 55; $i++) {
            $names[] = sprintf('Experiment %d', $i);
        }
        $out = [];
        foreach ($names as $name) {
            $out[(int) $generator->create_experiment(['name' => $name])->id] = $name;
        }

        return $out;
    }

    /**
     * AK1, AK2: A–Z by the language's collation, case ignored, numbers by value, equal names by id.
     *
     * @return void
     */
    public function test_the_order_is_alphabetical_and_deterministic(): void {
        $this->resetAfterTest();
        force_current_language('en');
        $experiments = $this->experiments();
        $sorted = experiment_order::sort(array_map(
            static fn(int $id, string $name): array => ['id' => $id, 'name' => $name],
            array_keys($experiments),
            $experiments
        ));
        $names = array_column(array_values($sorted), 'name');

        // Numbers by value.
        $this->assertLessThan(array_search('Experiment 10', $names), array_search('Experiment 9', $names));
        $this->assertLessThan(array_search('Run 10', $names), array_search('Run 9', $names));
        // Case ignored: apfel/Apfel together, in id order (created apfel before Apfel).
        $apfel = array_keys(array_filter($names, static fn(string $n): bool => strcasecmp($n, 'apfel') === 0));
        $this->assertSame(1, $apfel[1] - $apfel[0]);
        $this->assertSame('apfel', $names[$apfel[0]], 'equal names: the older one first');
        // Umlauts next to their base letter, not after z.
        $this->assertLessThan(array_search('beta', $names), array_search('Äpfel', $names));
        $this->assertLessThan(array_search('zebra', $names), array_search('Öl', $names));
        $this->assertSame('Zürich', end($names));

        // The same input in another order gives the same output.
        $shuffled = array_map(
            static fn(int $id, string $name): array => ['id' => $id, 'name' => $name],
            array_keys($experiments),
            $experiments
        );
        shuffle($shuffled);
        $this->assertSame($names, array_column(array_values(experiment_order::sort($shuffled)), 'name'));
    }

    /**
     * AK1, AK4, AK5, AK6: the selector on every tab has every experiment, A–Z, also past 50; changing timestamps and
     * states does not move anything; the overview has the same order.
     *
     * @return void
     */
    public function test_the_selector_lists_all_in_the_same_order(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        force_current_language('en');
        $experiments = $this->experiments();
        $selector = array_column(shell::context('plan', array_key_last($experiments))['experiments'], 'name');
        $this->assertCount(count($experiments), $selector, 'not cut at 50');
        $this->assertContains('Zürich', $selector);
        $selected = array_values(array_filter(
            shell::context('plan', array_key_last($experiments))['experiments'],
            static fn(array $e): bool => $e['selected']
        ));
        $this->assertSame(array_key_last($experiments), $selected[0]['id'], 'the one in view is in the list');

        // A change to one experiment and a new state do not move anything.
        $first = array_key_first($experiments);
        $DB->set_field('local_catquizlab_experiment', 'timemodified', time() + 1000, ['id' => $first]);
        $DB->set_field('local_catquizlab_experiment', 'status', 2, ['id' => $first]);
        shell::reset_for_testing();
        $this->assertSame($selector, array_column(shell::context('results', 0)['experiments'], 'name'));

        // The overview of all experiments: the same order.
        $overview = array_column(progress_overview::build(0, 200)['experiments'], 'name');
        $this->assertSame($selector, $overview);
    }

    /**
     * Rename: the experiment moves to its new place, the others keep theirs.
     *
     * @return void
     */
    public function test_a_rename_moves_only_that_experiment(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        force_current_language('en');
        $experiments = $this->experiments();
        $before = array_column(shell::context('plan', 0)['experiments'], 'name');
        $zebra = array_search('zebra', $experiments, true);
        $DB->set_field('local_catquizlab_experiment', 'name', 'Aardvark', ['id' => $zebra]);
        shell::reset_for_testing();
        $after = array_column(shell::context('plan', 0)['experiments'], 'name');
        $this->assertSame('Aardvark', $after[0]);
        $this->assertSame(array_values(array_diff($before, ['zebra'])), array_slice($after, 1));
    }
}
