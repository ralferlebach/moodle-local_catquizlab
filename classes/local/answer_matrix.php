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
        // In batches bounded by bytes (#147): a whole run of long sittings at once was more than the memory of a
        // request, and on MySQL/MariaDB the driver holds a whole result in PHP's memory.
        $questionids = [];
        $rows = [];
        $batches = trace_reader::batches(
            'a.runid = :runid AND a.tracejson IS NOT NULL',
            ['runid' => $runid],
            'a.id, a.id AS attemptid, a.personid, ' . trace_reader::digest_fields('a') . ', p.profilejson, p.stratum',
            'JOIN {local_catquizlab_person} p ON p.id = a.personid',
            true
        );
        foreach ($batches as $batch) {
            foreach ($batch as $record) {
                $rows[] = self::row($record, $questionids);
                $record->tracejson = null;
                $record->digestjson = null;
            }
        }
        // Person by person, as before the batches.
        usort($rows, static fn(array $a, array $b): int
            => [$a['personid'], $a['attemptid']] <=> [$b['personid'], $b['attemptid']]);

        ksort($questionids);
        return ['questionids' => array_keys($questionids), 'rows' => $rows];
    }

    /**
     * One row of the matrix from one sitting.
     *
     * @param \stdClass $record The sitting with its person's profile and stratum.
     * @param array $questionids The questions seen so far, extended.
     * @return array
     */
    protected static function row(\stdClass $record, array &$questionids): array {
        // The responses are in the digest (#147).
        $trace = trace_digest::decode($record);
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

        return [
            'attemptid' => (int) $record->attemptid,
            'personid'  => (int) $record->personid,
            'person'    => $label,
            'stratum'   => (string) $record->stratum,
            'responses' => $normalised,
        ];
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
