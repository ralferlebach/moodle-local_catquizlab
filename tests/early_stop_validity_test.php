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
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\reason_catalog;
use local_catquizlab\local\result_validity;
use local_catquizlab\local\results_query;
use local_catquizlab\output\results_page;

/**
 * An end before the minimum number of questions is not a regular one (#118).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\reason_catalog
 * @covers     \local_catquizlab\local\result_validity
 * @covers     \local_catquizlab\local\results_query
 */
final class early_stop_validity_test extends \advanced_testcase {
    /** @var array The design of the observed fastest run: 15 to 35 questions, SE 0.35. */
    protected const FACTS = ['strategy' => 'fastest', 'minitems' => 15, 'maxitems' => 35, 'semin' => 0.35];

    /**
     * The cases the issue lists.
     *
     * @return void
     */
    public function test_the_ends_are_classified_by_the_design(): void {
        $end = fn(array $facts, string $text = ''): string => reason_catalog::outcome($text, $facts + self::FACTS);

        // SE 0.20 after 7 of 15, the engine out of questions: not a precision success, not valid.
        $code = $end(['played' => 7, 'finalse' => 0.20, 'enginestatus' => 1]);
        $this->assertSame('no_eligible_item_before_minimum', $code);
        $this->assertSame(
            ['valid' => false, 'reason' => $code, 'enginefinished' => true, 'designstop' => false, 'criterionstop' => false],
            result_validity::evaluate($code)
        );
        // Any other end before the minimum is not a regular one either.
        $this->assertSame('ended_before_minimum', $end(['played' => 9, 'finalse' => 0.20, 'enginestatus' => 5]));

        // SE 0.20 after 15: the precision stop — valid, the design's stop.
        $code = $end(['played' => 15, 'finalse' => 0.20, 'enginestatus' => 1]);
        $this->assertSame('target_se_reached', $code);
        $this->assertTrue(result_validity::evaluate($code)['valid']);
        $this->assertTrue(result_validity::evaluate($code)['designstop']);

        // Out of questions after 17, SE above target: its own end — valid, but not a stop rule met.
        $code = $end(['played' => 17, 'finalse' => 0.48, 'enginestatus' => 1]);
        $this->assertSame('no_eligible_item_remaining', $code);
        $validity = result_validity::evaluate($code);
        $this->assertSame([true, false], [$validity['valid'], $validity['designstop']]);

        // The maximum after 35.
        $this->assertSame('max_items_reached', $end(['played' => 35, 'finalse' => 0.40, 'enginestatus' => 4]));

        // A strategy without a minimum is not held to one: the classical test plays every item.
        $this->assertSame('fixed_form_complete', reason_catalog::outcome('', ['strategy' => 'classic', 'played' => 7]));
    }

    /**
     * The engine's text, in any language, where there is no number (#118).
     *
     * @return void
     */
    public function test_the_engine_text_is_read_whatever_its_language(): void {
        // The engine's English string: the old patterns passed it by.
        $this->assertSame('noremainingquestions', reason_catalog::engine_code('You ran out of questions'));
        $this->assertSame('reachedmaximumquestions', reason_catalog::engine_code('Reached maximum number of questions'));
        // The number decides where there is one, whatever the text.
        $this->assertSame('noremainingquestions', reason_catalog::engine_code('Keine weiteren Fragen', 1));
        $this->assertSame('no_eligible_item_before_minimum', reason_catalog::outcome(
            'You ran out of questions',
            ['played' => 7, 'finalse' => 0.2] + self::FACTS
        ));
    }

    /**
     * Experiment 12's fastest run: 22 of 50 ended before question 15 (#118, the regression test).
     *
     * @return void
     */
    public function test_the_22_of_50_case(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $definition = experiment_definition::example_baseline();
        $definition['strategy'] = 'fastest';
        $definition['budgets']['global'] = ['minitems' => 15, 'maxitems' => 35];
        $definition['budgets']['se'] = ['min' => 0.35, 'max' => 2.0];
        $id = (int) experiment_service::save($definition)['id'];
        experiment_service::create_sweep($id);
        $run = $DB->get_record('local_catquizlab_run', ['experimentid' => $id], '*', IGNORE_MULTIPLE);

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        // The observed lengths of the 22: 5, 6, 7×3, 9×2, 11×8, 12, 13×5, 14.
        $short = [5, 6, 7, 7, 7, 9, 9, 11, 11, 11, 11, 11, 11, 11, 11, 12, 13, 13, 13, 13, 13, 14];
        $lengths = array_merge($short, array_fill(0, 28, 20));
        foreach ($lengths as $i => $n) {
            $person = $generator->create_person(['runid' => $run->id, 'abilityglobal' => 0.1 * ($i % 7)]);
            $trace = ['finaltheta' => 0.1 * ($i % 7) + 0.05, 'finalse' => $n < 15 ? 0.18 : 0.31,
                'items' => range(1, $n), 'nitems' => $n,
                'responses' => array_combine(range(1, $n), array_map(static fn(int $k): float => (float) ($k % 2), range(1, $n))),
                'stopreason' => 'Keine weiteren Fragen', 'scaleabilities' => []];
            $engineattemptid = 0;
            if ($i === 0) {
                // One from before 0.7.28: no code in its trace, the engine's table holds it.
                $engineattemptid = 900001;
                $DB->insert_record('local_catquiz_attempts', (object) ['userid' => 2, 'scaleid' => 1, 'contextid' => 1,
                    'courseid' => 1, 'attemptid' => $engineattemptid, 'component' => 'adaptivequiz', 'instanceid' => 1,
                    'teststrategy' => 1, 'status' => 1, 'total_number_of_testitems' => 50, 'number_of_testitems_used' => $n,
                    'personability_before_attempt' => 0, 'personability_after_attempt' => 0, 'starttime' => 0, 'endtime' => 0,
                    'json' => '{}', 'debug_info' => '', 'timecreated' => time(), 'timemodified' => time()]);
            } else {
                $trace['enginestatus'] = 1;
            }
            $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $run->id, 'personid' => $person->id,
                'status' => attempt_scheduler::STATUS_COLLECTED, 'tries' => 1, 'engineattemptid' => $engineattemptid,
                'tracejson' => json_encode($trace), 'timecreated' => time(), 'timemodified' => time()]);
        }

        $filter = ['experimentid' => $id];
        $query = new results_query($filter);
        $counts = $query->validity_counts();
        $this->assertSame([50, 28, 22], [$counts['total'], $counts['valid'], $counts['invalid']]);
        $this->assertSame(22, $counts['reasons']['no_eligible_item_before_minimum']);

        // The figures from the valid 28; the stop rules' success of all 50, not 100 %.
        $this->assertSame(28, $query->overview(100)['n']);
        $this->assertEqualsWithDelta(56.0, $query->stop_success(), 1e-9);
        $raw = new results_query($filter + ['validity' => result_validity::ALL]);
        $this->assertSame(50, count($raw->observations()));
        $this->assertSame(22, count(array_filter($raw->observations(), static fn(array $o): bool => !$o['valid'])));

        // Said where the figures are.
        $coverage = (new results_page($query, 'overview', $filter))->render_provenance();
        $this->assertStringContainsString('Valid sittings: 28 / 50 (56.0 %)', $coverage);
        $this->assertStringContainsString(': 22 / 50 (44.0 %)', $coverage);
    }
}
