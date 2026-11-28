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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/tests/fixtures/trace_scenario.php');
require_once($CFG->dirroot . '/local/catquiz/lib.php');

use adaptivequizcatmodel_catquiz\local\monitoring\request_simulator;
use adaptivequizcatmodel_catquiz\trace_scenario;
use local_catquizlab\local\population_guard;
use local_catquizlab\local\registry;

/**
 * No twin's estimate is another's prior, also while a finished twin waits for its collection (#174 AK04, DoD02;
 * re-audit 27.11.2026).
 *
 * With the real engine: the same answers give the same items and the same ability, whether or not another person's
 * attempt has finished and stays uncollected (a delayed collector; its final estimate admissible in the context).
 * The positive control shows what the guard is for: with as many admissible abilities in the context as the engine's
 * threshold (50), the same answers give another sitting — the engine starts from the population. The guard
 * (population_guard) hands nothing out before that point, counting the sittings being played.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\population_guard
 */
final class prior_isolation_test extends \advanced_testcase {
    /** @var trace_scenario The engine's activity, its pool and its first participant. */
    private trace_scenario $scenario;

    /** @var bool[] The answers every sitting gives, in order. */
    private const ANSWERS = [true, false, true, true, false, true, false, false, true, true];

    /**
     * One sitting with the given answers, to its end: the items it was given and the engine's final ability.
     *
     * @param int $userid The person.
     * @return array{items: int[], ability: float, contextid: int, catscaleid: int}
     */
    protected function sit(int $userid): array {
        global $DB;

        $this->setUser($userid);
        $items = [];
        $play = fn(?bool $right): array => request_simulator::request(
            $this->scenario->adaptivequiz,
            $this->scenario->context,
            $userid,
            $right
        );
        $result = $play(null);
        foreach (self::ANSWERS as $right) {
            if ($result['questionid'] === null) {
                break;
            }
            $items[] = (int) $result['questionid'];
            $result = $play($right);
        }
        $this->setAdminUser();
        // The engine's feedback path says the fixture's scale has the same lower and upper limit (a fixture of the
        // engine's, not of this test): noted there, not here.
        $this->resetDebugging();
        $engine = (int) $DB->get_field('adaptivequiz_attempt', 'MAX(id)', ['userid' => $userid,
            'instance' => $this->scenario->adaptivequiz->id]);
        $cat = $DB->get_record_sql(
            'SELECT * FROM {local_catquiz_attempts} WHERE attemptid = ? ORDER BY id DESC',
            [$engine],
            IGNORE_MULTIPLE
        );

        return ['items' => $items, 'ability' => round((float) $cat->personability_after_attempt, 6),
            'contextid' => (int) $cat->contextid, 'catscaleid' => (int) $cat->scaleid];
    }

    /**
     * A participant of the scenario's course.
     *
     * @return int
     */
    protected function participant(): int {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $this->scenario->adaptivequiz->course, 'student');

        return (int) $user->id;
    }

    /**
     * Control, delayed collector, positive control — and the guard.
     *
     * @return void
     */
    public function test_a_finished_uncollected_twin_is_no_prior_and_the_guard_holds_the_population_back(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        // The engine's whole end of an attempt, as production runs it: its feedback path writes the final estimate with
        // its context and standard error (skipping it leaves both empty, and no population can arise).
        putenv('CATQUIZ_TESTING_SKIP_FEEDBACK');
        $this->scenario = trace_scenario::create($this->getDataGenerator(), false, ['catquiz_maxquestions' => 8,
            'catquiz_minquestions' => 8, 'catquiz_standarderror_max' => 10]);
        // The test's CAT context, as the lab provisions every test (scale_provisioner): the one the items' parameters
        // live in. The engine's population is the admissible abilities of that context; the fixture's test names none.
        $contextid = (int) $DB->get_field_sql('SELECT MIN(contextid) FROM {local_catquiz_itemparams} WHERE contextid > 0');
        $this->assertGreaterThan(0, $contextid);
        $DB->set_field('local_catquiz_tests', 'contextid', $contextid, ['componentid' => $this->scenario->adaptivequiz->id]);
        \cache_helper::purge_all();
        $threshold = population_guard::threshold();
        $this->assertSame(50, $threshold, 'the engine\'s threshold, read from the engine');

        // Control, twice: the same answers on an empty context give the same sitting (the engine is deterministic here).
        $forget = static function (int $userid) use ($DB): void {
            $DB->delete_records('local_catquiz_personparams', ['userid' => $userid]);
        };
        $control = $this->sit($this->scenario->userid);
        $forget($this->scenario->userid);
        $again = $this->sit($this->participant());
        $this->assertSame($control['items'], $again['items'], 'deterministic');
        $this->assertSame($control['ability'], $again['ability']);
        $DB->delete_records_select('local_catquiz_personparams', 'userid <> ?', [0]);

        // A delayed collector: twin X has finished, its final estimate stays (its trace waits); twin Y sits meanwhile.
        $x = $this->participant();
        $this->sit($x);
        $admissible = \local_catquiz\catquiz::get_person_abilities($control['contextid'], [$control['catscaleid']]);
        $this->assertCount(1, $admissible, 'X\'s final estimate is admissible while it waits');
        $y = $this->sit($this->participant());
        $this->assertSame($control['items'], $y['items'], 'the same items as the control');
        $this->assertSame($control['ability'], $y['ability'], 'the same ability as the control');

        // Positive control: as many admissible abilities as the threshold, far from the control's — the engine takes
        // them, and the same answers no longer give the same sitting. What the guard prevents.
        $template = reset($admissible);
        $rows = [];
        for ($i = 1; $i < $threshold; $i++) {
            $row = clone $template;
            unset($row->id);
            $row->userid = 9000000 + $i;
            $row->ability = 2.5 + $i / 100;
            $rows[] = $row;
        }
        $DB->insert_records('local_catquiz_personparams', $rows);
        // X's, Y's and the made-up ones: at least the threshold.
        $this->assertGreaterThanOrEqual($threshold, count(\local_catquiz\catquiz::get_person_abilities(
            $control['contextid'],
            [$control['catscaleid']]
        )));
        $z = $this->sit($this->participant());
        $this->assertNotSame(
            [$control['items'], $control['ability']],
            [$z['items'], $z['ability']],
            'from the threshold on the population is the prior'
        );

        // The guard, on a run in this context: blocks at the threshold, counting the sittings being played.
        /** @var \local_catquizlab_generator $lab */
        $lab = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $lab->create_run(['status' => registry::STATUS_READY])->id;
        $DB->insert_record('local_catquizlab_scalemap', (object) ['runid' => $runid, 'catscaleid' => $control['catscaleid'],
            'parentcatscaleid' => 0, 'contextid' => $control['contextid'], 'level' => 0, 'nodekey' => 'root',
            'generation' => 1, 'name' => 'root', 'timecreated' => time(), 'timemodified' => time()]);
        $blocked = population_guard::state($runid);
        $this->assertTrue($blocked['blocked']);
        $this->assertGreaterThanOrEqual($threshold, $blocked['admissible']);
        $DB->delete_records_select('local_catquiz_personparams', 'userid > ?', [9000000]);
        $open = population_guard::state($runid);
        $this->assertFalse($open['blocked'], 'a few admissible, nothing played: free');
        // As many sittings being played as are left to the threshold: any of them may end uncollected — held back.
        $rows = [];
        for ($i = 0; $i < $threshold - $open['admissible']; $i++) {
            $rows[] = (object) ['runid' => $runid, 'personid' => 0,
                'status' => \local_catquizlab\local\attempt_scheduler::STATUS_RUNNING, 'tries' => 1,
                'timecreated' => time(), 'timemodified' => time()];
        }
        $DB->insert_records('local_catquizlab_attempt', $rows);
        $this->assertTrue(population_guard::state($runid)['blocked']);
        $this->assertFalse(\local_catquizlab\external\job_claim::execute('w-pop')['hasjob']);
    }
}
