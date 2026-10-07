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

use local_catquizlab\local\analysis_population;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\mode_detection;
use local_catquizlab\local\results_export;
use local_catquizlab\local\results_query;
use local_catquizlab\output\results_page;

/**
 * Mode-specific detection (#114) and what an analysis is made of (#115, #112).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\mode_detection
 * @covers     \local_catquizlab\local\analysis_population
 * @covers     \local_catquizlab\output\results_page
 */
final class mode_detection_test extends \advanced_testcase {
    /** @var float[] The true local deviation of the four subscales: a deficit, a strength, and two near the level. */
    protected const TRUTH = [1 => -1.0, 2 => 0.9, 3 => 0.1, 4 => -0.2];

    /** @var array[] Per strategy: the subscales a sitting plays, and what it estimates of their deviation. */
    protected const PLAYS = [
        // After the deficits: finds the deficit, and looks at one scale beside it.
        'lowestsub' => [1 => -0.8, 3 => 0.0],
        // After the relevant ones: both the deficit and the strength.
        'relsubs' => [1 => -0.9, 2 => 0.6],
        // Everything, but less of each: the deficit is seen too faintly to be called one.
        'allsubs' => [1 => -0.4, 2 => 0.7, 3 => 0.3, 4 => -0.2],
        // The whole form: exactly the truth.
        'classic' => [1 => -1.0, 2 => 0.9, 3 => 0.1, 4 => -0.2],
    ];

    /**
     * An experiment of five strategies and six simulated people; one strategy never ran, one twin has an invalid baseline.
     *
     * @return int The experiment id.
     */
    protected function experiment(): int {
        global $DB;

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $definition = experiment_definition::example_baseline();
        $definition['name'] = 'Modes';
        $definition['persons']['count'] = 6;
        $definition['pool']['scales'] = ['categories' => 1, 'subcategories' => 4, 'itemspersubscale' => 3];
        $definition['budgets'] = [
            'global'   => ['minitems' => 2, 'maxitems' => 12],
            'subscale' => ['minitems' => 1, 'maxitems' => 3],
            'se'       => ['min' => 0.35, 'max' => 1.0],
        ];
        $definition['sweep']['factors']['strategy'] = ['lowestsub', 'relsubs', 'allsubs', 'classic', 'fastest'];
        $experimentid = (int) experiment_service::save($definition)['id'];
        experiment_service::create_sweep($experimentid);

        foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC') as $run) {
            $strategy = (string) \local_catquizlab\local\run_registry::describe($run)['strategy'];
            $base = (int) $run->id * 100;
            $questions = [];
            foreach ([1, 2, 3, 4] as $subscale) {
                $DB->insert_record('local_catquizlab_scalemap', (object) [
                    'runid' => $run->id, 'level' => \local_catquizlab\local\scale_provisioner::LEVEL_SUBSCALE,
                    'catscaleid' => $base + $subscale, 'parentcatscaleid' => $base, 'contextid' => 1,
                    'nodekey' => 'c1s' . $subscale, 'generation' => 1, 'categoryindex' => 1,
                    'subscaleindex' => $subscale, 'timecreated' => time(),
                ]);
                foreach ([1, 2, 3] as $n) {
                    $questionid = $base * 10 + $subscale * 10 + $n;
                    $questions[$subscale][] = $questionid;
                    $DB->insert_record('local_catquizlab_item', (object) ['runid' => $run->id, 'questionid' => $questionid,
                        'assignedcatscaleid' => $base + $subscale, 'truecatscaleid' => $base + $subscale,
                        'itemname' => 'q' . $questionid, 'timecreated' => time()]);
                }
            }
            if (!isset(self::PLAYS[$strategy])) {
                // Planned, provisioned, never played: the strategy "fastest".
                continue;
            }
            for ($twin = 1; $twin <= 6; $twin++) {
                $global = -1.0 + 0.4 * $twin;
                $subscales = [];
                foreach (self::TRUTH as $subscale => $delta) {
                    $subscales[] = ['index' => $subscale, 'theta' => $global + $delta];
                }
                $person = $generator->create_person(['runid' => $run->id, 'abilityglobal' => $global,
                    'twinid' => sprintf('r001-t%05d', $twin), 'twinindex' => $twin]);
                $DB->set_field('local_catquizlab_person', 'profilejson', json_encode([
                    'global' => $global, 'categories' => [['index' => 1, 'theta' => $global, 'subscales' => $subscales]],
                ]), ['id' => $person->id]);

                $items = [];
                $responses = [];
                $abilities = [];
                // The sixth twin's sitting in "all sub" answers everything correctly: no ability is determined.
                $allcorrect = $strategy === 'allsubs' && $twin === 6;
                foreach (self::PLAYS[$strategy] as $subscale => $estimate) {
                    // A fixed form plays every item; the others two per scale, one right and one wrong.
                    $played = $strategy === 'classic' ? $questions[$subscale] : array_slice($questions[$subscale], 0, 2);
                    foreach ($played as $index => $questionid) {
                        $items[] = $questionid;
                        $responses[$questionid] = $allcorrect || $index % 2 === 0 ? 1.0 : 0.0;
                    }
                    $abilities[$base + $subscale] = $global + $estimate;
                }
                $DB->insert_record('local_catquizlab_attempt', (object) [
                    'runid' => $run->id, 'personid' => $person->id, 'status' => attempt_scheduler::STATUS_COLLECTED,
                    'tries' => 1, 'timecreated' => time(), 'timemodified' => time(),
                    'tracejson' => json_encode(['finaltheta' => $global, 'finalse' => 0.3, 'items' => $items,
                        'responses' => $responses, 'nitems' => count($items), 'stopreason' => 'se',
                        'scaleabilities' => $abilities]),
                ]);
            }
        }
        // The strategy that never ran has its sittings scheduled, and failed.
        foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC') as $run) {
            if (\local_catquizlab\local\run_registry::describe($run)['strategy'] !== 'fastest') {
                continue;
            }
            for ($twin = 1; $twin <= 6; $twin++) {
                $person = $generator->create_person(['runid' => $run->id, 'twinid' => sprintf('r001-t%05d', $twin),
                    'twinindex' => $twin]);
                $DB->insert_record('local_catquizlab_attempt', (object) [
                    'runid' => $run->id, 'personid' => $person->id, 'status' => attempt_scheduler::STATUS_FAILED,
                    'tries' => 3, 'timecreated' => time(), 'timemodified' => time(),
                ]);
            }
        }

        return $experimentid;
    }

    /**
     * What a mode is after decides what counts as found.
     *
     * @return void
     */
    public function test_each_mode_has_its_own_targets(): void {
        $this->assertSame(
            [mode_detection::RELEVANT, mode_detection::DEFICIT, mode_detection::STRENGTH, mode_detection::ALLSUBS,
                mode_detection::CLASSIC, null],
            array_map([mode_detection::class, 'role'], ['relsubs', 'lowestsub', 'highestsub', 'allsubs', 'classic', 'fastest'])
        );
        // A deficit is below, a strength above. What is relevant for a person's competence band is not
        // defined yet: nothing is called a target by guesswork.
        $is = static fn(float $delta, string $target): bool => mode_detection::is_target($delta, $target, 0.5);
        $this->assertSame([true, false, false], [$is(-0.5, 'deficit'), $is(-0.49, 'deficit'), $is(0.9, 'deficit')]);
        $this->assertSame([true, false, false], [$is(0.5, 'strength'), $is(0.49, 'strength'), $is(-0.9, 'strength')]);
        $this->assertSame([false, false, false], [$is(-0.7, 'relevant'), $is(0.7, 'relevant'), $is(0.2, 'relevant')]);
        $this->assertSame([false, true, true], array_map([mode_detection::class, 'has_targets'], mode_detection::TARGETS));
        // And ranked from its own end: the strongest target first, which the helpers take as the lowest value.
        $this->assertLessThan(mode_detection::oriented(0.1, 'strength'), mode_detection::oriented(0.9, 'strength'));
        $this->assertLessThan(mode_detection::oriented(0.1, 'deficit'), mode_detection::oriented(-0.9, 'deficit'));

        // Scale results are held packed, and come back as they went in.
        $scales = ['1:1' => [-1.0, -0.8], '12:104' => [0.25, 0.125]];
        $this->assertSame($scales, mode_detection::unpack_scales(mode_detection::pack_scales($scales)));
    }

    /**
     * A true target nobody looked at was not found; a twin is never replaced by another person.
     *
     * @return void
     */
    public function test_recall_and_pairing(): void {
        $sitting = static fn(string $key, array $scales, int $deficits = 1): array => [
            'role' => 'x', 'strategy' => 'x', 'pairkey' => $key, 'valid' => true, 'attemptid' => crc32($key),
            'withitems' => count($scales), 'included' => count($scales),
            'truth' => ['deficit' => $deficits, 'strength' => 1, 'relevant' => 2],
            'scales' => mode_detection::pack_scales($scales),
        ];
        // Two people, a deficit each. One sitting found it; the other never asked on that scale.
        $own = [$sitting('a', ['1:1' => [-1.0, -0.8], '1:3' => [0.1, 0.0]]), $sitting('b', ['1:3' => [0.1, -0.6]])];
        $figures = mode_detection::evaluate($own, 'deficit', 0.5);
        $this->assertSame([1, 1, 1], [$figures['tp'], $figures['fp'], $figures['fn']]);
        $this->assertSame([0.5, 0.5], [$figures['recall'], $figures['precision']]);
        $this->assertSame([2, 1], [$figures['truetargets'], $figures['targetscovered']]);
        $this->assertSame(3, $figures['scales']);

        // The baseline has person a and a third person c — c is not b's twin.
        $baseline = [$sitting('a', ['1:1' => [-1.0, -0.3], '1:2' => [0.9, 0.9]]), $sitting('c', ['1:1' => [-1.0, -1.0]])];
        $comparison = mode_detection::compare($own, $baseline, 'deficit', 0.5);
        $this->assertTrue($comparison['paired']);
        $this->assertSame([1, 2], [$comparison['pairs'], $comparison['pairable']]);
        // Only the scale valid in both sittings of the pair goes into the difference.
        $this->assertSame(1, $comparison['commonscales']);
        $this->assertEqualsWithDelta(0.2, $comparison['points'][0]['y'], 1e-9);
        $this->assertEqualsWithDelta(0.7, $comparison['points'][0]['x'], 1e-9);
        $this->assertEqualsWithDelta(-0.5, $comparison['meandifference'], 1e-9);
        // The baseline, for the same person and the mode's targets: it saw the deficit too faintly.
        $this->assertSame(0.0, $comparison['baseline']['recall']);
        $this->assertSame(1.0, $comparison['mode']['recall']);

        // Nobody in common: unpaired, and said to be — the baseline's own figures beside.
        $unpaired = mode_detection::compare($own, [$sitting('z', ['1:1' => [-1.0, -1.0]])], 'deficit', 0.5);
        $this->assertFalse($unpaired['paired']);
        $this->assertSame(0, $unpaired['pairs']);
        $this->assertSame(1.0, $unpaired['unpaired']['recall']);
        // A sitting without a twin id cannot be paired at all.
        $this->assertSame('', mode_detection::pair_key(['twinid' => '', 'experimentid' => 1]));
    }

    /**
     * The whole experiment: every mode on its own, beside the baselines, with what it is made of.
     *
     * @return void
     */
    public function test_the_experiment_is_evaluated_mode_by_mode(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $experimentid = $this->experiment();
        $filter = ['experimentid' => $experimentid];
        $query = new results_query($filter);

        $analysis = mode_detection::analyse(mode_detection::sittings($query));
        // The modes driven, and no other: nobody hunted strengths here.
        $this->assertSame(['relevant', 'deficit'], array_keys($analysis['targets']));
        $this->assertSame(['fastest'], mode_detection::unassigned($query));

        $deficit = $analysis['targets']['deficit'];
        // Six valid sittings; two valid scale results each, of two with an item.
        $this->assertSame([6, 6, 12, 12], [$deficit['own']['counts']['valid'], $deficit['own']['counts']['sittings'],
            $deficit['own']['counts']['included'], $deficit['own']['counts']['withitems']]);
        $this->assertSame([1.0, 1.0, 1.0], [$deficit['own']['recall'], $deficit['own']['precision'], $deficit['own']['f1']]);
        $this->assertEqualsWithDelta(0.2, $deficit['own']['targetrmse'], 1e-6);
        // Against "all sub": the sixth twin's sitting there is not valid, and nobody stands in for it.
        $allsubs = $deficit['baselines']['allsubs'];
        $this->assertSame([true, 5, 6], [$allsubs['paired'], $allsubs['pairs'], $allsubs['pairable']]);
        $this->assertSame(0.0, $allsubs['baseline']['recall'], 'all sub sees the deficit at -0.4: not a deficit by the threshold');
        $this->assertSame(1.0, $allsubs['mode']['recall']);
        // Common scales: subscales 1 and 3, of five pairs.
        $this->assertSame(10, $allsubs['commonscales']);
        $this->assertCount(5, $allsubs['points']);
        // Against the classical test: all six, and the classical test is exact.
        $classic = $deficit['baselines']['classic'];
        $this->assertSame([6, 6], [$classic['pairs'], $classic['pairable']]);
        $this->assertSame(1.0, $classic['baseline']['recall']);
        $this->assertEqualsWithDelta(0.0, $classic['common']['baseline']['rmse'], 1e-9);
        $this->assertGreaterThan(0.0, $classic['meandifference'], 'the mode is further from the truth than the full form');

        // Relevant scales: no set of true targets is defined, so nothing is counted as found or missed —
        // the recovery on the scales the mode chose is there, and the comparison with the baselines.
        $relevant = $analysis['targets']['relevant'];
        $this->assertSame([null, null, null, null], [$relevant['own']['truetargets'], $relevant['own']['tp'],
            $relevant['own']['recall'], $relevant['own']['precision']]);
        $this->assertSame([], $relevant['own']['topk']);
        $this->assertSame(12, $relevant['own']['scales']);
        $this->assertNotNull($relevant['own']['rmse']);
        $this->assertSame([5, 6], [$relevant['baselines']['allsubs']['pairs'], $relevant['baselines']['allsubs']['pairable']]);
        $this->assertNotNull($relevant['baselines']['classic']['meandifference']);
        // The deficit mode, by its own targets, never looked at the strength — and is not judged by that here.
        $this->assertSame(6, $deficit['own']['truetargets']);

        // The page: one name for the tab, each mode with its definition and what went in.
        $this->assertSame('Mode-specific detection', results_page::tabs()['deficits']);
        $html = (new results_page(new results_query($filter), 'deficits', $filter))->render_tab();
        $this->assertStringContainsString('data-region="catquizlab-mode-deficit"', $html);
        $this->assertStringContainsString('data-region="catquizlab-mode-relevant"', $html);
        $this->assertStringNotContainsString('data-region="catquizlab-mode-strength"', $html);
        $this->assertStringContainsString('Δs,true ≤ −0.50 logits', $html);
        $this->assertStringContainsString('relevant for the person\'s competence band', $html);
        $this->assertStringContainsString('is not defined in CatQuizLab yet', $html);
        $this->assertStringNotContainsString('|Δs,true|', $html);
        $this->assertStringContainsString('Valid paired sittings: 5 / 6 (83.3 %)', $html);
        $this->assertStringContainsString('Included: 12 / 12 scale results with at least one administered item (100.0 %)', $html);
        $this->assertStringContainsString('Valid sittings: 6 / 6 (100.0 %)', $html);
        $fastest = \local_catquizlab\local\strategy_catalog::label('fastest');
        $this->assertStringContainsString('Not evaluated here: ' . $fastest, $html);
        $this->assertStringContainsString('data-region="catquizlab-mode-plot"', $html);
        // Strengths are never spoken of as deficits, and nothing is pooled over modes.
        $this->assertStringNotContainsString('Deficit detection', $html);

        // One strategy only: the baselines are not in the selection, and that is said.
        $only = ['experimentid' => $experimentid, 'strategy' => 'lowestsub'];
        $html = (new results_page(new results_query($only), 'deficits', $only))->render_tab();
        $this->assertStringContainsString('All sub baseline: not available in the selected experiment/filter.', $html);
        $this->assertStringContainsString('Classic testing baseline: not available in the selected experiment/filter.', $html);
        // A baseline alone: no targeted mode to show.
        $only = ['experimentid' => $experimentid, 'strategy' => 'classic'];
        $html = (new results_page(new results_query($only), 'deficits', $only))->render_tab();
        $this->assertStringContainsString('data-region="catquizlab-mode-none"', $html);
    }

    /**
     * The exports: every scale result with mode, twin, baseline, validity and items — and the summary as shown.
     *
     * @return void
     */
    public function test_the_detection_is_exported(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $filter = ['experimentid' => $this->experiment()];

        $export = results_export::dataset(new results_query($filter), results_export::LEVEL_DETECTION);
        foreach (
            ['mode', 'strategy', 'twinid', 'attemptvalid', 'validityreason', 'items', 'scalevalid', 'included',
            'truedelta', 'estdelta', 'truedeficit', 'estdeficit', 'baseline_allsubs', 'baseline_classic'] as $column
        ) {
            $this->assertContains($column, $export['columns']);
        }
        $this->assertNotContains('truerelevant', $export['columns']);
        // Two scale results of six sittings (lowestsub, relsubs), four of six (allsubs, classic).
        $this->assertCount(12 + 12 + 24 + 24, $export['rows']);
        $rows = array_values(array_filter($export['rows'], static fn(array $row): bool => $row['mode'] === 'deficit'
            && $row['subscale'] === 1));
        $this->assertCount(6, $rows);
        $this->assertSame([1, 1, 2, 1], [$rows[0]['truedeficit'], $rows[0]['estdeficit'], $rows[0]['items'], $rows[0]['included']]);
        // The twin's sitting in each baseline; the sixth has none that is valid in "all sub".
        $this->assertNotNull($rows[0]['baseline_allsubs']);
        $sixth = array_values(array_filter($rows, static fn(array $row): bool => $row['twinid'] === 'r001-t00006'))[0];
        $this->assertNull($sixth['baseline_allsubs']);
        $this->assertNotNull($sixth['baseline_classic']);
        // The invalid sitting's scale results are there, marked, and in no figure.
        $invalid = array_values(array_filter($export['rows'], static fn(array $row): bool => $row['attemptvalid'] === 0));
        $this->assertCount(4, $invalid);
        $this->assertSame([0], array_values(array_unique(array_column($invalid, 'included'))));
        $this->assertStringContainsString('fraction', $invalid[0]['validityreason']);

        $summary = results_export::dataset(new results_query($filter), results_export::LEVEL_DETECTIONSUMMARY);
        $pairings = array_count_values(array_column($summary['rows'], 'pairing'));
        // Two modes: each against the truth, and both sides of two baselines, paired and on the common scales.
        $this->assertSame(['truth' => 2, 'paired' => 8, 'pairedcommonscales' => 8], $pairings);
        $truth = array_values(array_filter($summary['rows'], static fn(array $row): bool => $row['mode'] === 'deficit'
            && $row['pairing'] === 'truth'))[0];
        $this->assertSame([6, 6, 12, 12, 1.0], [$truth['sittings'], $truth['sittingsall'], $truth['scales'],
            $truth['scaleswithitems'], $truth['recall']]);
    }

    /**
     * What the figures are made of, from the plan down — and what the design lost, by name.
     *
     * @return void
     */
    public function test_the_analysis_population_is_said(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $filter = ['experimentid' => $this->experiment()];
        $query = new results_query($filter);

        $funnel = analysis_population::funnel($query);
        // Five runs of six: thirty planned and scheduled; six failed, twenty-four collected, twenty-three valid.
        $this->assertSame([30, 30, 30, 6, 24, 23, 1], [$funnel['planned'], $funnel['scheduled'], $funnel['started'],
            $funnel['failed'], $funnel['collected'], $funnel['valid'], $funnel['invalid']]);
        $this->assertSame(['fraction' => 1], $funnel['invalidreasons']);
        $this->assertSame([6, 0, 0], [$funnel['strategies']['fastest']['planned'], $funnel['strategies']['fastest']['collected'],
            $funnel['strategies']['fastest']['valid']]);
        $this->assertSame([6, 5], [$funnel['strategies']['allsubs']['collected'], $funnel['strategies']['allsubs']['valid']]);
        // No simulated person has a valid sitting in all five strategies: one never ran.
        $this->assertSame(['complete' => 0, 'twins' => 6, 'strategies' => 5], $funnel['pairs']);

        $codes = array_column(analysis_population::design_loss($funnel), 'code');
        $this->assertContains(analysis_population::LOSS_NOTHING_COLLECTED, $codes);
        $this->assertContains(analysis_population::LOSS_PAIRS, $codes);
        $this->assertNotContains(analysis_population::LOSS_COVERAGE, $codes, 'five of six is above 80 %');
        // A stricter threshold names the run that is below it; none switches the warning off.
        $strict = analysis_population::design_loss($funnel, null, 90);
        $coverage = array_values(array_filter($strict, static fn(array $loss): bool => $loss['code'] === 'coverage'));
        $this->assertCount(1, $coverage);
        $this->assertSame([5, 6], [$coverage[0]['a']['valid'], $coverage[0]['a']['planned']]);
        $this->assertNotContains('coverage', array_column(analysis_population::design_loss($funnel, null, 0), 'code'));
        set_config('coverage_warning', 95, 'local_catquizlab');
        $this->assertSame(95, analysis_population::threshold());
        $this->assertContains('coverage', array_column(analysis_population::design_loss($funnel), 'code'));
        set_config('coverage_warning', 80, 'local_catquizlab');

        // On the page: the loss above the figures, the population beneath it.
        $html = (new results_page(new results_query($filter), 'overview', $filter))->render_provenance();
        $this->assertStringContainsString('data-region="catquizlab-designloss"', $html);
        $fastest = \local_catquizlab\local\strategy_catalog::label('fastest');
        $this->assertStringContainsString($fastest . ': none of 6 planned sittings was collected (6 failed technically)', $html);
        $this->assertStringContainsString('Only 0 of 6 simulated people', $html);
        $this->assertStringContainsString('data-region="catquizlab-analysis-population"', $html);
        $this->assertStringContainsString('Analysis population: 23 valid of 30 planned sittings (76.7 %)', $html);
        $this->assertStringContainsString('valid of all started: 23 / 30 (76.7 %)', $html);
        $this->assertStringContainsString('valid of all finished: 23 / 24 (95.8 %)', $html);
        $this->assertStringContainsString('[fraction]: 1', $html);
        $this->assertStringContainsString('No figure of this view is made of scale results.', $html);
        $this->assertStringContainsString('experimentid = ' . $filter['experimentid'], $html);

        // The subscales view is made of scale results: the chain from what there was to what went in.
        $page = new results_page(new results_query($filter), 'subscales', $filter);
        $html = $page->render_provenance();
        // 12 + 12 + 24 + 24 scale results with an item; four of them from the invalid sitting.
        $this->assertMatchesRegularExpression('/Scale results with at least one administered item<\/td>\s*<td[^>]*>72</', $html);
        $this->assertStringContainsString('68 (94.4 %)', $html);
        $coverage = (new results_query($filter));
        $coverage->subscale_observations();
        $chain = $coverage->scale_coverage()['chain'];
        $this->assertSame(['fromvalid' => 68, 'paired' => 68, 'valid' => 68, 'withse' => 0], $chain);

        // The spread per strategy, all collected beside the valid ones — described, not explained.
        $spread = $coverage->scale_coverage()['spread'];
        $this->assertSame([24, 20], [$spread['allsubs']['all']['n'], $spread['allsubs']['valid']['n']]);
        $this->assertLessThan(1.0, $spread['allsubs']['valid']['ratio']);
        $this->assertEqualsWithDelta(1.0, $spread['classic']['valid']['ratio'], 1e-6);
        $tab = (new results_page(new results_query($filter), 'subscales', $filter))->render_tab();
        $this->assertStringContainsString('data-region="catquizlab-spread"', $tab);
        $this->assertStringContainsString('SD(Δ̂) / SD(Δtrue)', $tab);
        $this->assertStringNotContainsString('hrinkage', $tab);

        // The export says the same about itself, machine-readable — counted by the pass that wrote the rows.
        $json = json_decode(results_export::to_json(new results_query($filter), results_export::LEVEL_SUBSCALE), true);
        $population = $json['metadata']['population'];
        $this->assertEquals(['planned' => 30, 'scheduled' => 30, 'started' => 30, 'failed' => 6, 'collected' => 24,
            'valid' => 23, 'evaluated' => 24, 'invalid' => 1], $population['attempts']);
        $this->assertSame(['fraction' => 1], $population['invalidreasons']);
        $this->assertSame(count($json['data']), $population['scales']['included']);
        $this->assertSame([72, 68, 68], [$population['scales']['withadministereditem'],
            $population['scales']['fromvalidattempts'], $population['scales']['valid']]);
        $this->assertContains('nothingcollected', array_column($population['designloss'], 'code'));
        $this->assertCount(5, $population['runids']);
    }

    /**
     * Sittings collected of which none is valid: said as that, with the reasons — not as "nothing played".
     *
     * @return void
     */
    public function test_collected_but_none_valid_is_said(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $experimentid = $this->experiment();
        $filter = ['experimentid' => $experimentid, 'strategy' => 'allsubs'];
        // Every sitting of "all sub" loses its final ability.
        foreach ((new results_query($filter))->runs() as $runid => $unused) {
            foreach ($DB->get_records('local_catquizlab_attempt', ['runid' => $runid]) as $attempt) {
                $trace = json_decode($attempt->tracejson, true);
                unset($trace['finaltheta']);
                $DB->set_field('local_catquizlab_attempt', 'tracejson', json_encode($trace), ['id' => $attempt->id]);
            }
        }

        $html = (new results_page(new results_query($filter), 'overview', $filter))->render_provenance();
        $this->assertStringContainsString('data-region="catquizlab-nonevalid"', $html);
        $this->assertStringContainsString('6 sitting(s) were collected under this filter, and none of them is valid', $html);
        $this->assertStringNotContainsString('No attempts match this filter yet', $html);
        $this->assertStringContainsString('[no_final_theta]: 6', $html);
        $this->assertStringContainsString('none valid. No comparison against this baseline is possible.', $html);
        $this->assertStringContainsString('Analysis population: 0 valid of 6 planned sittings (0.0 %)', $html);

        // A strategy that never ran: nothing collected, and the population still says what was planned.
        $never = ['experimentid' => $experimentid, 'strategy' => 'fastest'];
        $html = (new results_page(new results_query($never), 'overview', $never))->render_provenance();
        $this->assertStringNotContainsString('data-region="catquizlab-nonevalid"', $html);
        $this->assertStringContainsString('Analysis population: 0 valid of 6 planned sittings', $html);
        $this->assertStringContainsString('none of 6 planned sittings was collected', $html);
    }
}
