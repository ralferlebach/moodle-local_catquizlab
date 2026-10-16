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
use local_catquizlab\local\engine_perf;
use local_catquizlab\output\engine_perf_view;

/**
 * The engine's request timing read per sitting and per run (#110, local_catquiz #136).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\engine_perf
 * @covers     \local_catquizlab\output\engine_perf_view
 */
final class engine_perf_test extends \advanced_testcase {
    /**
     * Without the engine's trace, nothing is invented and the page says why.
     *
     * @return void
     */
    public function test_without_the_engine_trace(): void {
        $this->resetAfterTest();
        if (engine_perf::available()) {
            $this->markTestSkipped('The installed engine traces requests; covered by the test below.');
        }
        $this->assertNull(engine_perf::for_sitting(123));
        $this->assertSame(['sittings' => 0, 'traced' => 0, 'kinds' => []], engine_perf::for_run(999));
        $this->assertStringContainsString('does not record request timing', engine_perf_view::render(999));
    }

    /**
     * With the engine's trace: per sitting in brief, per run as percentiles, first and following items apart.
     *
     * @return void
     */
    public function test_with_the_engine_trace(): void {
        global $DB;
        $this->resetAfterTest();
        if (!engine_perf::available()) {
            $this->markTestSkipped('Needs local_catquiz 2026100907 or later, which traces requests.');
        }

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        foreach ([7001, 7002] as $i => $engineid) {
            $person = $generator->create_person(['runid' => $run->id]);
            $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $run->id, 'personid' => $person->id,
                'status' => attempt_scheduler::STATUS_COLLECTED, 'tries' => 1, 'engineattemptid' => $engineid,
                'timecreated' => time(), 'timemodified' => time()]);
            $DB->insert_record('local_catquiz_trace', (object) ['adaptiveattemptid' => $engineid, 'userid' => 0,
                'kind' => 'first_item', 'questionnumber' => 1, 'requestms' => 1200 + 100 * $i, 'dbqueries' => 300,
                'spans' => json_encode([['name' => 'pool_items', 'depth' => 1, 'offsetms' => 10, 'ms' => 800 + $i]]),
                'timecreated' => time()]);
            foreach ([2, 3, 4] as $q) {
                $DB->insert_record('local_catquiz_trace', (object) ['adaptiveattemptid' => $engineid, 'userid' => 0,
                    'kind' => 'next_item', 'questionnumber' => $q, 'requestms' => 100 * $q, 'dbqueries' => 40,
                    'spans' => json_encode([['name' => 'strategy', 'depth' => 1, 'offsetms' => 5, 'ms' => 20 * $q]]),
                    'timecreated' => time()]);
            }
        }

        $sitting = engine_perf::for_sitting(7001);
        $this->assertSame([4, 1200.0, 300.0, 400.0, 400.0, 420], [$sitting['requests'], $sitting['firstms'],
            $sitting['nextp50ms'], $sitting['nextp95ms'], $sitting['nextmaxms'], $sitting['dbqueries']]);
        $this->assertSame(['pool_items', 'strategy'], array_keys($sitting['spans']), 'slowest first');

        $runfigures = engine_perf::for_run((int) $run->id);
        $this->assertSame([2, 2], [$runfigures['sittings'], $runfigures['traced']]);
        $this->assertSame(['first_item', 'next_item'], array_keys($runfigures['kinds']));
        $this->assertSame(6, $runfigures['kinds']['next_item']['count']);
        $this->assertSame(1300.0, $runfigures['kinds']['first_item']['requestms']['max']);

        $html = engine_perf_view::render((int) $run->id);
        $this->assertStringContainsString('2 of 2 sitting(s) traced', $html);
        $this->assertStringContainsString('data-kind="next_item"', $html);
        $this->assertStringContainsString('pool_items', $html);
    }
}
