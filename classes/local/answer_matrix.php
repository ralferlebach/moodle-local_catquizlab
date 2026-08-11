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
 * Answer matrix: persons by items response matrix for a run.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Builds the persons-by-items response matrix of a run (E6.2).
 *
 * This is the successor to the reference sim script: for every collected attempt
 * it reads the per-item scored fractions from the trace (responses: questionid =>
 * fraction) and assembles a matrix whose columns are the union of presented items
 * and whose rows are the persons. {@see self::build()} reads the lab store;
 * {@see self::to_rows()} flattens the matrix into tabular rows for the exporter —
 * pure and testable. Cells are empty where an item was not presented to a person
 * (adaptive tests present different items to different people).
 */
class answer_matrix {
    /**
     * Build the response matrix of a run from the collected traces.
     *
     * @param int $runid The run.
     * @return array{questionids: int[], rows: array[]}
     */
    public static function build(int $runid): array {
        global $DB;

        $sql = "SELECT a.id AS attemptid, a.personid, a.tracejson, p.profilejson, p.stratum
                  FROM {local_catquizlab_attempt} a
                  JOIN {local_catquizlab_person} p ON p.id = a.personid
                 WHERE a.runid = :runid AND a.tracejson IS NOT NULL
              ORDER BY a.personid ASC, a.id ASC";
        $records = $DB->get_records_sql($sql, ['runid' => $runid]);

        $questionids = [];
        $rows = [];
        foreach ($records as $record) {
            $trace = json_decode((string) $record->tracejson, true);
            $responses = (is_array($trace) && isset($trace['responses']) && is_array($trace['responses']))
                ? $trace['responses']
                : [];

            $normalised = [];
            foreach ($responses as $questionid => $fraction) {
                $questionid = (int) $questionid;
                $questionids[$questionid] = true;
                $normalised[$questionid] = $fraction === null ? null : (float) $fraction;
            }

            $profile = json_decode((string) $record->profilejson, true);
            $label = is_array($profile) && isset($profile['label'])
                ? (string) $profile['label']
                : ('P' . (int) $record->personid);

            $rows[] = [
                'attemptid' => (int) $record->attemptid,
                'personid'  => (int) $record->personid,
                'person'    => $label,
                'stratum'   => (string) $record->stratum,
                'responses' => $normalised,
            ];
        }

        ksort($questionids);
        return ['questionids' => array_keys($questionids), 'rows' => $rows];
    }

    /**
     * Flatten a matrix into export rows (person, stratum, then one column per item).
     *
     * @param array $matrix The matrix from {@see self::build()}.
     * @return array{columns: string[], rows: array[]}
     */
    public static function to_rows(array $matrix): array {
        $questionids = $matrix['questionids'] ?? [];
        $columns = array_merge(['person', 'stratum'], array_map('strval', $questionids));

        $rows = [];
        foreach ($matrix['rows'] ?? [] as $entry) {
            $row = [
                'person'  => $entry['person'] ?? '',
                'stratum' => $entry['stratum'] ?? '',
            ];
            foreach ($questionids as $questionid) {
                $value = $entry['responses'][$questionid] ?? null;
                $row[(string) $questionid] = $value === null ? '' : $value;
            }
            $rows[] = $row;
        }

        return ['columns' => $columns, 'rows' => $rows];
    }
}
