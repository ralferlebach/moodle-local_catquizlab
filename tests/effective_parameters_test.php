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
use local_catquizlab\local\provisioning_check;
use local_catquizlab\local\strategy_parameters;
use local_catquizlab\local\sweep;
use local_catquizlab\local\test_provisioner;

/**
 * Effective parameters reach the created activity, provably (#104).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\strategy_parameters
 * @covers     \local_catquizlab\local\provisioning_check
 */
final class effective_parameters_test extends \advanced_testcase {
    /**
     * The classical test plays every item unless given its own maximum.
     *
     * @return void
     */
    public function test_the_classical_test_is_unlimited_by_default(): void {
        $this->resetAfterTest();

        $definition = experiment_definition::example_baseline();
        $definition['budgets']['global'] = ['minitems' => 10, 'maxitems' => 35];
        $definition['budgetsbystrategy'] = [
            'allsubs' => ['global' => ['maxitems' => 80]],
            'relsubs' => ['global' => ['maxitems' => 40]],
        ];

        $max = [];
        $plan = sweep::expand(['base' => $definition, 'factors' => ['strategy' => ['classic', 'allsubs', 'relsubs']]]);
        foreach ($plan['runs'] as $run) {
            $options = test_provisioner::options_from_definition($run['definition']);
            $max[$run['definition']['strategy']] = $options['maxquestions'];
        }

        // The shared 35 was meant for the adaptive strategies beside it.
        $this->assertSame(['allsubs' => 80, 'classic' => -1, 'relsubs' => 40], $this->sorted($max));

        // The classical test plays every item of the scale: a swept question
        // budget does not reach it — its cells are alike, and the definition
        // warns about that.
        $swept = sweep::expand(['base' => $definition, 'factors' => [
            'strategy'     => ['classic'],
            'globalbudget' => [['minitems' => 5, 'maxitems' => 20], ['minitems' => 5, 'maxitems' => 40]],
        ]]);
        $levels = [];
        foreach ($swept['runs'] as $run) {
            $levels[] = test_provisioner::options_from_definition($run['definition'])['maxquestions'];
        }
        $this->assertSame([-1, -1], $levels);
        $sweepdefinition = $definition;
        $sweepdefinition['sweep']['factors'] = ['strategy' => ['classic'],
            'globalbudget' => [['minitems' => 5, 'maxitems' => 20], ['minitems' => 5, 'maxitems' => 40]]];
        $warnings = (new \local_catquizlab\local\experiment_definition($sweepdefinition))->validate()['warnings'];
        $this->assertStringContainsString('every item', implode(' ', $warnings));

        // Nor does a question budget named for it: it is not its parameter.
        $definition['budgetsbystrategy']['classic'] = ['global' => ['maxitems' => 20]];
        $plan = sweep::expand(['base' => $definition, 'factors' => ['strategy' => ['classic']]]);
        $options = test_provisioner::options_from_definition($plan['runs'][0]['definition']);
        $this->assertSame(-1, $options['maxquestions']);
        $this->assertSame(
            strategy_parameters::ALL_ITEMS,
            test_provisioner::effective_parameters($plan['runs'][0]['definition'])['budgets']['global']
        );
    }

    /**
     * A test's settings as the engine holds them, compared with the run's definition.
     *
     * @return void
     */
    public function test_the_engine_record_is_compared_with_the_definition(): void {
        global $DB;
        $this->resetAfterTest();

        $moduleid = (int) $DB->get_field('modules', 'id', ['name' => 'adaptivequiz']);
        if (!$moduleid || !$DB->get_manager()->table_exists('local_catquiz_tests')) {
            $this->markTestSkipped('Needs mod_adaptivequiz and local_catquiz.');
        }

        $definition = experiment_definition::example_baseline();
        $definition['strategy'] = 'allsubs';
        $definition['budgets']['global'] = ['minitems' => 10, 'maxitems' => 80];
        $definition = (new experiment_definition($definition))->get_normalised();

        // A course module of an adaptive quiz, and the engine's record of its
        // test built exactly as provisioning builds it. The module is written
        // directly rather than through mod_adaptivequiz's generator: the check
        // follows only course module → instance → engine record, and that
        // generator needs a question category Moodle 5.x no longer provides
        // the way 4.5 did — it failed the test on 5.0 and 5.2 for a reason
        // that has nothing to do with what is tested here.
        $course = $this->getDataGenerator()->create_course();
        $quiz = (object) ['id' => 424242];
        $quiz->cmid = (int) $DB->insert_record('course_modules', (object) [
            'course' => $course->id, 'module' => $moduleid, 'instance' => $quiz->id,
            'section' => 0, 'added' => time(), 'visible' => 1,
        ]);
        $settings = test_provisioner::build_quizsettings(
            't',
            (int) $course->id,
            [],
            test_provisioner::options_from_definition($definition)
        );
        $testid = $DB->insert_record('local_catquiz_tests', (object) [
            'componentid' => $quiz->id, 'component' => 'mod_adaptivequiz',
            'json' => json_encode($settings), 'status' => 1,
            'timecreated' => time(), 'timemodified' => time(),
        ]);

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $DB->update_record('local_catquizlab_run', (object) [
            'id' => $run->id,
            'testcmid' => $quiz->cmid,
            'manifestjson' => json_encode(['config' => ['definition' => $definition]]),
        ]);

        $check = provisioning_check::compare((int) $run->id);
        $this->assertTrue($check['ok'], implode(' ', $check['differences']));
        $this->assertSame(80, $check['checked']['maxquestions']['actual']);

        // The engine holding something else is a difference, named.
        $settings['maxquestionsgroup']['catquiz_maxquestions'] = 35;
        $DB->set_field('local_catquiz_tests', 'json', json_encode($settings), ['id' => $testid]);
        $check = provisioning_check::compare((int) $run->id);
        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('maxquestions', $check['differences'][0]);
        $this->assertStringContainsString('80', $check['differences'][0]);
        $this->assertStringContainsString('35', $check['differences'][0]);

        // No test in the engine at all is not a pass either.
        $DB->delete_records('local_catquiz_tests', ['id' => $testid]);
        $this->assertFalse(provisioning_check::compare((int) $run->id)['ok']);
    }

    /**
     * An array sorted by key, for comparing without depending on run order.
     *
     * @param array $values The values.
     * @return array
     */
    protected function sorted(array $values): array {
        ksort($values);
        return $values;
    }
}
