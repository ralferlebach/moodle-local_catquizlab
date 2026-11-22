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

namespace local_catquizlab\tests;

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\engine_validity;
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\item_identity;
use local_catquizlab\local\materialiser;
use local_catquizlab\local\person_generator;
use local_catquizlab\local\pool_mutator;
use local_catquizlab\local\pool_planner;
use local_catquizlab\local\question_template;
use local_catquizlab\local\registry;
use local_catquizlab\local\scale_provisioner;
use local_catquizlab\local\seed_domains;
use local_catquizlab\local\twin_response;

/**
 * An experiment of collected sittings as the setup builds it, for the tests of the outputs (#156, #136 AK 5).
 *
 * Three strategies × two replications × two pool variants by default, twelve twins per run (the using class' TWINS),
 * sittings of different lengths, valid, invalid and unknown ones, and twins without a sitting. Runs, persons and items
 * are built as the setup builds them (create_sweep(), person_generator, the materialiser's plan, its fingerprints);
 * the sittings' answers are what the twin answers.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait sittings_experiment {
    /**
     * Build the experiment.
     *
     * @param int $sittings How many of the twins sit the test, per run.
     * @param string $model The model.
     * @param array $persons Overrides of the persons block (stratum, severity).
     * @param string[] $strategies The strategies.
     * @param int[] $scales Categories, subscales per category, items per subscale.
     * @param int|null $length Questions per sitting; null: 12 to 18, mixed.
     * @param string[] $variants The pool variants.
     * @param bool $unknown Whether the third twin's sitting comes without its record of questions and answers: its
     *     validity, and that of its scale results, cannot be judged (unknown).
     * @param array $budgets Overrides of the global budget (minitems, maxitems).
     * @param bool $engineverdicts Whether the sittings carry the engine's own verdict (#136 AK 5): the fourth twin's
     *     rejected for max_questions_exceeded on its first scale, the host's stop code maxquestionsreached; the others
     *     accepted.
     * @return array{experimentid: int, runs: int[], truth: array} truth: per attempt [finaltheta, finalse].
     */
    protected function experiment(
        int $sittings = 10,
        string $model = '2pl',
        array $persons = [],
        array $strategies = ['classic', 'fastest', 'allsubs'],
        array $scales = [2, 2, 10],
        ?int $length = null,
        array $variants = ['ideal', 'shifted'],
        bool $unknown = false,
        array $budgets = [],
        bool $engineverdicts = false
    ): array {
        global $DB;

        $definition = experiment_definition::example_baseline();
        $definition['name'] = 'Package ' . $sittings . ' ' . $model . ' ' . json_encode($persons);
        $definition['model'] = $model;
        $definition['replications'] = 2;
        $definition['persons'] = array_merge($definition['persons'], ['count' => self::TWINS], $persons);
        $definition['pool']['scales'] = ['categories' => $scales[0], 'subcategories' => $scales[1],
            'itemspersubscale' => $scales[2]];
        $definition['sweep']['factors']['strategy'] = $strategies;
        $definition['sweep']['factors']['variant'] = $variants;
        $definition['budgets']['global'] = array_merge($definition['budgets']['global'], $budgets);
        $saved = experiment_service::save($definition);
        $experimentid = (int) $saved['id'];
        $sweep = experiment_service::create_sweep($experimentid);
        $truth = [];
        foreach ($sweep['runs'] as $n => $runid) {
            $run = $DB->get_record('local_catquizlab_run', ['id' => $runid], 'id, masterseed, replication, manifestjson');
            $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_FINISHED, ['id' => $runid]);
            $def = json_decode($run->manifestjson, true)['config']['definition'];
            $master = (int) $run->masterseed;
            $rep = (int) $run->replication;
            $model = (string) $def['model'];
            $variant = (string) $def['pool']['variant'];
            $scalemap = [];
            foreach (range(1, $scales[0]) as $c) {
                foreach (range(1, $scales[1]) as $s) {
                    $catscaleid = $runid * 100 + $c * 10 + $s;
                    $DB->insert_record('local_catquizlab_scalemap', (object) ['runid' => $runid,
                        'level' => scale_provisioner::LEVEL_SUBSCALE, 'catscaleid' => $catscaleid,
                        'parentcatscaleid' => $runid * 100 + $c, 'contextid' => 1, 'nodekey' => 'c' . $c . 's' . $s,
                        'generation' => 1, 'categoryindex' => $c, 'subscaleindex' => $s, 'name' => 'K' . $c . '.' . $s,
                        'timecreated' => 1]);
                    $scalemap[] = ['level' => scale_provisioner::LEVEL_SUBSCALE, 'categoryindex' => $c, 'subscaleindex' => $s,
                        'catscaleid' => $catscaleid, 'contextid' => 1, 'name' => 'Run ' . $runid];
                }
            }
            // The pool as the materialiser plans, mutates, renders and fingerprints it.
            $blueprint = pool_mutator::mutate(
                pool_planner::plan($def, seed_domains::pool($master, $rep, $model)),
                $variant,
                (array) ($def['pool']['recipe'] ?? []),
                seed_domains::mutation($master, $rep, $variant, $model)
            );
            $poolid = $DB->insert_record('local_catquizlab_pool', (object) ['experimentid' => $experimentid, 'runid' => $runid,
                'variant' => $variant, 'timecreated' => 1, 'timemodified' => 1]);
            $items = [];
            $poolitems = [];
            $q = 0;
            foreach (materialiser::plan_items($blueprint, $scalemap, ['model' => $model, 'replication' => $rep]) as $spec) {
                $spec['runid'] = $runid;
                $spec += item_identity::fingerprints($spec, question_template::render($spec));
                $item = (object) ['runid' => $runid, 'poolid' => $poolid, 'questionid' => $runid * 1000 + (++$q),
                    'itemname' => $spec['itemname'], 'itemkey' => $spec['itemkey'], 'contentfp' => $spec['contentfp'],
                    'fingerprint' => $spec['fingerprint'], 'model' => $spec['model'],
                    'truedifficulty' => $spec['truedifficulty'], 'storeddifficulty' => $spec['difficulty'],
                    'discrimination' => $spec['discrimination'], 'guessing' => $spec['guessing'],
                    'stepsjson' => isset($spec['steps']) ? json_encode($spec['steps']) : null,
                    'truecategory' => $spec['truecategory'], 'truesubscale' => $spec['truesubscale'],
                    'truecatscaleid' => $spec['truecatscaleid'], 'miscalibrated' => (int) $spec['miscalibrated'],
                    'mistagged' => (int) $spec['mistagged'],
                    'assignedcatscaleid' => $spec['catscaleid'], 'timecreated' => 1];
                $item->id = $DB->insert_record('local_catquizlab_item', $item);
                $items[] = $item;
                $poolitems[$item->itemkey] = $item->fingerprint;
            }
            $DB->update_record('local_catquizlab_pool', (object) ['id' => $poolid, 'itemcount' => count($items),
                'fingerprint' => item_identity::pool_fingerprint($poolitems)]);

            $generated = person_generator::generate($def, seed_domains::person_base($master, $rep), ['replication' => $rep,
                'deviationseed' => seed_domains::person_deviation(
                    $master,
                    $rep,
                    (string) $def['persons']['stratum'],
                    (string) $def['persons']['severity']
                )]);
            foreach (array_values($generated) as $i => $p) {
                $person = (object) ['runid' => $runid, 'twinid' => $p['twinid'], 'twinindex' => $i,
                    'severity' => $p['severity'] ?? 'none', 'stratum' => $p['stratum'], 'abilityglobal' => $p['abilityglobal'],
                    'profilejson' => json_encode($p['profile']), 'timecreated' => 1, 'timemodified' => 1];
                $person->id = $DB->insert_record('local_catquizlab_person', $person);
                if ($i >= $sittings) {
                    // A twin without a sitting: in twins, never in attempts.
                    continue;
                }
                // Mixed lengths; the order differs per strategy cell.
                $played = $length ?? 12 + ($i + $n) % 7;
                // Round robin over the subscales, so that a sitting has several subscale results to rank.
                $order = $items;
                usort($order, static fn(\stdClass $a, \stdClass $b): int => [substr($a->itemkey, -4), $a->itemkey]
                    <=> [substr($b->itemkey, -4), $b->itemkey]);
                $played = array_slice($n % 2 ? array_reverse($order) : $order, $length === null ? $i % 3 : 0, $played);
                $responses = [];
                foreach ($played as $item) {
                    $responses[(int) $item->questionid] = ['questionid' => (string) $item->questionid,
                        'slot' => count($responses) + 1, 'timecreated' => 1000 + count($responses),
                        'fraction' => twin_response::answer($run, $person, $item, [], (int) $item->questionid)['fraction']];
                }
                $theta = round(sin($person->id), 6);
                $se = 0.3 + ($i % 4) / 10;
                $trace = ['items' => array_keys($responses), 'nitems' => count($responses), 'steps' => count($responses),
                    // Each subscale its own estimate, so that the subscale figures and rankings have something to rank.
                    'scaleabilities' => array_combine(
                        array_map(static fn(array $m): int => $m['catscaleid'], $scalemap),
                        array_map(
                            static fn(array $m): float => round($theta + 0.4 * sin($person->id * $m['catscaleid']), 6),
                            $scalemap
                        )
                    ),
                    'scalestandarderrors' => array_fill_keys(
                        array_map(static fn(array $m): int => $m['catscaleid'], $scalemap),
                        $se
                    ),
                    // The estimate after each step, as the engine's ability path records it: what the flows read.
                    'abilitypath' => array_map(static fn(int $k): array => ['abilities' => [$runid * 100 => round(
                        $theta * ($k + 1) / count($responses) + 0.1 * sin($k + $person->id),
                        6
                    )]], range(0, count($responses) - 1)),
                    'progress' => ['playedquestions' => array_map(
                        static fn(int $id): array => ['id' => $id],
                        array_keys($responses)
                    ), 'responses' => $responses]];
                if ($i === 0) {
                    // Invalid: the engine ended on an error of its own.
                    $trace += ['finaltheta' => $theta, 'finalse' => $se, 'stopreason' => 'errorfetchnextquestion'];
                } else if ($i === 1) {
                    // No final ability.
                    $trace += ['stopreason' => 'maxitems'];
                } else {
                    $trace += ['finaltheta' => $theta, 'finalse' => $se, 'stopreason' => 'se'];
                }
                if ($unknown && $i === 2) {
                    // Nothing recorded of what was asked and answered: neither valid nor invalid.
                    unset($trace['items'], $trace['progress'], $trace['abilitypath']);
                }
                if ($engineverdicts && isset($trace['items'])) {
                    $measure = engine_validity::measure_from_trace($trace, (int) $runid) ?? ['scales' => [], 'n' => 0];
                    // The scale of the first question asked: one the sitting has a result on.
                    $first = (int) $played[0]->assignedcatscaleid;
                    $measure['engine'] = $i === 3
                        ? ['valid' => false, 'scales' => [$first => ['valid' => false,
                            'reasons' => [engine_validity::MAX_QUESTIONS_EXCEEDED]]]]
                        : ['valid' => true, 'scales' => [$first => ['valid' => true, 'reasons' => []]]];
                    $trace['enginevalidity'] = $measure;
                    if ($i === 3) {
                        $trace['hoststopcode'] = 'maxquestionsreached';
                    }
                }
                $attemptid = $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $runid,
                    'personid' => $person->id, 'status' => attempt_scheduler::STATUS_COLLECTED, 'tries' => 1 + $i % 2,
                    'runtimems' => 1000 + $i, 'timecreated' => 1, 'timemodified' => 1, 'tracejson' => json_encode($trace)]);
                $truth[$attemptid] = [$trace['finaltheta'] ?? null, $trace['finalse'] ?? null];
            }
        }

        return ['experimentid' => $experimentid, 'runs' => $sweep['runs'], 'truth' => $truth];
    }
}
