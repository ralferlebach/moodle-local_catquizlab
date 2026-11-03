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

namespace local_catquizlab\local;

/**
 * Whether a sitting's engine attempt holds every question once and every answer once (#133, #134).
 *
 * After an uncertain submission, a lease that ran out or a run resumed after the circuit breaker, the sitting goes
 * on in the same engine attempt. What must hold then is read from Moodle's own question usage: as many questions
 * presented as answered, none presented twice, and each answered with the same number of steps — a question with
 * two answer steps where the others have one was answered twice.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sitting_integrity {
    /**
     * The check of one engine attempt.
     *
     * @param int $engineattemptid The adaptivequiz attempt.
     * @return array{ok: bool, found: bool, answered: int, presented: int, distinct: int, steps: int[], problems: string[]}
     */
    public static function check(int $engineattemptid): array {
        global $DB;

        $none = ['ok' => false, 'found' => false, 'answered' => 0, 'presented' => 0, 'distinct' => 0, 'steps' => [],
            'problems' => ['no-engine-attempt']];
        if ($engineattemptid <= 0 || !$DB->get_manager()->table_exists('adaptivequiz_attempt')) {
            return $none;
        }
        $attempt = $DB->get_record('adaptivequiz_attempt', ['id' => $engineattemptid], 'id, uniqueid, questionsattempted');
        if (!$attempt) {
            return $none;
        }
        $presented = (int) $attempt->uniqueid > 0
            ? $DB->get_records('question_attempts', ['questionusageid' => (int) $attempt->uniqueid], 'slot', 'id, questionid')
            : [];
        $steps = [];
        foreach ($presented as $qa) {
            $steps[] = $DB->count_records_select(
                'question_attempt_steps',
                'questionattemptid = ? AND sequencenumber > 0',
                [$qa->id]
            );
        }
        $distinct = count(array_unique(array_column($presented, 'questionid')));
        $answered = (int) $attempt->questionsattempted;
        $problems = [];
        if ($distinct !== count($presented)) {
            $problems[] = 'question-presented-twice';
        }
        if (count(array_unique($steps)) > 1) {
            $problems[] = 'answer-steps-differ';
        }
        if ($answered !== count($presented)) {
            $problems[] = 'answered-presented-differ';
        }

        return ['ok' => $problems === [], 'found' => true, 'answered' => $answered, 'presented' => count($presented),
            'distinct' => $distinct, 'steps' => $steps, 'problems' => $problems];
    }
}
