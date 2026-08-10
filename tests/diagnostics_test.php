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
 * Tests for the diagnostics measures.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\diagnostics;

/**
 * Diagnostics tests.
 *
 * @covers \local_catquizlab\local\diagnostics
 */
final class diagnostics_test extends \advanced_testcase {
    /** @var float[] A true profile with the deficits at the first two subscales. */
    private const TRUEV = [-2.0, -1.5, 0.0, 0.5, 1.0];

    /**
     * Spearman is +1 for an order-preserving estimate and -1 for a reversed one.
     *
     * @return void
     */
    public function test_spearman(): void {
        $good = [-1.8, -1.2, 0.1, 0.4, 0.9];
        $reversed = [1.0, 0.5, 0.0, -1.5, -2.0];

        $this->assertEqualsWithDelta(1.0, diagnostics::spearman(self::TRUEV, $good), 1e-9);
        $this->assertEqualsWithDelta(-1.0, diagnostics::spearman(self::TRUEV, $reversed), 1e-9);
        $this->assertNull(diagnostics::spearman([1.0], [1.0]));
    }

    /**
     * Top-k agreement counts the overlap of the k most-deficient subscales.
     *
     * @return void
     */
    public function test_topk_agreement(): void {
        $good = [-1.8, -1.2, 0.1, 0.4, 0.9];
        $reversed = [1.0, 0.5, 0.0, -1.5, -2.0];

        $this->assertSame(2, diagnostics::topk_agreement(self::TRUEV, $good, 2)['overlap']);
        $this->assertEqualsWithDelta(1.0, diagnostics::topk_agreement(self::TRUEV, $good, 2)['fraction'], 1e-9);
        $this->assertSame(0, diagnostics::topk_agreement(self::TRUEV, $reversed, 2)['overlap']);
    }

    /**
     * nDCG is 1 for the ideal ranking and lower for a reversed one.
     *
     * @return void
     */
    public function test_ndcg(): void {
        $good = [-1.8, -1.2, 0.1, 0.4, 0.9];
        $reversed = [1.0, 0.5, 0.0, -1.5, -2.0];

        $this->assertEqualsWithDelta(1.0, diagnostics::ndcg_at_k(self::TRUEV, $good, 3), 1e-9);
        $this->assertLessThan(1.0, diagnostics::ndcg_at_k(self::TRUEV, $reversed, 3));
    }

    /**
     * The confusion matrix and its rates match a hand-worked example.
     *
     * @return void
     */
    public function test_confusion(): void {
        // Sub0 TP, sub1 FN, sub2 FP, sub3/4 TN.
        $truelabels = [true, true, false, false, false];
        $estlabels = [true, false, true, false, false];

        $c = diagnostics::confusion($truelabels, $estlabels);

        $this->assertSame(1, $c['tp']);
        $this->assertSame(1, $c['fp']);
        $this->assertSame(1, $c['fn']);
        $this->assertSame(2, $c['tn']);
        $this->assertEqualsWithDelta(0.5, $c['precision'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $c['recall'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $c['f1'], 1e-9);
        $this->assertEqualsWithDelta(0.6, $c['accuracy'], 1e-9);
    }

    /**
     * SE-aware deficit labels only flag values beyond the standard-error band.
     *
     * @return void
     */
    public function test_deficit_labels_se(): void {
        $values = [-1.0, -0.2, 0.5];
        $ses = [0.3, 0.3, 0.3];

        // At 1 SE only the clear deficit (-1.0 < -0.3) is flagged.
        $this->assertSame([true, false, false], diagnostics::deficit_labels_se($values, 0.0, $ses, 1.0));
        // At 0.5 SE the borderline case (-0.2 < -0.15) is flagged too.
        $this->assertSame([true, true, false], diagnostics::deficit_labels_se($values, 0.0, $ses, 0.5));
    }

    /**
     * Agreement counts subscales recovered within the SE tolerance.
     *
     * @return void
     */
    public function test_agreement_within_se(): void {
        $true = [-1.0, 0.0, 1.0];
        $est = [-1.2, 0.1, 1.5];
        $ses = [0.3, 0.3, 0.3];

        $one = diagnostics::agreement_within_se($true, $est, $ses, 1.0);
        $this->assertSame(2, $one['within']);
        $this->assertEqualsWithDelta(2 / 3, $one['fraction'], 1e-6);

        $two = diagnostics::agreement_within_se($true, $est, $ses, 2.0);
        $this->assertSame(3, $two['within']);
    }

    /**
     * Precision@k and recall@k use a variable relevant set.
     *
     * @return void
     */
    public function test_precision_recall_at_k(): void {
        $relevant = [true, true, false, false];
        $est = [-2.0, 0.5, -1.5, 0.9];

        $atk2 = diagnostics::precision_recall_at_k($relevant, $est, 2);
        $this->assertSame(1, $atk2['hits']);
        $this->assertEqualsWithDelta(0.5, $atk2['precision'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $atk2['recall'], 1e-9);

        $atk3 = diagnostics::precision_recall_at_k($relevant, $est, 3);
        $this->assertEqualsWithDelta(1.0, $atk3['recall'], 1e-9);
    }

    /**
     * Deficit labels apply the threshold, and a clean detector scores perfectly.
     *
     * @return void
     */
    public function test_labels_and_evaluate(): void {
        $labels = diagnostics::deficit_labels(self::TRUEV, 0.0);
        $this->assertSame([true, true, false, false, false], $labels);

        $good = [-1.8, -1.2, 0.1, 0.4, 0.9];
        $summary = diagnostics::evaluate(self::TRUEV, $good, 2, 0.0);

        $this->assertEqualsWithDelta(1.0, $summary['spearman'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $summary['ndcg'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $summary['confusion']['f1'], 1e-9);
    }
}
