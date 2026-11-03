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
use local_catquizlab\local\run_contract;

/**
 * Every parameter from the sweep cell to the sittings, and every limit a sitting broke (#123).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\run_contract
 */
final class run_contract_test extends \advanced_testcase {
    /**
     * A run of a strategy with limits, a pool of so many items on two subscales, and sittings.
     *
     * @param string $strategy The strategy.
     * @param int $pool Items in the pool, alternately on subscale 11 and 12.
     * @param array[] $sittings Traces of collected sittings.
     * @param array $budgets Budgets over the defaults (global 5–10, subscale 1–4).
     * @return int The run.
     */
    protected function run_with(string $strategy, int $pool, array $sittings, array $budgets = []): int {
        global $DB;
        run_contract::reset_caches();

        $definition = experiment_definition::example_baseline();
        $definition['strategy'] = $strategy;
        $definition['budgets']['global'] = ['minitems' => 5, 'maxitems' => 10];
        $definition['budgets']['subscale'] = ['minitems' => 1, 'maxitems' => 4];
        $definition['budgets'] = $budgets + $definition['budgets'];
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run(['manifestjson' => json_encode(['config' => ['definition' => $definition]])]);
        $runid = (int) $run->id;
        foreach ([[10, 0, 'root', 0], [11, 10, 'c1s1', 2], [12, 10, 'c1s2', 2]] as [$scale, $parent, $node, $level]) {
            $DB->insert_record('local_catquizlab_scalemap', (object) ['runid' => $runid, 'catscaleid' => $scale,
                'parentcatscaleid' => $parent, 'nodekey' => $node, 'level' => $level, 'name' => $node, 'generation' => 1,
                'timecreated' => time()]);
        }
        for ($q = 1; $q <= $pool; $q++) {
            $DB->insert_record('local_catquizlab_item', (object) ['runid' => $runid, 'questionid' => $q, 'itemname' => 'i' . $q,
                'model' => '2pl', 'truedifficulty' => 0, 'storeddifficulty' => 0, 'discrimination' => 1, 'guessing' => 0,
                'truecategory' => 1, 'truesubscale' => $q % 2, 'miscalibrated' => 0, 'mistagged' => 0,
                'truecatscaleid' => $q % 2 ? 11 : 12, 'timecreated' => time()]);
        }
        foreach ($sittings as $trace) {
            $person = $generator->create_person(['runid' => $runid]);
            $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $runid, 'personid' => $person->id,
                'status' => attempt_scheduler::STATUS_COLLECTED, 'tries' => 1, 'tracejson' => json_encode($trace),
                'timecreated' => time(), 'timemodified' => time()]);
        }

        return $runid;
    }

    /**
     * A trace of so many items, the given ones or the first n.
     *
     * @param int $n Items.
     * @param string $stop The engine's stop reason.
     * @param int[]|null $items Which questions.
     * @return array
     */
    protected function trace(int $n, string $stop = 'Reached maximum number of questions', ?array $items = null): array {
        $items = $items ?? range(1, $n);

        return ['nitems' => count($items), 'items' => $items, 'stopreason' => $stop, 'finalse' => 0.4, 'finaltheta' => 0.1];
    }

    /**
     * Sittings within their limits break nothing; the chain names defaults and neutralisations.
     *
     * @return void
     */
    public function test_sittings_within_their_limits(): void {
        $this->resetAfterTest();
        // Inferring all subscales: global and per-subscale limits both apply.
        $runid = $this->run_with('allsubs', 30, [$this->trace(6), $this->trace(8)]);

        $chain = run_contract::chain($runid);
        $this->assertSame([], $chain['violations']);
        $this->assertSame(2, $chain['sittings']);
        $rows = array_column($chain['rows'], null, 'param');
        $this->assertSame([10, 10, 8], [$rows['maxquestions']['requested'], $rows['maxquestions']['effective'],
            $rows['maxquestions']['observed']]);
        $this->assertSame(6, $rows['minquestions']['observed']);
        $this->assertSame(4, $rows['maxquestionspersubscale']['observed'], 'items 1–8: four on each subscale');
        // No test provisioned here: nothing in the engine to compare with.
        $this->assertSame(run_contract::UNKNOWN, $rows['maxquestions']['status']);
        // Pilot not asked for: the plugin's default, said as such.
        $this->assertSame(run_contract::DEFAULTED, $rows['includepilot']['status']);
    }

    /**
     * Simulated limits broken: each detected, a defect or a design violation, with its sittings (AK 5).
     *
     * @return void
     */
    public function test_a_broken_limit_is_detected_and_classified(): void {
        $this->resetAfterTest();
        $runid = $this->run_with('allsubs', 30, [
            $this->trace(12), // Over the maximum of 10, six on each subscale.
            $this->trace(3, 'Reached maximum number of questions'), // Under 5 with items left.
            $this->trace(3, 'You ran out of questions'), // Under 5, the pool exhausted.
            $this->trace(6, 'Reached maximum number of questions', [1, 3, 5, 7, 9, 2]), // Five on subscale 11 (max 4).
            $this->trace(7),
        ]);

        $chain = run_contract::chain($runid);
        $this->assertSame([
            'global_max_exceeded' => 1,
            'global_min_undercut' => 1,
            'pool_exhausted_before_min' => 1,
            'subscale_max_exceeded' => 2,
        ], $this->sorted($chain['violations']));
        $this->assertSame('defect', run_contract::RULES['global_max_exceeded']);
        $this->assertSame('design', run_contract::RULES['pool_exhausted_before_min']);
        $this->assertCount(4, $chain['violatingsittings']);
        $this->assertFalse($chain['ok']);
        $rows = array_column($chain['rows'], null, 'param');
        $this->assertSame(run_contract::VIOLATED, $rows['maxquestions']['status']);
        $this->assertSame(run_contract::VIOLATED, $rows['maxquestionspersubscale']['status']);
    }

    /**
     * The classical test is held to every item of its pool.
     *
     * @return void
     */
    public function test_the_classical_test_plays_every_item(): void {
        $this->resetAfterTest();
        $runid = $this->run_with('classic', 6, [$this->trace(6), $this->trace(4, 'You ran out of questions')]);

        $chain = run_contract::chain($runid);
        $this->assertSame(['classic_not_all_items' => 1], $chain['violations']);
        $rows = array_column($chain['rows'], null, 'param');
        $this->assertSame(run_contract::NEUTRALISED, $rows['maxquestions']['status'], 'the global maximum does not apply');
        $this->assertSame(run_contract::VIOLATED, $rows['allitems']['status']);
        $this->assertSame(6, $rows['allitems']['effective']);
    }

    /**
     * The sitting-level export names the rules each sitting broke.
     *
     * @return void
     */
    public function test_the_export_names_the_broken_rules(): void {
        $this->resetAfterTest();
        $runid = $this->run_with('allsubs', 30, [$this->trace(12)]);

        $this->assertSame(['global_max_exceeded', 'subscale_max_exceeded'], run_contract::check_observation(['runid' => $runid,
            'nitems' => 12, 'items' => range(1, 12), 'stopreason' => 'Reached maximum number of questions']));
        // Fastest uses no per-subscale limit: six on one subscale breaks nothing there.
        $fastest = $this->run_with('fastest', 30, [$this->trace(9)]);
        $this->assertSame([], run_contract::check_observation(['runid' => $fastest, 'nitems' => 9,
            'items' => [1, 3, 5, 7, 9, 11, 2, 4, 6], 'stopreason' => 'Reached maximum number of questions']));
        $this->assertSame([], run_contract::check_observation(['runid' => $runid, 'nitems' => 8, 'items' => range(1, 8),
            'stopreason' => 'Reached maximum number of questions']));
        $this->assertContains('contractviolations', \local_catquizlab\local\results_export::ATTEMPT_COLUMNS);
    }

    /**
     * Sorted by key, for comparison.
     *
     * @param array $values The values.
     * @return array
     */
    protected function sorted(array $values): array {
        ksort($values);

        return $values;
    }

    /**
     * The engine's counting since local_catquiz 2026100909 (#141): minimum on answered productive, maximum on all asked.
     *
     * @return void
     */
    public function test_minimum_counts_answered_productive_questions(): void {
        $this->resetAfterTest();
        $runid = $this->run_with('allsubs', 30, []);

        // Seven played, two of them pilots: five answered productive — the minimum of five is met.
        $pilots = $this->trace(7) + ['enginevalidity' => ['n' => 5]];
        $this->assertSame([], run_contract::check_sitting($runid, $pilots));
        // Six played, but only four answered productive: under the minimum, though the played count is not.
        $short = $this->trace(6) + ['enginevalidity' => ['n' => 4]];
        $this->assertSame(['global_min_undercut'], run_contract::check_sitting($runid, $short));
        // The maximum counts every question asked, pilots included: eleven asked is one too many.
        $long = $this->trace(11) + ['enginevalidity' => ['n' => 9]];
        $this->assertContains('global_max_exceeded', run_contract::check_sitting($runid, $long));
        // The engine's own code for running out before the minimum is the design's, not a defect.
        $this->assertSame(['pool_exhausted_before_min'], run_contract::check_sitting(
            $runid,
            $this->trace(3) + ['enginevalidity' => ['n' => 3]],
            null,
            'noremainingquestionsbeforeminimum'
        ));
    }

    /**
     * The engine's status 10 and the minimum without pilots are read as the engine means them (#141).
     *
     * @return void
     */
    public function test_the_engine_status_before_the_minimum(): void {
        $this->resetAfterTest();

        $this->assertSame('noremainingquestionsbeforeminimum', \local_catquizlab\local\reason_catalog::engine_code('', 10));
        $this->assertSame('no_eligible_item_before_minimum', \local_catquizlab\local\reason_catalog::outcome('', [
            'enginestatus' => 10, 'strategy' => 'allsubs', 'minitems' => 5, 'played' => 9,
        ]));
        // Nine played, but six answered productive under a minimum of eight: an end before the minimum.
        $this->assertSame('ended_before_minimum', \local_catquizlab\local\reason_catalog::outcome('statusok', [
            'enginestatus' => 0, 'strategy' => 'allsubs', 'minitems' => 8, 'played' => 9, 'answered' => 6,
        ]));
    }

    /**
     * A stop on the standard error above its target is a defect; at or under the target it is not (#123).
     *
     * Two configurations each: target 0.3 and target 0.5.
     *
     * @return void
     */
    public function test_a_standard_error_stop_above_its_target(): void {
        $this->resetAfterTest();
        $stop = function (float $se): array {
            return ['finalse' => $se] + $this->trace(6, 'standarderror');
        };
        foreach ([[0.3, 0.42, 0.3], [0.5, 0.55, 0.49]] as [$target, $above, $within]) {
            $runid = $this->run_with(
                'allsubs',
                30,
                [$stop($above), $stop($within), ['finalse' => 0.9] + $this->trace(8)],
                ['se' => ['min' => 0.1, 'max' => $target]]
            );
            $chain = run_contract::chain($runid);
            $this->assertSame(['se_stop_above_target' => 1], $chain['violations'], 'target ' . $target);
            $this->assertSame(run_contract::VIOLATED, array_column($chain['rows'], null, 'param')['se_max']['status']);
            $this->assertSame('defect', run_contract::RULES['se_stop_above_target']);
        }
        // A stop on the maximum with a large SE breaks no SE rule: it did not stop for its SE.
        $runid = $this->run_with('allsubs', 30, [['finalse' => 0.9] + $this->trace(8)], ['se' => ['min' => 0.1, 'max' => 0.3]]);
        $this->assertSame([], run_contract::chain($runid)['violations']);
    }

    /**
     * An active scale under its minimum at a criterion stop is a defect; a dropped one, or a stop on the pool or the
     * maximum, is not (#123). Two configurations: minimum 3 and minimum 2.
     *
     * @return void
     */
    public function test_an_active_scale_under_its_minimum(): void {
        $this->resetAfterTest();
        // Items 1, 3, 5, 7, 9 on scale 11, item 2 on scale 12: five and one.
        $items = [1, 3, 5, 7, 9, 2];
        $sitting = function (array $active, string $stop = 'standarderror') use ($items): array {
            return ['progress' => ['activescales' => $active], 'finalse' => 0.2] + $this->trace(6, $stop, $items);
        };
        foreach ([3, 2] as $minimum) {
            $runid = $this->run_with('allsubs', 30, [
                $sitting([11, 12]),
                // Scale 12 was dropped by the engine: not held to its minimum.
                $sitting([11]),
                // The pool ran out: not a criterion of the test's own.
                $sitting([11, 12], 'You ran out of questions'),
            ], ['subscale' => ['minitems' => $minimum, 'maxitems' => 8], 'global' => ['minitems' => 5, 'maxitems' => 10]]);
            $chain = run_contract::chain($runid);
            $this->assertSame(['subscale_min_undercut' => 1], $chain['violations'], 'minimum ' . $minimum);
            $this->assertSame(
                run_contract::VIOLATED,
                array_column($chain['rows'], null, 'param')['minquestionspersubscale']['status']
            );
        }
        // Fastest has no per-subscale minimum: nothing to break.
        $runid = $this->run_with(
            'fastest',
            30,
            [$sitting([11, 12])],
            ['subscale' => ['minitems' => 3, 'maxitems' => 8]]
        );
        $this->assertSame([], run_contract::chain($runid)['violations']);
    }
}
