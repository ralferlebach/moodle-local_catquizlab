<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_catquizlab;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/catquizlab/tests/fixtures/sittings_experiment.php');

use local_catquizlab\local\output_agreement;
use local_catquizlab\local\progress_provenance;
use local_catquizlab\local\result_validity;
use local_catquizlab\local\results_export;
use local_catquizlab\local\results_query;

/**
 * The equality matrix valid | invalid | unknown | all (#136 AK 5, re-audit 21.11.2026): on a deterministic experiment
 * of two real strategies, every output says the same of every sitting under every filter, the filters partition the
 * sittings as the reference says, and a sitting carrying another's engine progress is found.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\output_agreement
 */
final class output_agreement_test extends \advanced_testcase {
    use \local_catquizlab\tests\sittings_experiment;

    /** @var int Twins per run. */
    protected const TWINS = 8;

    /**
     * Forget the limits and scale maps cached per run id: the ids start again in every test.
     */
    protected function setUp(): void {
        parent::setUp();
        \local_catquizlab\local\run_contract::reset_caches();
        \local_catquizlab\local\engine_validity::reset();
    }

    /**
     * The experiment of the matrix: fastest and allsubs, two replications, one pool variant, seven of eight twins
     * sitting, 12 to 18 questions against a maximum of 15, the third twin's record missing (unknown), the fourth
     * rejected by the engine for max_questions_exceeded.
     *
     * @return array{experimentid: int, runs: int[], truth: array}
     */
    private function matrix(): array {
        return $this->experiment(
            7,
            '2pl',
            [],
            ['fastest', 'allsubs'],
            [2, 2, 10],
            null,
            ['ideal'],
            true,
            ['minitems' => 10, 'maxitems' => 15],
            true
        );
    }

    /**
     * The sittings of the experiment by twin index (0-based within its run).
     *
     * @param int $experimentid The experiment.
     * @return array<int,int[]> twin index => sitting ids
     */
    private function by_twin(int $experimentid): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT a.id, p.twinindex
               FROM {local_catquizlab_attempt} a
               JOIN {local_catquizlab_person} p ON p.id = a.personid
               JOIN {local_catquizlab_run} r ON r.id = a.runid
              WHERE r.experimentid = ?
           ORDER BY a.id",
            [$experimentid]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->twinindex][] = (int) $row->id;
        }
        return $out;
    }

    /**
     * Every output agrees under every filter, and the filters hold what the reference says.
     */
    public function test_every_output_agrees_under_every_filter(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $built = $this->matrix();
        $twins = $this->by_twin($built['experimentid']);

        $result = output_agreement::check($built['experimentid']);
        $this->assertSame([], $result['problems'], implode("\n", array_slice($result['problems'], 0, 20)));

        // The reference: 4 runs × 7 sittings; twin 0 invalid (the engine's own error), twin 1 invalid (no final
        // estimate), twin 2 unknown (nothing recorded of what was asked), twin 3 rejected by the engine.
        $ids = $result['ids'];
        $this->assertCount(28, $ids[result_validity::ALL]);
        $this->assertSame(array_keys($built['truth']), $ids[result_validity::ALL]);
        $this->assertEqualsCanonicalizing($twins[2], $ids[result_validity::UNKNOWN]);
        foreach ([...$twins[0], ...$twins[1]] as $id) {
            $this->assertContains($id, $ids[result_validity::INVALID]);
        }
        $this->assertNotEmpty($ids[result_validity::VALID]);
        $this->assertSame([], array_intersect($ids[result_validity::VALID], $ids[result_validity::INVALID]));
        $union = [...$ids[result_validity::VALID], ...$ids[result_validity::INVALID], ...$ids[result_validity::UNKNOWN]];
        sort($union);
        $this->assertSame($ids[result_validity::ALL], $union);
        // Two real strategies, each with half the sittings.
        $this->assertSame(['allsubs' => 14, 'fastest' => 14], $result['bystrategy'][result_validity::ALL]);
        // Every sitting with an engine progress carries its own.
        $this->assertSame(28 - 4, $result['provenance'][progress_provenance::VERIFIED]);
        $this->assertSame(0, $result['provenance'][progress_provenance::MISMATCH]);

        // Under each filter the exports hold the engine's stop codes and reasons, max_questions_exceeded among them,
        // CSV and JSON alike, and the limits broken: the scale results carrying the engine's rejection are those of
        // "all" in the filter's state.
        $carrying = static fn(array $rows): array => array_values(array_map(
            static fn(array $r): string => $r['attemptid'] . ':' . $r['category'] . ':' . $r['subscale'] . ':'
                . $r['validitystate'],
            array_filter($rows, static fn(array $r): bool => str_contains(
                (string) $r['enginescalereasons'],
                'max_questions_exceeded'
            ))
        ));
        $scales = static fn(string $validity): array => json_decode(results_export::to_json(
            new results_query(['experimentid' => $built['experimentid'], 'validity' => $validity]),
            results_export::LEVEL_SUBSCALE
        ), true)['data'];
        $all = $carrying($scales(result_validity::ALL));
        $this->assertSame($twins[3], array_values(array_unique(array_map(static fn(string $k): int => (int) $k, $all))));
        foreach (output_agreement::FILTERS as $validity) {
            $query = new results_query(['experimentid' => $built['experimentid'], 'validity' => $validity]);
            $expected = $validity === result_validity::ALL ? $all : array_values(array_filter(
                $all,
                static fn(string $k): bool => str_ends_with($k, ':' . $validity)
            ));
            $this->assertSame($expected, $carrying($scales($validity)), $validity);
            $rows = json_decode(results_export::to_json($query, results_export::LEVEL_ATTEMPT), true)['data'];
            foreach ($rows as $row) {
                if (in_array((int) $row['attemptid'], $twins[3], true)) {
                    $this->assertSame(result_validity::INVALID, $row['engineverdict']);
                } else if (!in_array((int) $row['attemptid'], $twins[2], true)) {
                    $this->assertSame(result_validity::VALID, $row['engineverdict']);
                }
                $over = (int) $row['nitems'] > 15;
                $this->assertSame(
                    $over,
                    str_contains((string) $row['contractviolations'], 'global_max_exceeded'),
                    "{$validity}: sitting {$row['attemptid']}, {$row['nitems']} item(s)"
                );
            }
        }
        $this->assertNotEmpty($DB->get_records_select('local_catquizlab_attempt', "tracejson LIKE '%maxquestionsreached%'"));
    }

    /**
     * A sitting carrying another's engine progress is found, and the one it came from named.
     */
    public function test_a_foreign_progress_is_found(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $built = $this->matrix();
        $twins = $this->by_twin($built['experimentid']);
        [$from, $to] = [$twins[4][0], $twins[5][0]];
        $this->copy_progress($from, [$to]);

        $result = output_agreement::check($built['experimentid'], output_agreement::FILTERS, false);
        $this->assertContains("sitting(s) {$to} carry the engine progress of sitting(s) {$from}", $result['problems']);
        $this->assertNotEmpty(array_filter($result['problems'], static fn(string $p): bool =>
            str_starts_with($p, "sitting {$to}: its engine progress is mismatch")));
        $this->assertSame(1, $result['provenance'][progress_provenance::MISMATCH]);
        $this->assertNotNull($DB->get_record('local_catquizlab_attempt', ['id' => $from]));
    }

    /**
     * One progress stored with several sittings is found in each of them.
     */
    public function test_a_duplicated_progress_is_found(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $built = $this->matrix();
        $twins = $this->by_twin($built['experimentid']);
        $from = $twins[4][1];
        $to = [$twins[5][1], $twins[6][1]];
        sort($to);
        $this->copy_progress($from, $to);

        $result = output_agreement::check($built['experimentid'], output_agreement::FILTERS, false);
        $this->assertContains(
            'sitting(s) ' . implode(', ', $to) . " carry the engine progress of sitting(s) {$from}",
            $result['problems']
        );
        $this->assertSame(2, $result['provenance'][progress_provenance::MISMATCH]);
    }

    /**
     * Its own questions in another order: not provably its own, and said so.
     */
    public function test_a_reordered_progress_is_suspect(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $built = $this->matrix();
        $id = $this->by_twin($built['experimentid'])[4][2];
        $trace = json_decode($DB->get_field('local_catquizlab_attempt', 'tracejson', ['id' => $id]), true);
        $trace['progress']['playedquestions'] = array_reverse($trace['progress']['playedquestions']);
        $DB->set_field('local_catquizlab_attempt', 'tracejson', json_encode($trace), ['id' => $id]);

        $result = output_agreement::check($built['experimentid'], output_agreement::FILTERS, false);
        $this->assertSame(1, $result['provenance'][progress_provenance::SUSPECT]);
        $this->assertNotEmpty(array_filter($result['problems'], static fn(string $p): bool =>
            str_starts_with($p, "sitting {$id}: its engine progress is suspect")));
    }

    /**
     * The partition is checked: a sitting under two filters, under a filter not its state, or under none is a problem.
     */
    public function test_a_broken_partition_is_found(): void {
        $states = [1 => 'valid', 2 => 'invalid', 3 => 'unknown', 4 => 'valid'];
        $this->assertSame([], output_agreement::partition(
            ['valid' => [1, 4], 'invalid' => [2], 'unknown' => [3], 'all' => [1, 2, 3, 4]],
            $states
        ));
        $problems = output_agreement::partition(
            ['valid' => [1, 2], 'invalid' => [2], 'unknown' => [], 'all' => [1, 2, 3, 4]],
            $states
        );
        $this->assertContains('sitting(s) 2 under both valid and invalid', $problems);
        $this->assertContains('sitting 2 listed under valid, its state is "invalid"', $problems);
        $this->assertNotEmpty(array_filter($problems, static fn(string $p): bool =>
            str_starts_with($p, 'valid + invalid + unknown hold 2 sitting(s), all 4; missing from the three: [3,4]')));
    }

    /**
     * Store one sitting's engine progress with others, as the collector did before 0.7.75.
     *
     * @param int $from The sitting whose progress it is.
     * @param int[] $to The sittings it is stored with.
     */
    private function copy_progress(int $from, array $to): void {
        global $DB;
        $source = json_decode($DB->get_field('local_catquizlab_attempt', 'tracejson', ['id' => $from]), true);
        foreach ($to as $id) {
            $trace = json_decode($DB->get_field('local_catquizlab_attempt', 'tracejson', ['id' => $id]), true);
            $this->assertNotSame($source['items'], $trace['items']);
            $trace['progress'] = $source['progress'];
            $DB->set_field('local_catquizlab_attempt', 'tracejson', json_encode($trace), ['id' => $id]);
        }
    }
}
