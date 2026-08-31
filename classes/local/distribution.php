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
 * Declarative distributions for model-dependent item parameters.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * A small declarative distribution: written in the experiment definition,
 * validated before a run starts, drawn from the seeded generator.
 *
 * Discrimination and guessing are scientific parameters. The plugin must not
 * invent values for them — a made-up a-distribution would quietly become part
 * of the published design. So the definition states the distribution, this
 * class checks it is well formed, and the pool planner draws from it. What the
 * distribution should be remains the study's decision.
 *
 * All draws go through mt_rand(), which the caller seeds, so an identical seed
 * reproduces an identical pool.
 */
class distribution {
    /** @var string[] The supported distribution families. */
    public const KINDS = ['constant', 'uniform', 'normal', 'lognormal'];

    /**
     * Validate a distribution specification.
     *
     * @param mixed $spec The specification, expected to be an array.
     * @param string $label Dotted label for error messages.
     * @return string[] Human-readable errors; empty when the spec is valid.
     */
    public static function validate($spec, string $label): array {
        $errors = [];
        if (!is_array($spec)) {
            return [get_string('def:missingblock', 'local_catquizlab', $label)];
        }
        $kind = $spec['dist'] ?? null;
        if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
            return [get_string('def:enum', 'local_catquizlab', $label . '.dist: ' . implode('|', self::KINDS))];
        }

        $numeric = function (string $key) use ($spec, $label, &$errors) {
            if (!isset($spec[$key]) || !is_numeric($spec[$key])) {
                $errors[] = get_string('def:numeric', 'local_catquizlab', $label . '.' . $key);
                return null;
            }
            return (float) $spec[$key];
        };

        switch ($kind) {
            case 'constant':
                $numeric('value');
                break;
            case 'uniform':
                $min = $numeric('min');
                $max = $numeric('max');
                if ($min !== null && $max !== null && $min > $max) {
                    $errors[] = get_string('def:mingtmax', 'local_catquizlab', $label);
                }
                break;
            case 'normal':
                $numeric('mean');
                $sd = $numeric('sd');
                if ($sd !== null && $sd < 0) {
                    $errors[] = get_string('def:negative', 'local_catquizlab', $label . '.sd');
                }
                break;
            case 'lognormal':
                $numeric('meanlog');
                $sdlog = $numeric('sdlog');
                if ($sdlog !== null && $sdlog < 0) {
                    $errors[] = get_string('def:negative', 'local_catquizlab', $label . '.sdlog');
                }
                break;
        }

        foreach (['min', 'max'] as $bound) {
            if (isset($spec['clamp'][$bound]) && !is_numeric($spec['clamp'][$bound])) {
                $errors[] = get_string('def:numeric', 'local_catquizlab', $label . '.clamp.' . $bound);
            }
        }

        return $errors;
    }

    /**
     * Draw one value from a specification using the seeded generator.
     *
     * @param array $spec A specification already checked by {@see self::validate()}.
     * @return float The drawn value, clamped when the spec asks for it.
     */
    public static function draw(array $spec): float {
        $kind = (string) ($spec['dist'] ?? 'constant');

        switch ($kind) {
            case 'uniform':
                $min = (float) ($spec['min'] ?? 0.0);
                $max = (float) ($spec['max'] ?? 1.0);
                $value = $min + ($max - $min) * self::unit();
                break;
            case 'normal':
                $value = self::normal((float) ($spec['mean'] ?? 0.0), (float) ($spec['sd'] ?? 1.0));
                break;
            case 'lognormal':
                $value = exp(self::normal((float) ($spec['meanlog'] ?? 0.0), (float) ($spec['sdlog'] ?? 1.0)));
                break;
            case 'constant':
            default:
                $value = (float) ($spec['value'] ?? 0.0);
                break;
        }

        if (isset($spec['clamp']['min']) && $value < (float) $spec['clamp']['min']) {
            $value = (float) $spec['clamp']['min'];
        }
        if (isset($spec['clamp']['max']) && $value > (float) $spec['clamp']['max']) {
            $value = (float) $spec['clamp']['max'];
        }

        return $value;
    }

    /**
     * Whether a specification is a constant, and therefore a degenerate
     * (control) parametrisation rather than a genuine distribution.
     *
     * @param mixed $spec The specification.
     * @return bool
     */
    public static function is_constant($spec): bool {
        return is_array($spec) && ($spec['dist'] ?? null) === 'constant';
    }

    /**
     * A constant specification, for defaults and control conditions.
     *
     * @param float $value The constant value.
     * @return array
     */
    public static function constant(float $value): array {
        return ['dist' => 'constant', 'value' => $value];
    }

    /**
     * A uniform variate on (0, 1) from the seeded generator.
     *
     * @return float
     */
    protected static function unit(): float {
        return (mt_rand() + 1) / (mt_getrandmax() + 2);
    }

    /**
     * A normal variate (Box-Muller) from the seeded generator.
     *
     * @param float $mean The mean.
     * @param float $sd The standard deviation.
     * @return float
     */
    protected static function normal(float $mean, float $sd): float {
        $u1 = self::unit();
        $u2 = self::unit();
        return $mean + $sd * sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
    }
}
