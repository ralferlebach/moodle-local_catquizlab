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

use local_catquizlab\local\analysis_population;
use local_catquizlab\local\progress_provenance;
use local_catquizlab\local\result_validity;
use local_catquizlab\local\results_export;
use local_catquizlab\local\results_query;

/**
 * Whose engine progress a stored sitting carries (#168): the rules, the inventory that only reads, and the decisions —
 * a sitting left out of every analysis and export, re-derived from its own progress, and the re-derivation undone.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\progress_provenance
 */
final class progress_provenance_test extends \advanced_testcase {
    use \local_catquizlab\tests\sittings_experiment;

    /** @var int Twins per run. */
    protected const TWINS = 6;

    /**
     * A trace of these items and this engine progress.
     *
     * @param int[]|null $items The items; null for none.
     * @param int[]|null $played The played questions; null for no progress.
     * @return array
     */
    private static function trace(?array $items, ?array $played): array {
        $trace = [];
        if ($items !== null) {
            $trace['items'] = $items;
        }
        if ($played !== null) {
            $trace['progress'] = ['playedquestions' => array_combine(
                array_map('strval', $played),
                array_map(static fn(int $id): array => ['id' => (string) $id], $played)
            ) ?: []];
        }
        return $trace;
    }

    /**
     * The rules, one case each (rules version 1).
     */
    public function test_the_rules(): void {
        $verdict = static fn(?array $trace, bool $digested = false): string =>
            progress_provenance::assess_trace($trace, $digested)['verdict'];
        $this->assertSame(1, progress_provenance::RULES);
        $this->assertSame(progress_provenance::VERIFIED, $verdict(self::trace([1, 2, 3], [1, 2, 3])));
        // The development site's three: no question in common.
        $this->assertSame(progress_provenance::MISMATCH, $verdict(self::trace([1, 2, 3], [7, 8, 9, 10])));
        // Most questions differ.
        $this->assertSame(progress_provenance::MISMATCH, $verdict(self::trace([1, 2, 3, 4], [1, 7, 8, 9])));
        // Its own questions, another order.
        $this->assertSame(progress_provenance::SUSPECT, $verdict(self::trace([1, 2, 3], [3, 2, 1])));
        // One a part of the other, mostly shared: a progress cut short, or one question more.
        $this->assertSame(progress_provenance::SUSPECT, $verdict(self::trace([1, 2, 3, 4], [1, 2, 3])));
        $this->assertSame(progress_provenance::SUSPECT, $verdict(self::trace([1, 2, 3], [1, 2, 3, 4])));
        // A part, but mostly not shared.
        $this->assertSame(progress_provenance::MISMATCH, $verdict(self::trace([1, 2, 3, 4, 5], [1, 2])));
        $this->assertSame(progress_provenance::UNRECONSTRUCTABLE, $verdict(self::trace(null, [1, 2])));
        $this->assertSame(progress_provenance::UNRECONSTRUCTABLE, $verdict(null, true));
        $this->assertSame(progress_provenance::NOTASSESSED, $verdict(self::trace([1, 2], null)));
        $this->assertSame(progress_provenance::NOTASSESSED, $verdict(null));
        $this->assertSame(
            ['verdict' => progress_provenance::MISMATCH, 'progress' => 4, 'items' => 3, 'overlap' => 0],
            progress_provenance::assess_trace(self::trace([1, 2, 3], [7, 8, 9, 10]))
        );
    }

    /**
     * The inventory finds a foreign progress, a reordered one and one without items by id, and writes nothing.
     */
    public function test_the_inventory_finds_them_and_only_reads(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $built = $this->experiment(5, '2pl', [], ['fastest'], [2, 2, 10], null, ['ideal']);
        $ids = array_keys($built['truth']);
        [$foreign, $reordered, $noitems] = [$ids[3], $ids[4], $ids[5]];
        $this->change($foreign, fn(array $t): array => ['progress' => $this->stored($ids[8])['progress']] + $t);
        $this->change($reordered, static function (array $t): array {
            $t['progress']['playedquestions'] = array_reverse($t['progress']['playedquestions'], true);
            return $t;
        });
        $this->change($noitems, static function (array $t): array {
            unset($t['items']);
            return $t;
        });
        $state = static fn(): string => md5(json_encode(array_values($DB->get_records(
            'local_catquizlab_attempt',
            null,
            'id',
            'id, tracejson, timemodified, status'
        )))) . ':' . $DB->count_records(progress_provenance::TABLE);
        $before = $state();

        $inventory = progress_provenance::inventory();

        $this->assertSame($before, $state());
        $this->assertTrue($inventory['fingerprint']['unchanged']);
        $this->assertSame([$foreign], $inventory['ids'][progress_provenance::MISMATCH]);
        $this->assertSame([$reordered], $inventory['ids'][progress_provenance::SUSPECT]);
        $this->assertSame([$noitems], $inventory['ids'][progress_provenance::UNRECONSTRUCTABLE]);
        $this->assertSame(count($ids), $inventory['total']);
        $this->assertSame(count($ids) - 3, $inventory['counts'][progress_provenance::VERIFIED]);
        // Only some runs: the others not read.
        $other = progress_provenance::inventory([$built['runs'][1]]);
        $this->assertSame([], $other['ids'][progress_provenance::MISMATCH]);
        // Before a time: every sitting here was last changed at 1 or now.
        $this->assertSame(count($ids) - 3, progress_provenance::inventory(null, 2)['before'][progress_provenance::VERIFIED]);
        // Ids and counts only: nothing of a trace in the report.
        $this->assertStringNotContainsString('playedquestions', json_encode($inventory));
    }

    /**
     * A sitting left out is left out of every analysis, export and count, and the population says so; taken back in
     * by a later decision.
     */
    public function test_an_excluded_sitting_is_left_out_everywhere(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $built = $this->experiment(5, '2pl', [], ['fastest'], [2, 2, 10], null, ['ideal']);
        $ids = array_keys($built['truth']);
        $target = $ids[4];
        $this->change($target, fn(array $t): array => ['progress' => $this->stored($ids[8])['progress']] + $t);
        $filter = ['experimentid' => $built['experimentid'], 'validity' => result_validity::ALL];
        $sample = static function () use ($filter): array {
            $query = new results_query($filter);
            $analysis = array_map(static fn(array $o): int => (int) $o['attemptid'], iterator_to_array(
                $query->each_observation(),
                false
            ));
            $json = array_map(static fn(array $r): int => (int) $r['attemptid'], json_decode(
                results_export::to_json($query, results_export::LEVEL_ATTEMPT),
                true
            )['data']);
            $steps = array_unique(array_map(static fn(array $r): int => (int) $r['attemptid'], iterator_to_array(
                results_export::dataset($query, results_export::LEVEL_STEP)['rows'],
                false
            )));
            return [$analysis, $json, array_values($steps), analysis_population::funnel(new results_query($filter))];
        };
        [$analysis, $json, , $funnel] = $sample();
        $this->assertContains($target, $analysis);
        $this->assertSame(0, $funnel['excluded']);
        $collected = $funnel['collected'];

        progress_provenance::record($target, progress_provenance::EXCLUDED, 'test');
        [$analysis, $json, $steps, $funnel] = $sample();
        $this->assertNotContains($target, $analysis);
        $this->assertNotContains($target, $json);
        $this->assertNotContains($target, $steps);
        $this->assertSame(1, $funnel['excluded']);
        $this->assertSame($collected - 1, $funnel['collected']);
        $this->assertSame(1, progress_provenance::excluded_count($built['runs']));
        // The trace itself untouched.
        $this->assertSame(progress_provenance::MISMATCH, progress_provenance::assess($target)['verdict']);

        progress_provenance::record($target, progress_provenance::RECORDED, 'taken back in');
        [$analysis, , , $funnel] = $sample();
        $this->assertContains($target, $analysis);
        $this->assertSame(0, $funnel['excluded']);
        $this->assertSame(progress_provenance::RECORDED, progress_provenance::latest([$target])[$target]->decision);
        $this->expectException(\coding_exception::class);
        progress_provenance::record($target, progress_provenance::REDERIVED);
    }

    /**
     * Re-derived from the sitting's own progress where the engine has it provably — the stored trace kept, and put back
     * by revert() byte for byte; not re-derived where the engine has none or only another's.
     */
    public function test_rederive_and_revert(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $manager = $DB->get_manager();
        if (!$manager->table_exists('local_catquiz_progress') || !$manager->table_exists('adaptivequiz_attempt')) {
            $this->markTestSkipped('The engine is not installed.');
        }
        $built = $this->experiment(5, '2pl', [], ['fastest'], [2, 2, 10], null, ['ideal']);
        $ids = array_keys($built['truth']);
        $target = $ids[3];
        $own = $this->stored($target);
        $this->change($target, fn(array $t): array => ['progress' => $this->stored($ids[8])['progress']] + $t);
        $foreigntrace = (string) $DB->get_field('local_catquizlab_attempt', 'tracejson', ['id' => $target]);

        // No engine attempt: nothing to re-derive from.
        $this->assertFalse(progress_provenance::rederive($target)['done']);

        $user = $this->getDataGenerator()->create_user();
        $columns = $DB->get_columns('adaptivequiz_attempt');
        $engineattempt = (int) $DB->insert_record('adaptivequiz_attempt', (object) array_intersect_key(['instance' => 1,
            'userid' => $user->id, 'uniqueid' => 0, 'attemptstate' => 'completed', 'attemptstopcriteria' => '',
            'questionsattempted' => 0, 'difficultysum' => 0, 'standarderror' => 0, 'measure' => 0, 'timecreated' => time(),
            'timemodified' => time()], $columns));
        $DB->set_field('local_catquizlab_attempt', 'engineattemptid', $engineattempt, ['id' => $target]);
        $catattempt = (int) $DB->insert_record('local_catquiz_attempts', (object) ['attemptid' => $engineattempt,
            'userid' => $user->id, 'component' => 'mod_adaptivequiz', 'timecreated' => time()]);
        $progress = static fn(array $played): int => (int) $DB->insert_record('local_catquiz_progress', (object) [
            'attemptid' => $catattempt, 'userid' => $user->id, 'component' => 'adaptivequiz', 'timemodified' => time(),
            'json' => json_encode(['playedquestions' => $played])]);
        // The engine holds another progress for it: not provably its own, not re-derived.
        $progressid = $progress($this->stored($ids[6])['progress']['playedquestions']);
        $this->assertFalse(progress_provenance::rederive($target)['done']);
        $this->assertSame($foreigntrace, $DB->get_field('local_catquizlab_attempt', 'tracejson', ['id' => $target]));

        // Its own: re-derived, the stored trace kept with its hash.
        $DB->set_field(
            'local_catquiz_progress',
            'json',
            json_encode(['playedquestions' => $own['progress']['playedquestions']]),
            ['id' => $progressid]
        );
        $done = progress_provenance::rederive($target, 'test');
        $this->assertTrue($done['done'], $done['reason']);
        $this->assertSame(progress_provenance::VERIFIED, progress_provenance::assess($target)['verdict']);
        $row = $DB->get_record(progress_provenance::TABLE, ['id' => $done['row']]);
        $this->assertSame(progress_provenance::REDERIVED, $row->decision);
        $this->assertSame(progress_provenance::MISMATCH, $row->verdict);
        $this->assertSame($foreigntrace, $row->previoustrace);
        $this->assertSame(hash('sha256', $foreigntrace), $row->previoushash);
        $now = $this->stored($target);
        $this->assertSame(['rederived' => true, 'rules' => 1], $now['provenance']);
        $this->assertSame($own['items'], $now['items']);
        $this->assertSame(count($own['items']), $now['steps']);
        // Nothing else of the trace changed.
        $this->assertSame(
            array_diff_key($own, ['progress' => 1, 'steps' => 1]),
            array_diff_key($now, ['progress' => 1, 'steps' => 1, 'provenance' => 1])
        );
        // Verified now: nothing more to re-derive.
        $this->assertFalse(progress_provenance::rederive($target)['done']);

        // Undone: the stored trace back, byte for byte.
        $this->assertTrue(progress_provenance::revert($target));
        $this->assertSame($foreigntrace, $DB->get_field('local_catquizlab_attempt', 'tracejson', ['id' => $target]));
        $this->assertSame(progress_provenance::REVERTED, progress_provenance::latest([$target])[$target]->decision);
        $this->assertFalse(progress_provenance::revert($target));
        // Two rows: the re-derivation and its undoing; the refused attempts wrote none.
        $this->assertCount(2, $DB->get_records(progress_provenance::TABLE, ['attemptid' => $target]));
    }

    /**
     * Whether found sittings went out (#168 AK4): exports and research packages made after they were collected carry
     * them; those made before do not; browser downloads are said to be unrecorded.
     */
    public function test_exposure_of_the_found_sittings(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $built = $this->experiment(5, '2pl', [], ['fastest'], [2, 2, 10], null, ['ideal']);
        $ids = array_keys($built['truth']);
        [$early, $late] = [$ids[2], $ids[3]];
        foreach ([$early => 1000, $late => 5000] as $id => $time) {
            $DB->insert_record('local_catquizlab_attemptlog', (object) ['attemptid' => $id, 'runid' => $built['runs'][0],
                'tryno' => 1, 'outcome' => 'collected', 'timecreated' => $time]);
        }
        $none = progress_provenance::exposure([$early, $late]);
        $this->assertSame(0, $none['carried']);
        $this->assertSame(0, $none['experiments'][$built['experimentid']]['exports']);
        $this->assertStringContainsString('not recorded', $none['unrecorded']);

        // An export of the run between the two collections: it carries the early sitting, not the late one.
        $DB->insert_record('local_catquizlab_exportlog', (object) ['runid' => $built['runs'][0], 'experimentid' => null,
            'format' => 'csv', 'dataset' => 'rawmatrix', 'usermodified' => 2, 'timecreated' => 3000]);
        $one = progress_provenance::exposure([$early, $late]);
        $this->assertSame(1, $one['carried']);
        $this->assertSame(1, $one['experiments'][$built['experimentid']]['carriedbyalaterexport']);

        // A research package of the experiment after both: it carries both.
        get_file_storage()->create_file_from_string(['contextid' => \context_system::instance()->id,
            'component' => 'local_catquizlab', 'filearea' => 'export', 'itemid' => 0, 'filepath' => '/',
            'filename' => \local_catquizlab\local\research_package::filename($built['experimentid'], 9000),
            'timecreated' => 9000, 'timemodified' => 9000], 'zip');
        $both = progress_provenance::exposure([$early, $late]);
        $this->assertSame(2, $both['carried']);
        $this->assertSame(1, $both['experiments'][$built['experimentid']]['packages']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', 9000), $both['experiments'][$built['experimentid']]['lastexport']);
    }

    /**
     * A sitting's stored trace, decoded.
     *
     * @param int $attemptid The sitting.
     * @return array
     */
    private function stored(int $attemptid): array {
        global $DB;
        return json_decode((string) $DB->get_field('local_catquizlab_attempt', 'tracejson', ['id' => $attemptid]), true);
    }

    /**
     * Change a sitting's stored trace, as a defect before 0.7.75 would have stored it.
     *
     * @param int $attemptid The sitting.
     * @param callable $change Trace in, trace out.
     */
    private function change(int $attemptid, callable $change): void {
        global $DB;
        $DB->set_field(
            'local_catquizlab_attempt',
            'tracejson',
            json_encode($change($this->stored($attemptid))),
            ['id' => $attemptid]
        );
    }
}
