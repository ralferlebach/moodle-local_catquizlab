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

namespace local_catquizlab\output;

use local_catquizlab\local\experiment_resume;

/**
 * The two tables a person reads before a stopped experiment goes on (#165): resuming, and resetting for good.
 *
 * Plain tables with a caption and header cells, so a screen reader reads each number with its run and its column.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resume_view {
    /**
     * Run by run: what a resume keeps, plays, collects, and what cannot go on and why.
     *
     * @param array $preview experiment_resume::preview().
     * @return string HTML.
     */
    public static function preview_table(array $preview): string {
        $component = 'local_catquizlab';
        $table = new \html_table();
        $table->attributes = ['class' => 'table table-sm small', 'data-region' => 'catquizlab-resume-preview'];
        $table->caption = get_string('resume:caption', $component);
        $columns = ['run', 'action', 'planned', 'collected', 'validated', 'failed', 'cancelled', 'requeue',
            'finishedafter', 'inprogress', 'leases'];
        $table->head = array_map(static fn(string $k): string => get_string('resume:col' . $k, $component), $columns);
        foreach ($preview['runs'] as $run) {
            $action = get_string('resume:action' . $run['action'], $component);
            if ($run['blocker'] !== '') {
                $action .= ': ' . get_string('resume:blocker' . $run['blocker'], $component);
            }
            $cell = new \html_table_cell(\html_writer::link(
                new \moodle_url('/local/catquizlab/runs.php', ['runid' => $run['runid']]),
                '#' . $run['runid']
            ) . ' ' . s($run['cellkey']));
            $cell->header = true;
            $cell->scope = 'row';
            $row = new \html_table_row([$cell, s($action), $run['planned'], $run['collected'], $run['validated'],
                $run['failed'], $run['cancelled'], $run['requeue'], $run['finishedafter'], $run['inprogress'],
                $run['leases']]);
            $row->attributes['data-runid'] = $run['runid'];
            $row->attributes['data-action'] = $run['action'];
            if ($run['action'] === experiment_resume::BLOCKED) {
                $row->attributes['class'] = 'table-warning';
            }
            $table->data[] = $row;
        }

        return \html_writer::div(\html_writer::table($table), 'table-responsive')
            . \html_writer::tag('p', get_string('resume:keeps', $component), ['class' => 'small text-muted']);
    }

    /**
     * Run by run: what resetting and running again deletes for good.
     *
     * @param array $inventory experiment_resume::restart_inventory().
     * @return string HTML.
     */
    public static function restart_table(array $inventory): string {
        $component = 'local_catquizlab';
        $table = new \html_table();
        $table->attributes = ['class' => 'table table-sm small', 'data-region' => 'catquizlab-restart-inventory'];
        $table->caption = get_string('restart:caption', $component);
        $table->head = array_map(
            static fn(string $k): string => get_string('restart:col' . $k, $component),
            ['run', 'sittings', 'collected', 'persons', 'items', 'results']
        );
        foreach ($inventory['runs'] as $run) {
            $cell = new \html_table_cell('#' . $run['runid'] . ' ' . s($run['cellkey']));
            $cell->header = true;
            $cell->scope = 'row';
            $table->data[] = new \html_table_row([$cell, $run['sittings'], $run['collected'], $run['persons'],
                $run['items'], $run['results']]);
        }

        return \html_writer::div(\html_writer::table($table), 'table-responsive');
    }
}
