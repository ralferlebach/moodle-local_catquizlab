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
use local_catquizlab\local\engine_validity;
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\registry;
use local_catquizlab\local\reproducibility;
use local_catquizlab\local\result_validity;
use local_catquizlab\local\results_export;
use local_catquizlab\local\results_query;
use local_catquizlab\local\scale_provisioner;
use local_catquizlab\local\trace_digest;

/**
 * One fixed matrix of sittings, and every view counts them the same way (#112, #115).
 *
 * Per strategy the engine can play, ten sittings of known kind:
 *
 * | | kind | uniform rules | engine's verdict |
 * |---|---|---|---|
 * | A | everything in order | valid | valid |
 * | B | no final ability | invalid (no_final_theta) | invalid |
 * | C | every answer correct | invalid (fraction_all_correct) | invalid |
 * | D | in order by the rules, the engine disagrees | valid | invalid |
 * | E | the engine said nothing; the rules measured from the trace | valid | unknown |
 * | F | fewer questions than the minimum | invalid (min_questions); valid for the classical test, which has none | invalid |
 * | G | standard error above the maximum | invalid (se_max); valid for the classical test, which has none | invalid |
 * | H | a trace without items or answers, the engine said nothing | unknown | unknown |
 * | I | failed technically | not collected | not collected |
 * | J | waiting for its trace | not collected | not collected |
 *
 * For every validity filter and both rules, the number of sittings is compared across the views that show them — the
 * overview, the stream, the raw data and the export of sittings as CSV and JSON, the export's metadata, the analysis
 * basis, the population funnel, the reproduction package — and against the table above. The subscale results are
 * compared between the subscales tab and the subscale export. Nothing is skipped: a strategy the installed engine
 * cannot play is left out of the experiment and named in the assertion message, never counted as passed.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\results_query
 * @covers     \local_catquizlab\local\result_validity
 * @covers     \local_catquizlab\local\analysis_population
 */
final class validity_matrix_test extends \advanced_testcase {
    /** @var array<string, array{0: ?string, 1: ?string}> Kind => [uniform state, engine state]; null: not collected. */
    protected const EXPECTED = [
        'A' => ['valid', 'valid'], 'B' => ['invalid', 'invalid'], 'C' => ['invalid', 'invalid'],
        'D' => ['valid', 'invalid'], 'E' => ['valid', 'unknown'], 'F' => ['invalid', 'invalid'],
        'G' => ['invalid', 'invalid'], 'H' => ['unknown', 'unknown'], 'I' => [null, null], 'J' => [null, null],
    ];

    /** @var string[] The strategies of the matrix, as far as the engine plays them. */
    protected const STRATEGIES = ['classic', 'cat', 'allsubs', 'relsubs', 'lowestsub', 'highestsub'];

    /**
     * Forget what a test read.
     *
     * @return void
     */
    protected function tearDown(): void {
        results_query::reset_caches();
        results_export::reset_caches();
        parent::tearDown();
    }

    /**
     * The experiment of the matrix.
     *
     * @return array{0: int, 1: string[], 2: string[]} The experiment, the strategies in it, those the engine cannot play.
     */
    protected function experiment(): array {
        global $DB;

        $playable = [];
        $unplayable = [];
        foreach (self::STRATEGIES as $strategy) {
            $probe = experiment_definition::example_baseline();
            $probe['strategy'] = $strategy;
            if (experiment_service::validate($probe)['valid']) {
                $playable[] = $strategy;
            } else {
                $unplayable[] = $strategy;
            }
        }
        $definition = experiment_definition::example_baseline();
        $definition['name'] = 'Validity matrix';
        $definition['sweep']['factors']['strategy'] = $playable;
        $definition['persons']['count'] = 10;
        $definition['pool']['scales'] = ['categories' => 1, 'subcategories' => 2, 'itemspersubscale' => 10];
        $definition['budgets'] = ['global' => ['minitems' => 10, 'maxitems' => 20],
            'subscale' => ['minitems' => 1, 'maxitems' => 10], 'se' => ['min' => 0.3, 'max' => 0.5]];
        $experimentid = (int) experiment_service::save($definition)['id'];
        experiment_service::create_sweep($experimentid);

        foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC') as $run) {
            $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_FINISHED, ['id' => $run->id]);
            $base = (int) $run->id * 100;
            $DB->insert_record('local_catquizlab_scalemap', (object) ['runid' => $run->id,
                'level' => scale_provisioner::LEVEL_ROOT, 'catscaleid' => $base, 'parentcatscaleid' => 0, 'contextid' => 1,
                'nodekey' => 'root', 'generation' => 1, 'name' => 'Root', 'timecreated' => time()]);
            $questions = [];
            foreach ([1, 2] as $s) {
                $DB->insert_record('local_catquizlab_scalemap', (object) ['runid' => $run->id,
                    'level' => scale_provisioner::LEVEL_SUBSCALE, 'catscaleid' => $base + $s, 'parentcatscaleid' => $base,
                    'contextid' => 1, 'nodekey' => 'c1s' . $s, 'generation' => 1, 'categoryindex' => 1,
                    'subscaleindex' => $s, 'name' => 'S' . $s, 'timecreated' => time()]);
                for ($k = 1; $k <= 10; $k++) {
                    $questionid = $base * 100 + $s * 10 + $k;
                    $questions[] = [$questionid, $base + $s];
                    $DB->insert_record('local_catquizlab_item', (object) ['runid' => $run->id, 'questionid' => $questionid,
                        'assignedcatscaleid' => $base + $s, 'truecatscaleid' => $base + $s, 'itemname' => 'q' . $questionid,
                        'timecreated' => time()]);
                }
            }
            foreach (array_keys(self::EXPECTED) as $index => $kind) {
                $global = round(-1 + 0.2 * $index, 2);
                $personid = $DB->insert_record('local_catquizlab_person', (object) ['runid' => $run->id,
                    'twinid' => sprintf('r001-t%05d', $index + 1), 'twinindex' => $index + 1, 'stratum' => 'subscalevariation',
                    'severity' => 'medium', 'abilityglobal' => $global, 'profilejson' => json_encode(['global' => $global,
                        'categories' => [['index' => 1, 'theta' => $global, 'subscales' => [
                            ['index' => 1, 'theta' => $global + 0.6], ['index' => 2, 'theta' => $global - 0.6]]]]]),
                    'timecreated' => time(), 'timemodified' => time()]);
                $row = ['runid' => $run->id, 'personid' => $personid, 'tries' => 1, 'runtimems' => 60000,
                    'timecreated' => time(), 'timemodified' => time(), 'engineattemptid' => 0];
                if ($kind === 'I') {
                    $DB->insert_record('local_catquizlab_attempt', (object) ($row + ['status' => attempt_scheduler::STATUS_FAILED,
                        'lasterror' => 'worker_crash']));
                    continue;
                }
                if ($kind === 'J') {
                    $DB->insert_record('local_catquizlab_attempt', (object) ($row + [
                        'status' => attempt_scheduler::STATUS_TRACEPENDING, 'lasterror' => 'trace_missing']));
                    continue;
                }
                $played = $kind === 'F' ? array_slice($questions, 0, 5) : $questions;
                $responses = [];
                foreach ($played as $k => [$questionid]) {
                    $responses[$questionid] = $kind === 'C' ? 1.0 : (float) ($k % 2);
                }
                $answered = count($played);
                $fraction = $kind === 'C' ? 1.0 : 0.5;
                $se = $kind === 'G' ? 0.9 : 0.35;
                $measure = in_array($kind, ['E', 'H'], true) ? null : ['source' => 'engine', 'n' => $answered,
                    'fraction' => $fraction, 'scales' => [$base => ['n' => $answered, 'fraction' => $fraction, 'se' => $se],
                        $base + 1 => ['n' => (int) ceil($answered / 2), 'fraction' => $fraction, 'se' => 0.45],
                        $base + 2 => ['n' => (int) floor($answered / 2), 'fraction' => $fraction, 'se' => 0.45]],
                    'engine' => ['valid' => in_array($kind, ['A'], true), 'scales' => []]];
                $trace = ['finalse' => $se, 'items' => $kind === 'H' ? [] : array_column($played, 0),
                    'responses' => $kind === 'H' ? [] : $responses, 'nitems' => $kind === 'H' ? 20 : $answered,
                    'stopreason' => 'Reached maximum number of questions', 'hoststopcode' => 'maximumquestions',
                    'scaleabilities' => [$base + 1 => $global + 0.5, $base + 2 => $global - 0.5],
                    'scalestandarderrors' => [$base + 1 => 0.45, $base + 2 => 0.45],
                    'enginevalidity' => $measure];
                if ($kind === 'E' || $kind === 'H') {
                    $trace['enginevalidityunknown'] = engine_validity::UNKNOWN_NO_ENGINE_ATTEMPT;
                }
                if ($kind !== 'B') {
                    $trace['finaltheta'] = $global + 0.05;
                }
                $json = json_encode($trace);
                $DB->insert_record('local_catquizlab_attempt', (object) ($row + ['status' => attempt_scheduler::STATUS_COLLECTED,
                    'tracejson' => $json, 'tracebytes' => strlen($json)] + trace_digest::fields($json)));
            }
        }

        return [$experimentid, $playable, $unplayable];
    }

    /**
     * Every view's count of sittings, and the matrix it should give.
     *
     * @return void
     */
    public function test_every_view_counts_the_same_sittings(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$experimentid, $playable, $unplayable] = $this->experiment();
        $note = 'strategies played: ' . implode(', ', $playable) . '; not playable by the installed engine: '
            . (implode(', ', $unplayable) ?: 'none');
        $this->assertContains('classic', $playable, $note);
        $strategies = count($playable);

        foreach (['uniform' => 0, 'engine' => 1] as $rule => $column) {
            $expected = ['valid' => 0, 'invalid' => 0, 'unknown' => 0];
            foreach ($playable as $strategy) {
                foreach (self::EXPECTED as $kind => [$uniform, $engine]) {
                    $state = $column === 0 ? $uniform : $engine;
                    // The classical test has no minimum and no standard error to keep (strategy_catalog).
                    if ($column === 0 && $strategy === 'classic' && in_array($kind, ['F', 'G'], true)) {
                        $state = 'valid';
                    }
                    if ($state !== null) {
                        $expected[$state]++;
                    }
                }
            }
            $expected['all'] = $expected['valid'] + $expected['invalid'] + $expected['unknown'];

            foreach (['valid', 'invalid', 'unknown', 'all'] as $validity) {
                $filter = ['experimentid' => $experimentid, 'validity' => $validity, 'validityrule' => $rule];
                $label = $rule . '/' . $validity . ' (' . $note . ')';
                results_query::reset_caches();
                results_export::reset_caches();

                $counts = (new results_query($filter))->validity_counts();
                $this->assertSame(
                    [$expected['valid'], $expected['invalid'], $expected['unknown'], $expected['all']],
                    [$counts['valid'], $counts['invalid'], $counts['unknown'], $counts['total']],
                    $label . ': the counts'
                );

                $views = [
                    'stream' => iterator_count((new results_query($filter))->each_observation()),
                    'overview' => (new results_query($filter))->overview()['n'],
                    'provenance' => (new results_query($filter))->provenance()['attempts'],
                    'export csv' => count(results_export::dataset(
                        new results_query($filter),
                        results_export::LEVEL_ATTEMPT
                    )['rows']),
                    'export json' => count(json_decode(results_export::to_json(
                        new results_query($filter),
                        results_export::LEVEL_ATTEMPT
                    ), true)['data']),
                    'export metadata' => results_export::metadata(
                        new results_query($filter),
                        results_export::LEVEL_ATTEMPT,
                        true
                    )['rows'],
                    'export tab' => results_export::all_row_counts(new results_query($filter))['counts']['attempt'],
                ];
                foreach ($views as $view => $n) {
                    $this->assertSame($expected[$validity], $n, $label . ': ' . $view);
                }

                // The population funnel: planned, failed and waiting are said beside what was evaluated (#115).
                $funnel = analysis_population::funnel(new results_query($filter));
                $this->assertSame(10 * $strategies, $funnel['planned'], $label . ': planned');
                $this->assertSame(8 * $strategies, $funnel['evaluated'], $label . ': evaluated');
                $this->assertSame($strategies, $funnel['failed'], $label . ': failed');
                $this->assertSame($strategies, $funnel['tracepending'], $label . ': waiting for the trace');
                $this->assertSame($expected['valid'], $funnel['valid'], $label . ': valid in the funnel');
                $this->assertSame($expected['invalid'], $funnel['invalid'], $label . ': invalid in the funnel');
                $this->assertSame($expected['unknown'], $funnel['unknown'], $label . ': unknown in the funnel');

                // The subscale results: what the tab is made of is what the export holds — and, where invalid ones are
                // asked for, the scale results estimated without an item of their own, which no figure uses (#113), as
                // many as the coverage counts.
                $scalequery = new results_query($filter);
                $store = $scalequery->subscale_store();
                $zeros = in_array($validity, ['invalid', 'all'], true) ? $scalequery->scale_coverage()['withoutitems'] : 0;
                $rows = results_export::dataset(new results_query($filter), results_export::LEVEL_SUBSCALE)['rows'];
                $this->assertSame(count($store) + $zeros, count($rows), $label . ': subscale tab and subscale export');
                $this->assertSame($zeros, count(array_filter($rows, static fn($r) => (int) $r['items'] === 0
                    && $r['items'] !== null)), $label . ': the zeros in the export');
            }
        }

        // The reproduction package names the same sittings as the view it was taken from.
        $filter = ['experimentid' => $experimentid, 'validity' => 'valid', 'validityrule' => 'uniform'];
        $package = json_decode(reproducibility::package_json($experimentid, $filter), true);
        $this->assertIsArray($package);
        $text = json_encode($package);
        $this->assertStringContainsString('"validityrule":"uniform"', $text, 'the package says its rule');
        $population = $package['analysis']['population'] ?? [];
        $this->assertEquals(
            ['planned' => 10 * $strategies, 'failed' => $strategies, 'evaluated' => 8 * $strategies,
            'valid' => 3 * $strategies + 2, 'invalid' => 4 * $strategies - 2, 'unknown' => $strategies,
            'tracepending' => $strategies],
            array_intersect_key(
                $population['attempts'] ?? [],
                array_flip(['planned', 'failed', 'evaluated', 'valid', 'invalid', 'unknown', 'tracepending'])
            ),
            'the package counts the sittings as the page does'
        );
    }
}
