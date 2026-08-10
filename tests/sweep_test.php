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
 * Tests for the sweep expander.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\sweep;
use local_catquizlab\local\experiment_definition;

/**
 * Sweep expansion tests.
 *
 * @covers \local_catquizlab\local\sweep
 */
final class sweep_test extends \advanced_testcase {
    /**
     * Build a sweep spec whose base is the valid baseline minus the swept fields.
     *
     * @param array $overrides Spec overrides merged on top.
     * @return array
     */
    protected function spec(array $overrides = []): array {
        $base = experiment_definition::example_baseline();
        // Persons count kept small and known for capacity assertions.
        $base['persons']['count'] = 10;

        return array_replace([
            'base'    => $base,
            'factors' => [
                'variant'  => ['ideal', 'shifted'],
                'stratum'  => ['conforming', 'chaotic'],
                'strategy' => ['classic', 'fastest'],
            ],
            'replications'                => 2,
            'seed'                        => 42,
            'estimatedsecondsperattempt'  => 100,
        ], $overrides);
    }

    /**
     * Full product: 2x2x2 = 8 cells, each expanded into R runs; all cells valid.
     *
     * @return void
     */
    public function test_full_product(): void {
        $out = sweep::expand($this->spec());

        $this->assertCount(8, $out['cells']);
        $this->assertCount(16, $out['runs']);
        $this->assertSame(0, $out['excluded']);
        foreach ($out['cells'] as $cell) {
            $this->assertTrue($cell['valid'], 'Cell should validate: ' . $cell['cellkey']
                . ' ' . implode('; ', $cell['errors']));
        }
    }

    /**
     * Exclusion rules drop exactly the matching combinations.
     *
     * @return void
     */
    public function test_exclusion(): void {
        $out = sweep::expand($this->spec([
            'exclude' => [
                ['variant' => 'ideal', 'stratum' => 'chaotic'],
            ],
        ]));

        // The rule matches 2 combinations (strategy free): 8 - 2 = 6 cells.
        $this->assertSame(2, $out['excluded']);
        $this->assertCount(6, $out['cells']);
        foreach ($out['cells'] as $cell) {
            $this->assertFalse(
                $cell['factors']['variant'] === 'ideal' && $cell['factors']['stratum'] === 'chaotic'
            );
        }
    }

    /**
     * maxcells caps the number of cells deterministically.
     *
     * @return void
     */
    public function test_maxcells_cap_is_deterministic(): void {
        $a = sweep::expand($this->spec(['maxcells' => 3]));
        $b = sweep::expand($this->spec(['maxcells' => 3]));

        $this->assertCount(3, $a['cells']);
        $this->assertSame(
            array_column($a['cells'], 'cellkey'),
            array_column($b['cells'], 'cellkey'),
            'Capping must be deterministic across expansions.'
        );
    }

    /**
     * Seeds are deterministic per (cell, replication) and vary within a cell.
     *
     * @return void
     */
    public function test_seeds_are_deterministic_and_distinct(): void {
        $first = sweep::expand($this->spec());
        $second = sweep::expand($this->spec());

        $seedsfirst = array_map(fn(array $r): int => $r['seed'], $first['runs']);
        $seedssecond = array_map(fn(array $r): int => $r['seed'], $second['runs']);
        $this->assertSame($seedsfirst, $seedssecond, 'Seeds must be reproducible.');

        // Within one cell the two replications get different seeds.
        $bycell = [];
        foreach ($first['runs'] as $run) {
            $bycell[$run['cellkey']][] = $run['seed'];
        }
        foreach ($bycell as $seeds) {
            $this->assertSame($seeds, array_unique($seeds), 'Replication seeds within a cell must differ.');
        }
    }

    /**
     * Capacity reflects cells x replications x persons and the time assumption.
     *
     * @return void
     */
    public function test_capacity_estimate(): void {
        $out = sweep::expand($this->spec());
        $cap = $out['capacity'];

        $this->assertSame(8, $cap['cells']);
        $this->assertSame(16, $cap['runs']);
        $this->assertSame(160, $cap['attempts']);           // 16 runs x 10 persons.
        $this->assertSame(16000, $cap['estimatedseconds']); // 160 x 100s.
    }

    /**
     * An unknown factor is rejected.
     *
     * @return void
     */
    public function test_unknown_factor_rejected(): void {
        $this->expectException(\invalid_parameter_exception::class);
        sweep::expand($this->spec(['factors' => ['flavour' => ['sweet']]]));
    }

    /**
     * An empty factor level list is rejected.
     *
     * @return void
     */
    public function test_empty_factor_rejected(): void {
        $this->expectException(\invalid_parameter_exception::class);
        sweep::expand($this->spec(['factors' => ['variant' => []]]));
    }
}
