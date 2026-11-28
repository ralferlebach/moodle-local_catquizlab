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

use local_catquizlab\local\attempt_history;
use local_catquizlab\local\attempt_scheduler;

/**
 * A sitting's state in words (#175 AK05, #179): on hold apart from failed for good, a technical end apart from a
 * regular one — in English and German.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\attempt_scheduler::state_in_words
 */
final class sitting_state_words_test extends \advanced_testcase {
    /**
     * Held, failed for good, decided, technical, incomplete, regular: each its own words.
     *
     * @return void
     */
    public function test_each_state_has_its_own_words(): void {
        global $DB;
        $this->resetAfterTest();
        /** @var \local_catquizlab_generator $lab */
        $lab = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $lab->create_run()->id;
        $sitting = static function (int $status, array $outcomes, array $more = []) use ($DB, $runid): \stdClass {
            $id = (int) $DB->insert_record('local_catquizlab_attempt', (object) (['runid' => $runid, 'personid' => 0,
                'status' => $status, 'tries' => 3, 'timecreated' => time(), 'timemodified' => time()] + $more));
            foreach ($outcomes as $outcome) {
                attempt_history::record($id, $outcome, ['detail' => 'x']);
            }
            return $DB->get_record('local_catquizlab_attempt', ['id' => $id]);
        };
        $words = static fn(\stdClass $s): string => attempt_scheduler::state_in_words(
            $s,
            attempt_history::of_attempt((int) $s->id)
        )['code'];

        $held = $sitting(attempt_scheduler::STATUS_FAILED, [attempt_history::STARTED, attempt_history::FAILED,
            attempt_history::HELD]);
        $exhausted = $sitting(attempt_scheduler::STATUS_FAILED, [attempt_history::FAILED, attempt_history::REQUEUED,
            attempt_history::FAILED, attempt_history::REQUEUED, attempt_history::FAILED]);
        $released = $sitting(attempt_scheduler::STATUS_FAILED, [attempt_history::HELD, attempt_history::REQUEUED,
            attempt_history::FAILED]);
        $identity = $sitting(attempt_scheduler::STATUS_FAILED, [], ['lasterror' => '[identity:ambiguous-engine-attempt]']);
        $decided = $sitting(attempt_scheduler::STATUS_FAILED, [attempt_history::HELD, attempt_history::DECIDED]);
        $technical = $sitting(
            attempt_scheduler::STATUS_COLLECTED,
            [],
            ['completionjson' => json_encode(['class' => 'technical'])]
        );
        $incomplete = $sitting(
            attempt_scheduler::STATUS_COLLECTED,
            [],
            ['completionjson' => json_encode(['class' => 'incomplete'])]
        );
        $regular = $sitting(
            attempt_scheduler::STATUS_COLLECTED,
            [],
            ['completionjson' => json_encode(['class' => 'regular'])]
        );
        $legacy = $sitting(attempt_scheduler::STATUS_COLLECTED, []);
        // Waiting for its trace: never said "collected, …", also when its completion could not be judged yet (#138).
        $waiting = $sitting(
            attempt_scheduler::STATUS_TRACEPENDING,
            [],
            ['completionjson' => json_encode(['class' => 'unknown'])]
        );

        $this->assertSame(
            ['held', 'exhausted', 'exhausted', 'held', 'decided', 'technical', 'incomplete',
            'status' . attempt_scheduler::STATUS_COLLECTED, 'status' . attempt_scheduler::STATUS_COLLECTED,
            'status' . attempt_scheduler::STATUS_TRACEPENDING],
            array_map(
                $words,
                [$held, $exhausted, $released, $identity, $decided, $technical, $incomplete, $regular, $legacy, $waiting]
            )
        );

        // In both languages (read from the plugin's own files: a site need not have the German pack installed), and
        // never the same words for held and failed for good.
        global $CFG;
        foreach (['en', 'de'] as $lang) {
            $string = [];
            include($CFG->dirroot . '/local/catquizlab/lang/' . $lang . '/local_catquizlab.php');
            $words = [];
            foreach (['held', 'exhausted', 'decided', 'technical', 'incomplete', 'unknown'] as $code) {
                $words[$code] = (string) ($string['attemptstate:' . $code] ?? '');
                $this->assertNotSame('', trim($words[$code]), "{$lang} {$code}");
            }
            $this->assertCount(6, array_unique($words), $lang);
            $this->assertStringContainsString('{$a}', $words['exhausted']);
            if ($lang === 'de') {
                $this->assertStringStartsWith('Angehalten', $words['held']);
            }
        }
    }
}
