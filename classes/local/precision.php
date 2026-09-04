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

namespace local_catquizlab\local;

/**
 * Standard errors computed from the items a person actually saw.
 *
 * The engine records a standard error on its own attempt row but leaves
 * `local_catquiz_personparams.standarderror` empty, so nothing downstream could
 * say how precise an estimate was — and a diagnosis without a precision is a
 * number without a claim.
 *
 * The lab can compute it, because it knows more than the engine does: it has
 * the true item parameters it generated. For a dichotomous IRT model the Fisher
 * information of one item at an ability is
 *
 *     I(θ) = a² · (P − c)² · (1 − P) / ((1 − c)² · P)
 *
 * which for the 2PL case (c = 0) reduces to the familiar a²·P·(1−P). Test
 * information is the sum over the administered items, and the standard error is
 * 1/√I. This is the same identity the feasibility view already uses in the
 * other direction, where a precision target is converted into the information
 * it demands.
 *
 * Two limits are worth stating plainly. The information is evaluated at the
 * *estimated* ability, which is what an operational test can do; evaluating it
 * at the true ability would produce a figure no real test could report. And it
 * assumes the model the items were generated under, so it measures the
 * precision the design implies rather than the engine's own internal estimate.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class precision {
    /**
     * The Fisher information of one item at an ability.
     *
     * @param float $theta The ability the information is evaluated at.
     * @param float $difficulty The item's difficulty.
     * @param float $discrimination The item's discrimination.
     * @param float $guessing The item's guessing parameter.
     * @return float The information, never negative.
     */
    public static function item_information(
        float $theta,
        float $difficulty,
        float $discrimination = 1.0,
        float $guessing = 0.0
    ): float {
        $p = response_oracle::probability($theta, $difficulty, $discrimination, $guessing);

        // At the extremes the probability saturates and the item carries no
        // information; returning zero is both correct and avoids a division by
        // something arbitrarily close to zero.
        if ($p <= 0.0 || $p >= 1.0 || $guessing >= 1.0) {
            return 0.0;
        }

        $numerator = ($discrimination ** 2) * (($p - $guessing) ** 2) * (1.0 - $p);
        $denominator = ((1.0 - $guessing) ** 2) * $p;

        return $denominator > 0.0 ? max(0.0, $numerator / $denominator) : 0.0;
    }

    /**
     * The test information of a set of items at an ability.
     *
     * @param array $items Items with difficulty, discrimination and guessing.
     * @param float $theta The ability the information is evaluated at.
     * @return float The summed information.
     */
    public static function test_information(array $items, float $theta): float {
        $total = 0.0;
        foreach ($items as $item) {
            $total += self::item_information(
                $theta,
                (float) ($item['difficulty'] ?? 0.0),
                (float) ($item['discrimination'] ?? 1.0),
                (float) ($item['guessing'] ?? 0.0)
            );
        }

        return $total;
    }

    /**
     * The standard error a given amount of test information implies.
     *
     * @param float $information The test information.
     * @return float|null The standard error, or null when no item carried any.
     */
    public static function standard_error(float $information): ?float {
        return $information > 0.0 ? 1.0 / sqrt($information) : null;
    }

    /**
     * The standard error of an attempt, globally and per scale.
     *
     * @param int $runid The run the attempt belongs to.
     * @param int[] $questionids The questions administered, in order.
     * @param float $theta The estimated global ability.
     * @param array $scaleabilities Estimated ability per engine scale id.
     * @return array{global: float|null, information: float, scales: array, nitems: int}
     */
    public static function for_attempt(
        int $runid,
        array $questionids,
        float $theta,
        array $scaleabilities = []
    ): array {
        $items = self::items_of($runid, $questionids);

        $information = self::test_information($items, $theta);

        // Per scale, only the items of that scale contribute, and each is
        // evaluated at that scale's own estimate where one exists. A subscale
        // estimate is only as precise as the items that informed it.
        $byscale = [];
        foreach ($items as $item) {
            $scaleid = (int) $item['catscaleid'];
            $byscale[$scaleid][] = $item;
        }

        $scales = [];
        foreach ($byscale as $scaleid => $scaleitems) {
            $at = array_key_exists($scaleid, $scaleabilities) ? (float) $scaleabilities[$scaleid] : $theta;
            $scaleinformation = self::test_information($scaleitems, $at);
            $scales[$scaleid] = [
                'information' => round($scaleinformation, 6),
                'se'          => self::standard_error($scaleinformation),
                'nitems'      => count($scaleitems),
            ];
        }

        return [
            'global'      => self::standard_error($information),
            'information' => round($information, 6),
            'scales'      => $scales,
            'nitems'      => count($items),
        ];
    }

    /**
     * The ground-truth parameters of the questions a person saw.
     *
     * The lab's own record is used rather than the engine's copy: it is what the
     * items were generated from, and for a disturbed pool the two differ on
     * purpose — that difference is the experimental condition.
     *
     * @param int $runid The run.
     * @param int[] $questionids The question ids.
     * @return array[] One entry per question that belongs to the run.
     */
    protected static function items_of(int $runid, array $questionids): array {
        global $DB;

        $questionids = array_values(array_unique(array_map('intval', $questionids)));
        if ($questionids === []) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED, 'q');
        $params['runid'] = $runid;
        $rows = $DB->get_records_select(
            'local_catquizlab_item',
            'runid = :runid AND questionid ' . $insql,
            $params,
            '',
            'id, questionid, assignedcatscaleid, truedifficulty, discrimination, guessing'
        );

        $byquestion = [];
        foreach ($rows as $row) {
            $byquestion[(int) $row->questionid] = [
                'difficulty'     => (float) $row->truedifficulty,
                'discrimination' => (float) $row->discrimination,
                'guessing'       => (float) $row->guessing,
                'catscaleid'     => (int) $row->assignedcatscaleid,
            ];
        }

        // Kept in the order they were administered, so a caller can compute a
        // running precision over the attempt.
        $items = [];
        foreach ($questionids as $questionid) {
            if (isset($byquestion[$questionid])) {
                $items[] = $byquestion[$questionid];
            }
        }

        return $items;
    }
}
