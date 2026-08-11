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
 * Tests for the response oracle.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\response_oracle;

/**
 * Response oracle tests.
 *
 * @covers \local_catquizlab\local\response_oracle
 */
final class response_oracle_test extends \advanced_testcase {
    /**
     * At theta = b the Rasch probability is 0.5, and it is monotone in theta.
     *
     * @return void
     */
    public function test_probability_rasch(): void {
        $this->assertEqualsWithDelta(0.5, response_oracle::probability(0.0, 0.0), 1e-9);
        $this->assertLessThan(response_oracle::probability(0.0, 0.0), response_oracle::probability(-2.0, 0.0));
        $this->assertGreaterThan(response_oracle::probability(0.0, 0.0), response_oracle::probability(2.0, 0.0));
    }

    /**
     * Guessing sets the lower asymptote and discrimination steepens the curve.
     *
     * @return void
     */
    public function test_probability_parameters(): void {
        $this->assertEqualsWithDelta(0.25, response_oracle::probability(-20.0, 0.0, 1.0, 0.25), 1e-6);
        $this->assertGreaterThan(
            response_oracle::probability(1.0, 0.0, 1.0),
            response_oracle::probability(1.0, 0.0, 2.0)
        );
        $this->assertGreaterThanOrEqual(0.0, response_oracle::probability(-50.0, 0.0));
        $this->assertLessThanOrEqual(1.0, response_oracle::probability(50.0, 0.0));
    }

    /**
     * The response is deterministic for a given seed.
     *
     * @return void
     */
    public function test_respond_deterministic(): void {
        $this->assertSame(
            response_oracle::respond(0.5, 0.0, 12345),
            response_oracle::respond(0.5, 0.0, 12345)
        );
    }

    /**
     * Over many seeds the empirical accuracy tracks the probability.
     *
     * @return void
     */
    public function test_respond_frequency(): void {
        $correct = 0;
        for ($seed = 1; $seed <= 4000; $seed++) {
            if (response_oracle::respond(0.0, 0.0, $seed)) {
                $correct++;
            }
        }
        // At theta = b the expected share is 0.5.
        $this->assertEqualsWithDelta(0.5, $correct / 4000, 0.05);
    }

    /**
     * The hierarchical ability resolves per level with fallbacks.
     *
     * @return void
     */
    public function test_ability_for(): void {
        $profile = [
            'global'     => 0.1,
            'categories' => [
                ['index' => 1, 'theta' => 0.5, 'subscales' => [
                    ['index' => 1, 'theta' => 0.9],
                    ['index' => 2, 'theta' => 0.2],
                ]],
                ['index' => 2, 'theta' => -0.4, 'subscales' => [
                    ['index' => 1, 'theta' => -0.7],
                ]],
            ],
        ];

        $this->assertEqualsWithDelta(0.1, response_oracle::ability_for($profile), 1e-9);
        $this->assertEqualsWithDelta(0.5, response_oracle::ability_for($profile, 1), 1e-9);
        $this->assertEqualsWithDelta(0.2, response_oracle::ability_for($profile, 1, 2), 1e-9);
        // Missing subscale falls back to the category ability.
        $this->assertEqualsWithDelta(-0.4, response_oracle::ability_for($profile, 2, 9), 1e-9);
        // Missing category falls back to the global ability.
        $this->assertEqualsWithDelta(0.1, response_oracle::ability_for($profile, 99), 1e-9);
    }

    /**
     * GPCM category probabilities sum to 1 and shift upward with ability.
     *
     * @return void
     */
    public function test_gpcm_probabilities(): void {
        $steps = [-1.0, 0.0, 1.0];

        $low = response_oracle::gpcm_probabilities(-3.0, 1.0, $steps);
        $high = response_oracle::gpcm_probabilities(3.0, 1.0, $steps);

        $this->assertCount(4, $low);
        $this->assertEqualsWithDelta(1.0, array_sum($low), 1e-9);
        $this->assertEqualsWithDelta(1.0, array_sum($high), 1e-9);
        // Lowest category dominates at low ability, highest at high ability.
        $this->assertSame(0, self::argmax($low));
        $this->assertSame(3, self::argmax($high));
    }

    /**
     * GRM category probabilities sum to 1 and are ordered by ability.
     *
     * @return void
     */
    public function test_grm_probabilities(): void {
        $thresholds = [-1.0, 0.0, 1.0];

        $low = response_oracle::grm_probabilities(-3.0, 1.2, $thresholds);
        $high = response_oracle::grm_probabilities(3.0, 1.2, $thresholds);

        $this->assertCount(4, $low);
        $this->assertEqualsWithDelta(1.0, array_sum($low), 1e-9);
        $this->assertSame(0, self::argmax($low));
        $this->assertSame(3, self::argmax($high));
    }

    /**
     * Polytomous draws are seed-deterministic and rise with ability on average.
     *
     * @return void
     */
    public function test_respond_polytomous(): void {
        $steps = [-1.0, 0.0, 1.0];

        $a = response_oracle::respond_polytomous(0.5, 'gpcm', 1.0, $steps, 12345);
        $b = response_oracle::respond_polytomous(0.5, 'gpcm', 1.0, $steps, 12345);
        $this->assertSame($a, $b);
        $this->assertGreaterThanOrEqual(0, $a);
        $this->assertLessThanOrEqual(3, $a);

        $meanlow = self::mean_category(-2.0, $steps);
        $meanhigh = self::mean_category(2.0, $steps);
        $this->assertLessThan($meanhigh, $meanlow);
    }

    /**
     * Index of the largest value in a list.
     *
     * @param float[] $values The values.
     * @return int
     */
    private static function argmax(array $values): int {
        $best = 0;
        foreach ($values as $index => $value) {
            if ($value > $values[$best]) {
                $best = $index;
            }
        }
        return $best;
    }

    /**
     * Empirical mean drawn category over many seeds at a given ability.
     *
     * @param float $theta The ability.
     * @param float[] $steps The GPCM step parameters.
     * @return float
     */
    private static function mean_category(float $theta, array $steps): float {
        $sum = 0;
        for ($seed = 0; $seed < 1500; $seed++) {
            $sum += response_oracle::respond_polytomous($theta, 'gpcm', 1.0, $steps, $seed);
        }
        return $sum / 1500;
    }
}
