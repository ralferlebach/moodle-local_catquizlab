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
 * The answer a digital twin gives to an item in a run (#155).
 *
 * Which ability governs the item is a question about the item's true content, not about the tag it was imported
 * with; the probability comes from the true parameters, not the stored ones. Under a tagging or calibration error the
 * two differ, and that difference is the condition being tested. The random number comes from the run's RNG schema:
 * schema 2 (runs created since 0.7.61) draws one per replication, twin and canonical item, so the same twin answers
 * the same item the same way in every strategy cell; schema 1 (runs before) drew per run, person row and question,
 * and is kept for them unchanged.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class twin_response {
    /**
     * The seed of an answer, and under which schema it was drawn.
     *
     * @param \stdClass $run The run (id, masterseed, replication, manifestjson).
     * @param \stdClass $person The person (id, twinid).
     * @param \stdClass|null $truth The item's ground truth (itemkey), null for an item the lab did not make.
     * @param int $questionid The question.
     * @return array{seed: int, schema: int}
     */
    public static function seed(\stdClass $run, \stdClass $person, ?\stdClass $truth, int $questionid): array {
        $master = (int) ($run->masterseed ?? 0);
        $manifest = json_decode((string) ($run->manifestjson ?? ''), true) ?: [];
        $schema = seed_domains::rng_schema($manifest);
        $twinid = (string) ($person->twinid ?? '');
        $itemkey = (string) ($truth->itemkey ?? '');
        if ($schema >= 2 && $twinid !== '' && $itemkey !== '') {
            return ['seed' => seed_domains::response_crn($master, (int) ($run->replication ?? 1), $twinid, $itemkey),
                'schema' => 2];
        }

        return ['seed' => seed_domains::response($master, (int) $run->id, (int) $person->id, $questionid), 'schema' => 1];
    }

    /**
     * The answer: the chosen category and its score fraction.
     *
     * @param \stdClass $run The run (id, masterseed, replication, manifestjson).
     * @param \stdClass $person The person (id, twinid, profilejson).
     * @param \stdClass|null $truth The item's ground truth row, or null.
     * @param array $item The engine's item (catscaleid, difficulty, discrimination, guessing, model, steps), used
     *      where there is no ground truth.
     * @param int $questionid The question.
     * @return array{fraction: float, choice: int, schema: int}
     */
    public static function answer(\stdClass $run, \stdClass $person, ?\stdClass $truth, array $item, int $questionid): array {
        $profile = json_decode((string) ($person->profilejson ?? ''), true) ?: [];
        $draw = self::draw($run, $person, $profile, $truth, $item, $questionid);

        return ['fraction' => $draw['fraction'], 'choice' => $draw['choice'], 'schema' => $draw['schema']];
    }

    /**
     * The answer and everything it was drawn from, so that somebody else can check it (#155 AK5): the seed and its
     * schema, the common random number, the true ability the item asked for, the true category probabilities and the
     * category drawn. The same computation as answer(); the export reads it from here.
     *
     * @param \stdClass $run The run (id, masterseed, replication, manifestjson).
     * @param \stdClass $person The person (id, twinid).
     * @param array $profile The person's decoded profile.
     * @param \stdClass|null $truth The item's ground truth row, or null.
     * @param array $item The engine's item, used where there is no ground truth.
     * @param int $questionid The question.
     * @return array{fraction: float, choice: int, category: int, probabilities: float[], uniform: float,
     *     polytomous: bool, ability: float, seed: int, schema: int}
     */
    public static function draw(
        \stdClass $run,
        \stdClass $person,
        array $profile,
        ?\stdClass $truth,
        array $item,
        int $questionid
    ): array {
        $categoryindex = null;
        $subscaleindex = null;
        if ($truth !== null && (int) $truth->truecategory > 0) {
            $categoryindex = (int) $truth->truecategory;
            $subscaleindex = (int) $truth->truesubscale;
        } else {
            $mapping = scale_provisioner::mapping_for((int) $run->id, (int) ($item['catscaleid'] ?? 0));
            $categoryindex = $mapping['categoryindex'] ?? null;
            $subscaleindex = $mapping['subscaleindex'] ?? null;
        }

        $ability = response_oracle::ability_for($profile, $categoryindex, $subscaleindex);
        $ability = response_oracle::deviant_ability($ability, $profile['deviance'] ?? null, $categoryindex, $subscaleindex);

        if ($truth !== null) {
            $item['difficulty'] = (float) $truth->truedifficulty;
            $item['discrimination'] = (float) $truth->discrimination;
            $item['guessing'] = (float) $truth->guessing;
            $item['model'] = (string) $truth->model;
            if (!empty($truth->stepsjson)) {
                $item['steps'] = json_decode((string) $truth->stepsjson, true) ?: [];
            }
        }

        $seed = self::seed($run, $person, $truth, $questionid);

        return response_oracle::explain_item($ability, $item, $seed['seed'])
            + ['ability' => $ability, 'seed' => $seed['seed'], 'schema' => $seed['schema']];
    }
}
