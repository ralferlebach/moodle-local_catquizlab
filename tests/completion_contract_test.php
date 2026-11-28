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

use local_catquizlab\local\completion_contract;
use local_catquizlab\local\engine_validity;
use local_catquizlab\local\reason_catalog;
use local_catquizlab\local\result_validity;

/**
 * Technically finished and a regular result, told apart (P0 "[ENGINE-LIFECYCLE]", re-audit 27.11.2026, DoD02).
 *
 * The verdict alone: timeout, a classical form broken off or played in full, adaptive ends of the design, unknown and
 * inconsistent ends — and what the figures make of it (result_validity). The real engine's time limit is in
 * host_timeout_test.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\completion_contract
 * @covers     \local_catquizlab\local\reason_catalog
 * @covers     \local_catquizlab\local\result_validity
 */
final class completion_contract_test extends \advanced_testcase {
    /**
     * A classical form of $n items, question ids 1001…
     *
     * @param int $n Its length.
     * @return int[]
     */
    protected function form(int $n): array {
        return range(1001, 1000 + $n);
    }

    /**
     * A trace of these items, all answered.
     *
     * @param int[] $items The items in order.
     * @param string $stop The stop text.
     * @return array
     */
    protected function trace(array $items, string $stop = ''): array {
        $responses = [];
        foreach ($items as $i => $q) {
            $responses[$q] = $i % 2 ? 1.0 : 0.0;
        }

        return ['items' => $items, 'responses' => $responses, 'nitems' => count($items), 'finaltheta' => 0.3,
            'finalse' => 0.25, 'stopreason' => $stop];
    }

    /**
     * The host as the collector gives it.
     *
     * @param string $stopcode The host's stop code ('' for none).
     * @param int|null $status The engine's status number.
     * @param string $state attemptstate.
     * @return array
     */
    protected function host(string $stopcode, ?int $status, string $state = 'complete'): array {
        return ['state' => $state, 'stopcode' => $stopcode, 'enginestatus' => $status, 'answered' => 0, 'slots' => 0,
            'engineattemptid' => 7, 'qubaid' => 8, 'timecreated' => 1, 'timefinished' => 2];
    }

    /**
     * Run 103: CLASSIC of 2500, closed by the engine's clean-up after 1373 answers — technical, kept, never a result.
     *
     * @return void
     */
    public function test_a_classical_form_closed_by_the_time_limit_is_technical(): void {
        $design = ['strategy' => 'classic', 'form' => $this->form(2500)];
        $played = array_slice($this->form(2500), 0, 1373);
        $verdict = completion_contract::judge($this->host('timelimit', 9), $this->trace(
            $played,
            'Versuch wurde wegen Zeitüberschreitung automatisch beendet'
        ), [], $design, 'collector');
        $this->assertSame(completion_contract::TECHNICAL, $verdict['class']);
        $this->assertSame(completion_contract::HOST_TIMEOUT, $verdict['technical']);
        $this->assertSame(completion_contract::RECOVERY_KEEP, $verdict['recovery']);
        $this->assertSame('timeout', $verdict['end']);
        $this->assertSame(['planned' => 2500, 'played' => 1373, 'unique' => 1373, 'foreign' => 0, 'repeated' => 0,
            'missing' => 1127, 'unanswered' => 0], $verdict['form']);
        $this->assertSame('intact', $verdict['trace'], 'the diagnosis is kept whole');
        $this->assertSame(['rules' => 1, 'source' => 'collector'], array_intersect_key($verdict, ['rules' => 1,
            'source' => 1]));
        $this->assertFalse(completion_contract::regular($verdict));

        // The same by the engine's status alone (no host code), and by the host's code alone (no status).
        $this->assertSame(completion_contract::HOST_TIMEOUT, completion_contract::judge(
            $this->host('', 9),
            $this->trace($played),
            [],
            $design,
            'x'
        )['technical']);
        $this->assertSame(completion_contract::HOST_TIMEOUT, completion_contract::judge(
            $this->host('timelimit', null),
            $this->trace($played),
            [],
            $design,
            'x'
        )['technical']);
    }

    /**
     * The time limit after every item of the form was answered: still technical — the engine would have ended it
     * itself (explicit policy, no "complete is complete").
     *
     * @return void
     */
    public function test_the_time_limit_after_the_whole_form_is_technical_too(): void {
        $verdict = completion_contract::judge(
            $this->host('timelimit', 9),
            $this->trace($this->form(30)),
            [],
            ['strategy' => 'classic', 'form' => $this->form(30)],
            'x'
        );
        $this->assertSame(completion_contract::TECHNICAL, $verdict['class']);
        $this->assertSame(0, $verdict['form']['missing']);
    }

    /**
     * A classical form is regular only played in full, each item of it once and answered, and ended by the engine.
     *
     * @return void
     */
    public function test_a_classical_form_is_regular_only_in_full(): void {
        $design = ['strategy' => 'classic', 'form' => $this->form(30)];
        $full = completion_contract::judge($this->host('noeligiblequestions', 1), $this->trace(
            $this->form(30),
            'You ran out of questions'
        ), [], $design, 'x');
        $this->assertSame(completion_contract::REGULAR, $full['class'], $full['why']);
        $this->assertSame(completion_contract::RECOVERY_NONE, $full['recovery']);
        $this->assertSame('fixed_form_complete', $full['end']);

        // Broken off: "maximum reached" after 20 of 30 is no end of a fixed form (it used to read as max_items_reached).
        $short = completion_contract::judge($this->host('maximumquestions', 4), $this->trace(array_slice(
            $this->form(30),
            0,
            20
        )), [], $design, 'x');
        $this->assertSame(completion_contract::INCOMPLETE, $short['class']);
        $this->assertSame('fixed_form_incomplete', $short['end']);
        $this->assertSame('fixed_form_incomplete', reason_catalog::outcome('', ['strategy' => 'classic', 'enginestatus' => 4,
            'played' => 20, 'poolsize' => 30]));
        $this->assertSame('max_items_reached', reason_catalog::outcome('', ['strategy' => 'fastest', 'enginestatus' => 4,
            'played' => 20]), 'an adaptive strategy keeps its maximum');

        // An item not of the form, one played twice, one left without an answer: inconsistent, not decided.
        $foreign = $this->form(29);
        $foreign[] = 99999;
        $this->assertSame(completion_contract::UNKNOWN, completion_contract::judge(
            $this->host('noeligiblequestions', 1),
            $this->trace($foreign),
            [],
            $design,
            'x'
        )['class']);
        $twice = $this->form(30);
        $twice[29] = 1001;
        $verdict = completion_contract::judge($this->host('noeligiblequestions', 1), $this->trace($twice), [], $design, 'x');
        $this->assertSame(completion_contract::UNKNOWN, $verdict['class']);
        $this->assertSame(1, $verdict['form']['repeated']);
        $unanswered = $this->trace($this->form(30));
        $unanswered['responses'][1030] = null;
        $this->assertSame(1, completion_contract::judge(
            $this->host('noeligiblequestions', 1),
            $unanswered,
            [],
            $design,
            'x'
        )['form']['unanswered']);

        // Without the planned form, or without a trace, nothing is regular.
        $this->assertSame(completion_contract::UNKNOWN, completion_contract::judge(
            $this->host('noeligiblequestions', 1),
            $this->trace($this->form(30)),
            [],
            ['strategy' => 'classic', 'form' => []],
            'x'
        )['class']);
        $none = completion_contract::judge($this->host('noeligiblequestions', 1), null, [], $design, 'x');
        $this->assertSame([completion_contract::UNKNOWN, 'missing'], [$none['class'], $none['trace']]);
    }

    /**
     * Adaptive ends of the design stay regular (AK06); ends before the minimum are incomplete; an unknown end is not
     * decided.
     *
     * @return void
     */
    public function test_adaptive_stop_rules_stay_in_force(): void {
        $design = ['strategy' => 'fastest', 'minitems' => 5, 'maxitems' => 30, 'semin' => 0.3];
        $items = range(1, 12);
        foreach (
            [['targetprecision', 11, 'target_se_reached'], ['scalescomplete', 13, 'subscale_rule_satisfied'],
                ['maximumquestions', 4, 'max_items_reached'],
                ['noeligiblequestions', 1, 'no_eligible_item_remaining']] as [$code, $status, $end]
        ) {
            $trace = $this->trace($code === 'maximumquestions' ? range(1, 30) : $items);
            $trace['finalse'] = 0.25;
            $verdict = completion_contract::judge($this->host($code, $status), $trace, [], $design, 'x');
            $this->assertSame(completion_contract::REGULAR, $verdict['class'], $code . ': ' . $verdict['why']);
            // Running out with the SE at its bound reads as the precision reached (reason_catalog): regular either way.
            $this->assertContains($verdict['end'], [$end, 'pool_exhausted', 'target_se_reached'], $code);
        }
        $early = completion_contract::judge($this->host('noeligiblebeforeminimum', 10), $this->trace([1, 2]), [], $design, 'x');
        $this->assertSame(completion_contract::INCOMPLETE, $early['class']);

        // No code, no text: not decided. An engine error, a teacher, a restart: technical.
        $this->assertSame(completion_contract::UNKNOWN, completion_contract::judge(
            $this->host('', null),
            $this->trace($items),
            [],
            $design,
            'x'
        )['class']);
        foreach (
            [['error', null, completion_contract::ENGINE_ERROR], ['', 3, completion_contract::ENGINE_ERROR],
                ['manual', null, completion_contract::CLOSED_BY_SOMEBODY],
                ['restarted', null, completion_contract::CLOSED_BY_SOMEBODY]] as [$code, $status, $technical]
        ) {
            $verdict = completion_contract::judge($this->host($code, $status), $this->trace($items), [], $design, 'x');
            $this->assertSame([completion_contract::TECHNICAL, $technical], [$verdict['class'], $verdict['technical']]);
        }
        // Still open on the host: in progress, may go on; a state nobody knows: not decided.
        $this->assertSame(completion_contract::RECOVERY_CONTINUE, completion_contract::judge($this->host(
            '',
            null,
            'inprogress'
        ), null, [], $design, 'x')['recovery']);
        $this->assertSame(completion_contract::UNKNOWN, completion_contract::judge(
            $this->host('', null, ''),
            null,
            [],
            $design,
            'x'
        )['class']);
        // A trace with defects is not decided, whatever the end.
        $this->assertSame('defective', completion_contract::judge(
            $this->host('targetprecision', 11),
            $this->trace($items),
            ['noengineresult'],
            $design,
            'x'
        )['trace']);
    }

    /**
     * The figures take regular results only: a technical, incomplete or undecided verdict makes a sitting invalid (or
     * not judged), a regular one leaves the rules as they were; a timeout is never a result, also without a verdict.
     *
     * @return void
     */
    public function test_the_figures_take_regular_results_only(): void {
        $this->resetAfterTest();
        $facts = ['strategy' => 'fastest', 'minitems' => 5, 'maxitems' => 30, 'played' => 12, 'finalse' => 0.25];
        $trace = $this->trace(range(1, 12));
        $measure = null;
        $evaluate = static fn(array $t, array $f): array => result_validity::evaluate_attempt(
            $t,
            $f,
            $measure,
            [],
            engine_validity::RULE_UNIFORM
        );

        // A timeout of an adaptive sitting above its minimum: valid until 0.7.84, not a result now.
        $timeout = $evaluate($trace, $facts + ['enginestatus' => 9, 'hoststopcode' => 'timelimit']);
        $this->assertFalse($timeout['valid']);
        $this->assertContains('timeout', $timeout['reasons']);

        foreach (
            [[completion_contract::TECHNICAL, result_validity::COMPLETION_TECHNICAL, result_validity::INVALID],
                [completion_contract::INCOMPLETE, result_validity::COMPLETION_INCOMPLETE, result_validity::INVALID],
                [completion_contract::UNKNOWN, result_validity::COMPLETION_UNKNOWN, null]] as [$class, $reason, $state]
        ) {
            $v = $evaluate($trace + ['completion' => ['class' => $class]], $facts + ['enginestatus' => 13]);
            $this->assertFalse($v['valid'], $class);
            $this->assertContains($reason, $v['reasons'], $class);
            if ($state !== null) {
                $this->assertSame($state, $v['state'], $class);
            }
        }
        // Regular: nothing added by the verdict.
        $regular = $evaluate(
            $trace + ['completion' => ['class' => completion_contract::REGULAR]],
            $facts + ['enginestatus' => 13]
        );
        $this->assertNotContains(result_validity::COMPLETION_UNKNOWN, $regular['reasons']);
        $this->assertNotContains(result_validity::COMPLETION_TECHNICAL, $regular['reasons']);
        $this->assertNull(completion_contract::regular(null), 'a sitting collected before 0.7.85 has no verdict');
        foreach (
            [result_validity::COMPLETION_TECHNICAL, result_validity::COMPLETION_INCOMPLETE,
                result_validity::COMPLETION_UNKNOWN, 'timeout'] as $code
        ) {
            $this->assertNotSame($code, engine_validity::label($code), "$code is labelled");
        }
    }
}
