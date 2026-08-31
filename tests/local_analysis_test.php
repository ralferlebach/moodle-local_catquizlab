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
 * Tests for the local diagnostic analysis.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\local_analysis;

/**
 * Local-analysis tests.
 *
 * Every case uses a hand-built profile whose right answer is known in advance,
 * so a failure points at the arithmetic rather than at the simulation.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\local_analysis
 */
final class local_analysis_test extends \advanced_testcase {
    /**
     * A scale map over four subscales of one domain.
     *
     * @return array<int, string>
     */
    protected function scalemap(): array {
        return [11 => '1:1', 12 => '1:2', 13 => '1:3', 14 => '1:4'];
    }

    /**
     * An observation with the given true and estimated abilities.
     *
     * @param float $trueglobal The true global ability.
     * @param array $truesubscales True subscale abilities, in subscale order.
     * @param float $estglobal The estimated global ability.
     * @param array $estsubscales Estimated subscale abilities, in subscale order.
     * @param array $ses Per-scale standard errors keyed by engine scale id.
     * @return array
     */
    protected function observation(
        float $trueglobal,
        array $truesubscales,
        float $estglobal,
        array $estsubscales,
        array $ses = []
    ): array {
        $subscales = [];
        foreach ($truesubscales as $index => $theta) {
            $subscales[] = ['index' => $index + 1, 'theta' => $theta];
        }
        $abilities = [];
        foreach ($estsubscales as $index => $theta) {
            $abilities[11 + $index] = $theta;
        }

        return [
            'attemptid' => 1,
            'runid'     => 1,
            'personid'  => 1,
            'twinid'    => 'r001-t00001',
            'strategy'  => 'lowestsub',
            'variant'   => 'ideal',
            'stratum'   => 'subscalevariation',
            'severity'  => 'medium',
            'tier'      => 'main',
            'esttheta'  => $estglobal,
            'profile'   => [
                'global'     => $trueglobal,
                'categories' => [['index' => 1, 'theta' => $trueglobal, 'subscales' => $subscales]],
            ],
            'trace'     => [
                'finaltheta'          => $estglobal,
                'scaleabilities'      => $abilities,
                'scalestandarderrors' => $ses,
                'questionsperscale'   => [11 => 4, 12 => 3, 13 => 5, 14 => 2],
            ],
        ];
    }

    /**
     * A global offset shared by every subscale leaves the deviations intact.
     *
     * This is the reason the analysis works on deviations: the test below
     * places every subscale a full logit too high, which is a global failure
     * and a local success, and comparing absolute abilities would report it as
     * a local failure too.
     *
     * @return void
     */
    public function test_a_global_offset_does_not_spoil_local_recovery(): void {
        $this->resetAfterTest();

        $observation = $this->observation(
            0.0,
            [-1.0, -0.5, 0.5, 1.0],
            1.0,
            [0.0, 0.5, 1.5, 2.0]
        );

        $rows = local_analysis::subscale_rows($observation, $this->scalemap());
        $summary = local_analysis::summarise($rows);

        $this->assertCount(4, $rows);
        $this->assertEqualsWithDelta(0.0, $summary['bias'], 0.000001);
        $this->assertEqualsWithDelta(0.0, $summary['rmse'], 0.000001);
        $this->assertEqualsWithDelta(1.0, $summary['correlation'], 0.000001);
    }

    /**
     * The deviations are computed against each side's own global level.
     *
     * @return void
     */
    public function test_deviations_are_relative_to_each_global_level(): void {
        $this->resetAfterTest();

        $rows = local_analysis::subscale_rows(
            $this->observation(0.5, [0.0, 1.0], 0.2, [0.0, 0.6]),
            [11 => '1:1', 12 => '1:2']
        );

        $this->assertEqualsWithDelta(-0.5, $rows[0]['truedelta'], 0.000001);
        $this->assertEqualsWithDelta(-0.2, $rows[0]['estdelta'], 0.000001);
        $this->assertEqualsWithDelta(0.5, $rows[1]['truedelta'], 0.000001);
        $this->assertEqualsWithDelta(0.4, $rows[1]['estdelta'], 0.000001);
    }

    /**
     * Agreement counts an error against the recorded standard error.
     *
     * @return void
     */
    public function test_se_agreement(): void {
        $this->resetAfterTest();

        // Errors of 0.1, 0.3, 0.9 and 2.5 against a standard error of 0.5:
        // three inside one SE, one inside two, one outside both.
        $observation = $this->observation(
            0.0,
            [0.0, 0.0, 0.0, 0.0],
            0.0,
            [0.1, 0.3, 0.9, 2.5],
            [11 => 0.5, 12 => 0.5, 13 => 0.5, 14 => 0.5]
        );

        $rows = local_analysis::subscale_rows($observation, $this->scalemap());
        $summary = local_analysis::summarise($rows);

        $this->assertSame(4, $summary['nwithse']);
        $this->assertEqualsWithDelta(0.5, $summary['within1se'], 0.000001);
        $this->assertEqualsWithDelta(0.75, $summary['within2se'], 0.000001);
    }

    /**
     * Without recorded standard errors the agreement is unavailable, not zero.
     *
     * @return void
     */
    public function test_missing_se_is_reported_as_unavailable(): void {
        $this->resetAfterTest();

        $rows = local_analysis::subscale_rows(
            $this->observation(0.0, [0.0, 0.0], 0.0, [0.1, 0.2]),
            [11 => '1:1', 12 => '1:2']
        );
        $summary = local_analysis::summarise($rows);

        // Zero would claim the estimates never agreed, which is a different
        // and much stronger statement than "we could not check".
        $this->assertNull($summary['within1se']);
        $this->assertNull($summary['within2se']);
        $this->assertSame(0, $summary['nwithse']);
        $this->assertNotNull($summary['rmse']);
    }

    /**
     * A subscale the engine reported nothing for is left out.
     *
     * @return void
     */
    public function test_unaligned_subscales_are_skipped(): void {
        $this->resetAfterTest();

        $observation = $this->observation(0.0, [-1.0, 0.0, 1.0, 2.0], 0.0, [-1.0, 0.0]);
        $rows = local_analysis::subscale_rows($observation, $this->scalemap());

        $this->assertCount(2, $rows);
    }

    /**
     * A perfect ranking scores one throughout.
     *
     * @return void
     */
    public function test_perfect_ranking(): void {
        $this->resetAfterTest();

        $rows = local_analysis::subscale_rows(
            $this->observation(0.0, [-1.5, -0.8, 0.4, 1.2], 0.0, [-1.4, -0.7, 0.5, 1.1]),
            $this->scalemap()
        );
        $ranking = local_analysis::ranking($rows, 'lowestsub');

        $this->assertEqualsWithDelta(1.0, $ranking['spearman'], 0.000001);
        $this->assertEqualsWithDelta(1.0, $ranking['topk'][1]['agreement'], 0.000001);
        $this->assertEqualsWithDelta(0.0, $ranking['rankerror'], 0.000001);
    }

    /**
     * A reversed ranking scores minus one.
     *
     * @return void
     */
    public function test_reversed_ranking(): void {
        $this->resetAfterTest();

        $rows = local_analysis::subscale_rows(
            $this->observation(0.0, [-1.5, -0.8, 0.4, 1.2], 0.0, [1.2, 0.4, -0.8, -1.5]),
            $this->scalemap()
        );
        $ranking = local_analysis::ranking($rows, 'lowestsub');

        $this->assertEqualsWithDelta(-1.0, $ranking['spearman'], 0.000001);
        $this->assertEqualsWithDelta(0.0, $ranking['topk'][1]['agreement'], 0.000001);
    }

    /**
     * A strengths strategy ranks from the other end of the scale.
     *
     * @return void
     */
    public function test_strength_strategy_ranks_the_other_way(): void {
        $this->resetAfterTest();

        $rows = local_analysis::subscale_rows(
            $this->observation(0.0, [-1.5, -0.8, 0.4, 1.2], 0.0, [-1.4, -0.7, 0.5, 1.1]),
            $this->scalemap()
        );

        // The same data are a perfect ranking under either orientation, but the
        // target subscale differs: the weakest for one, the strongest for the
        // other. What must not happen is a strengths strategy being scored as
        // if it had been hunting deficits.
        $deficit = local_analysis::ranking($rows, 'lowestsub');
        $strength = local_analysis::ranking($rows, 'highestsub');

        $this->assertEqualsWithDelta(1.0, $deficit['spearman'], 0.000001);
        $this->assertEqualsWithDelta(1.0, $strength['spearman'], 0.000001);
        $this->assertSame(-1.0, local_analysis::orientation('highestsub'));
        $this->assertSame(1.0, local_analysis::orientation('lowestsub'));
    }

    /**
     * The confusion matrix counts against the deficit threshold.
     *
     * @return void
     */
    public function test_confusion_matrix(): void {
        $this->resetAfterTest();

        // True deviations -1.0 and -0.6 are deficits at a 0.5 threshold; 0.0
        // and 1.0 are not. The estimate finds one of them and invents one.
        $rows = local_analysis::subscale_rows(
            $this->observation(0.0, [-1.0, -0.6, 0.0, 1.0], 0.0, [-1.2, 0.1, -0.8, 1.0]),
            $this->scalemap()
        );
        $ranking = local_analysis::ranking($rows, 'lowestsub', 0.5);
        $confusion = $ranking['confusion'];

        $this->assertSame(1, $confusion['tp']);
        $this->assertSame(1, $confusion['fp']);
        $this->assertSame(1, $confusion['fn']);
        $this->assertSame(1, $confusion['tn']);

        $rates = local_analysis::confusion_rates($confusion);
        $this->assertEqualsWithDelta(0.5, $rates['precision'], 0.000001);
        $this->assertEqualsWithDelta(0.5, $rates['recall'], 0.000001);
        $this->assertEqualsWithDelta(0.5, $rates['specificity'], 0.000001);
    }

    /**
     * Confusion rates of an empty matrix are unavailable rather than zero.
     *
     * @return void
     */
    public function test_confusion_rates_of_nothing(): void {
        $this->resetAfterTest();

        $rates = local_analysis::confusion_rates(['tp' => 0, 'fp' => 0, 'fn' => 0, 'tn' => 0]);

        $this->assertNull($rates['precision']);
        $this->assertNull($rates['recall']);
        $this->assertNull($rates['accuracy']);
    }

    /**
     * A single subscale cannot be ranked.
     *
     * @return void
     */
    public function test_ranking_needs_two_subscales(): void {
        $this->resetAfterTest();

        $rows = local_analysis::subscale_rows(
            $this->observation(0.0, [0.5], 0.0, [0.4]),
            [11 => '1:1']
        );

        $this->assertNull(local_analysis::ranking($rows, 'lowestsub'));
    }

    /**
     * Aggregating across attempts pools the matrix and describes the spread.
     *
     * @return void
     */
    public function test_aggregate_ranking(): void {
        $this->resetAfterTest();

        $good = local_analysis::ranking(
            local_analysis::subscale_rows(
                $this->observation(0.0, [-1.5, -0.8, 0.4, 1.2], 0.0, [-1.4, -0.7, 0.5, 1.1]),
                $this->scalemap()
            ),
            'lowestsub'
        );
        $bad = local_analysis::ranking(
            local_analysis::subscale_rows(
                $this->observation(0.0, [-1.5, -0.8, 0.4, 1.2], 0.0, [1.2, 0.4, -0.8, -1.5]),
                $this->scalemap()
            ),
            'lowestsub'
        );

        $aggregate = local_analysis::aggregate_ranking([$good, $bad]);

        $this->assertSame(2, $aggregate['n']);
        $this->assertEqualsWithDelta(0.0, $aggregate['spearman']['mean'], 0.000001);
        $this->assertSame(2, $aggregate['spearman']['n']);
        $this->assertArrayHasKey(1, $aggregate['topk']);
        $this->assertEqualsWithDelta(0.5, $aggregate['topk'][1]['agreement']['mean'], 0.000001);
    }

    /**
     * The tab title follows the strategy's purpose.
     *
     * @return void
     */
    public function test_detection_labels_follow_the_strategy(): void {
        $this->resetAfterTest();

        $this->assertSame('Deficit detection', local_analysis::detection_labels('lowestsub')['title']);
        $this->assertSame('Strength detection', local_analysis::detection_labels('highestsub')['title']);
        $this->assertSame('Subscale coverage', local_analysis::detection_labels('allsubs')['title']);
        $this->assertNotEmpty(local_analysis::detection_labels('fastest')['goal']);
    }

    /**
     * Grouping summarises per domain and per subscale.
     *
     * @return void
     */
    public function test_grouping(): void {
        $this->resetAfterTest();

        $rows = local_analysis::subscale_rows(
            $this->observation(0.0, [-1.0, -0.5, 0.5, 1.0], 0.0, [-0.9, -0.5, 0.6, 1.0]),
            $this->scalemap()
        );

        $bydomain = local_analysis::group($rows, 'category');
        $bysubscale = local_analysis::group($rows, 'key');

        $this->assertCount(1, $bydomain);
        $this->assertSame(4, $bydomain[0]['n']);
        $this->assertCount(4, $bysubscale);
    }
}
