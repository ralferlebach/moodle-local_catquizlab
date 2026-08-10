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
 * Exporter: serialise run, result and metric data to portable formats.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Serialises tabular lab data to CSV, JSON and XML (E6).
 *
 * The registry, metrics and diagnostics all produce plain arrays of rows; this
 * class turns such rows into portable text. It is a pure, side-effect-free
 * serialiser — it never touches the database or the filesystem, so the gathering
 * of rows and the writing of files stay separate and both stay testable. The
 * spreadsheet formats (xlsx/ods) belong to a later step that uses Moodle's
 * workbook writer.
 */
class exporter {
    /**
     * Serialise rows to CSV (comma-separated, LF line endings, RFC 4180 quoting).
     *
     * @param array $rows List of associative row arrays.
     * @param array|null $columns Column order/selection; defaults to the first row's keys.
     * @return string The CSV text (with a trailing newline), or '' for no rows and no columns.
     */
    public static function to_csv(array $rows, ?array $columns = null): string {
        if ($columns === null) {
            if ($rows === []) {
                return '';
            }
            $columns = array_keys((array) reset($rows));
        }

        $lines = [self::csv_line($columns)];
        foreach ($rows as $row) {
            $row = (array) $row;
            $cells = [];
            foreach ($columns as $column) {
                $cells[] = $row[$column] ?? '';
            }
            $lines[] = self::csv_line($cells);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Serialise any data to JSON.
     *
     * @param mixed $data The data to encode.
     * @param bool $pretty Whether to pretty-print.
     * @return string The JSON text.
     */
    public static function to_json($data, bool $pretty = true): string {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        return (string) json_encode($data, $flags);
    }

    /**
     * Serialise rows to a well-formed XML document.
     *
     * @param array $rows List of associative row arrays.
     * @param string $root The root element name.
     * @param string $rowelement The per-row element name.
     * @return string The XML text.
     */
    public static function to_xml(array $rows, string $root = 'export', string $rowelement = 'row'): string {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $rootnode = $doc->createElement(self::xml_name($root, 'export'));
        $doc->appendChild($rootnode);

        foreach ($rows as $row) {
            $rownode = $doc->createElement(self::xml_name($rowelement, 'row'));
            foreach ((array) $row as $key => $value) {
                $field = $doc->createElement(self::xml_name((string) $key, 'field'));
                $field->appendChild($doc->createTextNode(self::scalar($value)));
                $rownode->appendChild($field);
            }
            $rootnode->appendChild($rownode);
        }

        return (string) $doc->saveXML();
    }

    /**
     * Format one CSV line from a list of cells.
     *
     * @param array $cells The cell values.
     * @return string
     */
    protected static function csv_line(array $cells): string {
        return implode(',', array_map([self::class, 'csv_field'], $cells));
    }

    /**
     * Quote and escape a single CSV field when necessary.
     *
     * @param mixed $value The field value.
     * @return string
     */
    protected static function csv_field($value): string {
        $text = self::scalar($value);
        if (preg_match('/["\n\r,]/', $text)) {
            return '"' . str_replace('"', '""', $text) . '"';
        }
        return $text;
    }

    /**
     * Render a value as a scalar string (booleans, null and arrays included).
     *
     * @param mixed $value The value.
     * @return string
     */
    protected static function scalar($value): string {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return '';
        }
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return (string) $value;
    }

    /**
     * Sanitise a string into a valid XML element name.
     *
     * @param string $name The proposed name.
     * @param string $fallback A valid fallback prefix.
     * @return string
     */
    protected static function xml_name(string $name, string $fallback): string {
        $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', $name);
        if ($name === '' || !preg_match('/^[A-Za-z_]/', $name)) {
            $name = $fallback . ($name !== '' ? '_' . $name : '');
        }
        return $name;
    }
}
