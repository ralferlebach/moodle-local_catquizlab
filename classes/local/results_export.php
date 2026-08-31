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
 * Export of the results currently on screen.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Turns the filtered results into flat data files.
 *
 * The export takes the same filter the screen is showing. Anything else would
 * be a trap: a reader looks at one selection, exports, and gets another. The
 * filter is therefore written into the export's own metadata, so a file that
 * has been moved or renamed still says what it contains.
 *
 * Four levels are offered because analyses ask different questions of the same
 * run: the run level for cell comparisons, the attempt level for per-person
 * outcomes, the subscale level for the local diagnostics, and the item level
 * for the ground truth against what the engine was told. Each level is a flat
 * rectangle — no nesting, no repeated headers — so it opens in a statistics
 * package without being reshaped first.
 */
class results_export {
    /** @var string One row per run. */
    public const LEVEL_RUN = 'run';

    /** @var string One row per attempt. */
    public const LEVEL_ATTEMPT = 'attempt';

    /** @var string One row per subscale of an attempt. */
    public const LEVEL_SUBSCALE = 'subscale';

    /** @var string One row per materialised item. */
    public const LEVEL_ITEM = 'item';

    /**
     * The levels an export can be taken at.
     *
     * @return array<string, string> Level => language string key.
     */
    public static function levels(): array {
        return [
            self::LEVEL_RUN      => 'export:levelrun',
            self::LEVEL_ATTEMPT  => 'export:levelattempt',
            self::LEVEL_SUBSCALE => 'export:levelsubscale',
            self::LEVEL_ITEM     => 'export:levelitem',
        ];
    }

    /**
     * Build the dataset of one level under a filter.
     *
     * @param results_query $query The filtered data source.
     * @param string $level One of the LEVEL_* constants.
     * @return array{columns: string[], rows: array[]}
     * @throws \coding_exception If the level is unknown.
     */
    public static function dataset(results_query $query, string $level): array {
        switch ($level) {
            case self::LEVEL_RUN:
                return self::runs($query);
            case self::LEVEL_ATTEMPT:
                return self::attempts($query);
            case self::LEVEL_SUBSCALE:
                return self::subscales($query);
            case self::LEVEL_ITEM:
                return self::items($query);
            default:
                throw new \coding_exception('Unknown export level: ' . $level);
        }
    }

    /**
     * One row per run, with its coordinates and aggregate outcomes.
     *
     * @param results_query $query The data source.
     * @return array{columns: string[], rows: array[]}
     */
    protected static function runs(results_query $query): array {
        $columns = [
            'runid', 'experimentid', 'experiment', 'cellkey', 'replication', 'tier',
            'strategy', 'model', 'variant', 'strength', 'stratum', 'severity',
            'attempts', 'rmse', 'bias', 'correlation', 'meanse', 'meanitems',
            'stopsuccess', 'meanruntimems',
        ];

        $byrun = [];
        foreach ($query->observations() as $observation) {
            $byrun[$observation['runid']][] = $observation;
        }

        $rows = [];
        foreach ($query->runs() as $runid => $run) {
            $members = $byrun[$runid] ?? [];
            $outcomes = $members === [] ? [] : robustness_analysis::outcomes($members);
            $rows[] = [
                'runid'         => $runid,
                'experimentid'  => $run['experimentid'],
                'experiment'    => $run['experiment'],
                'cellkey'       => $run['cellkey'],
                'replication'   => $run['replication'],
                'tier'          => $run['tier'] ?? '',
                'strategy'      => $run['strategy'],
                'model'         => $run['model'],
                'variant'       => $run['variant'],
                'strength'      => $run['strength'],
                'stratum'       => $run['stratum'],
                'severity'      => $run['severity'],
                'attempts'      => count($members),
                'rmse'          => $outcomes['rmse'] ?? null,
                'bias'          => $outcomes['bias'] ?? null,
                'correlation'   => $outcomes['correlation'] ?? null,
                'meanse'        => $outcomes['se'] ?? null,
                'meanitems'     => $outcomes['nitems'] ?? null,
                'stopsuccess'   => $outcomes['stopsuccess'] ?? null,
                'meanruntimems' => $outcomes['runtimems'] ?? null,
            ];
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * One row per attempt.
     *
     * @param results_query $query The data source.
     * @return array{columns: string[], rows: array[]}
     */
    protected static function attempts(results_query $query): array {
        $columns = [
            'attemptid', 'runid', 'personid', 'twinid', 'replication', 'tier',
            'strategy', 'model', 'variant', 'strength', 'stratum', 'severity',
            'truetheta', 'esttheta', 'error', 'nitems', 'se', 'stopreason',
            'stopreached', 'runtimems',
        ];

        $rows = [];
        foreach ($query->observations() as $observation) {
            $row = [];
            foreach ($columns as $column) {
                $value = $observation[$column] ?? null;
                // Booleans are written as 0 and 1: a statistics package reads
                // those, and "true"/"" would silently become a factor level.
                $row[$column] = is_bool($value) ? (int) $value : $value;
            }
            $rows[] = $row;
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * One row per subscale of an attempt.
     *
     * @param results_query $query The data source.
     * @return array{columns: string[], rows: array[]}
     */
    protected static function subscales(results_query $query): array {
        $columns = [
            'attemptid', 'runid', 'personid', 'twinid', 'strategy', 'variant',
            'stratum', 'severity', 'category', 'subscale',
            'truetheta', 'esttheta', 'truedelta', 'estdelta', 'error',
            'localse', 'items', 'within1se', 'within2se',
        ];

        $rows = [];
        foreach ($query->subscale_observations() as $observation) {
            $row = [];
            foreach ($columns as $column) {
                $value = $observation[$column] ?? null;
                $row[$column] = is_bool($value) ? (int) $value : $value;
            }
            $rows[] = $row;
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * One row per materialised item: the ground truth beside the engine's view.
     *
     * @param results_query $query The data source.
     * @return array{columns: string[], rows: array[]}
     */
    protected static function items(results_query $query): array {
        return export_dataset::items(array_keys($query->runs()));
    }

    /**
     * The metadata that travels with an export.
     *
     * A results file outlives the screen it came from. Without the filter, the
     * aggregation level and the versions it was produced under, a reader cannot
     * tell what the numbers refer to — and a file whose provenance is unclear
     * is not usable as evidence.
     *
     * @param results_query $query The data source.
     * @param string $level The export level.
     * @return array The metadata block.
     */
    public static function metadata(results_query $query, string $level): array {
        global $CFG, $USER;

        $plugin = new \stdClass();
        require($CFG->dirroot . '/local/catquizlab/version.php');

        $provenance = $query->provenance();
        $dataset = self::dataset($query, $level);

        return [
            'schema'        => 'local_catquizlab/results',
            'schemaversion' => 1,
            'level'         => $level,
            'leveldescription' => get_string(self::levels()[$level], 'local_catquizlab'),
            'filter'        => $query->get_filter(),
            'runs'          => $provenance['runs'],
            'attempts'      => $provenance['attempts'],
            'replications'  => $provenance['replications'],
            'rows'          => count($dataset['rows']),
            'columns'       => $dataset['columns'],
            'dispersion'    => $provenance['dispersion'],
            'exported'      => date('c'),
            'exportedby'    => (int) ($USER->id ?? 0),
            'plugin'        => [
                'component' => 'local_catquizlab',
                'version'   => $plugin->version ?? null,
                'release'   => $plugin->release ?? null,
            ],
            'environment'   => [
                'moodlerelease' => $CFG->release ?? null,
                'phpversion'    => PHP_VERSION,
            ],
            // The engine version is part of what a result depends on, and it
            // is recorded per run in the manifest; the export points at that
            // rather than duplicating a lookup.
            'engine'        => ['available' => environment::engine_available()],
        ];
    }

    /**
     * Render a dataset as CSV.
     *
     * @param array $dataset A dataset from {@see self::dataset()}.
     * @return string
     */
    public static function to_csv(array $dataset): string {
        $lines = [implode(',', $dataset['columns'])];

        foreach ($dataset['rows'] as $row) {
            $cells = [];
            foreach ($dataset['columns'] as $column) {
                $value = $row[$column] ?? null;
                if ($value === null) {
                    // An empty field, not the string "null": a missing value
                    // must not arrive as a category of its own.
                    $cells[] = '';
                    continue;
                }
                if (is_bool($value)) {
                    $cells[] = (int) $value;
                    continue;
                }
                if (is_float($value) || is_int($value)) {
                    $cells[] = (string) $value;
                    continue;
                }
                $cells[] = '"' . str_replace('"', '""', (string) $value) . '"';
            }
            $lines[] = implode(',', $cells);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Render a dataset as JSON, with its metadata attached.
     *
     * @param results_query $query The data source.
     * @param string $level The export level.
     * @return string
     */
    public static function to_json(results_query $query, string $level): string {
        $dataset = self::dataset($query, $level);

        return json_encode(
            [
                'metadata' => self::metadata($query, $level),
                'data'     => $dataset['rows'],
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /**
     * A file name that carries the level and the filter.
     *
     * @param results_query $query The data source.
     * @param string $level The export level.
     * @param string $extension The file extension without a dot.
     * @return string
     */
    public static function filename(results_query $query, string $level, string $extension): string {
        $parts = ['catquizlab', $level];
        foreach ($query->get_filter() as $key => $value) {
            $parts[] = $key . '-' . clean_param((string) $value, PARAM_ALPHANUMEXT);
        }
        $parts[] = date('Ymd-His');

        return implode('_', $parts) . '.' . $extension;
    }
}
