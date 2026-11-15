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
 * The same item across runs, proven (#154).
 *
 * Every run materialises its own Moodle questions, so a question id says nothing about which item it is in another
 * run. The canonical item key does: replication, pool condition (the model), the item's true category and subscale
 * and its index in the blueprint. It depends on nothing a run's provisioning decides — not the order runs are set
 * up, a retry, the database's ids or a restore — only on the scientific definition and its seed, the same things the
 * pool's seed depends on (seed_domains::pool()).
 *
 * Two fingerprints per item make the guarantee checkable:
 *
 * - **contentfp** — the item as generated: key, name, model, true parameters and true placement. Equal in every
 *   run of a replication and pool condition, whatever the strategy; equal across pool variants that only change
 *   what the engine is told (a calibration or tagging error).
 * - **fingerprint** — the item as realised: the content plus the stored difficulty, the assigned scale, the
 *   injected errors and the question text and options a person sees. Equal across strategies; different, on
 *   purpose, where a variant changes the item or what the engine is told about it.
 *
 * The pool's fingerprint is the hash of all its (key, fingerprint) pairs, sorted. compare() groups an experiment's
 * runs by the factors the pool depends on and says, group by group, whether their pools are identical, which items
 * differ, which keys are missing or duplicated, and which runs predate the keys.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class item_identity {
    /** @var int The schema of keys and fingerprints; 0 for runs materialised before 0.7.61. */
    public const SCHEMA = 1;

    /** @var int Items listed per difference in compare(): enough to see the pattern, bounded for large pools. */
    public const LISTED = 20;

    /**
     * The canonical key of an item.
     *
     * @param int $replication The replication.
     * @param string $poolcondition The pool condition (the model key).
     * @param int $category The true category index in the blueprint.
     * @param int $subscale The true subscale index in the blueprint.
     * @param int $index The item's index in its subscale.
     * @return string
     */
    public static function key(int $replication, string $poolcondition, int $category, int $subscale, int $index): string {
        return sprintf('r%03d-%s-c%02d-s%02d-i%04d', $replication, $poolcondition, $category, $subscale, $index);
    }

    /**
     * The two fingerprints of an item, from its spec and its rendered question.
     *
     * @param array $spec The item spec (materialiser::plan_items()), with itemkey.
     * @param array $rendered The rendered question (question_template::render()).
     * @return array{contentfp: string, fingerprint: string}
     */
    public static function fingerprints(array $spec, array $rendered): array {
        $number = static fn($value): string => sprintf('%.5f', (float) $value);
        // The item as generated: what the blueprint made of it. Not its text, which shows what the engine is told.
        $content = [
            'itemkey' => (string) ($spec['itemkey'] ?? ''),
            'itemname' => (string) ($spec['itemname'] ?? ''),
            'model' => (string) ($spec['publicmodel'] ?? $spec['model'] ?? ''),
            'truedifficulty' => $number($spec['truedifficulty'] ?? $spec['difficulty'] ?? 0),
            'discrimination' => $number($spec['discrimination'] ?? 1),
            'guessing' => $number($spec['guessing'] ?? 0),
            'steps' => array_map($number, array_values((array) ($spec['steps'] ?? []))),
            'categories' => (int) ($spec['categories'] ?? 0),
            'truecategory' => (int) ($spec['truecategory'] ?? 0),
            'truesubscale' => (int) ($spec['truesubscale'] ?? 0),
        ];
        $contentfp = hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE));
        // The item as realised: plus what the engine was told, the errors injected, and the question as a person
        // sees it (its ID number carries the run and is no part of the item).
        $realised = [
            'contentfp' => $contentfp,
            'storeddifficulty' => $number($spec['difficulty'] ?? 0),
            'assignedcategory' => (int) ($spec['assignedcategory'] ?? $spec['truecategory'] ?? 0),
            'assignedsubscale' => (int) ($spec['scalenumber'] ?? $spec['truesubscale'] ?? 0),
            'miscalibrated' => !empty($spec['miscalibrated']),
            'mistagged' => !empty($spec['mistagged']),
            'name' => (string) ($rendered['name'] ?? ''),
            'questiontext' => (string) ($rendered['questiontext'] ?? ''),
            'single' => (bool) ($rendered['single'] ?? true),
            'answers' => array_map(
                static fn(array $a): array => [(string) $a['text'], $number($a['fraction'])],
                array_values((array) ($rendered['answers'] ?? []))
            ),
        ];

        return ['contentfp' => $contentfp, 'fingerprint' => hash('sha256', json_encode($realised, JSON_UNESCAPED_UNICODE))];
    }

    /**
     * The fingerprint of a pool: its (key, fingerprint) pairs, sorted by key.
     *
     * @param array $items Item key => item fingerprint (strings).
     * @return string
     */
    public static function pool_fingerprint(array $items): string {
        ksort($items, SORT_STRING);
        $lines = [];
        foreach ($items as $key => $fingerprint) {
            $lines[] = $key . ' ' . $fingerprint;
        }

        return hash('sha256', implode("\n", $lines));
    }

    /**
     * Keys and fingerprints of these runs' items, by run and question, in one query.
     *
     * @param int[] $runids The runs.
     * @return array<int, array<int, array{itemkey: string|null, contentfp: string|null, fingerprint: string|null}>>
     */
    public static function for_runs(array $runids): array {
        global $DB;

        $runids = array_values(array_unique(array_filter(array_map('intval', $runids))));
        if ($runids === []) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($runids, SQL_PARAMS_NAMED, 'ii');
        $rows = $DB->get_recordset_select(
            'local_catquizlab_item',
            "runid {$insql}",
            $params,
            'runid, id',
            'id, runid, questionid, itemkey, contentfp, fingerprint'
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->runid][(int) $row->questionid] = ['itemkey' => $row->itemkey, 'contentfp' => $row->contentfp,
                'fingerprint' => $row->fingerprint];
        }
        $rows->close();

        return $out;
    }

    /**
     * Which runs of an experiment share their pool, and whether they really do (#154 AK2–AK4).
     *
     * Runs are grouped by what their pool depends on: replication, pool condition (model), variant and its recipe.
     * Within a group every pool must be identical; a run whose pool differs is a defect, said with the items that
     * differ. Between groups the differences are the design: listed as the factors that vary, and, for groups that
     * share replication and model, which items keep their content and which were changed by the variant.
     *
     * @param int $experimentid The experiment.
     * @return array{schema: int, groups: array[], problems: string[], legacy: int[], ok: bool}
     */
    public static function compare(int $experimentid): array {
        global $DB;

        $runs = $DB->get_records(
            'local_catquizlab_run',
            ['experimentid' => $experimentid],
            'id ASC',
            'id, replication, cellkey, manifestjson'
        );
        $out = ['schema' => self::SCHEMA, 'groups' => [], 'problems' => [], 'legacy' => [], 'ok' => true];
        if ($runs === []) {
            return $out;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($runs), SQL_PARAMS_NAMED, 'ic');
        $pools = $DB->get_records_select_menu('local_catquizlab_pool', "runid {$insql}", $params, '', 'runid, fingerprint');
        $counts = $DB->get_records_sql(
            "SELECT runid, COUNT(1) AS n, COUNT(itemkey) AS keyed, COUNT(DISTINCT itemkey) AS distinctkeys
               FROM {local_catquizlab_item}
              WHERE runid {$insql}
           GROUP BY runid",
            $params
        );

        $groups = [];
        foreach ($runs as $run) {
            $manifest = json_decode((string) $run->manifestjson, true) ?: [];
            $definition = (array) ($manifest['config']['definition'] ?? []);
            $model = (string) ($definition['model'] ?? '2pl');
            $variant = (string) ($definition['pool']['variant'] ?? 'ideal');
            $recipe = (array) ($definition['pool']['recipe'] ?? []);
            ksort($recipe);
            $label = 'r' . (int) $run->replication . ' · ' . $model . ' · ' . $variant
                . ($recipe !== [] ? ' ' . json_encode($recipe) : '');
            $c = $counts[(int) $run->id] ?? null;
            $entry = ['runid' => (int) $run->id, 'cellkey' => (string) $run->cellkey,
                'strategy' => (string) ($manifest['config']['strategy']['key'] ?? ''),
                'items' => $c ? (int) $c->n : 0, 'keyed' => $c ? (int) $c->keyed : 0,
                'distinct' => $c ? (int) $c->distinctkeys : 0, 'fingerprint' => $pools[(int) $run->id] ?? null];
            if ($entry['items'] === 0) {
                // Not materialised yet: nothing to compare.
                continue;
            }
            if ($entry['keyed'] < $entry['items'] || $entry['fingerprint'] === null) {
                $out['legacy'][] = (int) $run->id;
            }
            if ($entry['keyed'] > 0 && $entry['distinct'] < $entry['keyed']) {
                $out['problems'][] = get_string('identity:duplicates', 'local_catquizlab', (object) ['run' => (int) $run->id,
                    'n' => $entry['keyed'] - $entry['distinct']]);
            }
            $groups[$label] = $groups[$label] ?? ['label' => $label, 'replication' => (int) $run->replication,
                'model' => $model, 'variant' => $variant, 'runs' => []];
            $groups[$label]['runs'][] = $entry;
        }

        foreach ($groups as $label => $group) {
            $keyed = array_values(array_filter(
                $group['runs'],
                static fn(array $r): bool => $r['fingerprint'] !== null && $r['keyed'] === $r['items']
            ));
            $fingerprints = array_values(array_unique(array_column($keyed, 'fingerprint')));
            $group['identical'] = count($fingerprints) <= 1;
            $group['fingerprint'] = count($fingerprints) === 1 ? $fingerprints[0] : null;
            $group['differences'] = [];
            if (count($fingerprints) > 1) {
                // A defect: runs that must share their pool do not. Which items differ, against the group's first run.
                $group['differences'] = self::differences(array_column($keyed, 'runid'));
                foreach ($group['differences'] as $difference) {
                    $out['problems'][] = get_string('identity:mismatch', 'local_catquizlab', (object) [
                        'run' => $difference['runid'], 'reference' => $difference['reference'], 'group' => $label,
                        'changed' => $difference['changedcount'], 'missing' => $difference['missingcount'],
                        'extra' => $difference['extracount']]);
                }
            }
            $groups[$label] = $group;
        }
        $out['groups'] = array_values($groups);
        $out['factors'] = self::factors($out['groups']);
        $out['contrasts'] = self::contrasts($out['groups']);
        $out['ok'] = $out['problems'] === [];

        return $out;
    }

    /**
     * Which items of each run differ from the first run's, by key.
     *
     * @param int[] $runids Runs that should share their pool, the reference first.
     * @return array[] One per run after the first: runid, reference, changed, missing and extra keys (at most
     *      LISTED of each, with the counts complete).
     */
    protected static function differences(array $runids): array {
        $items = self::for_runs($runids);
        $bykey = [];
        foreach ($runids as $runid) {
            foreach ($items[$runid] ?? [] as $item) {
                $bykey[$runid][(string) $item['itemkey']] = (string) $item['fingerprint'];
            }
        }
        $reference = array_shift($runids);
        $out = [];
        foreach ($runids as $runid) {
            $a = $bykey[$reference] ?? [];
            $b = $bykey[$runid] ?? [];
            $changed = array_keys(array_filter(
                array_intersect_key($b, $a),
                static fn($fp, $key) => $a[$key] !== $fp,
                ARRAY_FILTER_USE_BOTH
            ));
            $missing = array_keys(array_diff_key($a, $b));
            $extra = array_keys(array_diff_key($b, $a));
            if ($changed === [] && $missing === [] && $extra === []) {
                continue;
            }
            $out[] = ['runid' => $runid, 'reference' => $reference,
                'changed' => array_slice($changed, 0, self::LISTED), 'changedcount' => count($changed),
                'missing' => array_slice($missing, 0, self::LISTED), 'missingcount' => count($missing),
                'extra' => array_slice($extra, 0, self::LISTED), 'extracount' => count($extra)];
        }

        return $out;
    }

    /**
     * Pool variants of the same replication and model, item by item against the ideal pool (or the first variant):
     * which items keep their content, which the variant changed, which it removed or added (#154 AK3). Groups of
     * different replications or models share no items by design and are not compared.
     *
     * @param array[] $groups From compare().
     * @return array[] reference, variant (group labels), same, contentchanged, realisedchanged, removed, added counts.
     */
    protected static function contrasts(array $groups): array {
        $byset = [];
        foreach ($groups as $group) {
            if ($group['runs'] !== []) {
                $byset[$group['replication'] . '|' . $group['model']][] = $group;
            }
        }
        $out = [];
        foreach ($byset as $set) {
            if (count($set) < 2) {
                continue;
            }
            usort($set, static fn(array $a, array $b): int =>
                [$a['variant'] !== 'ideal', $a['label']] <=> [$b['variant'] !== 'ideal', $b['label']]);
            $reference = array_shift($set);
            $runids = array_merge([$reference['runs'][0]['runid']], array_map(
                static fn(array $g): int => $g['runs'][0]['runid'],
                $set
            ));
            $items = self::for_runs($runids);
            $index = static function (int $runid) use ($items): array {
                $out = [];
                foreach ($items[$runid] ?? [] as $item) {
                    if ($item['itemkey'] !== null) {
                        $out[$item['itemkey']] = $item;
                    }
                }
                return $out;
            };
            $a = $index($reference['runs'][0]['runid']);
            foreach ($set as $group) {
                $b = $index($group['runs'][0]['runid']);
                $common = array_intersect_key($b, $a);
                $contentchanged = count(array_filter(
                    $common,
                    static fn($item, $key) => $a[$key]['contentfp'] !== $item['contentfp'],
                    ARRAY_FILTER_USE_BOTH
                ));
                $realisedchanged = count(array_filter(
                    $common,
                    static fn($item, $key) =>
                    $a[$key]['contentfp'] === $item['contentfp'] && $a[$key]['fingerprint'] !== $item['fingerprint'],
                    ARRAY_FILTER_USE_BOTH
                ));
                $out[] = ['reference' => $reference['label'], 'variant' => $group['label'],
                    'same' => count($common) - $contentchanged - $realisedchanged, 'contentchanged' => $contentchanged,
                    'realisedchanged' => $realisedchanged, 'removed' => count(array_diff_key($a, $b)),
                    'added' => count(array_diff_key($b, $a))];
            }
        }

        return $out;
    }

    /**
     * What varies between the groups: the factors the design changes on purpose.
     *
     * @param array[] $groups From compare().
     * @return array<string, array> Factor => its values.
     */
    protected static function factors(array $groups): array {
        $out = [];
        foreach (['replication', 'model', 'variant'] as $factor) {
            $values = array_values(array_unique(array_column($groups, $factor)));
            if (count($values) > 1) {
                sort($values);
                $out[$factor] = $values;
            }
        }

        return $out;
    }
}
