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
use local_catquizlab\local\engine_validity;
use local_catquizlab\local\local_analysis;
use local_catquizlab\local\results_export;
use local_catquizlab\local\results_query;
use local_catquizlab\local\scale_exposure;
use local_catquizlab\output\results_page;

/**
 * The items administered per scale, from the sitting's own steps — and unknown is not zero (#113).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\scale_exposure
 * @covers     \local_catquizlab\local\local_analysis
 * @covers     \local_catquizlab\local\results_query
 */
final class scale_exposure_test extends \advanced_testcase {
    /** @var array Question id => its scale and the scales above: root 100, domains 200 and 300, subscales below. */
    protected const MAP = [
        11 => [210, 200, 100], 12 => [210, 200, 100], 13 => [220, 200, 100],
        14 => [310, 300, 100], 15 => [310, 300, 100], 16 => [310, 300, 100],
        // Subscale 320 has an item in the pool that this sitting never saw.
        17 => [320, 300, 100],
    ];

    /**
     * A known sitting: the counts per scale are those of its steps, and they add up.
     *
     * @return void
     */
    public function test_counts_are_reconstructed_from_the_steps(): void {
        $trace = ['items' => [11, 13, 14, 12, 15, 16], 'responses' => [11 => 1.0, 13 => 0.0, 14 => 1.0, 12 => 0.0,
            15 => 1.0, 16 => 0.0]];
        $exposure = scale_exposure::reconstruct($trace, 0, self::MAP);

        $this->assertSame(scale_exposure::SOURCE_STEPS, $exposure['source']);
        $this->assertSame('', $exposure['reason']);
        $this->assertSame([6, 6], [$exposure['administered'], $exposure['assigned']]);
        // Every item under exactly one scale of its own.
        $this->assertEquals([210 => 2, 220 => 1, 310 => 3], $exposure['direct']);
        $this->assertTrue($exposure['checksum']);
        $this->assertSame(6, array_sum($exposure['direct']));
        // And counted for every scale above, as the engine counts.
        $this->assertSame([3, 3, 6], [$exposure['cumulative'][200], $exposure['cumulative'][300], $exposure['cumulative'][100]]);
        $this->assertSame([], $exposure['diagnosis'], 'no debug information needed, none missed');

        // A real zero: the mapping is complete and no item of the scale was administered.
        $this->assertSame(0, scale_exposure::items($exposure, 320));
        $this->assertSame(2, scale_exposure::items($exposure, 210));
        $this->assertSame(3, scale_exposure::items($exposure, 200));

        // An older trace kept the responses only: the same questions.
        unset($trace['items']);
        $this->assertEquals($exposure['direct'], scale_exposure::reconstruct($trace, 0, self::MAP)['direct']);
        // And the responses as the engine's progress keeps them.
        $fromprogress = scale_exposure::reconstruct(['progress' => ['responses' => [
            '11' => ['questionid' => '11', 'fraction' => '1.000'], '14' => ['questionid' => '14', 'fraction' => '0.000'],
        ]]], 0, self::MAP);
        $this->assertEquals([210 => 1, 310 => 1], $fromprogress['direct']);
    }

    /**
     * What cannot be determined is unknown — never zero.
     *
     * @return void
     */
    public function test_unknown_is_not_zero(): void {
        // An administered item the mapping does not hold: every count is a lower bound.
        $exposure = scale_exposure::reconstruct(['items' => [11, 12, 999]], 0, self::MAP);
        $this->assertSame(scale_exposure::SOURCE_UNKNOWN, $exposure['source']);
        $this->assertSame(scale_exposure::UNMAPPED, $exposure['reason']);
        $this->assertSame([999], $exposure['unmapped']);
        $this->assertSame([3, 2], [$exposure['administered'], $exposure['assigned']]);
        $this->assertNull(scale_exposure::items($exposure, 210), 'two counted, but the third may be here too');
        $this->assertNull(scale_exposure::items($exposure, 320));

        // No mapping at all.
        $exposure = scale_exposure::reconstruct(['items' => [11, 12]], 0, []);
        $this->assertSame([scale_exposure::SOURCE_UNKNOWN, scale_exposure::NO_MAPPING], [$exposure['source'], $exposure['reason']]);
        $this->assertNull(scale_exposure::items($exposure, 210));

        // Nothing recorded of what was administered, and nothing from the engine.
        $exposure = scale_exposure::reconstruct([], 0, self::MAP);
        $this->assertSame([scale_exposure::SOURCE_UNKNOWN, scale_exposure::NO_STEPS], [$exposure['source'], $exposure['reason']]);
        $this->assertNull(scale_exposure::items($exposure, 210));

        // The classes: unknown is its own, and zero is zero.
        $this->assertSame(
            ['unknown', '0', '1', '2', '3-4', '3-4', '5+', '5+'],
            array_map([scale_exposure::class, 'class_of'], [null, 0, 1, 2, 3, 4, 5, 40])
        );
        $this->assertSame('item count unknown', scale_exposure::class_label('unknown'));
        $this->assertSame('3–4 items', scale_exposure::class_label('3-4'));
        $this->assertSame('5 or more items', scale_exposure::class_label('5+'));
    }

    /**
     * The engine's counts: compared where there are steps, used only where there are none and they add up.
     *
     * @return void
     */
    public function test_the_engines_counts_are_checked_not_trusted(): void {
        $steps = ['items' => [11, 12, 13, 14]];

        // Agreeing: nothing to say.
        $agreeing = scale_exposure::reconstruct($steps + ['questionsperscale' => [210 => 2, 220 => 1, 310 => 1]], 0, self::MAP);
        $this->assertSame([], $agreeing['diagnosis']);

        // The engine's progress counts fewer on a scale — a pilot item, say: said, and the steps stand.
        $lower = scale_exposure::reconstruct($steps + ['enginevalidity' => ['scales' => [
            210 => ['n' => 1], 220 => ['n' => 1], 100 => ['n' => 4],
        ]]], 0, self::MAP);
        $this->assertSame(scale_exposure::SOURCE_STEPS, $lower['source']);
        $this->assertSame([scale_exposure::ENGINE_LOWER], $lower['diagnosis']);
        $this->assertSame([210 => ['steps' => 2, 'engine' => 1]], $lower['differences']);
        $this->assertSame(2, scale_exposure::items($lower, 210));

        // More than were administered: an inconsistency of its own kind.
        $higher = scale_exposure::reconstruct($steps + ['questionsperscale' => [310 => 5]], 0, self::MAP);
        $this->assertSame([scale_exposure::ENGINE_HIGHER], $higher['diagnosis']);
        $this->assertSame(1, scale_exposure::items($higher, 310));

        // A measure made from the trace itself is not the engine's count.
        $own = scale_exposure::reconstruct($steps + ['enginevalidity' => ['source' => 'trace', 'scales' => [
            210 => ['n' => 9],
        ]]], 0, self::MAP);
        $this->assertSame([], $own['diagnosis']);

        // No steps. The engine's counts add up — subscales to the test: used, and said to be the engine's.
        $consistent = scale_exposure::reconstruct(['questionsperscale' => [210 => 2, 310 => 3, 200 => 2, 300 => 3,
            100 => 5]], 0, self::MAP);
        $this->assertSame(scale_exposure::SOURCE_ENGINE, $consistent['source']);
        $this->assertSame([2, 3, 0], [scale_exposure::items($consistent, 210), scale_exposure::items($consistent, 310),
            scale_exposure::items($consistent, 220)]);
        $this->assertContains(scale_exposure::NO_STEPS, $consistent['diagnosis']);

        // They do not add up, or the test's count is missing: not used.
        foreach ([[210 => 2, 310 => 3, 100 => 9], [210 => 2, 310 => 3]] as $counts) {
            $inconsistent = scale_exposure::reconstruct(['questionsperscale' => $counts], 0, self::MAP);
            $this->assertSame(scale_exposure::SOURCE_UNKNOWN, $inconsistent['source']);
            $this->assertContains(scale_exposure::ENGINE_INCONSISTENT, $inconsistent['diagnosis']);
            $this->assertNull(scale_exposure::items($inconsistent, 210));
        }
    }

    /**
     * A run of one domain with three subscales; twelve sittings that play subscales 1 and 2 only.
     *
     * @param array $trace What every sitting's trace holds beside its abilities.
     * @return int The run id.
     */
    protected function run_with(array $trace): int {
        global $DB;

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        // An adaptive run: a sitting need not play the whole pool, as a fixed form must (#112).
        $experiment = $generator->create_experiment(['strategy' => 'fastest',
            'definition' => ['budgets' => ['global' => ['minitems' => 1, 'maxitems' => 100]]]]);
        $run = $generator->create_run(['experimentid' => $experiment->id]);
        $scales = [];
        $questionid = 0;
        // Subscale 1 holds five items, 2 holds one, 3 holds two.
        foreach ([1 => 5, 2 => 1, 3 => 2] as $subscale => $items) {
            $catscaleid = 2000 + $subscale;
            $DB->insert_record('local_catquizlab_scalemap', (object) [
                'runid' => $run->id, 'level' => \local_catquizlab\local\scale_provisioner::LEVEL_SUBSCALE,
                'catscaleid' => $catscaleid, 'parentcatscaleid' => 2000, 'contextid' => 1,
                'nodekey' => 'c1s' . $subscale, 'generation' => 1, 'categoryindex' => 1, 'subscaleindex' => $subscale,
                'timecreated' => time(),
            ]);
            $scales[] = $catscaleid;
            for ($i = 0; $i < $items; $i++) {
                $questionid++;
                $DB->insert_record('local_catquizlab_item', (object) ['runid' => $run->id, 'questionid' => $questionid,
                    'assignedcatscaleid' => $catscaleid, 'truecatscaleid' => $catscaleid, 'itemname' => 'q' . $questionid,
                    'timecreated' => time()]);
            }
        }
        for ($i = 0; $i < 12; $i++) {
            $global = -1.5 + 0.25 * $i;
            $subscales = [];
            foreach ([1, 2, 3] as $subscale) {
                $subscales[] = ['index' => $subscale, 'theta' => $global + 0.3 * $subscale * (($i % 3) - 1)];
            }
            $person = $generator->create_person(['runid' => $run->id, 'abilityglobal' => $global]);
            $DB->set_field('local_catquizlab_person', 'profilejson', json_encode([
                'global' => $global, 'categories' => [['index' => 1, 'theta' => $global, 'subscales' => $subscales]],
            ]), ['id' => $person->id]);
            $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid' => $run->id, 'personid' => $person->id, 'status' => attempt_scheduler::STATUS_COLLECTED,
                'tries' => 1, 'timecreated' => time(), 'timemodified' => time(),
                'tracejson' => json_encode($trace + ['finaltheta' => $global + 0.1, 'finalse' => 0.4,
                    'stopreason' => 'se', 'scaleabilities' => array_fill_keys($scales, $global + 0.2)]),
            ]);
        }

        return (int) $run->id;
    }

    /**
     * The evaluation uses the reconstructed counts: the denominator, the classes, the page and the export.
     *
     * @return void
     */
    public function test_the_evaluation_uses_the_reconstructed_counts(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        // Five items of subscale 1 (questions 1–5), the one of subscale 2 (question 6), none of subscale 3.
        $runid = $this->run_with(['items' => [1, 2, 3, 4, 5, 6], 'nitems' => 6,
            'responses' => [1 => 1.0, 2 => 0.0, 3 => 1.0, 4 => 0.0, 5 => 1.0, 6 => 1.0]]);

        $query = new results_query(['runid' => $runid, 'validity' => 'all']);
        $rows = $query->subscale_observations();
        $coverage = $query->scale_coverage();
        // Subscales 1 and 2 of twelve sittings had items; subscale 3 was estimated without one.
        $this->assertSame([24, 12, 0], [$coverage['measured'], $coverage['withoutitems'], $coverage['unknownitems']]);
        $this->assertSame([scale_exposure::SOURCE_STEPS => 12], $coverage['sources']);
        $this->assertSame([], $coverage['diagnosis']);
        $this->assertCount(24, $rows);
        $bysubscale = [];
        foreach ($rows as $row) {
            $bysubscale[$row['subscale']] = $row;
        }
        $this->assertSame([5, 1], [$bysubscale[1]['items'], $bysubscale[2]['items']]);
        $this->assertSame(['5+', '1'], [$bysubscale[1]['itemclass'], $bysubscale[2]['itemclass']]);
        $this->assertSame(scale_exposure::SOURCE_STEPS, $bysubscale[1]['itemssource']);

        // By class of item count: the number of scale results, error, SE and correlation in each.
        $classes = local_analysis::by_item_class($rows);
        $this->assertSame(['1', '5+'], array_column($classes, 'class'));
        $this->assertSame([12, 12], array_column($classes, 'n'));
        foreach ($classes as $class) {
            $this->assertNotNull($class['rmse']);
            $this->assertArrayHasKey('correlation', $class);
            $this->assertArrayHasKey('meanse', $class);
        }

        $filter = ['runid' => $runid, 'validity' => 'all'];
        $html = (new results_page(new results_query($filter), 'subscales', $filter))->render_tab();
        $this->assertStringContainsString('data-region="catquizlab-itemclasses"', $html);
        $this->assertStringContainsString('5 or more items', $html);
        $this->assertStringContainsString('the sittings\' own steps — 12 sitting(s)', $html);
        $this->assertStringContainsString('12 scale result(s) estimated although no item of the scale was administered', $html);
        $this->assertStringNotContainsString('unknown item count', $html);
        // The error against the item count, from the valid scale results unless asked otherwise;
        // no standard errors in these sittings, so no plot of them.
        $this->assertStringContainsString('data-region="catquizlab-erroritems"', $html);
        $this->assertStringContainsString('Local error by items administered on the scale', $html);
        $this->assertStringContainsString('All scale results are drawn, valid or not.', $html);
        $this->assertStringNotContainsString('data-region="catquizlab-seitems"', $html);
        $valid = ['runid' => $runid];
        $validhtml = (new results_page(new results_query($valid), 'subscales', $valid))->render_tab();
        $this->assertStringContainsString('Only valid scale results of valid sittings are drawn.', $validhtml);

        // The export: the count, where it is from, its class.
        $export = results_export::dataset(new results_query($filter), results_export::LEVEL_SUBSCALE);
        $this->assertContains('itemssource', $export['columns']);
        $counts = [];
        foreach ($export['rows'] as $row) {
            $counts[$row['subscale']] = [$row['items'], $row['itemssource'], $row['itemclass']];
        }
        $this->assertSame([5, 'steps', '5+'], $counts[1]);
        $this->assertSame([0, 'steps', '0'], $counts[3], 'a real zero is a zero');
    }

    /**
     * With standard errors per scale there is the second plot: the local SE against the item count.
     *
     * @return void
     */
    public function test_the_standard_error_is_plotted_against_the_item_count(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $runid = $this->run_with(['items' => [1, 2, 3, 4, 5, 6], 'nitems' => 6,
            'responses' => [1 => 1.0, 2 => 0.0, 3 => 1.0, 4 => 0.0, 5 => 1.0, 6 => 1.0],
            'scalestandarderrors' => [2001 => 0.42, 2002 => 0.95]]);
        $filter = ['runid' => $runid, 'validity' => 'all'];

        $html = (new results_page(new results_query($filter), 'subscales', $filter))->render_tab();
        $this->assertStringContainsString('data-region="catquizlab-seitems"', $html);
        $this->assertStringContainsString('Local standard error by items administered on the scale', $html);
        $this->assertStringContainsString('5 item(s), local error', $html, 'the exact count in the tooltip');
    }

    /**
     * Where the count cannot be determined, nothing is zero: not in the figures, not in the denominator, and said.
     *
     * @return void
     */
    public function test_an_unknown_count_is_shown_as_unknown(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        // Question 77 was administered and is not in the run's mapping.
        $runid = $this->run_with(['items' => [1, 2, 77], 'nitems' => 3, 'responses' => [1 => 1.0, 2 => 0.0, 77 => 1.0]]);
        $filter = ['runid' => $runid, 'validity' => 'all'];

        $query = new results_query($filter);
        $rows = $query->subscale_observations();
        $coverage = $query->scale_coverage();
        $this->assertSame([0, 36], [$coverage['measured'], $coverage['unknownitems']]);
        $this->assertSame([scale_exposure::SOURCE_UNKNOWN => 12], $coverage['sources']);
        $this->assertSame([scale_exposure::UNMAPPED => 12], $coverage['diagnosis']);
        $this->assertCount(36, $rows);
        foreach ($rows as $row) {
            $this->assertNull($row['items']);
            $this->assertSame('unknown', $row['itemclass']);
            $this->assertFalse($row['valid']);
        }
        // Subscale 3 has nothing measured and an unknown count: not "no item on the scale".
        $third = array_values(array_filter($rows, static fn(array $row): bool => $row['subscale'] === 3))[0];
        $this->assertSame([engine_validity::ITEMS_UNKNOWN], $third['scalereasons']);
        $this->assertSame(['unknown'], array_column(local_analysis::by_item_class($rows), 'class'));

        // Valid only: none of them.
        $valid = new results_query(['runid' => $runid]);
        $this->assertSame([], $valid->subscale_observations());

        $html = (new results_page(new results_query($filter), 'subscales', $filter))->render_tab();
        $this->assertStringContainsString('36 scale result(s) with an unknown item count — not counted as 0', $html);
        $this->assertStringContainsString('an administered item is not assigned to any scale of the run', $html);
        $this->assertStringContainsString('item count unknown', $html);
        // No place on an axis of item counts: not drawn at zero, not drawn at all.
        $this->assertStringNotContainsString('data-region="catquizlab-erroritems"', $html);

        // The export: empty, not 0 — and why.
        $export = results_export::dataset(new results_query($filter), results_export::LEVEL_SUBSCALE);
        $this->assertNotSame([], $export['rows']);
        foreach ($export['rows'] as $row) {
            $this->assertNull($row['items']);
            $this->assertSame(['unknown', 'unmapped', 'unknown'], [$row['itemssource'], $row['itemsreason'], $row['itemclass']]);
        }
    }
}
