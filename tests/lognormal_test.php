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

use local_catquizlab\form\experiment_form;
use local_catquizlab\local\lognormal;

/**
 * Log-normal discrimination: μlog, σlog and E(a), any two giving the third (#159).
 *
 * Tolerances: conversions 1e-6 absolute (the reference values have six decimals), round trips 1e-9 to 1e-12;
 * quantiles 1e-8 absolute (Acklam's approximation is good to about 1.2e-9 relative); the density's integral 1e-4.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\lognormal
 * @covers     \local_catquizlab\form\experiment_form
 */
final class lognormal_test extends \advanced_testcase {
    /**
     * DoD-02: the three reference cases of the issue.
     *
     * @return void
     */
    public function test_the_reference_cases(): void {
        $this->assertEqualsWithDelta(1.046028, lognormal::expected(0.0, 0.3), 1e-6);
        $this->assertEqualsWithDelta(1.343126, lognormal::expected(0.25, 0.3), 1e-6);
        $this->assertEqualsWithDelta(0.360465, lognormal::mu_from(1.5, 0.3), 1e-6);
    }

    /**
     * DoD-01: every direction, and back again, to full precision.
     *
     * @return void
     */
    public function test_all_three_directions_round_trip(): void {
        foreach ([[0.0, 0.3], [0.25, 0.3], [-1.2, 0.05], [2.0, 1.5], [0.0, 0.0]] as [$mu, $sigma]) {
            $mean = lognormal::expected($mu, $sigma);
            $this->assertEqualsWithDelta($mu, lognormal::mu_from($mean, $sigma), 1e-12);
            $this->assertEqualsWithDelta($sigma, lognormal::sigma_from($mean, $mu), 1e-6);
            $pairs = [['mu' => $mu, 'sigma' => $sigma], ['mean' => $mean, 'sigma' => $sigma], ['mean' => $mean, 'mu' => $mu]];
            foreach ($pairs as $given) {
                $solved = lognormal::solve($given);
                $this->assertTrue($solved['ok']);
                $this->assertEqualsWithDelta($mu, $solved['mu'], 1e-9);
                $this->assertEqualsWithDelta($sigma, $solved['sigma'], 1e-6);
                $this->assertEqualsWithDelta($mean, $solved['mean'], 1e-9);
            }
        }
    }

    /**
     * AK-07: what has no solution is said, never corrected silently.
     *
     * @return void
     */
    public function test_unsolvable_inputs_are_said(): void {
        $this->assertSame('lognormal:errorsigma', lognormal::solve(['mu' => 0.0, 'sigma' => -0.1])['error']);
        $this->assertSame('lognormal:errormean', lognormal::solve(['mean' => 0.0, 'sigma' => 0.3])['error']);
        $this->assertSame('lognormal:errormean', lognormal::solve(['mean' => -1.0, 'mu' => 0.0])['error']);
        $this->assertSame('lognormal:errorbelow', lognormal::solve(['mean' => 1.0, 'mu' => 0.5])['error']);
        $this->assertSame('lognormal:errorfinite', lognormal::solve(['mu' => INF, 'sigma' => 0.3])['error']);
        $this->assertSame('lognormal:errortwo', lognormal::solve(['mu' => 0.0])['error']);
        // Where ln(E(a)) = μlog exactly: σlog 0, the degenerate case, not an error.
        $this->assertSame(0.0, lognormal::solve(['mean' => exp(0.4), 'mu' => 0.4])['sigma']);
    }

    /**
     * DoD-04: quantiles, median and density against independent reference values.
     *
     * @return void
     */
    public function test_quantiles_and_density_against_references(): void {
        // Standard normal quantiles (tables): 1.6448536269514722 at 0.95, 2.5758293035489 at 0.995.
        $this->assertEqualsWithDelta(1.6448536269514722, lognormal::normal_quantile(0.95), 1e-8);
        $this->assertEqualsWithDelta(-1.6448536269514722, lognormal::normal_quantile(0.05), 1e-8);
        $this->assertEqualsWithDelta(2.5758293035489, lognormal::normal_quantile(0.995), 1e-8);
        $this->assertEqualsWithDelta(0.0, lognormal::normal_quantile(0.5), 1e-12);
        // Defaults 0 / 0.3: 5th and 95th percentile exp(∓0.3 · 1.6448536), median 1.
        $this->assertEqualsWithDelta(exp(-0.3 * 1.6448536269514722), lognormal::quantile(0.05, 0.0, 0.3), 1e-8);
        $this->assertEqualsWithDelta(exp(0.3 * 1.6448536269514722), lognormal::quantile(0.95, 0.0, 0.3), 1e-8);
        $this->assertEqualsWithDelta(1.0, lognormal::quantile(0.5, 0.0, 0.3), 1e-12);
        // The density integrates to 1 (trapezoid over 0..20) and peaks at the mode exp(μ − σ²).
        $sum = 0.0;
        $h = 0.0005;
        for ($x = $h; $x < 20; $x += $h) {
            $sum += lognormal::density($x, 0.0, 0.3) * $h;
        }
        $this->assertEqualsWithDelta(1.0, $sum, 1e-4);
        $mode = exp(0.0 - 0.09);
        $this->assertGreaterThan(lognormal::density($mode * 1.01, 0.0, 0.3), lognormal::density($mode, 0.0, 0.3));
        $this->assertGreaterThan(lognormal::density($mode * 0.99, 0.0, 0.3), lognormal::density($mode, 0.0, 0.3));
    }

    /**
     * AK-02, AK-16, AK-17: a new experiment starts at 0 / 0.3; the two inputs typed last decide; only meanlog and sdlog
     * are stored; opening it again shows the same three values.
     *
     * @return void
     */
    public function test_the_form_stores_two_and_shows_three(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $base = ['name' => 'L', 'model' => '2pl', 'discriminationdist' => 'lognormal', 'discriminationa' => '9',
            'discriminationb' => '9'];
        // E(a) 1.5 and σlog 0.3 typed: μlog follows.
        $definition = experiment_form::to_definition($base + ['lognormalmu' => '7', 'lognormalsigma' => '0.3',
            'lognormalmean' => '1.5', 'lognormalsource' => 'sigma,mean']);
        $discrimination = $definition['modelparams']['discrimination'];
        $this->assertSame('lognormal', $discrimination['dist']);
        $this->assertEqualsWithDelta(0.360465, $discrimination['meanlog'], 1e-6);
        $this->assertEqualsWithDelta(0.3, $discrimination['sdlog'], 1e-12);
        $this->assertArrayNotHasKey('mean', $discrimination, 'no third stored value');
        $this->assertArrayNotHasKey('expected', $discrimination);

        // Opened again: the stored two and the E(a) they make.
        $data = experiment_form::to_form_data($definition, 0);
        $this->assertEqualsWithDelta(0.360465, unformat_float($data['lognormalmu']), 1e-6);
        $this->assertEqualsWithDelta(1.5, unformat_float($data['lognormalmean']), 1e-6);
        $this->assertSame('mu,sigma', $data['lognormalsource']);
    }

    /**
     * AK-07: the form refuses to save what has no solution, on the field it concerns.
     *
     * @return void
     */
    public function test_the_form_refuses_unsolvable_pairs(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $form = new experiment_form(null, ['id' => 0]);
        $data = ['name' => 'L', 'model' => '2pl', 'discriminationdist' => 'lognormal', 'lognormalmu' => '0.5',
            'lognormalsigma' => '0.3', 'lognormalmean' => '1', 'lognormalsource' => 'mu,mean'];
        $errors = $form->validation($data, []);
        $this->assertArrayHasKey('lognormalsigma', $errors);
        $this->assertSame(get_string('lognormal:errorbelow', 'local_catquizlab'), $errors['lognormalsigma']);
        $data['lognormalsource'] = 'mu,sigma';
        $data['lognormalsigma'] = '-1';
        $this->assertArrayHasKey('lognormalsigma', $form->validation($data, []));
        $data['lognormalsigma'] = 'abc';
        $this->assertSame(get_string('form:notanumber', 'local_catquizlab'), $form->validation($data, [])['lognormalsigma']);
    }
}
