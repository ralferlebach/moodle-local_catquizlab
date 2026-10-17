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
use local_catquizlab\local\result_validity;
use local_catquizlab\local\results_export;
use local_catquizlab\local\results_query;

/**
 * Valid, invalid and unknown kept apart in counts, filters and exports (re-audit 16.10.2026, AK 7).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\results_query
 * @covers     \local_catquizlab\local\results_export
 * @covers     \local_catquizlab\local\engine_validity
 */
final class validity_unknown_test extends \advanced_testcase {
    /**
     * A run of six collected sittings that the engine never saw (no engine attempt).
     *
     * @return int The run id.
     */
    protected function run_without_engine(): int {
        global $DB;

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        for ($i = 0; $i < 6; $i++) {
            $person = $generator->create_person(['runid' => $run->id, 'abilityglobal' => -1 + 0.4 * $i]);
            $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid' => $run->id, 'personid' => $person->id, 'status' => attempt_scheduler::STATUS_COLLECTED,
                'tries' => 1, 'engineattemptid' => 0, 'timecreated' => time(), 'timemodified' => time(),
                'tracejson' => json_encode(['finaltheta' => 0.1 * $i, 'finalse' => 0.4, 'items' => [1, 2, 3, 4],
                    'responses' => [1 => 1.0, 2 => 0.0, 3 => 1.0, 4 => 0.0], 'nitems' => 4, 'stopreason' => '']),
            ]);
        }

        return (int) $run->id;
    }

    /**
     * By the engine's rule, sittings without a verdict are unknown — not invalid — with their cause.
     *
     * @return void
     */
    public function test_counts_filters_and_export_keep_unknown_apart(): void {
        $this->resetAfterTest();
        $runid = $this->run_without_engine();
        $rule = ['runid' => $runid, 'validityrule' => engine_validity::RULE_ENGINE];

        $all = new results_query($rule + ['validity' => result_validity::ALL]);
        $observations = $all->observations();
        $counts = $all->validity_counts();
        $this->assertSame([6, 0, 0, 6], [$counts['total'], $counts['valid'], $counts['invalid'], $counts['unknown']]);
        $cause = engine_validity::available() ? engine_validity::UNKNOWN_NO_ENGINE_ATTEMPT
            : engine_validity::UNKNOWN_ENGINE_UNAVAILABLE;
        $this->assertSame([$cause => 6], $counts['unknownreasons']);
        $this->assertSame([], $counts['invalidreasons'], 'no invalid reason is counted for what was not judged');
        $this->assertSame([result_validity::UNKNOWN], array_values(array_unique(array_column($observations, 'validitystate'))));

        // The filter "invalid only" shows none of them; "no verdict only" all of them.
        $this->assertCount(0, (new results_query($rule + ['validity' => result_validity::INVALID]))->observations());
        $this->assertCount(6, (new results_query($rule + ['validity' => result_validity::UNKNOWN]))->observations());
        $this->assertCount(0, (new results_query($rule + ['validity' => result_validity::VALID]))->observations());

        // The export says it in three words, with the cause.
        $data = results_export::dataset(
            new results_query($rule + ['validity' => result_validity::ALL]),
            results_export::LEVEL_ATTEMPT
        );
        $this->assertContains('validitystate', $data['columns']);
        $row = reset($data['rows']);
        $this->assertSame(
            [result_validity::UNKNOWN, result_validity::UNKNOWN, $cause, '', 0],
            [$row['validitystate'], $row['engineverdict'], $row['engineunknownreason'], $row['enginevalid'], $row['valid']]
        );
    }

    /**
     * The causes a missing verdict is traced to, in order.
     *
     * @return void
     */
    public function test_why_the_engine_had_nothing(): void {
        global $DB;
        $this->resetAfterTest();

        if (!engine_validity::available()) {
            $this->assertSame(engine_validity::UNKNOWN_ENGINE_UNAVAILABLE, engine_validity::unmeasured_reason(5));
            return;
        }
        $this->assertSame(engine_validity::UNKNOWN_NO_ENGINE_ATTEMPT, engine_validity::unmeasured_reason(0));
        $this->assertSame(engine_validity::UNKNOWN_NO_ENGINE_ATTEMPT, engine_validity::unmeasured_reason(987654));

        $now = time();
        $aqid = (int) $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => 1, 'userid' => 2, 'uniqueid' => 0, 'attemptstate' => 'complete', 'attemptstopcriteria' => '',
            'questionsattempted' => 3, 'difficultysum' => 0, 'standarderror' => 1, 'measure' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $this->assertSame(engine_validity::UNKNOWN_NO_CAT_ATTEMPT, engine_validity::unmeasured_reason($aqid));

        $DB->insert_record('local_catquiz_attempts', (object) [
            'userid' => 2, 'attemptid' => $aqid, 'component' => 'mod_adaptivequiz', 'contextid' => 1, 'status' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $this->assertSame(engine_validity::UNKNOWN_NO_PROGRESS, engine_validity::unmeasured_reason($aqid));

        // The cause a measure carries wins over the one recorded; a verdict has none.
        $this->assertSame(engine_validity::UNKNOWN_VALIDATOR_EXCEPTION, engine_validity::unknown_reason(
            ['engine' => ['valid' => null, 'unknown' => engine_validity::UNKNOWN_VALIDATOR_EXCEPTION]],
            engine_validity::UNKNOWN_NO_PROGRESS
        ));
        $this->assertNull(engine_validity::unknown_reason(['engine' => ['valid' => false]]));
        $this->assertSame(engine_validity::UNKNOWN_NOT_RECORDED, engine_validity::unknown_reason(null, 'something else'));
    }
}
