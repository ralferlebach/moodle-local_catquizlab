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

use local_catquizlab\local\answer_matrix;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\mode_detection;
use local_catquizlab\local\registry;
use local_catquizlab\local\results_export;
use local_catquizlab\local\results_query;
use local_catquizlab\local\robustness_analysis;
use local_catquizlab\local\scale_provisioner;
use local_catquizlab\local\subscale_evaluator;
use local_catquizlab\local\trace_digest;
use local_catquizlab\local\trace_reader;

/**
 * The results read digests, and every figure, row and file is the same as from the whole traces (#147, AK7, D4).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\trace_digest
 * @covers     \local_catquizlab\local\results_query
 */
final class trace_digest_test extends \advanced_testcase {
    /**
     * Forget what a test read.
     *
     * @return void
     */
    protected function tearDown(): void {
        trace_digest::$wholetraces = false;
        results_query::reset_caches();
        results_export::reset_caches();
        trace_reader::reset();
        parent::tearDown();
    }

    /**
     * An experiment of two strategies on twins, long sittings with every record the engine keeps, some invalid.
     *
     * @param bool $digests Whether the sittings are written with their digests, as since 0.7.57.
     * @return int The experiment.
     */
    protected function experiment(bool $digests): int {
        global $DB;

        $definition = experiment_definition::example_baseline();
        $definition['name'] = 'Digest';
        $definition['sweep']['factors']['strategy'] = ['classic', 'allsubs'];
        $definition['pool']['scales'] = ['categories' => 1, 'subcategories' => 4, 'itemspersubscale' => 20];
        $definition['budgets']['global'] = ['minitems' => 1, 'maxitems' => 80];
        $definition['budgets']['subscale'] = ['minitems' => 1, 'maxitems' => 20];
        $experimentid = (int) experiment_service::save($definition)['id'];
        experiment_service::create_sweep($experimentid);
        mt_srand(147);
        foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC') as $run) {
            $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_FINISHED, ['id' => $run->id]);
            $base = (int) $run->id * 100;
            $items = [];
            for ($s = 1; $s <= 4; $s++) {
                $DB->insert_record('local_catquizlab_scalemap', (object) ['runid' => $run->id,
                    'level' => scale_provisioner::LEVEL_SUBSCALE, 'catscaleid' => $base + $s, 'parentcatscaleid' => $base,
                    'contextid' => 1, 'nodekey' => 'c1s' . $s, 'generation' => 1, 'categoryindex' => 1,
                    'subscaleindex' => $s, 'name' => 'S' . $s, 'timecreated' => time()]);
                for ($k = 1; $k <= 20; $k++) {
                    $questionid = $base * 100 + $s * 20 + $k;
                    $items[] = [$questionid, $base + $s];
                    $DB->insert_record('local_catquizlab_item', (object) ['runid' => $run->id, 'questionid' => $questionid,
                        'assignedcatscaleid' => $base + $s, 'truecatscaleid' => $base + $s, 'itemname' => 'q' . $questionid,
                        'timecreated' => time()]);
                }
            }
            for ($twin = 1; $twin <= 12; $twin++) {
                $global = round(-1.5 + 0.25 * $twin, 3);
                $subs = [];
                for ($s = 1; $s <= 4; $s++) {
                    $subs[] = ['index' => $s, 'theta' => round($global + 0.6 * sin($twin + $s), 3)];
                }
                $personid = $DB->insert_record('local_catquizlab_person', (object) ['runid' => $run->id,
                    'twinid' => sprintf('r001-t%05d', $twin), 'twinindex' => $twin, 'stratum' => 'subscalevariation',
                    'severity' => 'medium', 'abilityglobal' => $global, 'profilejson' => json_encode(['global' => $global,
                        'categories' => [['index' => 1, 'theta' => $global, 'subscales' => $subs]]]),
                    'timecreated' => time(), 'timemodified' => time()]);
                $played = [];
                $path = [];
                $responses = [];
                $abilities = [];
                foreach ($items as $k => [$questionid, $scaleid]) {
                    $played[(string) $questionid] = ['id' => (string) $questionid, 'catscaleid' => (string) $scaleid,
                        'model' => 'raschbirnbaum', 'discrimination' => '1.2', 'difficulty' => (string) round(sin($k), 3),
                        'guessing' => '0'];
                    $responses[$questionid] = $twin === 12 ? 1.0 : (float) (($k + $twin) % 2);
                    $abilities[$scaleid] = round($global + 0.5 * cos($k + $twin), 4);
                    $path[] = ['step' => $k + 1, 'abilities' => $abilities];
                }
                $trace = ['finalse' => 0.31, 'information' => 10.4, 'items' => array_column($items, 0), 'nitems' => 80,
                    'stopreason' => 'Reached maximum number of questions', 'hoststopcode' => 'maximumquestions',
                    'scaleabilities' => $abilities, 'scalestandarderrors' => array_map(static fn() => 0.4, $abilities),
                    'abilitypath' => $path, 'responses' => $responses,
                    'progress' => ['playedquestions' => $played, 'responses' => [], 'activescales' => array_keys($abilities),
                        'droppedscales' => [], 'lockedscales' => [], 'scalestatetrace' => [['step' => 1, 'scale' => $base + 1,
                        'state' => 'active']], 'abilitytrace' => $path]];
                if ($twin !== 11) {
                    $trace['finaltheta'] = round($global + 0.1 * cos($twin), 4);
                }
                $json = json_encode($trace);
                $DB->insert_record('local_catquizlab_attempt', (object) ([
                    'runid' => $run->id, 'personid' => $personid, 'status' => attempt_scheduler::STATUS_COLLECTED,
                    'tries' => 1, 'runtimems' => 1000 * $twin, 'timecreated' => time(), 'timemodified' => time(),
                    'tracejson' => $json, 'tracebytes' => strlen($json),
                ] + ($digests ? trace_digest::fields($json) : [])));
            }
        }

        return $experimentid;
    }

    /**
     * Everything the results produce for an experiment, as one comparable array.
     *
     * @param int $experimentid The experiment.
     * @return array
     */
    protected function everything(int $experimentid): array {
        global $DB;
        $out = [];
        foreach (['valid', 'all', 'invalid'] as $validity) {
            foreach (['uniform', 'engine'] as $rule) {
                $filter = ['experimentid' => $experimentid, 'validity' => $validity, 'validityrule' => $rule];
                results_query::reset_caches();
                results_export::reset_caches();
                $query = new results_query($filter);
                $key = $validity . '/' . $rule;
                $overview = $query->overview();
                $out[$key]['overview'] = [$overview['n'], $overview['sample'], $overview['points'], $overview['people'],
                    $overview['itemcounts'], $overview['all']->count()];
                $out[$key]['provenance'] = array_diff_key($query->provenance(), ['computed' => true]);
                $out[$key]['validity'] = $query->validity_counts();
                $out[$key]['coverage'] = (new results_query($filter))->scale_coverage();
                $store = (new results_query($filter))->subscale_store();
                $out[$key]['subscales'] = iterator_to_array($store->rows(), false);
                foreach (array_keys(results_export::levels()) as $level) {
                    if (in_array($level, [results_export::LEVEL_EXECUTION], true)) {
                        continue;
                    }
                    $data = results_export::dataset(new results_query($filter), $level);
                    $out[$key]['export'][$level] = [$data['columns'], $data['rows']];
                }
                $out[$key]['modes'] = mode_detection::analyse(mode_detection::sittings(new results_query($filter)));
                $observations = iterator_to_array((new results_query($filter))->each_observation(), false);
                $out[$key]['robustness'] = robustness_analysis::cells(
                    $observations,
                    (new results_query($filter))->scale_maps(),
                    $rule
                );
            }
        }
        foreach ($DB->get_fieldset_select('local_catquizlab_run', 'id', 'experimentid = ?', [$experimentid]) as $runid) {
            $out['run' . $runid] = [answer_matrix::build((int) $runid), subscale_evaluator::evaluate_run((int) $runid)];
        }

        return $out;
    }

    /**
     * Digests, the trace where a sitting has none yet, and whole traces: the same results, and a tenth of the bytes.
     *
     * @return void
     */
    public function test_the_results_are_the_same_from_digests(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $experimentid = $this->experiment(true);

        trace_digest::$wholetraces = true;
        $whole = $this->everything($experimentid);
        trace_digest::$wholetraces = false;
        trace_reader::reset();
        $digests = $this->everything($experimentid);
        // Not an empty comparison: sittings, subscale results, every export level and the modes are there.
        $this->assertSame(20, $whole['valid/uniform']['overview'][0], 'valid sittings: 24 less one all correct and one '
            . 'without a final ability per strategy');
        $this->assertSame(80, count($whole['valid/uniform']['subscales']), '20 valid sittings of four subscales');
        $this->assertSame(1600, count($whole['valid/uniform']['export']['step'][1]), '20 valid sittings of 80 steps');
        $this->assertNotEmpty($whole['all/uniform']['modes']['sittings'] ?? $whole['all/uniform']['modes']);
        $this->assertEquals($whole, $digests);
        $this->assertSame(json_encode($whole), json_encode($digests), 'the same to the last digit');

        // Read: digests only, a small share of the traces.
        $sums = $DB->get_record_sql('SELECT SUM(tracebytes) AS t, SUM(digestbytes) AS d FROM {local_catquizlab_attempt}');
        $this->assertLessThan(0.25 * (int) $sums->t, (int) $sums->d);

        // Sittings from before 0.7.57, without a digest: the same again, and the task fills them in.
        $DB->set_field_select('local_catquizlab_attempt', 'digestjson', null, '1 = 1');
        $DB->set_field_select('local_catquizlab_attempt', 'digestbytes', null, '1 = 1');
        $this->assertSame(json_encode($whole), json_encode($this->everything($experimentid)), 'without digests');
        $this->assertGreaterThan(0, trace_digest::missing());
        (new \local_catquizlab\task\build_trace_digests())->execute();
        $this->expectOutputRegex('/wrote \d+ trace digests, 0 left/');
        $this->assertSame(0, trace_digest::missing());
        $this->assertSame(json_encode($whole), json_encode($this->everything($experimentid)), 'after the task');
    }

    /**
     * A digest drops the step records and nothing else.
     *
     * @return void
     */
    public function test_a_digest_drops_only_the_step_records(): void {
        $trace = ['finaltheta' => 0.5, 'items' => [1, 2], 'responses' => [1 => 1.0], 'abilitypath' => [[1]],
            'enginevalidity' => ['n' => 2], 'whatever' => 'kept',
            'progress' => ['playedquestions' => [1 => []], 'scalestatetrace' => [1], 'abilitytrace' => [1],
                'responses' => [1 => ['fraction' => 1]], 'activescales' => [3], 'droppedscales' => [], 'lockedscales' => []]];
        $digest = trace_digest::of($trace);
        $this->assertSame(['finaltheta', 'items', 'responses', 'enginevalidity', 'whatever', 'progress'], array_keys($digest));
        $this->assertSame(['responses', 'activescales', 'droppedscales', 'lockedscales'], array_keys($digest['progress']));
        $this->assertSame(['digestjson' => null, 'digestbytes' => null], trace_digest::fields(null));
        $this->assertSame(['digestjson' => null, 'digestbytes' => null], trace_digest::fields('not json'));
    }
}
