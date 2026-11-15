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

namespace local_catquizlab\local;

/**
 * The log-normal distribution of item discrimination a, parametrised three ways (#159).
 *
 * a ~ Lognormal(μlog, σlog²): ln(a) is normal with mean μlog and standard deviation σlog. Then
 *   E(a) = exp(μlog + σlog²/2),  μlog = ln(E(a)) − σlog²/2,  σlog = sqrt(2 (ln(E(a)) − μlog)).
 * σlog is the standard deviation of ln(a), not of a. Any two of the three determine the third; this is the server's
 * side of that, the same arithmetic as amd/src/lognormal.js, which couples the three inputs in the editor.
 * Only μlog and σlog are stored (modelparams.discrimination.meanlog, sdlog): E(a) is derived, never a third source.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lognormal {
    /** @var float Default μlog for a new experiment. */
    public const DEFAULT_MU = 0.0;

    /** @var float Default σlog for a new experiment. */
    public const DEFAULT_SIGMA = 0.3;

    /** @var string[] The three inputs, by their form names. */
    public const FIELDS = ['mu' => 'lognormalmu', 'sigma' => 'lognormalsigma', 'mean' => 'lognormalmean'];

    /**
     * E(a) from μlog and σlog.
     *
     * @param float $mu μlog.
     * @param float $sigma σlog.
     * @return float|null Null where not defined (σlog < 0, not finite).
     */
    public static function expected(float $mu, float $sigma): ?float {
        if (!is_finite($mu) || !is_finite($sigma) || $sigma < 0) {
            return null;
        }
        $value = exp($mu + $sigma * $sigma / 2);

        return is_finite($value) ? $value : null;
    }

    /**
     * μlog from E(a) and σlog.
     *
     * @param float $mean E(a).
     * @param float $sigma σlog.
     * @return float|null Null where not solvable (E(a) ≤ 0, σlog < 0, not finite).
     */
    public static function mu_from(float $mean, float $sigma): ?float {
        if (!is_finite($mean) || !is_finite($sigma) || $mean <= 0 || $sigma < 0) {
            return null;
        }

        return log($mean) - $sigma * $sigma / 2;
    }

    /**
     * σlog from E(a) and μlog.
     *
     * @param float $mean E(a).
     * @param float $mu μlog.
     * @return float|null Null where not solvable (E(a) ≤ 0, ln(E(a)) < μlog, not finite).
     */
    public static function sigma_from(float $mean, float $mu): ?float {
        if (!is_finite($mean) || !is_finite($mu) || $mean <= 0) {
            return null;
        }
        $radicand = 2 * (log($mean) - $mu);
        if ($radicand < 0) {
            // When ln(E(a)) < μlog there is no real σlog. Rounding of a computed value is not this: below 1e-12, it is zero.
            return $radicand > -1e-12 ? 0.0 : null;
        }

        return sqrt($radicand);
    }

    /**
     * All three from the two given, or why not.
     *
     * @param array $given Two of mu, sigma, mean => value.
     * @return array{ok: bool, mu: float|null, sigma: float|null, mean: float|null, error: string} error: a string key.
     */
    public static function solve(array $given): array {
        $out = ['ok' => false, 'mu' => null, 'sigma' => null, 'mean' => null, 'error' => ''];
        foreach ($given as $key => $value) {
            if (!is_finite((float) $value)) {
                return ['error' => 'lognormal:errorfinite'] + $out;
            }
        }
        $mu = isset($given['mu']) ? (float) $given['mu'] : null;
        $sigma = isset($given['sigma']) ? (float) $given['sigma'] : null;
        $mean = isset($given['mean']) ? (float) $given['mean'] : null;
        if ($sigma !== null && $sigma < 0) {
            return ['error' => 'lognormal:errorsigma'] + $out;
        }
        if ($mean !== null && $mean <= 0) {
            return ['error' => 'lognormal:errormean'] + $out;
        }
        if ($mu !== null && $sigma !== null) {
            $mean = self::expected($mu, $sigma);
        } else if ($mean !== null && $sigma !== null) {
            $mu = self::mu_from($mean, $sigma);
        } else if ($mean !== null && $mu !== null) {
            $sigma = self::sigma_from($mean, $mu);
            if ($sigma === null) {
                return ['error' => 'lognormal:errorbelow'] + $out;
            }
        } else {
            return ['error' => 'lognormal:errortwo'] + $out;
        }
        if ($mu === null || $sigma === null || $mean === null) {
            return ['error' => 'lognormal:errorfinite'] + $out;
        }

        return ['ok' => true, 'mu' => $mu, 'sigma' => $sigma, 'mean' => $mean, 'error' => ''];
    }

    /**
     * The p-quantile of a.
     *
     * @param float $p Probability in (0, 1).
     * @param float $mu μlog.
     * @param float $sigma σlog.
     * @return float
     */
    public static function quantile(float $p, float $mu, float $sigma): float {
        return exp($mu + $sigma * self::normal_quantile($p));
    }

    /**
     * The density of a at x.
     *
     * @param float $x Where, > 0.
     * @param float $mu μlog.
     * @param float $sigma σlog, > 0.
     * @return float
     */
    public static function density(float $x, float $mu, float $sigma): float {
        if ($x <= 0 || $sigma <= 0) {
            return 0.0;
        }
        $z = (log($x) - $mu) / $sigma;

        return exp(-0.5 * $z * $z) / ($x * $sigma * sqrt(2 * M_PI));
    }

    /**
     * The standard normal quantile (Acklam's rational approximation, relative error below 1.2e-9).
     *
     * @param float $p Probability in (0, 1).
     * @return float
     */
    public static function normal_quantile(float $p): float {
        $a = [-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02, 1.383577518672690e+02,
            -3.066479806614716e+01, 2.506628277459239e+00];
        $b = [-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02, 6.680131188771972e+01,
            -1.328068155288572e+01];
        $c = [-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00, -2.549732539343734e+00,
            4.374664141464968e+00, 2.938163982698783e+00];
        $d = [7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00, 3.754408661907416e+00];
        $low = 0.02425;
        if ($p < $low) {
            $q = sqrt(-2 * log($p));

            return ((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        }
        if ($p > 1 - $low) {
            $q = sqrt(-2 * log(1 - $p));

            return -((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        }
        $q = $p - 0.5;
        $r = $q * $q;

        return ((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q
            / ((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1);
    }
}
