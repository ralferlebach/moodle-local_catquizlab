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
use local_catquizlab\local\log_view;

/**
 * One chronology for worker, lifecycle and sittings, filterable by sitting and experiment (#90).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\log_view
 */
final class log_view_chronology_test extends \advanced_testcase {
    /**
     * Sittings in the chronology, and the filters that find them.
     *
     * @return void
     */
    public function test_sittings_in_one_chronology(): void {
        global $DB;
        $this->resetAfterTest();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $other = $generator->create_run();

        $sitting = static fn(\stdClass $r): int => (int) $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $r->id, 'personid' => 0, 'status' => 0, 'tries' => 1, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $mine = $sitting($run);
        $theirs = $sitting($other);
        attempt_history::record($mine, attempt_history::STARTED, ['tryno' => 1, 'workerid' => 'w-1']);
        attempt_history::record($mine, attempt_history::FAILED, [
            'tryno' => 1, 'workerid' => 'w-1',
            'detail' => 'Execution context was destroyed, most likely because of a navigation. sesskey=Zz99Yy88',
        ]);
        attempt_history::record($theirs, attempt_history::STARTED, ['tryno' => 1]);

        // A debug line of the worker, about the same sitting, with the experiment recorded.
        $DB->insert_record('local_catquizlab_debug', (object) [
            'channel' => 'worker', 'action' => 'job_complete', 'outcome' => 'error', 'runid' => $run->id,
            'attemptid' => $mine, 'experimentid' => $run->experimentid, 'workerid' => 'w-1',
            'timecreated' => time(), 'timecreatedms' => (int) round(microtime(true) * 1000),
        ]);

        $lines = log_view::lines(['since' => 0, 'attemptid' => $mine], 100);
        $sources = array_unique(array_column($lines, 'source'));
        sort($sources);
        $this->assertSame(['attempt', 'worker'], $sources);
        $this->assertCount(3, $lines);
        foreach ($lines as $line) {
            $this->assertSame($mine, $line['attemptid']);
        }
        $failed = array_values(array_filter($lines, static fn(array $l): bool => $l['source'] === 'attempt' && $l['failed']));
        $this->assertCount(1, $failed);
        $this->assertStringContainsString('reason=execution_context_destroyed', $failed[0]['text']);
        $this->assertStringNotContainsString('Zz99Yy88', $failed[0]['text']);

        // The experiment filter reaches the worker's debug lines too.
        $byexperiment = log_view::lines(['since' => 0, 'experimentid' => $run->experimentid, 'channel' => 'worker'], 100);
        $this->assertCount(1, $byexperiment);

        // The attempt channel alone.
        $channel = log_view::lines(['since' => 0, 'channel' => 'attempt'], 100);
        $this->assertCount(3, $channel);
    }
}
