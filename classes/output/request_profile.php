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

namespace local_catquizlab\output;

use local_catquizlab\local\resource_guard;
use local_catquizlab\local\results_query;
use local_catquizlab\local\trace_reader;

/**
 * What a results request took, step by step: for administrators, with ?profile=1 (#147, AK3).
 *
 * The same figures the fatal-error line carries, for a request that did not fail: each phase and step of the page
 * with its time, its database queries and the peak memory at its end; the passes over the selection, the bytes read,
 * the sittings read one at a time; and the process's memory as the system sees it. A measurement on the live server
 * is then one page load, in the web server's own PHP.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class request_profile {
    /**
     * The profile as a table, folded.
     *
     * @return string
     */
    public static function render(): string {
        global $DB;

        $component = 'local_catquizlab';
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->head = [
            get_string('profile:step', $component),
            get_string('profile:ms', $component),
            get_string('profile:queries', $component),
            get_string('profile:peak', $component),
        ];
        foreach (resource_guard::marks() as $name => $mark) {
            $table->data[] = [s($name), $mark['ms'], $mark['queries'], resource_guard::megabytes($mark['peak'])];
        }
        $read = trace_reader::figures();
        $started = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $facts = [
            'php' => PHP_VERSION . ' ' . PHP_SAPI,
            'db' => $DB->get_dbfamily(),
            'memorylimit' => (string) ini_get('memory_limit'),
            'maxexec' => (string) ini_get('max_execution_time'),
            'runtime' => (int) round(1000 * (microtime(true) - $started)) . ' ms',
            'queries' => (int) $DB->perf_get_queries(),
            'peak' => resource_guard::megabytes(memory_get_peak_usage()),
            'passes' => results_query::$passes,
            'onebyone' => results_query::$detailqueries,
            'read' => resource_guard::megabytes((int) $read['read']),
            'batches' => (int) $read['batches'],
            'largestbatch' => resource_guard::megabytes((int) $read['largest']),
        ] + resource_guard::process_memory();
        $summary = [];
        foreach ($facts as $name => $value) {
            $summary[] = $name . '=' . $value;
        }

        return \html_writer::tag(
            'details',
            \html_writer::tag('summary', get_string('profile:heading', $component))
            . \html_writer::tag('p', get_string('profile:explain', $component), ['class' => 'small text-muted'])
            . \html_writer::tag('p', s(implode(' · ', $summary)), ['class' => 'small', 'data-region' => 'catquizlab-profile-facts'])
            . accessible_table::render($table),
            ['class' => 'mt-4', 'data-region' => 'catquizlab-profile', 'data-facts' => json_encode($facts),
                'data-marks' => json_encode(resource_guard::marks())]
        );
    }
}
