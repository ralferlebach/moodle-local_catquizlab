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

use local_catquizlab\local\engine_perf;

/**
 * The engine's request timing of a run, on the run's page (#110).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine_perf_view {
    /** @var int Spans shown per kind of request. */
    public const SPANS = 10;

    /**
     * The section: percentiles per kind of request and per span, or why there are none.
     *
     * @param int $runid The run.
     * @return string
     */
    public static function render(int $runid): string {
        global $OUTPUT;

        $component = 'local_catquizlab';
        $out = $OUTPUT->heading(get_string('engineperf:heading', $component), 4);
        if (!engine_perf::available()) {
            return $out . \html_writer::tag('p', get_string('engineperf:unavailable', $component), ['class' => 'text-muted small']);
        }
        $run = engine_perf::for_run($runid);
        $state = engine_perf::enabled() ? get_string('engineperf:on', $component)
            : get_string('engineperf:off', $component);
        $out .= \html_writer::tag('p', get_string('engineperf:coverage', $component, (object) [
            'traced' => $run['traced'], 'sittings' => $run['sittings'],
        ]) . ' ' . $state, ['class' => 'small text-muted', 'data-region' => 'catquizlab-engineperf-coverage']);
        if ($run['kinds'] === []) {
            return $out;
        }
        $ms = static fn(?float $v): string => $v === null ? '–' : format_float($v, 0);
        foreach ($run['kinds'] as $kind => $figures) {
            $table = new \html_table();
            $table->attributes = ['class' => 'generaltable table-sm w-auto', 'data-kind' => $kind];
            $table->caption = get_string('engineperf:kind_' . $kind, $component) . ' — '
                . get_string('engineperf:requests', $component, $figures['count']);
            $table->head = [get_string('engineperf:span', $component), 'n', 'p50 ms', 'p95 ms', 'p99 ms', 'max ms'];
            $r = $figures['requestms'];
            $table->data[] = [\html_writer::tag('strong', get_string('engineperf:request', $component)), $r['n'],
                $ms($r['p50']), $ms($r['p95']), $ms($r['p99']), $ms($r['max'])];
            $q = $figures['queries'];
            $table->data[] = [get_string('engineperf:queries', $component), $q['n'], $ms($q['p50']), $ms($q['p95']),
                $ms($q['p99']), $ms($q['max'])];
            foreach (array_slice($figures['spans'], 0, self::SPANS, true) as $name => $p) {
                $table->data[] = [s($name), $p['n'], $ms($p['p50']), $ms($p['p95']), $ms($p['p99']), $ms($p['max'])];
            }
            $out .= \html_writer::div(
                \html_writer::table($table),
                'table-responsive position-relative',
                ['data-region' => 'catquizlab-engineperf-' . $kind]
            );
        }

        return $out;
    }
}
