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
 * Tests for the strategy and model catalogues.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\distribution;
use local_catquizlab\local\model_catalog;
use local_catquizlab\local\strategy_catalog;

/**
 * Catalogue tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\strategy_catalog
 * @covers     \local_catquizlab\local\model_catalog
 * @covers     \local_catquizlab\local\distribution
 */
final class catalog_test extends \advanced_testcase {
    /**
     * Every allowed strategy key has a complete descriptor.
     *
     * @return void
     */
    public function test_every_strategy_has_a_descriptor(): void {
        foreach (strategy_catalog::keys() as $key) {
            $descriptor = strategy_catalog::descriptor($key);

            $this->assertSame($key, $descriptor['key']);
            $this->assertGreaterThan(0, $descriptor['engineid']);
            $this->assertNotEmpty($descriptor['label']);
            $this->assertNotEmpty($descriptor['description']);
            $this->assertStringStartsWith('LOCAL_CATQUIZ_STRATEGY_', $descriptor['constant']);
        }
    }

    /**
     * Each strategy maps to the engine id fixed by the engine's own constants.
     *
     * @return void
     */
    public function test_strategy_engine_ids_match_the_engine_contract(): void {
        $expected = [
            'fastest'    => 1,
            'balanced'   => 2,
            'allsubs'    => 3,
            'lowestsub'  => 4,
            'highestsub' => 5,
            'pilot'      => 6,
            'classic'    => 7,
            'relsubs'    => 8,
        ];

        foreach ($expected as $key => $engineid) {
            $this->assertSame($engineid, strategy_catalog::engine_id($key), 'Strategy ' . $key);
        }
    }

    /**
     * Publication labels are unique, so a results table cannot conflate two modes.
     *
     * @return void
     */
    public function test_strategy_labels_are_unique(): void {
        $labels = array_map([strategy_catalog::class, 'label'], strategy_catalog::keys());

        $this->assertCount(count($labels), array_unique($labels));
    }

    /**
     * An unknown strategy key is refused rather than silently defaulted.
     *
     * @return void
     */
    public function test_unknown_strategy_is_refused(): void {
        $this->expectException(\coding_exception::class);

        strategy_catalog::engine_id('vibes');
    }

    /**
     * The public model names map to the engine's catmodel keys.
     *
     * @return void
     */
    public function test_model_engine_keys(): void {
        $this->assertSame('rasch', model_catalog::engine_key('1pl'));
        $this->assertSame('raschbirnbaum', model_catalog::engine_key('2pl'));
        $this->assertSame('mixedraschbirnbaum', model_catalog::engine_key('3pl'));
        $this->assertSame('pcmgeneralized', model_catalog::engine_key('gpcm'));
        $this->assertSame('grm', model_catalog::engine_key('grm'));
    }

    /**
     * Legacy engine-side names are accepted and normalised to the public key.
     *
     * @return void
     */
    public function test_legacy_model_names_are_normalised(): void {
        $this->assertSame('1pl', model_catalog::normalise('rasch'));
        $this->assertSame('2pl', model_catalog::normalise('raschbirnbaum'));
        $this->assertSame('3pl', model_catalog::normalise('mixedraschbirnbaum'));
        $this->assertSame('ggrm', model_catalog::normalise('grmgeneralized'));
        $this->assertSame('2pl', model_catalog::normalise('2PL'));
        $this->assertNull(model_catalog::normalise('quantum'));
    }

    /**
     * GPCM and GRM are distinct models with distinct engine keys and oracle families.
     *
     * @return void
     */
    public function test_gpcm_and_grm_are_not_conflated(): void {
        $this->assertNotSame(model_catalog::engine_key('gpcm'), model_catalog::engine_key('grm'));
        $this->assertSame('gpcm', model_catalog::oracle_family('gpcm'));
        $this->assertSame('grm', model_catalog::oracle_family('grm'));
        $this->assertTrue(model_catalog::is_polytomous('gpcm'));
        $this->assertFalse(model_catalog::is_polytomous('2pl'));
    }

    /**
     * Each model declares exactly the item parameters it needs.
     *
     * @return void
     */
    public function test_required_parameters_follow_the_model(): void {
        $this->assertFalse(model_catalog::needs_discrimination('1pl'));
        $this->assertTrue(model_catalog::needs_discrimination('2pl'));
        $this->assertFalse(model_catalog::needs_guessing('2pl'));
        $this->assertTrue(model_catalog::needs_guessing('3pl'));
        $this->assertContains('steps', model_catalog::requires('gpcm'));
    }

    /**
     * A malformed distribution is reported rather than drawn from.
     *
     * @return void
     */
    public function test_distribution_validation(): void {
        $this->assertSame([], distribution::validate(['dist' => 'constant', 'value' => 1.0], 'a'));
        $this->assertNotEmpty(distribution::validate(['dist' => 'wishful'], 'a'));
        $this->assertNotEmpty(distribution::validate(['dist' => 'normal', 'mean' => 0.0], 'a'));
        $this->assertNotEmpty(distribution::validate(['dist' => 'uniform', 'min' => 2, 'max' => 1], 'a'));
        $this->assertNotEmpty(distribution::validate('not a block', 'a'));
    }

    /**
     * Drawing is seed-deterministic and honours the clamp.
     *
     * @return void
     */
    public function test_distribution_draws_are_deterministic_and_clamped(): void {
        $spec = ['dist' => 'lognormal', 'meanlog' => 0.0, 'sdlog' => 0.4, 'clamp' => ['min' => 0.5, 'max' => 2.0]];

        mt_srand(7);
        $first = [];
        for ($i = 0; $i < 20; $i++) {
            $first[] = distribution::draw($spec);
        }
        mt_srand(7);
        $second = [];
        for ($i = 0; $i < 20; $i++) {
            $second[] = distribution::draw($spec);
        }

        $this->assertSame($first, $second);
        foreach ($first as $value) {
            $this->assertGreaterThanOrEqual(0.5, $value);
            $this->assertLessThanOrEqual(2.0, $value);
        }
    }
}
