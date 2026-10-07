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

use local_catquizlab\local\engine_validity;

/**
 * Validity by the engine's definitions, measured with the engine's functions (#112).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\engine_validity
 */
final class engine_validity_test extends \advanced_testcase {
    /** @var array Thresholds as a test's settings give them: 10 items per test, 3 per scale, SE up to 0.5. */
    protected const THRESHOLDS = ['nmintest' => 10, 'nminscale' => 3, 'semax' => 0.5, 'rootscaleid' => 1];

    /**
     * Every rule of the engine, one by one.
     *
     * @return void
     */
    public function test_the_rules(): void {
        $judge = static fn(array $scale, bool $root = false): array =>
            engine_validity::judge_scale($scale, self::THRESHOLDS, $root);

        $this->assertSame([], $judge(['n' => 4, 'fraction' => 0.5, 'se' => 0.4]));
        $this->assertSame([engine_validity::SE_MAX], $judge(['n' => 4, 'fraction' => 0.5, 'se' => 0.6]));
        $this->assertSame([engine_validity::N_MIN], $judge(['n' => 2, 'fraction' => 0.5, 'se' => 0.4]));
        $this->assertSame([engine_validity::FRACTION], $judge(['n' => 4, 'fraction' => 1.0, 'se' => 0.4]));
        $this->assertSame([engine_validity::FRACTION], $judge(['n' => 4, 'fraction' => 0.0, 'se' => 0.4]));
        $this->assertSame([engine_validity::SE_MISSING], $judge(['n' => 4, 'fraction' => 0.5, 'se' => null]));
        $this->assertSame([engine_validity::NOT_MEASURED], engine_validity::judge_scale(null, self::THRESHOLDS));
        // Several at once, each named.
        $this->assertSame(
            [engine_validity::N_MIN, engine_validity::FRACTION, engine_validity::SE_MAX],
            $judge(['n' => 2, 'fraction' => 1.0, 'se' => 0.9])
        );
        // The test as a whole: the test's minimum, not the scale's.
        $this->assertSame([engine_validity::N_MIN], $judge(['n' => 8, 'fraction' => 0.5, 'se' => 0.3], true));
        // A threshold the test does not set is not checked.
        $this->assertSame([], engine_validity::judge_scale(
            ['n' => 1, 'fraction' => 0.5, 'se' => 3.0],
            ['nmintest' => 0, 'nminscale' => 0, 'semax' => null, 'rootscaleid' => 1]
        ));
    }

    /**
     * Measured with the engine's own progress and judged two ways — and where they differ.
     *
     * @return void
     */
    public function test_measured_by_the_engine_and_judged_both_ways(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        if (!engine_validity::available()) {
            $this->markTestSkipped('Needs local_catquiz.');
        }

        // Five truefalse questions: three on scale 5, two on scale 6, both under the root 1.
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $questions = [];
        for ($i = 0; $i < 5; $i++) {
            $questions[] = $generator->create_question('truefalse', null, ['category' => $category->id]);
        }
        $quba = \question_engine::make_questions_usage_by_activity('mod_adaptivequiz', \context_system::instance());
        $quba->set_preferred_behaviour('deferredfeedback');
        foreach ($questions as $question) {
            $quba->add_question(\question_bank::load_question($question->id));
        }
        $quba->start_all_questions();
        $quba->finish_all_questions();
        \question_engine::save_questions_usage_by_activity($quba);
        $attemptid = (int) $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => 55001, 'userid' => 2, 'uniqueid' => $quba->get_id(), 'attemptstate' => 'complete',
            'attemptstopcriteria' => '', 'questionsattempted' => 5, 'difficultysum' => 0,
            'standarderror' => 1, 'measure' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);

        // The progress as the engine keeps it: a question counted for its scale and the root.
        // Scale 5 all correct; scale 6 one right, one wrong.
        $fractions = [1.0, 1.0, 1.0, 1.0, 0.0];
        $scales = [5, 5, 5, 6, 6];
        $progress = \local_catquiz\teststrategy\progress::load($attemptid, 'mod_adaptivequiz', 9, (object) []);
        $played = [];
        $responses = [];
        $byscale = [1 => [], 5 => [], 6 => []];
        foreach ($questions as $i => $question) {
            $q = (object) ['id' => (int) $question->id, 'catscaleid' => $scales[$i], 'is_pilot' => false,
                'fisherinformation' => []];
            $played[$q->id] = $q;
            $responses[$q->id] = ['questionid' => $q->id, 'fraction' => $fractions[$i]];
            $byscale[$scales[$i]][] = $q;
            $byscale[1][] = $q;
        }
        foreach (
            ['responses' => $responses, 'playedquestions' => $played, 'lastquestion' => end($played),
                'playedquestionsbyscale' => $byscale] as $name => $value
        ) {
            $property = new \ReflectionProperty($progress, $name);
            $property->setAccessible(true);
            $property->setValue($progress, $value);
        }
        $progress->save();
        $DB->set_field('local_catquiz_attempts', 'contextid', 9, ['attemptid' => $attemptid]);
        // The engine's stored result: no rule flagged — allsubs never checks the response pattern.
        $DB->set_field('local_catquiz_attempts', 'json', json_encode([
            'personabilities_abilities' => [
                1 => ['value' => 0.8, 'toreport' => true], 5 => ['value' => 2.5], 6 => ['value' => 0.1],
            ],
            'se' => [1 => 0.35, 5 => 0.45, 6 => 0.9],
            'primaryscale' => ['id' => 1],
        ]), ['attemptid' => $attemptid]);

        $measure = engine_validity::measure($attemptid);
        $this->assertNotNull($measure);
        // The engine's N and share of points: per scale, and the root counting everything.
        $this->assertSame([5, 3, 2], [$measure['scales'][1]['n'], $measure['scales'][5]['n'], $measure['scales'][6]['n']]);
        $this->assertEqualsWithDelta(1.0, $measure['scales'][5]['fraction'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $measure['scales'][6]['fraction'], 1e-9);
        $this->assertEqualsWithDelta(0.8, $measure['fraction'], 1e-9);
        $this->assertSame(0.9, $measure['scales'][6]['se']);

        $thresholds = ['nmintest' => 5, 'nminscale' => 2, 'semax' => 0.5, 'rootscaleid' => 1];
        // Uniform: scale 5 is all correct, scale 6's SE is above the bound; the test as a whole is valid.
        $this->assertSame([engine_validity::FRACTION], engine_validity::judge_scale($measure['scales'][5], $thresholds));
        $this->assertSame([engine_validity::SE_MAX], engine_validity::judge_scale($measure['scales'][6], $thresholds));
        $this->assertSame([], engine_validity::judge_attempt($measure, $thresholds));
        // The engine's verdict, word for word: valid — it flagged nothing.
        $this->assertTrue(engine_validity::engine_attempt($measure));
        $this->assertTrue($measure['engine']['scales'][5]['valid'], 'the engine does not check the pattern here');
    }

    /**
     * Where the engine has nothing to read, the same definitions are applied to the trace.
     *
     * @return void
     */
    public function test_the_trace_is_measured_the_same_way(): void {
        global $DB;
        $this->resetAfterTest();
        // As every evaluation does: run ids repeat between tests.
        engine_validity::begin();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        foreach ([[100, 0], [200, 100], [300, 100]] as [$scaleid, $parent]) {
            $DB->insert_record('local_catquizlab_scalemap', (object) ['runid' => $run->id, 'catscaleid' => $scaleid,
                'parentcatscaleid' => $parent, 'level' => $parent ? 2 : 0, 'generation' => 1, 'nodekey' => (string) $scaleid,
                'name' => 's' . $scaleid, 'timecreated' => time(), 'timemodified' => time()]);
        }
        foreach ([[11, 200], [12, 200], [13, 300]] as [$questionid, $scaleid]) {
            $DB->insert_record('local_catquizlab_item', (object) ['runid' => $run->id, 'questionid' => $questionid,
                'assignedcatscaleid' => $scaleid, 'truecatscaleid' => $scaleid, 'itemname' => 'q' . $questionid,
                'timecreated' => time()]);
        }

        $measure = engine_validity::measure_from_trace(['responses' => [11 => 1.0, 12 => 1.0, 13 => 0.0],
            'scalestandarderrors' => [200 => 0.4]], (int) $run->id);
        $this->assertSame([3, 2, 1], [$measure['scales'][100]['n'], $measure['scales'][200]['n'], $measure['scales'][300]['n']]);
        $this->assertEqualsWithDelta(1.0, $measure['scales'][200]['fraction'], 1e-9);
        $this->assertNull(engine_validity::engine_attempt($measure), 'no engine verdict without engine data');
        // The responses as the engine's progress keeps them are read the same way.
        $fromprogress = engine_validity::measure_from_trace(['progress' => ['responses' => [
            '11' => ['questionid' => '11', 'fraction' => '1.000'], '13' => ['questionid' => '13', 'fraction' => '0.000'],
        ]]], (int) $run->id);
        $this->assertSame([2, 0.5], [$fromprogress['n'], $fromprogress['fraction']]);
        // No responses at all: nothing to judge by — not valid.
        $this->assertNull(engine_validity::measure_from_trace([], (int) $run->id));
        $this->assertSame([engine_validity::NO_ENGINE_DATA], engine_validity::judge_attempt(null, self::THRESHOLDS));
    }
}
