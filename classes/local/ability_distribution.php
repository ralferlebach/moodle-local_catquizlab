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

/**
 * How simulated abilities are distributed, and within which range.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * The ground-truth ability distribution and the scale range, in one place (#102, #105).
 *
 * The simulated abilities came from N(μ = 0, σ = 2) without bounds, while the
 * CAT scales this plugin provisions were fixed at [−3, +3]: about 13 % of the
 * simulated people lay where the engine could not place them, and the results
 * showed estimates saturating at ±3 — an effect of two hard-wired numbers, not
 * of a strategy. The distribution, its parameters and the range are now part of
 * the experiment: one range for the ground truth and for every provisioned
 * scale, a distribution named rather than implied, and no silent clamping.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ability_distribution {
    /** @var string Normal, unbounded: values outside the range are possible, and reported. */
    public const NORMAL = 'normal';

    /** @var string Normal, truncated to the range: a draw outside it is drawn again. */
    public const TRUNCATED_NORMAL = 'truncated_normal';

    /** @var string Uniform over the range; the standard deviation does not apply. */
    public const UNIFORM = 'uniform';

    /** @var string[] The distributions offered. */
    public const DISTRIBUTIONS = [self::TRUNCATED_NORMAL, self::NORMAL, self::UNIFORM];

    /**
     * What a definition without these settings did until 0.7.6, kept for them.
     *
     * Their results were produced this way; reading them otherwise would make
     * them irreproducible. They are recorded explicitly from now on, and the
     * validation warns about the share outside the range.
     */
    public const LEGACY = [
        'distribution' => self::NORMAL, 'mean' => 0.0, 'sd' => 2.0, 'min' => -3.0, 'max' => 3.0,
    ];

    /**
     * What a new experiment starts with: the usual IRT scaling of abilities,
     * truncated to the range the plugin has always provisioned its scales with.
     */
    public const RECOMMENDED = [
        'distribution' => self::TRUNCATED_NORMAL, 'mean' => 0.0, 'sd' => 1.0, 'min' => -3.0, 'max' => 3.0,
    ];

    /** @var float A share outside the range from which a warning is given. */
    public const WARN_SHARE = 0.001;

    /**
     * The distribution of a definition, filled from the legacy behaviour where unset.
     *
     * @param array $definition The definition.
     * @return array{distribution: string, mean: float, sd: float, min: float, max: float, explicit: bool}
     */
    public static function of(array $definition): array {
        $persons = (array) ($definition['persons'] ?? []);
        $range = (array) ($persons['abilityrange'] ?? []);
        $explicit = isset($persons['distribution']) || isset($persons['abilityrange']);

        return [
            'distribution' => (string) ($persons['distribution'] ?? self::LEGACY['distribution']),
            'mean'         => (float) ($persons['abilitymean'] ?? self::LEGACY['mean']),
            'sd'           => (float) ($persons['abilitysd'] ?? self::LEGACY['sd']),
            'min'          => (float) ($range['min'] ?? self::LEGACY['min']),
            'max'          => (float) ($range['max'] ?? self::LEGACY['max']),
            'explicit'     => $explicit,
        ];
    }

    /**
     * Validate the persons block's distribution settings.
     *
     * @param array $persons The persons block.
     * @param string[] $errors Errors, by reference.
     * @param string[] $warnings Warnings, by reference.
     * @return void
     */
    public static function validate(array $persons, array &$errors, array &$warnings): void {
        $component = 'local_catquizlab';
        $params = self::of(['persons' => $persons]);

        if (!in_array($params['distribution'], self::DISTRIBUTIONS, true)) {
            $errors[] = get_string('def:distribution', $component, implode(', ', self::DISTRIBUTIONS));
            return;
        }
        if ($params['distribution'] !== self::UNIFORM && !($params['sd'] > 0)) {
            $errors[] = get_string('def:abilitysd', $component);
        }
        if (!($params['min'] < $params['max'])) {
            $errors[] = get_string('def:abilityrange', $component);
            return;
        }
        if ($params['mean'] < $params['min'] || $params['mean'] > $params['max']) {
            $errors[] = get_string('def:abilitymeanrange', $component);
        }

        // A normal distribution is unbounded: say how much of it falls where
        // the engine cannot place it, before a single person is generated.
        $outside = self::share_outside($params);
        if ($outside >= self::WARN_SHARE) {
            $warnings[] = get_string('def:abilityoutside', $component, (object) [
                'share' => format_float(100 * $outside, 1),
                'range' => self::range_text($params),
            ]);
        }
    }

    /**
     * The expected share of global abilities outside the range.
     *
     * Zero where the distribution cannot leave it (truncated, uniform).
     *
     * @param array $params What of() returned.
     * @return float
     */
    public static function share_outside(array $params): float {
        if ($params['distribution'] !== self::NORMAL || !($params['sd'] > 0)) {
            return 0.0;
        }

        return self::cdf(($params['min'] - $params['mean']) / $params['sd'])
            + (1.0 - self::cdf(($params['max'] - $params['mean']) / $params['sd']));
    }

    /**
     * Draw a global ability.
     *
     * Truncated: drawn again until it lies within the range — a truncated
     * normal distribution, not a clamped one, which would pile people up at
     * the bounds.
     *
     * @param array $params What of() returned.
     * @return float
     */
    public static function draw(array $params): float {
        if ($params['distribution'] === self::UNIFORM) {
            return $params['min'] + ($params['max'] - $params['min']) * (mt_rand() / mt_getrandmax());
        }
        $value = self::normal($params['mean'], $params['sd']);
        if ($params['distribution'] === self::TRUNCATED_NORMAL) {
            $value = self::within($params, fn(): float => self::normal($params['mean'], $params['sd']), $value);
        }

        return $value;
    }

    /**
     * An ability anchored on another, with a deviation, kept within the range where truncated.
     *
     * @param array $params What of() returned.
     * @param float $anchor The global or category ability.
     * @param float $sd The deviation's standard deviation.
     * @return float
     */
    public static function deviate(array $params, float $anchor, float $sd): float {
        if (!($sd > 0)) {
            return $anchor;
        }
        $value = $anchor + self::normal(0.0, $sd);
        if ($params['distribution'] !== self::NORMAL) {
            $value = self::within($params, fn(): float => $anchor + self::normal(0.0, $sd), $value);
        }

        return $value;
    }

    /**
     * Whether a value lies within the range.
     *
     * @param array $params What of() returned.
     * @param float $value The value.
     * @return bool
     */
    public static function inside(array $params, float $value): bool {
        return $value >= $params['min'] && $value <= $params['max'];
    }

    /**
     * The distribution in words, with explicit parameter names.
     *
     * @param array $params What of() returned.
     * @return string
     */
    public static function describe(array $params): string {
        $component = 'local_catquizlab';
        $a = (object) [
            'distribution' => get_string('distribution:' . $params['distribution'], $component),
            'mean'         => format_float($params['mean'], 2),
            'sd'           => format_float($params['sd'], 2),
            'range'        => self::range_text($params),
        ];

        return $params['distribution'] === self::UNIFORM
            ? get_string('distribution:describeuniform', $component, $a)
            : get_string('distribution:describe', $component, $a);
    }

    /**
     * The range as text.
     *
     * @param array $params What of() returned.
     * @return string
     */
    public static function range_text(array $params): string {
        return '[' . format_float($params['min'], 2) . ', ' . format_float($params['max'], 2) . ']';
    }

    /**
     * Draw again until the value lies within the range.
     *
     * @param array $params What of() returned.
     * @param callable $redraw A new draw.
     * @param float $value The first draw.
     * @return float
     */
    protected static function within(array $params, callable $redraw, float $value): float {
        // With the mean inside the range (validated), acceptance is at worst a
        // few per cent; a thousand rounds not being enough means something
        // else is wrong, and that is reported rather than papered over.
        for ($round = 0; !self::inside($params, $value); $round++) {
            if ($round >= 1000) {
                throw new \coding_exception('No draw within ' . self::range_text($params) . ' in 1000 rounds.');
            }
            $value = $redraw();
        }

        return $value;
    }

    /**
     * A normal draw (Box–Muller), from the current mt_rand stream.
     *
     * @param float $mean The mean.
     * @param float $sd The standard deviation.
     * @return float
     */
    protected static function normal(float $mean, float $sd): float {
        $u1 = (mt_rand() + 1) / (mt_getrandmax() + 2);
        $u2 = (mt_rand() + 1) / (mt_getrandmax() + 2);

        return $mean + $sd * sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
    }

    /**
     * The standard normal cumulative distribution function.
     *
     * Abramowitz & Stegun 7.1.26 for erf; absolute error below 1.5e-7,
     * ample for a warning threshold of a tenth of a per cent.
     *
     * @param float $z The standardised value.
     * @return float
     */
    public static function cdf(float $z): float {
        $x = abs($z) / M_SQRT2;
        $t = 1.0 / (1.0 + 0.3275911 * $x);
        $erf = 1.0 - (((((1.061405429 * $t - 1.453152027) * $t) + 1.421413741) * $t - 0.284496736) * $t
            + 0.254829592) * $t * exp(-$x * $x);

        return $z >= 0 ? 0.5 * (1.0 + $erf) : 0.5 * (1.0 - $erf);
    }
}
