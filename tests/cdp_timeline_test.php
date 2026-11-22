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

use local_catquizlab\local\attempt_history;
use local_catquizlab\local\cdp_timeline;
use local_catquizlab\local\db_health;

/**
 * The life of an uncertain answer submission and its side (#110 AK-CLARA-01), and the database under a step.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\cdp_timeline
 * @covers     \local_catquizlab\local\db_health
 */
final class cdp_timeline_test extends \advanced_testcase {
    /** @var float The click, epoch ms. */
    private const POSTED = 1795352400000.0;

    /**
     * A request line of the POST.
     *
     * @param float $start Epoch ms.
     * @param int $duration ms.
     * @param int $status HTTP status.
     * @param int $execution The execution.
     * @return array
     */
    private static function line(float $start, int $duration, int $status = 303, int $execution = 1): array {
        return ['unixms' => $start, 'durationms' => $duration, 'status' => $status, 'connection' => 'normal',
            'method' => 'POST', 'path' => '/mod/adaptivequiz/attempt.php', 'execution' => $execution, 'attemptid' => 7];
    }

    /**
     * Each cause from the timing alone, and unattributed where the worker's times are missing.
     */
    public function test_the_side_is_decided_by_the_timing(): void {
        $p = self::POSTED;
        // CLARA, 15.11.2026: the selector a minute long, the browser gave up at 60 s while PHP still ran.
        $this->assertSame(cdp_timeline::SERVER_SLOW, cdp_timeline::classify(
            'cdp_timeout',
            60000,
            $p,
            self::line($p + 20, 64000)
        ));
        // The server answered in 300 ms; the browser waited 60 s for what it never got (the slowresponse fault).
        $this->assertSame(cdp_timeline::NOT_DELIVERED, cdp_timeline::classify(
            'cdp_timeout',
            60000,
            $p,
            self::line($p + 20, 300)
        ));
        // Answered, then the connection reset at the browser (lostresponse): PHP's shutdown a little after.
        $this->assertSame(cdp_timeline::NOT_DELIVERED, cdp_timeline::classify(
            'connect_failed',
            310,
            $p,
            self::line($p + 20, 700)
        ));
        // Never reached PHP (lostrequest).
        $this->assertSame(cdp_timeline::NOT_RECEIVED, cdp_timeline::classify('connect_failed', 15, $p, null));
        // An error the server sent.
        $this->assertSame(cdp_timeline::SERVER_ERROR, cdp_timeline::classify(
            'http_5xx',
            200,
            $p,
            self::line($p + 20, 150, 500)
        ));
        $this->assertSame(cdp_timeline::SERVER_ERROR, cdp_timeline::classify(
            'cdp_timeout',
            60000,
            $p,
            self::line($p + 20, 150, 503)
        ));
        // Not enough recorded: said, not guessed.
        $this->assertSame(cdp_timeline::UNATTRIBUTED, cdp_timeline::classify('cdp_timeout', null, $p, null));
        $this->assertSame(cdp_timeline::UNATTRIBUTED, cdp_timeline::classify('cdp_timeout', 60000, null, null));
        $this->assertSame(cdp_timeline::UNATTRIBUTED, cdp_timeline::classify('http_404', 100, $p, null));
        foreach (cdp_timeline::SIDES as $cause => $side) {
            $this->assertContains($side, ['client', 'server', 'unknown'], $cause);
        }
    }

    /**
     * One event from the attempt history: the worker's times from the reconciliation (recovered) or from the
     * submission's text (failed), the request line of its execution matched, the rest of the sitting's lines not.
     */
    public function test_an_event_is_laid_out(): void {
        $this->resetAfterTest();
        $p = self::POSTED;
        $iso = static fn(float $ms): string => gmdate('Y-m-d\TH:i:s', (int) floor($ms / 1000))
            . sprintf('.%03dZ', (int) fmod($ms, 1000));
        $recovered = (object) ['id' => 1, 'attemptid' => 7, 'runid' => 3, 'tryno' => 1, 'outcome' => attempt_history::RECOVERED,
            'workerid' => 'w1', 'engineattemptid' => 0, 'correlationid' => 'c-1', 'reasoncode' => 'submit_cdp_timeout',
            'detail' => '[submit:cdp_timeout] reconciled', 'timecreated' => (int) ($p / 1000) + 61,
            'diagnosis' => json_encode(['reconciliation' => ['class' => 'cdp_timeout', 'verdict' => 'stored',
                'postedat' => $iso($p), 'waitedms' => 60000, 'reconciledat' => $iso($p + 60100),
                'server' => ['state' => 'inprogress', 'questionsattempted' => 6, 'slots' => 7]]])];
        $lines = [
            self::line($p - 30000, 200),
            // The answer before, half a second earlier: not this one.
            self::line($p - 500, 200),
            self::line($p + 25, 64000, 303, 2),
            self::line($p + 25, 64000),
        ];
        $event = cdp_timeline::event($recovered, $lines);
        $this->assertSame(cdp_timeline::SERVER_SLOW, $event['cause']);
        $this->assertSame('server', $event['side']);
        $this->assertSame($iso($p), $event['worker']['postedat']);
        $this->assertSame($iso($p + 60000), $event['worker']['gaveupat']);
        $this->assertSame('stored', $event['worker']['verdict']);
        $this->assertSame($iso($p + 25), $event['server']['startedat']);
        $this->assertSame(64000, $event['server']['durationms']);

        // Failed: the times from the submission's own text.
        $failed = (object) ((array) $recovered + []);
        $failed->outcome = attempt_history::FAILED;
        $failed->diagnosis = json_encode(['reconciliations' => []]);
        $failed->detail = '[submit:cdp_timeout] Submit: usage=4 slot=6 answered=5 chain=[] at ' . $iso($p)
            . ' in 60004 ms';
        $event = cdp_timeline::event($failed, [self::line($p + 25, 300)]);
        $this->assertSame(cdp_timeline::NOT_DELIVERED, $event['cause']);
        $this->assertSame(60004, $event['worker']['waitedms']);
        $this->assertSame('execution ended', $event['worker']['verdict']);

        // A worker before 0.7.78: nothing to time it by.
        $old = (object) ((array) $recovered + []);
        $old->diagnosis = json_encode(['reconciliation' => ['class' => 'cdp_timeout', 'verdict' => 'stored']]);
        $this->assertSame(cdp_timeline::UNATTRIBUTED, cdp_timeline::event($old, $lines)['cause']);
    }

    /**
     * The report reads the attempt history of a window and counts by cause and side.
     */
    public function test_the_report_counts(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $DB->insert_record('local_catquizlab_attemptlog', (object) ['attemptid' => 99, 'runid' => 1, 'tryno' => 1,
            'outcome' => attempt_history::RECOVERED, 'workerid' => 'w', 'engineattemptid' => 0,
            'reasoncode' => 'submit_connect_failed', 'detail' => 'x', 'timecreated' => $now,
            'diagnosis' => json_encode(['reconciliation' => ['postedat' => gmdate('Y-m-d\TH:i:s', $now) . '.000Z',
                'waitedms' => 12, 'verdict' => 'notstored']])]);
        $DB->insert_record('local_catquizlab_attemptlog', (object) ['attemptid' => 99, 'runid' => 1, 'tryno' => 1,
            'outcome' => attempt_history::COLLECTED, 'workerid' => 'w', 'engineattemptid' => 0,
            'reasoncode' => 'finished', 'detail' => '', 'timecreated' => $now]);
        $report = cdp_timeline::report($now - 10, $now + 10);
        $this->assertCount(1, $report['events']);
        $this->assertSame(1, $report['counts'][cdp_timeline::NOT_RECEIVED]);
        $this->assertSame(['client' => 1, 'server' => 0, 'unknown' => 0], $report['sides']);
        $this->assertSame(0, $report['unattributed']);
    }

    /**
     * The database: limits and counters read, a step's growth, the database layer's exceptions by class.
     */
    public function test_the_database_under_a_step(): void {
        $this->resetAfterTest();
        $before = db_health::snapshot();
        $this->assertNotSame([], $before['limits']);
        $this->assertNotSame([], $before['counters']);
        $this->assertGreaterThan(0, (int) ($before['limits']['max_connections'] ?? 0));
        $lines = [
            ['exception' => ['class' => 'dml_write_exception']],
            ['exception' => ['class' => 'dml_write_exception']],
            ['exception' => ['class' => 'core\\exception\\moodle_exception']],
            ['exception' => ['class' => 'dml_read_exception']],
            ['exception' => null],
        ];
        $step = db_health::step($before, db_health::snapshot(), $lines);
        $this->assertTrue($step['readable']);
        $this->assertSame(['dml_read_exception' => 1, 'dml_write_exception' => 2], $step['dmlerrors']);
        $this->assertSame(2, $step['failedwrites']);
        $this->assertSame(0, (int) $step['deadlocks']);
        // Counters that cannot be read: not readable, said so.
        $this->assertFalse(db_health::step($before, ['counters' => []], [])['readable']);
    }
}
