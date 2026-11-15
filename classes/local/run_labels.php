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
 * Which experiment a run belongs to and which strategy it plays, for every short list of runs (#150).
 *
 * Runs of two experiments with the same strategies look alike by design cell alone. Every list that shows single
 * runs from more than one experiment names the experiment (linked), the run (linked) and its strategy, read for all
 * of them in one query, by the experiment's id — so a rename shows everywhere at once and nothing historic changes.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_labels {
    /** @var int Names longer than this are shortened in a list, the full name kept for the title and screen readers. */
    public const SHORT = 40;

    /**
     * The labels of these runs.
     *
     * @param int[] $runids The runs.
     * @return array<int, array> Run id => runid, runurl, experimentid, experimentname (shortened), experimentfull
     *      (whole), experimenturl, strategy, strategylabel, cellkey, label (all of it in words). Names are filtered
     *      plain text: the templates escape them, once.
     */
    public static function for_runs(array $runids): array {
        global $DB;

        $runids = array_values(array_unique(array_filter(array_map('intval', $runids))));
        if ($runids === []) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($runids, SQL_PARAMS_NAMED, 'rl');
        $rows = $DB->get_records_sql(
            "SELECT r.id, r.experimentid, r.cellkey, r.manifestjson, e.name
               FROM {local_catquizlab_run} r
          LEFT JOIN {local_catquizlab_experiment} e ON e.id = r.experimentid
              WHERE r.id {$insql}",
            $params
        );
        $out = [];
        foreach ($rows as $row) {
            $manifest = json_decode((string) $row->manifestjson, true) ?: [];
            $strategy = (string) ($manifest['config']['strategy']['key'] ?? '');
            $full = $row->name !== null ? format_string((string) $row->name, true, ['escape' => false])
                : get_string('runlabel:noexperiment', 'local_catquizlab', (int) $row->experimentid);
            $short = \core_text::strlen($full) > self::SHORT ? \core_text::substr($full, 0, self::SHORT - 1) . '…' : $full;
            $strategylabel = $strategy !== '' ? strategy_catalog::display_label($strategy) : '';
            $out[(int) $row->id] = [
                'runid' => (int) $row->id,
                'runurl' => (new \moodle_url('/local/catquizlab/runs.php', ['runid' => (int) $row->id]))->out(false),
                'experimentid' => (int) $row->experimentid,
                'experimentname' => $short,
                'experimentfull' => $full,
                'shortened' => $short !== $full,
                'experimenturl' => (new \moodle_url(
                    '/local/catquizlab/runs.php',
                    ['experimentid' => (int) $row->experimentid]
                ))->out(false),
                'strategy' => $strategy,
                'strategylabel' => $strategylabel,
                'cellkey' => (string) $row->cellkey,
                'label' => get_string('runlabel:full', 'local_catquizlab', (object) ['run' => (int) $row->id,
                    'experiment' => $full, 'strategy' => $strategylabel !== '' ? $strategylabel : '—']),
            ];
        }

        return $out;
    }
}
