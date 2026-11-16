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

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\live_correlation;
use local_catquizlab\local\registry;

/**
 * An engine trace finds its lab sitting while the sitting is being played (#164 AK4).
 *
 * On the live installation the 60 latest traces all read "lab attempt NULL" while eleven workers played: the lab stores
 * the engine attempt only when it collects a sitting. The link is made from the trace's Moodle user instead, which is
 * the simulated person's own.
 *
 * @package    local_catquizlab
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\live_correlation
 */
final class live_correlation_test extends \advanced_testcase {
    /**
     * In play, collected, an earlier try, a real user: each said for what it is, with sitting, run, experiment and
     * worker; the same for the engine's own trace rows read back from its table; and in three queries for any number.
     *
     * @return void
     */
    public function test_traces_find_their_sitting_while_it_is_played(): void {
        global $DB;
        $this->resetAfterTest();
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run(['status' => registry::STATUS_RUNNING]);
        $u1 = (int) $this->getDataGenerator()->create_user()->id;
        $u2 = (int) $this->getDataGenerator()->create_user()->id;
        $real = (int) $this->getDataGenerator()->create_user()->id;
        $p1 = $generator->create_person(['runid' => $run->id, 'moodleuserid' => $u1]);
        $p2 = $generator->create_person(['runid' => $run->id, 'moodleuserid' => $u2]);
        $sitting = static fn(array $f): int => (int) $DB->insert_record('local_catquizlab_attempt', (object) ($f + [
            'runid' => $run->id, 'tries' => 1, 'nextruntime' => 0, 'timecreated' => time(), 'timemodified' => time()]));
        $playing = $sitting(['personid' => $p1->id, 'status' => attempt_scheduler::STATUS_RUNNING, 'leaseowner' => 'w-7',
            'leaseexpires' => time() + 600]);
        $collected = $sitting(['personid' => $p2->id, 'status' => attempt_scheduler::STATUS_COLLECTED,
            'engineattemptid' => 77]);

        $links = live_correlation::link([
            'a' => ['userid' => $u1, 'adaptiveattemptid' => 55],
            'b' => ['userid' => $u2, 'adaptiveattemptid' => 77],
            'c' => ['userid' => $u2, 'adaptiveattemptid' => 76],
            'd' => ['userid' => $real, 'adaptiveattemptid' => 90],
            'e' => ['userid' => 0, 'adaptiveattemptid' => 91],
        ]);
        $this->assertSame(
            [live_correlation::INPLAY, $playing, (int) $run->id, (int) $run->experimentid, 'w-7'],
            [$links['a']['how'], $links['a']['labattemptid'], $links['a']['runid'], $links['a']['experimentid'],
            $links['a']['workerid']]
        );
        $this->assertSame(
            [live_correlation::STORED, $collected, 77],
            [$links['b']['how'], $links['b']['labattemptid'], $links['b']['engineattemptid']]
        );
        $this->assertSame(
            [live_correlation::PERSON, $collected],
            [$links['c']['how'], $links['c']['labattemptid']],
            'an earlier engine attempt of the same person: its sitting, said as less sure'
        );
        $this->assertSame(live_correlation::NONE, $links['d']['how'], 'a real user is no sitting of the lab');
        $this->assertSame(0, $links['d']['labattemptid']);
        $this->assertSame(live_correlation::NONE, $links['e']['how']);

        // The engine's own rows, as trace_report.php reads them.
        $this->assertTrue($DB->get_manager()->table_exists('local_catquiz_trace'), 'the engine is part of every test setup');
        $id = (int) $DB->insert_record('local_catquiz_trace', (object) ['adaptiveattemptid' => 55, 'userid' => $u1,
            'kind' => 'next_item', 'questionnumber' => 3, 'requestms' => 64240, 'dbqueries' => 12,
            'spans' => json_encode([['name' => 'strategy:select_question', 'ms' => 61000]]), 'timecreated' => time()]);
        $rows = $DB->get_records('local_catquiz_trace', ['id' => $id], '', 'id, adaptiveattemptid, userid');
        $this->assertSame($playing, live_correlation::link($rows)[$id]['labattemptid']);

        // Three queries, whatever the number of traces.
        $many = [];
        for ($i = 0; $i < 50; $i++) {
            $many[] = ['userid' => $i % 2 ? $u1 : $u2, 'adaptiveattemptid' => 1000 + $i];
        }
        $before = $DB->perf_get_reads();
        live_correlation::link($many);
        $this->assertSame(3, $DB->perf_get_reads() - $before);
    }
}
