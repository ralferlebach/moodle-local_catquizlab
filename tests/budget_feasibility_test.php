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

use local_catquizlab\local\budget_feasibility;

/**
 * Question budgets against the pool they are given.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\budget_feasibility
 */
final class budget_feasibility_test extends \advanced_testcase {
    /** @var array The budgets of the reported form: 15 to 25 questions, 3 to 5 per subscale. */
    protected const REPORTED = ['globalmin' => 15, 'globalmax' => 25, 'subscalemin' => 3, 'subscalemax' => 5];

    /**
     * The rules, one by one.
     *
     * @return void
     */
    public function test_the_rules(): void {
        $this->resetAfterTest();
        $codes = static fn(string $strategy, array $budgets, int $leaves, int $items): array =>
            array_column(budget_feasibility::check($strategy, $budgets, $leaves, $items), 'code');

        // Covering 100 subscales at 3 each needs 300 questions; 25 are allowed.
        $problems = budget_feasibility::check('allsubs', self::REPORTED, 100, 25);
        $this->assertSame(['floorabovemaximum'], array_column($problems, 'code'));
        $this->assertSame('globalmax', $problems[0]['field']);
        $this->assertStringContainsString('300', budget_feasibility::message($problems[0]));
        // The others are not bound to fill every subscale.
        $this->assertSame([], $codes('relsubs', self::REPORTED, 100, 25));
        // The classical test has no budget at all.
        $this->assertSame([], $codes('classic', ['globalmin' => 500, 'globalmax' => 1], 100, 25));

        // A ceiling per subscale every strategy with subscale budgets obeys: 1 × 4 subscales < 15.
        $this->assertSame(
            ['ceilingbelowminimum'],
            $codes('relsubs', ['globalmin' => 15, 'globalmax' => 35, 'subscalemin' => 1, 'subscalemax' => 1], 4, 25)
        );
        // Fewer items in a subscale than its minimum.
        $this->assertSame(
            ['floorabovepool'],
            $codes('allsubs', ['globalmin' => 5, 'globalmax' => 100, 'subscalemin' => 8, 'subscalemax' => 10], 2, 5)
        );
        // Fewer items in the pool than the minimum.
        $this->assertContains(
            'poolbelowminimum',
            $codes('fastest', ['globalmin' => 30, 'globalmax' => 35, 'subscalemin' => 1, 'subscalemax' => 10], 2, 10)
        );
        // A minimum above the maximum.
        $this->assertSame(['minabovemax'], $codes('fastest', ['globalmin' => 30, 'globalmax' => 20], 0, 0));
        // An unlimited maximum is no conflict.
        $this->assertSame([], $codes('allsubs', ['globalmax' => 'unlimited'] + self::REPORTED, 100, 25));
    }

    /**
     * The browser gets the same rules and the messages with their placeholders.
     *
     * @return void
     */
    public function test_the_client_rules(): void {
        $this->resetAfterTest();
        $rules = budget_feasibility::client_rules();
        $this->assertTrue($rules['strategies']['allsubs']['floor']);
        $this->assertFalse($rules['strategies']['classic']['global']);
        $this->assertFalse($rules['strategies']['classic']['subscale']);
        foreach (budget_feasibility::CODES as $code) {
            $this->assertStringContainsString('{$a->strategy}', $rules['messages'][$code]);
        }
    }

    /**
     * The real form, with the reported values, refuses the budget — on the field to change.
     *
     * @return void
     */
    public function test_the_form_refuses_the_reported_budget(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $values = [
            'name' => 'Reported 06.10.', 'strategy' => 'classic',
            'sweepstrategies' => ['highestsub', 'classic', 'relsubs', 'allsubs'],
            'poolcategories' => 10, 'poolsubcategories' => 10, 'poolitems' => 25,
            'globalmin' => 15, 'globalmax' => 25, 'subscalemin' => 3, 'subscalemax' => 5,
        ];
        \local_catquizlab\form\experiment_form::mock_submit($values);
        $form = new \local_catquizlab\form\experiment_form(null, []);
        $this->assertNull($form->get_data());
        $errors = $this->errors_of($form);
        $this->assertArrayHasKey('globalmax', $errors);
        $this->assertStringContainsString('300', $errors['globalmax']);

        // Without allsubs in the sweep, the same budgets pass this check.
        \local_catquizlab\form\experiment_form::mock_submit(['sweepstrategies' => ['classic', 'relsubs']] + $values);
        $form = new \local_catquizlab\form\experiment_form(null, []);
        $form->get_data();
        $this->assertArrayNotHasKey('globalmax', $this->errors_of($form));
    }

    /**
     * The validation errors of a submitted form.
     *
     * @param \moodleform $form The form.
     * @return array
     */
    protected function errors_of(\moodleform $form): array {
        $property = new \ReflectionProperty(\moodleform::class, '_form');
        $property->setAccessible(true);

        return (array) $property->getValue($form)->_errors;
    }
}
