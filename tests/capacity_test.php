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

/**
 * Tests for capacity planning and throughput.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\capacity;

/**
 * Capacity tests.
 *
 * @covers \local_catquizlab\local\capacity
 */
final class capacity_test extends \advanced_testcase {
    /**
     * Batching splits the queue into concurrency-sized chunks.
     *
     * @return void
     */
    public function test_plan_batches(): void {
        $this->assertSame([3, 3, 3, 1], capacity::plan_batches(10, 3));
        $this->assertSame([2, 2], capacity::plan_batches(4, 2));
        $this->assertSame([], capacity::plan_batches(0, 4));
        // A zero concurrency is treated as one.
        $this->assertSame([1, 1], capacity::plan_batches(2, 0));
    }

    /**
     * Stagger offsets step by the interval.
     *
     * @return void
     */
    public function test_stagger_offsets(): void {
        $this->assertSame([0, 30, 60, 90], capacity::stagger_offsets(4, 30));
        $this->assertSame([], capacity::stagger_offsets(0, 30));
    }

    /**
     * Throughput reflects concurrency: the same work finishes faster in parallel.
     *
     * @return void
     */
    public function test_throughput(): void {
        $serial = capacity::throughput([1000, 2000, 3000], 1);
        $this->assertSame(3, $serial['count']);
        $this->assertSame(2000, $serial['meanms']);
        $this->assertSame(2000, $serial['medianms']);
        $this->assertSame(6000, $serial['wallclockms']);
        $this->assertEqualsWithDelta(30.0, $serial['perminute'], 1e-6);

        $parallel = capacity::throughput([1000, 2000, 3000], 3);
        $this->assertSame(2000, $parallel['wallclockms']);
        $this->assertGreaterThan($serial['perminute'], $parallel['perminute']);

        $this->assertSame(0, capacity::throughput([])['count']);
    }
}
