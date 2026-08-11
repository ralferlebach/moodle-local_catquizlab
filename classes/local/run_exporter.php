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
 * Run exporter: write a run's answer matrix to stored files.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Exports a run's answer matrix to Moodle file storage in several formats (E6.3).
 *
 * Builds the matrix ({@see answer_matrix}), renders it with the pure exporter
 * (CSV/JSON/XML) or the spreadsheet writer (xlsx/ods), stores each result as a
 * file in the system context and logs it. CSV/JSON/XML need no engine; xlsx/ods
 * need the corresponding dataformat plugin and are skipped when it is absent.
 */
class run_exporter {
    /** @var string The plugin's export file area. */
    public const FILEAREA = 'export';

    /**
     * Export a run's answer matrix to stored files.
     *
     * @param int $runid The run.
     * @param string[] $formats Any of csv, json, xml, xlsx, ods.
     * @return string[] The stored file names.
     */
    public static function export_to_files(int $runid, array $formats = ['csv']): array {
        global $DB, $USER;

        $matrix = answer_matrix::build($runid);
        $table = answer_matrix::to_rows($matrix);
        $context = \context_system::instance();
        $fs = get_file_storage();
        $now = time();
        $experimentid = (int) $DB->get_field('local_catquizlab_run', 'experimentid', ['id' => $runid]);

        $stored = [];
        foreach ($formats as $format) {
            $filename = "run-{$runid}-answermatrix." . self::extension($format);
            $content = self::render($format, $table);
            if ($content === null) {
                continue;
            }

            self::replace_file($fs, $context, $filename, $content);
            $DB->insert_record('local_catquizlab_exportlog', (object) [
                'runid'        => $runid,
                'experimentid' => $experimentid,
                'format'       => $format,
                'dataset'      => 'answermatrix',
                'itemid'       => $runid,
                'usermodified' => $USER->id ?? 0,
                'timecreated'  => $now,
            ]);
            $stored[] = $filename;
        }

        return $stored;
    }

    /**
     * Export a level/scope dataset to stored files.
     *
     * @param string $level raw, groundtruth or metrics.
     * @param string $scope run, experiment or tier.
     * @param int|string $selector The run id, experiment id, or tier name.
     * @param string[] $formats Any of csv, json, xml, xlsx, ods.
     * @return string[] The stored file names.
     */
    public static function export_dataset(string $level, string $scope, $selector, array $formats = ['csv']): array {
        $runids = export_dataset::runids_for($scope, $selector);

        if ($level === 'groundtruth') {
            $table = export_dataset::ground_truth($runids);
        } else if ($level === 'metrics') {
            $table = export_dataset::metrics($runids);
        } else {
            $table = ($runids === []) ? ['columns' => [], 'rows' => []]
                : answer_matrix::to_rows(answer_matrix::build((int) reset($runids)));
        }

        $name = $level . '-' . $scope;
        $anchor = $runids === [] ? 0 : (int) reset($runids);
        return self::store_table($anchor, $name, $table, $formats);
    }

    /**
     * Store a prepared table in the requested formats and log each export.
     *
     * @param int $runid The anchor run id (for the file name and log).
     * @param string $dataset The dataset name.
     * @param array $table The columns/rows table.
     * @param string[] $formats The formats.
     * @return string[] The stored file names.
     */
    public static function store_table(int $runid, string $dataset, array $table, array $formats): array {
        global $DB, $USER;

        $context = \context_system::instance();
        $fs = get_file_storage();
        $now = time();
        $experimentid = (int) $DB->get_field('local_catquizlab_run', 'experimentid', ['id' => $runid]);

        $stored = [];
        foreach ($formats as $format) {
            $content = self::render($format, $table);
            if ($content === null) {
                continue;
            }
            $filename = "run-{$runid}-{$dataset}." . self::extension($format);
            self::replace_file($fs, $context, $filename, $content);
            $DB->insert_record('local_catquizlab_exportlog', (object) [
                'runid'        => $runid,
                'experimentid' => $experimentid,
                'format'       => $format,
                'dataset'      => $dataset,
                'itemid'       => $runid,
                'usermodified' => $USER->id ?? 0,
                'timecreated'  => $now,
            ]);
            $stored[] = $filename;
        }

        return $stored;
    }

    /**
     * Render the table in the given text format, or null if unsupported here.
     *
     * @param string $format The format.
     * @param array $table The columns/rows table.
     * @return string|null
     */
    protected static function render(string $format, array $table): ?string {
        switch ($format) {
            case 'csv':
                return exporter::to_csv($table['rows'], $table['columns']);
            case 'json':
                return exporter::to_json($table['rows']);
            case 'xml':
                return exporter::to_xml($table['rows'], 'answermatrix', 'person');
            case 'xlsx':
            case 'ods':
                return self::render_spreadsheet($format, $table);
            default:
                return null;
        }
    }

    /**
     * Render a spreadsheet to a temp file and return its bytes, or null.
     *
     * @param string $format xlsx or ods.
     * @param array $table The columns/rows table.
     * @return string|null
     */
    protected static function render_spreadsheet(string $format, array $table): ?string {
        $dataformat = $format === 'xlsx' ? 'excel' : 'ods';
        $tmp = make_request_directory() . '/matrix.' . self::extension($format);
        if (!exporter::to_spreadsheet_file($table['columns'], $table['rows'], $dataformat, $tmp)) {
            return null;
        }
        $bytes = file_get_contents($tmp);
        return $bytes === false ? null : $bytes;
    }

    /**
     * Store (replacing) a file in the plugin's export area.
     *
     * @param \file_storage $fs The file storage.
     * @param \context $context The system context.
     * @param string $filename The file name.
     * @param string $content The file content.
     * @return void
     */
    protected static function replace_file(\file_storage $fs, \context $context, string $filename, string $content): void {
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'local_catquizlab',
            'filearea'  => self::FILEAREA,
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => $filename,
        ];
        $existing = $fs->get_file(
            $context->id,
            'local_catquizlab',
            self::FILEAREA,
            0,
            '/',
            $filename
        );
        if ($existing) {
            $existing->delete();
        }
        $fs->create_file_from_string($filerecord, $content);
    }

    /**
     * File extension for a format.
     *
     * @param string $format The format.
     * @return string
     */
    protected static function extension(string $format): string {
        return $format === 'xlsx' ? 'xlsx' : ($format === 'ods' ? 'ods' : $format);
    }
}
