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
 * Export dataset: select the export level and scope.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Resolves what to export by level and scope (E6.1 selection layer).
 *
 * The level chooses the dataset — raw (the answer matrix), ground truth (each
 * person's true profile in tidy long form) or metrics (the stored result rows) —
 * and the scope chooses the runs — a single run, all runs of an experiment, or
 * all runs of a tier. Each builder returns a {columns, rows} table for the
 * exporter. Reads only the lab store, so it is testable without the engine.
 */
class export_dataset {
    /** @var string[] Ground-truth long-format columns. */
    public const GROUNDTRUTH_COLUMNS = [
        'runid', 'personid', 'person', 'twinid', 'stratum', 'severity',
        'level', 'categoryindex', 'subscaleindex', 'theta',
    ];

    /** @var string[] Item ground-truth columns: the truth beside the engine's view. */
    public const ITEM_COLUMNS = [
        'runid', 'questionid', 'itemname', 'model', 'truedifficulty', 'storeddifficulty',
        'discrimination', 'guessing', 'truecategory', 'truesubscale',
        'truecatscaleid', 'assignedcatscaleid', 'miscalibrated', 'mistagged',
    ];

    /** @var string[] Metric long-format columns. */
    public const METRIC_COLUMNS = ['runid', 'scope', 'metric', 'value'];

    /**
     * Resolve the run ids for a scope.
     *
     * @param string $scope run, experiment or tier.
     * @param int|string $selector The run id, experiment id, or tier name.
     * @return int[] The run ids.
     */
    public static function runids_for(string $scope, $selector): array {
        global $DB;

        switch ($scope) {
            case 'run':
                return [(int) $selector];
            case 'experiment':
                return array_map('intval', array_keys(
                    $DB->get_records('local_catquizlab_run', ['experimentid' => (int) $selector], 'id', 'id')
                ));
            case 'tier':
                return self::runids_for_tier((string) $selector);
            default:
                return [];
        }
    }

    /**
     * Ground truth as tidy long rows: a global, category and subscale row per person.
     *
     * @param int[] $runids The runs.
     * @return array{columns: string[], rows: array[]}
     */
    public static function ground_truth(array $runids): array {
        $persons = self::persons($runids);

        $rows = [];
        foreach ($persons as $person) {
            $profile = json_decode((string) $person->profilejson, true) ?: [];
            $label = $profile['label'] ?? ('P' . (int) $person->id);

            $rows[] = self::gt_row($person, $label, 'global', null, null, (float) ($profile['global'] ?? 0.0));
            foreach ($profile['categories'] ?? [] as $category) {
                $c = (int) ($category['index'] ?? 0);
                $rows[] = self::gt_row($person, $label, 'category', $c, null, (float) ($category['theta'] ?? 0.0));
                foreach ($category['subscales'] ?? [] as $subscale) {
                    $rows[] = self::gt_row(
                        $person,
                        $label,
                        'subscale',
                        $c,
                        (int) ($subscale['index'] ?? 0),
                        (float) ($subscale['theta'] ?? 0.0)
                    );
                }
            }
        }

        return ['columns' => self::GROUNDTRUTH_COLUMNS, 'rows' => $rows];
    }

    /**
     * Item ground truth across the given runs.
     *
     * Exports the true item parameters and the true content placement next to
     * what the engine was given. A robustness analysis needs both columns: the
     * difference between them is the condition, and without it a mistagged or
     * miscalibrated item cannot be identified after the run.
     *
     * @param int[] $runids The runs.
     * @return array{columns: string[], rows: array[]}
     */
    public static function items(array $runids): array {
        global $DB;

        $runids = array_values(array_filter(array_map('intval', $runids)));
        if ($runids === []) {
            return ['columns' => self::ITEM_COLUMNS, 'rows' => []];
        }

        [$insql, $params] = $DB->get_in_or_equal($runids, SQL_PARAMS_NAMED, 'run');
        $records = $DB->get_records_select('local_catquizlab_item', 'runid ' . $insql, $params, 'runid, id');

        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'runid'              => (int) $record->runid,
                'questionid'         => (int) $record->questionid,
                'itemname'           => (string) $record->itemname,
                'model'              => (string) $record->model,
                'truedifficulty'     => round((float) $record->truedifficulty, 5),
                'storeddifficulty'   => round((float) $record->storeddifficulty, 5),
                'discrimination'     => round((float) $record->discrimination, 5),
                'guessing'           => round((float) $record->guessing, 5),
                'truecategory'       => (int) $record->truecategory,
                'truesubscale'       => (int) $record->truesubscale,
                'truecatscaleid'     => (int) $record->truecatscaleid,
                'assignedcatscaleid' => (int) $record->assignedcatscaleid,
                'miscalibrated'      => (int) $record->miscalibrated,
                'mistagged'          => (int) $record->mistagged,
            ];
        }

        return ['columns' => self::ITEM_COLUMNS, 'rows' => $rows];
    }

    /**
     * Stored metrics as long rows across the given runs.
     *
     * @param int[] $runids The runs.
     * @return array{columns: string[], rows: array[]}
     */
    public static function metrics(array $runids): array {
        global $DB;

        if ($runids === []) {
            return ['columns' => self::METRIC_COLUMNS, 'rows' => []];
        }

        [$insql, $params] = $DB->get_in_or_equal($runids, SQL_PARAMS_NAMED);
        $results = $DB->get_records_select('local_catquizlab_result', "runid $insql", $params, 'runid, scope, metric');

        $rows = [];
        foreach ($results as $result) {
            $rows[] = [
                'runid'  => (int) $result->runid,
                'scope'  => (string) $result->scope,
                'metric' => (string) $result->metric,
                'value'  => $result->value === null ? '' : (float) $result->value,
            ];
        }

        return ['columns' => self::METRIC_COLUMNS, 'rows' => $rows];
    }

    /**
     * Resolve run ids belonging to a tier.
     *
     * @param string $tier The tier name.
     * @return int[]
     */
    protected static function runids_for_tier(string $tier): array {
        global $DB;

        $experimentids = array_keys($DB->get_records('local_catquizlab_experiment', ['tier' => $tier], 'id', 'id'));
        if ($experimentids === []) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($experimentids, SQL_PARAMS_NAMED);
        return array_map('intval', array_keys(
            $DB->get_records_select('local_catquizlab_run', "experimentid $insql", $params, 'id', 'id')
        ));
    }

    /**
     * Load the persons of the given runs.
     *
     * @param int[] $runids The runs.
     * @return array
     */
    protected static function persons(array $runids): array {
        global $DB;

        if ($runids === []) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($runids, SQL_PARAMS_NAMED);
        return $DB->get_records_select('local_catquizlab_person', "runid $insql", $params, 'runid, id');
    }

    /**
     * Build one ground-truth long-format row.
     *
     * @param \stdClass $person The person record.
     * @param string $label The person label.
     * @param string $level global, category or subscale.
     * @param int|null $categoryindex The category index.
     * @param int|null $subscaleindex The subscale index.
     * @param float $theta The true ability.
     * @return array
     */
    protected static function gt_row(
        \stdClass $person,
        string $label,
        string $level,
        ?int $categoryindex,
        ?int $subscaleindex,
        float $theta
    ): array {
        return [
            'runid'         => (int) $person->runid,
            'personid'      => (int) $person->id,
            'person'        => $label,
            // The twin key is what makes a paired analysis possible after the
            // fact: without it, matching the same person across cells means
            // guessing from the ability value.
            'twinid'        => (string) ($person->twinid ?? ''),
            'stratum'       => (string) ($person->stratum ?? ''),
            'severity'      => (string) ($person->severity ?? 'none'),
            'level'         => $level,
            'categoryindex' => $categoryindex ?? '',
            'subscaleindex' => $subscaleindex ?? '',
            'theta'         => round($theta, 5),
        ];
    }
}
