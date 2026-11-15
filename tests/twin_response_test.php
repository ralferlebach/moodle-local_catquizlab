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
use local_catquizlab\local\item_identity;
use local_catquizlab\local\materialiser;
use local_catquizlab\local\person_generator;
use local_catquizlab\local\pool_planner;
use local_catquizlab\local\registry;
use local_catquizlab\local\results_export;
use local_catquizlab\local\results_query;
use local_catquizlab\local\scale_provisioner;
use local_catquizlab\local\seed_domains;
use local_catquizlab\local\twin_response;

/**
 * The same twin answers the same item the same way in every strategy cell (#155).
 *
 * Runs are built as the setup builds them, minus the engine: run rows with their manifest and seeds from
 * create_sweep(), persons from person_generator, item ground truth from the materialiser's own plan. Every run gets
 * its own person ids and question ids, as in a real installation.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\twin_response
 * @covers     \local_catquizlab\local\seed_domains
 * @covers     \local_catquizlab\local\results_export
 * @covers     \local_catquizlab\local\response_oracle
 */
final class twin_response_test extends \advanced_testcase {
    /** @var string[] Five strategies. */
    protected const STRATEGIES = ['classic', 'fastest', 'allsubs', 'highestsub', 'lowestsub'];

    /** @var int Twins per run. */
    protected const TWINS = 100;

    /**
     * An experiment: five strategies, two replications, 2 × 2 × 50 = 200 items, 100 twins, set up from create_sweep().
     *
     * @param string $model The model.
     * @param array $persons Overrides of the persons block (stratum, severity).
     * @param string $variant The pool variant.
     * @return array{runs: array<int, \stdClass>, persons: array<int, \stdClass[]>, items: array<int, \stdClass[]>}
     */
    protected function experiment(string $model, array $persons = [], string $variant = 'ideal'): array {
        global $DB;

        $definition = experiment_definition::example_baseline();
        $definition['name'] = 'CRN ' . $model . ' ' . json_encode($persons) . ' ' . $variant;
        $definition['model'] = $model;
        $definition['replications'] = 2;
        $definition['persons'] = array_merge($definition['persons'], ['count' => self::TWINS], $persons);
        $definition['pool']['scales'] = ['categories' => 2, 'subcategories' => 2, 'itemspersubscale' => 50];
        $definition['pool']['variant'] = $variant;
        $definition['sweep']['factors']['strategy'] = self::STRATEGIES;
        $saved = experiment_service::save($definition);
        $sweep = experiment_service::create_sweep((int) $saved['id']);
        $out = ['runs' => [], 'persons' => [], 'items' => []];
        foreach ($sweep['runs'] as $runid) {
            $run = $DB->get_record('local_catquizlab_run', ['id' => $runid], 'id, masterseed, replication, manifestjson');
            $manifest = json_decode($run->manifestjson, true);
            $def = $manifest['config']['definition'];
            $master = (int) $run->masterseed;
            $rep = (int) $run->replication;
            // People: as the setup's people stage generates them, each run its own person rows.
            $generated = person_generator::generate($def, seed_domains::person_base($master, $rep), ['replication' => $rep,
                'deviationseed' => seed_domains::person_deviation(
                    $master,
                    $rep,
                    (string) $def['persons']['stratum'],
                    (string) $def['persons']['severity']
                )]);
            foreach ($generated as $person) {
                $id = (int) $DB->insert_record('local_catquizlab_person', (object) ['runid' => $runid,
                    'twinid' => $person['twinid'], 'twinindex' => 0, 'severity' => $person['severity'] ?? 'none',
                    'stratum' => $person['stratum'], 'abilityglobal' => $person['abilityglobal'],
                    'profilejson' => json_encode($person['profile']), 'timecreated' => 1, 'timemodified' => 1]);
                $out['persons'][$runid][] = $DB->get_record('local_catquizlab_person', ['id' => $id]);
            }
            // Items: the materialiser's plan of this run's pool, with question ids of this run's own.
            $blueprint = pool_planner::plan($def, seed_domains::pool($master, $rep, $model));
            $blueprint = \local_catquizlab\local\pool_mutator::mutate(
                $blueprint,
                $variant,
                (array) ($def['pool']['recipe'] ?? []),
                seed_domains::mutation($master, $rep, $variant, $model)
            );
            $scalemap = [];
            foreach ([1, 2] as $c) {
                foreach ([1, 2] as $s) {
                    $scalemap[] = ['level' => scale_provisioner::LEVEL_SUBSCALE, 'categoryindex' => $c,
                        'subscaleindex' => $s, 'catscaleid' => $runid * 100 + $c * 10 + $s, 'contextid' => $runid,
                        'name' => 'Run ' . $runid];
                }
            }
            $q = 0;
            foreach (materialiser::plan_items($blueprint, $scalemap, ['model' => $model, 'replication' => $rep]) as $spec) {
                $out['items'][$runid][] = (object) ['runid' => $runid, 'questionid' => $runid * 10000 + (++$q),
                    'itemkey' => $spec['itemkey'], 'model' => $spec['model'], 'truedifficulty' => $spec['truedifficulty'],
                    'discrimination' => $spec['discrimination'], 'guessing' => $spec['guessing'],
                    'stepsjson' => isset($spec['steps']) ? json_encode($spec['steps']) : null,
                    'truecategory' => $spec['truecategory'], 'truesubscale' => $spec['truesubscale']];
            }
            $out['runs'][$runid] = $run;
        }

        return $out;
    }

    /**
     * Every answer of every twin to every item in a run, by twin and item key.
     *
     * @param array $e From experiment().
     * @param int $runid The run.
     * @param bool $reversed Ask in the opposite order (a different order of play).
     * @return array<string, array> "twin|item" => [choice, fraction, schema]
     */
    protected function answers(array $e, int $runid, bool $reversed = false): array {
        $out = [];
        $persons = $reversed ? array_reverse($e['persons'][$runid]) : $e['persons'][$runid];
        $items = $reversed ? array_reverse($e['items'][$runid]) : $e['items'][$runid];
        foreach ($persons as $person) {
            foreach ($items as $item) {
                $answer = twin_response::answer($e['runs'][$runid], $person, $item, [], (int) $item->questionid);
                $out[$person->twinid . '|' . $item->itemkey] = [$answer['choice'], $answer['fraction'], $answer['schema']];
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * DoD1: 2PL and GPCM, five strategies, two replications, 100 twins, 200 items: within a replication not one
     * answer differs; between the replications they are other twins and other items.
     *
     * @return void
     */
    public function test_the_same_twin_answers_the_same_item_alike_in_every_cell(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        foreach (['2pl', 'gpcm'] as $model) {
            $e = $this->experiment($model);
            $this->assertCount(10, $e['runs']);
            $byreplication = [];
            foreach ($e['runs'] as $runid => $run) {
                $answers = $this->answers($e, $runid, $runid % 2 === 0);
                $this->assertCount(self::TWINS * 200, $answers, $model . ' run ' . $runid);
                $this->assertSame([2], array_values(array_unique(array_column($answers, 2))), 'RNG schema 2');
                $byreplication[(int) $run->replication][$runid] = $answers;
            }
            foreach ($byreplication as $replication => $runs) {
                $this->assertCount(5, $runs);
                $first = reset($runs);
                foreach ($runs as $runid => $answers) {
                    $differ = count(array_diff_assoc(array_map('json_encode', $first), array_map('json_encode', $answers)));
                    $this->assertSame(0, $differ, "{$model} r{$replication}: run {$runid} answers differently");
                }
                // Not trivially equal: the answers vary over twins and items.
                $this->assertGreaterThan(1, count(array_unique(array_column($first, 0))) + count(array_unique(
                    array_column($first, 1)
                )));
            }
            $this->assertSame(
                [],
                array_intersect_key(reset($byreplication[1]), reset($byreplication[2])),
                'another replication: other twins, other items'
            );
        }
    }

    /**
     * DoD2, AK4: a twin of another stratum or severity, or an item whose true parameters a variant moved, draws the
     * same random number — the answers differ only as much as the condition makes them. Retries and replays draw
     * nothing new.
     *
     * @return void
     */
    public function test_conditions_change_the_probability_not_the_random_number(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $base = $this->experiment('2pl');
        $chaotic = $this->experiment('2pl', ['stratum' => 'chaotic', 'severity' => 'strong']);
        $shifted = $this->experiment('2pl', [], 'shifted');
        $pick = static fn(array $e): int => (int) array_key_first(array_filter(
            $e['runs'],
            static fn(\stdClass $r): bool => (int) $r->replication === 1
        ));
        foreach ([$chaotic, $shifted] as $other) {
            $a = $pick($base);
            $b = $pick($other);
            $seeds = [];
            foreach ([$a => $base, $b => $other] as $runid => $e) {
                foreach (array_slice($e['persons'][$runid], 0, 20) as $person) {
                    foreach (array_slice($e['items'][$runid], 0, 20) as $item) {
                        $seeds[$runid][$person->twinid . '|' . $item->itemkey] = twin_response::seed(
                            $e['runs'][$runid],
                            $person,
                            $item,
                            (int) $item->questionid
                        )['seed'];
                    }
                }
            }
            $this->assertSame($seeds[$a], $seeds[$b], 'the same common random number per twin and item');
        }
        // A shift by +1 logit makes items harder: the same numbers through lower probabilities, fewer correct.
        $correct = static fn(array $answers): int => (int) array_sum(array_column($answers, 1));
        $this->assertLessThan($correct($this->answers($base, $pick($base))), $correct($this->answers(
            $shifted,
            $pick($shifted)
        )));

        // Asked again, in another order: nothing new is drawn.
        $runid = $pick($base);
        $this->assertSame($this->answers($base, $runid), $this->answers($base, $runid, true));
    }

    /**
     * AK6: a run created before 0.7.61 keeps RNG schema 1 (per run, person and question): not silently
     * reinterpreted. Its manifest has no common-random-number domain; its answers are drawn as they were.
     *
     * @return void
     */
    public function test_runs_from_before_keep_their_schema(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $e = $this->experiment('2pl');
        $runid = (int) array_key_first($e['runs']);
        $legacy = clone $e['runs'][$runid];
        $manifest = json_decode($legacy->manifestjson, true);
        unset($manifest['config']['seeds'][seed_domains::DOMAIN_RESPONSE_CRN]);
        $legacy->manifestjson = json_encode($manifest);
        $this->assertSame(1, seed_domains::rng_schema($manifest));
        $person = $e['persons'][$runid][0];
        $item = $e['items'][$runid][0];
        $seed = twin_response::seed($legacy, $person, $item, (int) $item->questionid);
        $this->assertSame(1, $seed['schema']);
        $this->assertSame(seed_domains::response(
            (int) $legacy->masterseed,
            $runid,
            (int) $person->id,
            (int) $item->questionid
        ), $seed['seed']);
        // An item from before the keys, in a new run: schema 1 for it too, never a made-up key.
        $keyless = clone $item;
        $keyless->itemkey = null;
        $this->assertSame(1, twin_response::seed($e['runs'][$runid], $person, $keyless, (int) $item->questionid)['schema']);
        $this->assertSame(2, seed_domains::rng_schema(json_decode($e['runs'][$runid]->manifestjson, true)));
    }
    /**
     * AK5: the step export carries what each answer was drawn from — seed, common random number, true ability, true
     * category probabilities, the category drawn — so that anybody can check the pair and the rule; runs under
     * RNG schema 1 are not reconstructed. Two strategy cells, five twins, overlapping items in different orders.
     *
     * @return void
     */
    public function test_the_step_export_lets_anyone_check_the_draw(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        foreach (['2pl', 'gpcm'] as $model) {
            results_export::reset_caches();
            results_query::reset_caches();
            $e = $this->experiment($model);
            $runids = array_slice(array_keys(array_filter(
                $e['runs'],
                static fn(\stdClass $r): bool => (int) $r->replication === 1
            )), 0, 2);
            $experimentid = (int) $DB->get_field('local_catquizlab_run', 'experimentid', ['id' => $runids[0]]);
            foreach ($runids as $n => $runid) {
                $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_FINISHED, ['id' => $runid]);
                foreach ($e['items'][$runid] as $item) {
                    $DB->insert_record('local_catquizlab_item', (object) ((array) $item
                        + ['itemname' => $item->itemkey, 'timecreated' => 1]));
                }
                // Thirty items each: the first cell in pool order, the second from the other end, twenty shared.
                foreach (array_slice($e['persons'][$runid], 0, 5) as $person) {
                    $items = $n === 0 ? array_slice($e['items'][$runid], 0, 30)
                        : array_reverse(array_slice($e['items'][$runid], 10, 30));
                    $responses = [];
                    foreach ($items as $item) {
                        $responses[(int) $item->questionid] = twin_response::answer(
                            $e['runs'][$runid],
                            $person,
                            $item,
                            [],
                            (int) $item->questionid
                        )['fraction'];
                    }
                    $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $runid, 'personid' => $person->id,
                        'status' => attempt_scheduler::STATUS_COLLECTED, 'tries' => 1, 'runtimems' => 1000,
                        'timecreated' => 1, 'timemodified' => 1, 'tracejson' => json_encode(['finaltheta' => 0.0,
                        'finalse' => 0.3, 'items' => array_keys($responses), 'nitems' => 30, 'steps' => 30,
                        'stopreason' => 'se', 'progress' => [
                            // As the engine keeps them: the questions in the order played, the scores by question.
                            'playedquestions' => array_map(static fn(int $q): array => ['id' => $q], array_keys($responses)),
                            'responses' => array_map(static fn(float $f): array => ['fraction' => $f], $responses),
                        ]])]);
                }
            }
            $export = fn(): array => iterator_to_array(results_export::iterate(new results_query(['experimentid' =>
                $experimentid, 'validity' => 'all']), results_export::LEVEL_STEP)['rows'], false);
            $rows = $export();
            $this->assertCount(2 * 5 * 30, $rows, $model);
            $pairs = [];
            foreach ($rows as $row) {
                $this->assertSame(2, $row['rngschema']);
                $this->assertNotSame('', $row['itemkey']);
                // What the engine recorded is what was drawn.
                $this->assertEqualsWithDelta((float) $row['score'], (float) $row['oraclefraction'], 1e-6);
                // The rule, from the exported numbers alone.
                $probs = array_map('floatval', explode(';', $row['trueprobs']));
                $this->assertEqualsWithDelta(1.0, array_sum($probs), 1e-5);
                $u = (float) $row['uniform'];
                $this->assertTrue($u >= 0.0 && $u < 1.0);
                if ($model === '2pl') {
                    $this->assertCount(2, $probs);
                    $this->assertSame($u < $probs[1] ? 1 : 0, $row['responsecategory']);
                } else {
                    $sum = 0.0;
                    $category = count($probs) - 1;
                    foreach ($probs as $k => $p) {
                        $sum += $p;
                        if ($u < $sum) {
                            $category = $k;
                            break;
                        }
                    }
                    $this->assertSame($category, $row['responsecategory']);
                }
                $pairs[$row['twinid'] . '|' . $row['itemkey']][$row['runid']] = [$row['rngseed'], $row['uniform'],
                    $row['responsecategory'], (float) $row['score'], $row['questionid']];
            }
            $shared = array_filter($pairs, static fn(array $byrun): bool => count($byrun) === 2);
            $this->assertCount(5 * 20, $shared, 'twenty items per twin in both cells');
            foreach ($shared as $key => $byrun) {
                [$a, $b] = array_values($byrun);
                $this->assertSame(array_slice($a, 0, 4), array_slice($b, 0, 4), "{$model} {$key}: the same draw");
                $this->assertNotSame($a[4], $b[4], 'under different question ids');
            }

            // A run from before 0.7.61: its answers are not reconstructed, the cells stay empty.
            $manifest = json_decode($e['runs'][$runids[1]]->manifestjson, true);
            unset($manifest['config']['seeds'][seed_domains::DOMAIN_RESPONSE_CRN]);
            $DB->set_field('local_catquizlab_run', 'manifestjson', json_encode($manifest), ['id' => $runids[1]]);
            results_export::reset_caches();
            foreach ($export() as $row) {
                if ((int) $row['runid'] === $runids[1]) {
                    $this->assertSame(1, $row['rngschema']);
                    $this->assertSame(['', '', '', ''], [$row['rngseed'], $row['uniform'], $row['trueprobs'],
                        $row['responsecategory']]);
                } else {
                    $this->assertNotSame('', $row['rngseed']);
                }
            }
        }
    }
}
