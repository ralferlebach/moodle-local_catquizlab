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

use local_catquizlab\local\results_query;
use local_catquizlab\local\stream_summary;

/**
 * Runs, sittings and people counted apart; intervals over the independent unit (#127).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\results_query
 * @covers     \local_catquizlab\local\stream_summary
 */
final class statistics_units_test extends \advanced_testcase {
    /**
     * Sittings of one run, one replication: a twin each.
     *
     * @param int $n How many.
     * @param int $runid The run.
     * @param int $replication The replication.
     * @param callable $value The value of sitting $i.
     * @return array[]
     */
    protected function sittings(int $n, int $runid, int $replication, callable $value): array {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['runid' => $runid, 'replication' => $replication, 'twinid' => sprintf('t%05d', $i),
                'attemptid' => $runid * 10000 + $i, 'nitems' => $value($i), 'se' => 0.3, 'error' => 0.0];
        }

        return $rows;
    }

    /**
     * One run of 250 sittings: 1 run, 250 sittings, 250 people; the interval over people, not "replications".
     *
     * @return void
     */
    public function test_one_run_of_250_sittings(): void {
        $summary = new stream_summary();
        foreach ($this->sittings(250, 7, 1, static fn(int $i): int => 10 + $i % 5) as $row) {
            $summary->add($row);
        }

        $this->assertSame([1, 250, 250], [$summary->runs(), $summary->count(), $summary->people()]);
        $stat = $summary->describe('nitems');
        $this->assertSame(250, $stat['units']);
        $this->assertSame('person', $stat['unit']);
        // Over 250 independent values: sd / sqrt(250).
        $this->assertEqualsWithDelta($stat['sd'] / sqrt(250), $stat['se'], 1e-6);
        $this->assertNotNull($stat['ci95lo']);
    }

    /**
     * Paired twins across two strategies are one person each: n is the people, the SE from their means.
     *
     * @return void
     */
    public function test_paired_twins_count_once(): void {
        // The same 50 twins under two strategies (two runs), pooled in one group.
        $rows = array_merge(
            $this->sittings(50, 1, 1, static fn(int $i): int => 10 + $i % 7),
            $this->sittings(50, 2, 1, static fn(int $i): int => 12 + $i % 7)
        );
        $summary = new stream_summary();
        foreach ($rows as $row) {
            $summary->add($row);
        }
        $this->assertSame([2, 100, 50], [$summary->runs(), $summary->count(), $summary->people()]);

        $stat = $summary->describe('nitems');
        $this->assertSame(100, $stat['n']);
        $this->assertSame(50, $stat['units']);
        // The SE of the 50 person means, not of 100 values as if independent.
        $means = [];
        for ($i = 0; $i < 50; $i++) {
            $means[] = ((10 + $i % 7) + (12 + $i % 7)) / 2;
        }
        $m = array_sum($means) / 50;
        $sd = sqrt(array_sum(array_map(static fn(float $v): float => ($v - $m) ** 2, $means)) / 49);
        $this->assertEqualsWithDelta($sd / sqrt(50), $stat['se'], 1e-6);
        // The array path says the same as the stream.
        $this->assertSame($stat, results_query::summarise($rows, 'nitems'));
    }

    /**
     * Two replications are independent draws: the same twin id in each is two people.
     *
     * @return void
     */
    public function test_replications_are_independent_people(): void {
        $rows = array_merge(
            $this->sittings(30, 1, 1, static fn(int $i): int => 10 + $i % 3),
            $this->sittings(30, 2, 2, static fn(int $i): int => 10 + $i % 4)
        );
        $stat = results_query::summarise($rows, 'nitems');
        $this->assertSame(60, $stat['units']);
    }

    /**
     * One person: a mean and no interval — not a precision the data does not have.
     *
     * @return void
     */
    public function test_one_person_has_no_interval(): void {
        $rows = array_merge(
            $this->sittings(1, 1, 1, static fn(int $i): int => 10),
            $this->sittings(1, 2, 1, static fn(int $i): int => 14)
        );
        $stat = results_query::summarise($rows, 'nitems');
        $this->assertSame([2, 1, 12.0], [$stat['n'], $stat['units'], $stat['mean']]);
        $this->assertNull($stat['ci95lo']);
        $this->assertNull($stat['se']);
        $this->assertNotNull($stat['sd'], 'the values still have a spread');
    }

    /**
     * The interval's method and unit are named where the figures are shown and exported.
     *
     * @return void
     */
    public function test_the_method_is_named(): void {
        $text = get_string('dispersion:ci95', 'local_catquizlab');
        $this->assertStringContainsString('simulated person', $text);
        $this->assertStringNotContainsString('over replications', $text);
        $this->assertSame('Runs', get_string('report:runs', 'local_catquizlab'));
        $this->assertSame('Sittings', get_string('report:sittings', 'local_catquizlab'));
    }
}
