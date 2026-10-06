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
 * TI@n and the remaining test potential, computed by the engine itself.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * The engine's own information arithmetic, for a run's item pool.
 *
 * TI@n is the test information of the n most informative items of the pool at
 * an ability — what n items could at most contribute there. The engine computes
 * exactly that in catscale::get_testpotential(), which its test-information
 * filter calls with the items not yet played (the remaining potential). Both
 * are taken from there, with the engine's item parameters and the Fisher
 * information of each item's own model, rather than recomputed here.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine_information {
    /** @var array<string, mixed> Item pools by run, scale and model, for this request. */
    protected static array $pools = [];

    /**
     * Whether the engine offers the methods used here.
     *
     * @return bool
     */
    public static function available(): bool {
        return class_exists('\local_catquiz\catscale')
            && method_exists('\local_catquiz\catscale', 'get_testpotential')
            && class_exists('\local_catquiz\local\model\model_item_param_list');
    }

    /**
     * Forget the pools read in this request.
     *
     * @return void
     */
    public static function reset(): void {
        self::$pools = [];
        self::$prepared = [];
    }

    /**
     * The item pool of a run's scale and every scale below it, as the engine holds it.
     *
     * @param int $runid The run.
     * @param int $scaleid The catscale.
     * @param string $model The engine's model name, such as "raschbirnbaum".
     * @return mixed A model_item_param_list, or null where there is none.
     */
    public static function pool(int $runid, int $scaleid, string $model) {
        global $DB;

        $key = $runid . '|' . $scaleid . '|' . $model;
        if (array_key_exists($key, self::$pools)) {
            return self::$pools[$key];
        }
        self::$pools[$key] = null;
        if (!self::available() || $scaleid <= 0 || $model === '') {
            return null;
        }
        $contextid = (int) $DB->get_field_sql(
            'SELECT contextid FROM {local_catquizlab_scalemap} WHERE runid = ? AND catscaleid = ? ORDER BY generation DESC',
            [$runid, $scaleid],
            IGNORE_MULTIPLE
        );
        if ($contextid <= 0) {
            return null;
        }
        try {
            $ids = [$scaleid, ...\local_catquiz\catscale::get_subscale_ids($scaleid)];
            $pool = \local_catquiz\local\model\model_item_param_list::get($contextid, $model, $ids);
            self::$pools[$key] = count($pool) > 0 ? $pool : null;
        } catch (\Throwable $e) {
            self::$pools[$key] = null;
        }

        return self::$pools[$key];
    }

    /** @var array<string, array> Per pool: item id => [model instance, parameters], fetched once. */
    protected static array $prepared = [];

    /**
     * The pool's items with their model and parameters, fetched once per pool.
     *
     * The engine fetches the model instance and the parameter array for every
     * item on every call; for thousands of steps over hundreds of items that
     * is most of the cost. The values are the same; they are only fetched once.
     *
     * @param mixed $pool A model_item_param_list.
     * @return array<int, array{0: object, 1: array}>
     */
    protected static function prepared($pool): array {
        $key = spl_object_hash($pool);
        if (!isset(self::$prepared[$key])) {
            $items = [];
            foreach ($pool as $id => $item) {
                $items[(int) $id] = [
                    \local_catquiz\local\model\model_model::get_instance($item->get_model_name()),
                    $item->get_params_array(),
                ];
            }
            self::$prepared[$key] = $items;
        }

        return self::$prepared[$key];
    }

    /**
     * TI@n and the remaining potential of one step, from one pass over the pool.
     *
     * The engine's arithmetic exactly — each item's own model and
     * fisher_info(), sorted in descending order, the first n summed — as
     * catscale::get_testpotential() does it; only computed once for both
     * values instead of twice, and without copying the pool to leave out the
     * items played.
     *
     * @param float $theta The ability.
     * @param mixed $pool A model_item_param_list.
     * @param int $n How many items TI@n takes.
     * @param int[] $played Component ids of the items played so far.
     * @param int $remaining How many items may still be played.
     * @return array{tiatn: ?float, remaining: ?float, remainingmin: ?float}
     */
    public static function step(float $theta, $pool, int $n, array $played, int $remaining): array {
        if ($pool === null) {
            return ['tiatn' => null, 'remaining' => null, 'remainingmin' => null];
        }
        try {
            $information = [];
            foreach (self::prepared($pool) as $id => [$model, $params]) {
                $information[$id] = $model->fisher_info(['ability' => $theta], $params);
            }
        } catch (\Throwable $e) {
            return ['tiatn' => null, 'remaining' => null, 'remainingmin' => null];
        }

        $all = array_values($information);
        rsort($all, SORT_NUMERIC);
        $tiatn = $n < 1 ? null : (float) array_sum(array_slice($all, 0, $n));

        if ($remaining < 1) {
            return ['tiatn' => $tiatn, 'remaining' => 0.0, 'remainingmin' => 0.0];
        }
        foreach ($played as $id) {
            unset($information[(int) $id]);
        }
        $rest = array_values($information);
        rsort($rest, SORT_NUMERIC);
        $best = (float) array_sum(array_slice($rest, 0, $remaining));
        // The least the rest could add (#106): the same items, the weakest of them.
        $worst = (float) array_sum(array_slice(array_reverse($rest), 0, min($remaining, count($rest))));

        return ['tiatn' => $tiatn, 'remaining' => $best, 'remainingmin' => $worst];
    }

    /**
     * TI@n: the information of the n most informative items at an ability.
     *
     * @param float $theta The ability.
     * @param mixed $pool A model_item_param_list.
     * @param int $n How many items.
     * @return float|null
     */
    public static function ti_at_n(float $theta, $pool, int $n): ?float {
        if ($pool === null || $n < 1) {
            return null;
        }
        try {
            return (float) \local_catquiz\catscale::get_testpotential($theta, $pool, $n);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The remaining potential: the most the items not yet played could still add.
     *
     * As the engine's test-information filter computes it: the pool without
     * the items played, the best of them up to the number still allowed.
     *
     * @param float $theta The ability.
     * @param mixed $pool A model_item_param_list.
     * @param int[] $played Component ids of the items played so far.
     * @param int $remaining How many items may still be played.
     * @return float|null
     */
    public static function remaining_max(float $theta, $pool, array $played, int $remaining): ?float {
        if ($pool === null) {
            return null;
        }
        if ($remaining < 1) {
            return 0.0;
        }
        try {
            $rest = clone $pool;
            foreach ($played as $componentid) {
                if (in_array((int) $componentid, array_map('intval', $rest->get_item_ids()), true)) {
                    $rest->offsetUnset($componentid);
                }
            }

            return (float) \local_catquiz\catscale::get_testpotential($theta, $rest, $remaining);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
