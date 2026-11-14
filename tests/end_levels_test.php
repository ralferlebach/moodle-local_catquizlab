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
use local_catquizlab\local\results_export;
use local_catquizlab\local\results_query;

/**
 * Three levels of how a sitting ended, kept apart (#134, audit 11.11.2026).
 *
 * A transport event on the way (a failed execution that was retried), an answer submission reconciled with the
 * server, and the terminal end the engine gave: before 0.7.59 the export's history code was whichever came last, so a
 * sitting that lost a request and then ran out of questions said "submit_connect_failed" for its end.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\results_query
 * @covers     \local_catquizlab\local\results_export
 */
final class end_levels_test extends \advanced_testcase {
    /**
     * A sitting with a retried execution, a reconciled submission and a collected end.
     *
     * @param int $runid The run.
     * @param bool $collected Whether it ends collected.
     * @return int The sitting's id.
     */
    protected function sitting(int $runid, bool $collected): int {
        global $DB;
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $person = $generator->create_person(['runid' => $runid, 'abilityglobal' => 0.1]);
        $id = (int) $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $runid, 'personid' => $person->id,
            'status' => $collected ? attempt_scheduler::STATUS_COLLECTED : attempt_scheduler::STATUS_FAILED,
            'tries' => 2, 'timecreated' => time(), 'timemodified' => time(),
            'tracejson' => $collected ? json_encode(['finaltheta' => 0.1, 'finalse' => 0.4, 'items' => [11, 12],
                'responses' => [11 => 1.0, 12 => 0.0], 'nitems' => 2, 'stopreason' => 'se', 'scaleabilities' => []])
                : null,
        ]);
        attempt_history::record($id, attempt_history::STARTED, ['tryno' => 1]);
        // A request that never reached the server: the execution fails and is retried.
        attempt_history::record($id, attempt_history::FAILED, ['tryno' => 1,
            'detail' => 'submit failed: connect ECONNREFUSED 127.0.0.1:8000']);
        attempt_history::record($id, attempt_history::REQUEUED, ['tryno' => 1]);
        attempt_history::record($id, attempt_history::STARTED, ['tryno' => 2]);
        if ($collected) {
            attempt_history::record($id, attempt_history::COLLECTED, ['tryno' => 2]);
            attempt_history::record_outcome_reason($id, 'se');
            // A response lost on the way, reconciled with the server, and the sitting went on - written after the
            // collected row, the last code in the history: what the CI run of 11.11.2026 took for the end.
            attempt_history::record_reconciliations($id, [['class' => 'connect_failed', 'verdict' => 'stored',
                'answered' => 5, 'usage' => 1, 'slot' => 5, 'server' => 'stored']], ['tryno' => 2]);
        }

        return $id;
    }

    /**
     * The terminal code is the collected end; transport and recovery are columns of their own.
     *
     * @return void
     */
    public function test_transport_recovery_and_terminal_end_are_kept_apart(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        results_export::reset_caches();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $finished = $this->sitting((int) $run->id, true);

        $transport = (string) $DB->get_field(
            'local_catquizlab_attemptlog',
            'reasoncode',
            ['attemptid' => $finished, 'outcome' => attempt_history::FAILED]
        );
        $recovered = (string) $DB->get_field(
            'local_catquizlab_attemptlog',
            'reasoncode',
            ['attemptid' => $finished, 'outcome' => attempt_history::RECOVERED]
        );
        $terminal = (string) $DB->get_field(
            'local_catquizlab_attemptlog',
            'reasoncode',
            ['attemptid' => $finished, 'outcome' => attempt_history::COLLECTED]
        );
        $this->assertNotSame('', $transport);
        $this->assertNotSame('', $recovered);
        $this->assertNotSame('', $terminal);
        $this->assertNotSame($transport, $terminal);
        $lastcode = (string) $DB->get_field_sql('SELECT reasoncode FROM {local_catquizlab_attemptlog}
            WHERE attemptid = ? AND reasoncode IS NOT NULL ORDER BY id DESC', [$finished], IGNORE_MULTIPLE);
        $this->assertSame($recovered, $lastcode, 'the reconciled submission is the last code in the history');

        $query = new results_query(['runid' => (int) $run->id, 'validity' => 'all']);
        $rows = iterator_to_array(results_export::iterate($query, results_export::LEVEL_ATTEMPT)['rows'], false);
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame($terminal, $row['terminalreasoncode']);
        $this->assertSame($transport, $row['transportevents']);
        $this->assertSame($recovered, $row['recoveredevents']);
        $this->assertSame(2, $row['executions']);

        // Appended after statisticalvalid: no existing column moves.
        $columns = results_export::ATTEMPT_COLUMNS;
        $at = array_search('statisticalvalid', $columns);
        $this->assertSame(
            ['statisticalvalid', 'terminalreasoncode', 'transportevents', 'recoveredevents', 'executions'],
            array_slice($columns, $at, 5)
        );
    }

    /**
     * A sitting never collected ends with its last failure, and the observation says so.
     *
     * @return void
     */
    public function test_a_sitting_never_collected_ends_with_its_failure(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        results_export::reset_caches();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $this->sitting((int) $run->id, true);
        $failed = $this->sitting((int) $run->id, false);

        $query = new results_query(['runid' => (int) $run->id, 'validity' => 'all']);
        $byid = [];
        foreach ($query->each_observation() as $observation) {
            $byid[(int) $observation['attemptid']] = $observation;
        }
        if (isset($byid[$failed])) {
            // Where the selection carries sittings without a trace at all, their end is their failure.
            $this->assertSame($byid[$failed]['transportevents'], $byid[$failed]['storedreasoncode']);
        }
        foreach ($byid as $id => $observation) {
            if ($id !== $failed) {
                $this->assertNotSame($observation['transportevents'], $observation['storedreasoncode']);
                $this->assertSame(2, $observation['executions']);
            }
        }
    }

    /**
     * In the order a worker's completion report has them: the end found first, the collected row written after.
     *
     * Kampagne B (audit 11.11.2026): every sitting collected through a worker's report had a collected row without a
     * code, because the code was set on a row that did not exist yet; terminalreasoncode was empty throughout.
     *
     * @return void
     */
    public function test_the_end_found_before_its_row_is_written_reaches_it(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        results_export::reset_caches();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $person = $generator->create_person(['runid' => $run->id, 'abilityglobal' => 0.1]);
        $id = (int) $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $run->id, 'personid' => $person->id, 'status' => attempt_scheduler::STATUS_COLLECTED,
            'tries' => 1, 'timecreated' => time(), 'timemodified' => time(),
            'tracejson' => json_encode(['finaltheta' => 0.1, 'finalse' => 0.4, 'items' => [11, 12],
                'responses' => [11 => 1.0, 12 => 0.0], 'nitems' => 2, 'stopreason' => 'se', 'scaleabilities' => []]),
        ]);
        attempt_history::record($id, attempt_history::STARTED, ['tryno' => 1]);
        // The collector, inside the completion report: the end is known now, its row is not written yet.
        $code = attempt_history::record_outcome_reason($id, 'se');
        $this->assertNotSame('', $code);
        // The completion report writes the collected row.
        attempt_history::record($id, attempt_history::COLLECTED, ['tryno' => 1]);

        $row = $DB->get_record('local_catquizlab_attemptlog', ['attemptid' => $id, 'outcome' => attempt_history::COLLECTED]);
        $this->assertSame($code, $row->reasoncode);
        $observations = iterator_to_array((new results_query(['runid' => (int) $run->id, 'validity' => 'all']))
            ->each_observation(), false);
        $this->assertSame($code, $observations[0]['storedreasoncode']);
        // Taken once: a later collected row of the sitting does not inherit it.
        $later = attempt_history::record($id, attempt_history::COLLECTED, ['tryno' => 2]);
        $this->assertNull($DB->get_field('local_catquizlab_attemptlog', 'reasoncode', ['id' => $later]));
    }
}
