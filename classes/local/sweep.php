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
 * Sweep expansion: turns a factorial sweep spec into concrete runs.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Expands a declarative sweep specification into concrete, seeded runs (E1.2).
 *
 * A sweep spec is a base experiment definition (see {@see experiment_definition})
 * plus swept factors. The expander forms the cartesian product of the factor
 * levels, drops combinations matched by exclusion rules, optionally caps the
 * number of cells deterministically (a coarse fractionation), and expands each
 * surviving cell into R replications. Every replication gets a seed derived
 * deterministically from the master seed and its cell/replication, so the whole
 * sweep is reproducible. Each produced cell is validated as a full experiment
 * definition, and a capacity estimate (attempts and expected duration) is
 * returned to inform scheduling.
 *
 * The class is pure: it performs no database writes. Persisting the produced
 * runs is the job of the registry/provisioning steps (E1.3/E2).
 */
class sweep {
    /**
     * Swept factor name => dotted path into the experiment definition it sets.
     *
     * @var array
     */
    protected const FACTOR_PATHS = [
        'variant'  => ['pool', 'variant'],
        'stratum'  => ['persons', 'stratum'],
        'strategy' => ['strategy'],
    ];

    /**
     * Expand a sweep specification.
     *
     * The spec keys are: base (array, the non-swept experiment definition),
     * factors (map factorname => list of levels), exclude (list of rules, each
     * an assoc array of factorname => level; a combination is dropped when it
     * matches all keys of any rule), replications (int >= 1), seed (int master
     * seed), estimatedsecondsperattempt (int, for the capacity estimate) and
     * maxcells (int|null, deterministic cap on the number of cells).
     *
     * @param array $spec The sweep specification.
     * @return array{cells: array[], runs: array[], excluded: int, capacity: array} The expansion.
     * @throws \invalid_parameter_exception On an unknown or empty factor.
     */
    public static function expand(array $spec): array {
        $factors = $spec['factors'] ?? [];
        foreach ($factors as $name => $levels) {
            if (!isset(self::FACTOR_PATHS[$name])) {
                throw new \invalid_parameter_exception(
                    get_string('sweep:unknownfactor', 'local_catquizlab', $name)
                );
            }
            if (!is_array($levels) || $levels === []) {
                throw new \invalid_parameter_exception(
                    get_string('sweep:emptyfactor', 'local_catquizlab', $name)
                );
            }
        }

        $base = $spec['base'] ?? [];
        $replications = max(1, (int) ($spec['replications'] ?? 1));
        $masterseed = (int) ($spec['seed'] ?? 0);
        $secondsperattempt = max(0, (int) ($spec['estimatedsecondsperattempt'] ?? 120));
        $maxcells = isset($spec['maxcells']) ? (int) $spec['maxcells'] : null;

        [$kept, $excluded] = self::select_combinations($factors, $spec['exclude'] ?? [], $maxcells);

        // Build cells and their runs.
        $cells = [];
        $runs = [];
        foreach ($kept as $combo) {
            $cellkey = self::cellkey($combo);
            $definition = self::apply_factors($base, $combo);

            $validation = (new experiment_definition($definition))->validate();

            $cells[] = [
                'cellkey'    => $cellkey,
                'factors'    => $combo,
                'tier'       => $definition['tier'] ?? 'baseline',
                'valid'      => $validation['valid'],
                'errors'     => $validation['errors'],
            ];

            for ($r = 1; $r <= $replications; $r++) {
                $runs[] = [
                    'cellkey'     => $cellkey,
                    'replication' => $r,
                    'seed'        => self::derive_seed($masterseed, $cellkey, $r),
                    'definition'  => $definition,
                ];
            }
        }

        return [
            'cells'    => $cells,
            'runs'     => $runs,
            'excluded' => $excluded,
            'capacity' => self::estimate_capacity($base, $cells, $replications, $secondsperattempt),
        ];
    }

    /**
     * Form the factor product, drop excluded combinations and apply the cell cap.
     *
     * @param array $factors Factor name => levels.
     * @param array $excluderules Exclusion rules.
     * @param int|null $maxcells Optional deterministic cap on the number of cells.
     * @return array A pair: the kept combinations (sorted by cell key) and the excluded count.
     */
    protected static function select_combinations(array $factors, array $excluderules, ?int $maxcells): array {
        $factornames = array_keys($factors);
        sort($factornames);
        $combinations = self::product($factors, $factornames);

        $excluded = 0;
        $kept = [];
        foreach ($combinations as $combo) {
            if (self::is_excluded($combo, $excluderules)) {
                $excluded++;
                continue;
            }
            $kept[] = $combo;
        }

        usort($kept, static function (array $a, array $b): int {
            return strcmp(self::cellkey($a), self::cellkey($b));
        });

        if ($maxcells !== null && $maxcells >= 0 && count($kept) > $maxcells) {
            $kept = array_slice($kept, 0, $maxcells);
        }

        return [$kept, $excluded];
    }

    /**
     * Cartesian product of factor levels.
     *
     * @param array $factors Factor name => levels.
     * @param string[] $factornames Factor names in the desired iteration order.
     * @return array List of combinations (factorname => level).
     */
    protected static function product(array $factors, array $factornames): array {
        $result = [[]];
        foreach ($factornames as $name) {
            $next = [];
            foreach ($result as $partial) {
                foreach ($factors[$name] as $level) {
                    $next[] = $partial + [$name => $level];
                }
            }
            $result = $next;
        }
        return $result;
    }

    /**
     * Whether a combination matches any exclusion rule.
     *
     * A rule matches when every key it specifies equals the combination's value.
     *
     * @param array $combo The combination.
     * @param array $rules Exclusion rules.
     * @return bool
     */
    protected static function is_excluded(array $combo, array $rules): bool {
        foreach ($rules as $rule) {
            if (!is_array($rule) || $rule === []) {
                continue;
            }
            $matches = true;
            foreach ($rule as $key => $value) {
                if (!array_key_exists($key, $combo) || $combo[$key] !== $value) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return true;
            }
        }
        return false;
    }

    /**
     * Stable, canonical key for a combination (sorted factorname=level pairs).
     *
     * @param array $combo The combination.
     * @return string
     */
    protected static function cellkey(array $combo): string {
        $parts = [];
        foreach ($combo as $name => $level) {
            $parts[] = $name . '=' . $level;
        }
        sort($parts);
        return implode(';', $parts);
    }

    /**
     * Merge a combination's factor levels into the base definition.
     *
     * @param array $base The base experiment definition.
     * @param array $combo The combination.
     * @return array The per-cell experiment definition.
     */
    protected static function apply_factors(array $base, array $combo): array {
        $definition = $base;
        foreach ($combo as $name => $level) {
            $path = self::FACTOR_PATHS[$name];
            if (count($path) === 1) {
                $definition[$path[0]] = $level;
            } else {
                [$outer, $inner] = $path;
                $definition[$outer] = ($definition[$outer] ?? []);
                $definition[$outer][$inner] = $level;
            }
        }
        return $definition;
    }

    /**
     * Derive a deterministic, reproducible seed for one replication of a cell.
     *
     * @param int $masterseed The sweep master seed.
     * @param string $cellkey The cell key.
     * @param int $replication The 1-based replication index.
     * @return int A non-negative 31-bit seed.
     */
    protected static function derive_seed(int $masterseed, string $cellkey, int $replication): int {
        return (int) (crc32($masterseed . '|' . $cellkey . '|' . $replication) & 0x7fffffff);
    }

    /**
     * Estimate the capacity a sweep needs.
     *
     * Attempts per run equal the number of simulated persons (persons.count in
     * the base). Total attempts = cells x replications x persons. Expected
     * duration multiplies that by the assumed seconds per attempt.
     *
     * @param array $base The base definition.
     * @param array[] $cells The produced cells.
     * @param int $replications Replications per cell.
     * @param int $secondsperattempt Assumed seconds per attempt.
     * @return array{cells: int, runs: int, attempts: int, secondsperattempt: int, estimatedseconds: int}
     */
    protected static function estimate_capacity(array $base, array $cells, int $replications, int $secondsperattempt): array {
        $personspercell = max(0, (int) ($base['persons']['count'] ?? 0));
        $cellcount = count($cells);
        $runcount = $cellcount * $replications;
        $attempts = $runcount * $personspercell;

        return [
            'cells'             => $cellcount,
            'runs'              => $runcount,
            'attempts'          => $attempts,
            'secondsperattempt' => $secondsperattempt,
            'estimatedseconds'  => $attempts * $secondsperattempt,
        ];
    }
}
