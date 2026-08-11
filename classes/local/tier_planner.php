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
 * Tier planner: order experiments by their study tier.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Orders experiments and their runs by study tier (E7).
 *
 * The study proceeds in tiers: a small baseline first, then the main grid, then
 * robustness variants, then any operative runs. {@see self::tier_rank()} maps a
 * tier name to its position and {@see self::sort_experiments()} orders experiment
 * records accordingly (unknown tiers sort last, ties broken by id). Pure and
 * testable; {@see self::experiments_in_order()} reads the registry.
 */
class tier_planner {
    /** @var array<string, int> The canonical tier order. */
    public const TIER_ORDER = [
        'baseline'   => 0,
        'main'       => 1,
        'robustness' => 2,
        'operative'  => 3,
    ];

    /**
     * Rank of a tier (unknown tiers sort after the known ones).
     *
     * @param string $tier The tier name.
     * @return int
     */
    public static function tier_rank(string $tier): int {
        return self::TIER_ORDER[$tier] ?? count(self::TIER_ORDER);
    }

    /**
     * Sort experiment records by tier, then by id.
     *
     * @param array $experiments Experiment records (each with ->tier and ->id).
     * @return array The experiments in tier order.
     */
    public static function sort_experiments(array $experiments): array {
        $experiments = array_values($experiments);
        usort($experiments, static function ($a, $b) {
            $ra = self::tier_rank((string) ($a->tier ?? ''));
            $rb = self::tier_rank((string) ($b->tier ?? ''));
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            return ((int) ($a->id ?? 0)) <=> ((int) ($b->id ?? 0));
        });
        return $experiments;
    }

    /**
     * All experiments from the registry in tier order.
     *
     * @return array
     */
    public static function experiments_in_order(): array {
        global $DB;

        return self::sort_experiments($DB->get_records('local_catquizlab_experiment'));
    }
}
