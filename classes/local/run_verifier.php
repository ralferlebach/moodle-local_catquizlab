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
 * Verification of a provisioned run against the CAT engine.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Walks the materialisation chain of a run and reports where it breaks.
 *
 * The chain has several links, and each one can fail on its own:
 *
 *     scale map → planned items → Moodle questions → engine item rows
 *       → active parameters in the right context → engine-visible items
 *
 * Knowing only that the last number is zero does not say which link gave way.
 * Counting each link separately turns "the CAT manager shows no questions" into
 * a specific answer: the questions exist but the engine refused the assignment,
 * or the assignment worked but the parameters landed in another context, or
 * everything is stored and the retrieval path still does not see it.
 *
 * The last count deliberately uses the engine's own retrieval, not a row count.
 * A row count would agree with itself and miss exactly the inconsistencies this
 * exists to find.
 */
class run_verifier {
    /**
     * Verify one run.
     *
     * @param int $runid The run.
     * @return array{ok: bool, counts: array<string, int>, firstfailure: ?string, details: string[]}
     */
    public static function verify(int $runid): array {
        global $DB;

        $counts = [
            'scale mappings'   => 0,
            'lab item rows'    => 0,
            'Moodle questions' => 0,
            'CAT item rows'    => 0,
            'active params'    => 0,
            'engine-visible'   => 0,
        ];
        $details = [];

        $counts['scale mappings'] = $DB->count_records('local_catquizlab_scalemap', ['runid' => $runid]);

        $items = $DB->get_records('local_catquizlab_item', ['runid' => $runid]);
        $counts['lab item rows'] = count($items);

        if ($items === []) {
            return [
                'ok'           => false,
                'counts'       => $counts,
                'firstfailure' => get_string('verify:noitems', 'local_catquizlab'),
                'details'      => [],
            ];
        }

        $questionids = [];
        foreach ($items as $item) {
            $questionids[(int) $item->questionid] = true;
        }
        $questionids = array_keys($questionids);

        [$insql, $params] = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED, 'q');
        $counts['Moodle questions'] = $DB->count_records_select('question', 'id ' . $insql, $params);

        // The question-bank link needs no engine, so it is reported first: an
        // installation missing its questions can be diagnosed even where the
        // engine is absent, and stopping earlier hid that.
        if ($counts['Moodle questions'] < $counts['lab item rows']) {
            return [
                'ok'           => false,
                'counts'       => $counts,
                'firstfailure' => get_string('verify:brokenlink', 'local_catquizlab', (object) [
                    'link'     => 'Moodle questions',
                    'found'    => $counts['Moodle questions'],
                    'expected' => $counts['lab item rows'],
                ]),
                'details'      => [],
            ];
        }

        if (!environment::engine_available()) {
            return [
                'ok'           => false,
                'counts'       => $counts,
                'firstfailure' => get_string('verify:noengine', 'local_catquizlab'),
                'details'      => [],
            ];
        }

        // The engine side, counted per item so a partial failure is visible.
        $byscale = [];
        foreach ($items as $item) {
            $catscaleid = (int) $item->assignedcatscaleid;
            $questionid = (int) $item->questionid;

            $row = $DB->get_record('local_catquiz_items', [
                'componentid'   => $questionid,
                'componentname' => 'question',
                'catscaleid'    => $catscaleid,
            ]);
            if (!$row) {
                $details = self::note($details, $item->itemname, 'verify:noitemrow');
                continue;
            }
            $counts['CAT item rows']++;

            if (empty($row->activeparamid)) {
                $details = self::note($details, $item->itemname, 'verify:noactiveparam');
                continue;
            }
            $param = $DB->get_record('local_catquiz_itemparams', ['id' => (int) $row->activeparamid]);
            if (!$param) {
                $details = self::note($details, $item->itemname, 'verify:activeparammissing');
                continue;
            }
            $counts['active params']++;

            $byscale[$catscaleid][] = ['questionid' => $questionid, 'contextid' => (int) $param->contextid];
        }

        // The retrieval check, once per scale rather than once per item: the
        // engine caches its lookup, and a per-item call would be an N+1 query
        // over a pool that can hold thousands of items.
        foreach ($byscale as $catscaleid => $entries) {
            $contextid = (int) ($entries[0]['contextid'] ?? 0);
            $visible = [];
            foreach (cat_item_provisioner::visible_items($catscaleid, $contextid) as $record) {
                $componentid = $record->componentid ?? $record->id ?? null;
                if ($componentid !== null) {
                    $visible[(int) $componentid] = true;
                }
            }
            foreach ($entries as $entry) {
                if (isset($visible[$entry['questionid']])) {
                    $counts['engine-visible']++;
                }
            }
        }

        $firstfailure = self::first_failure($counts);

        return [
            'ok'           => $firstfailure === null,
            'counts'       => $counts,
            'firstfailure' => $firstfailure,
            'details'      => $details,
        ];
    }

    /**
     * Verify every run of an experiment.
     *
     * @param int $experimentid The experiment.
     * @return array<int, array> Run id => report.
     */
    public static function verify_experiment(int $experimentid): array {
        global $DB;

        $reports = [];
        foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id', 'id') as $run) {
            $reports[(int) $run->id] = self::verify((int) $run->id);
        }

        return $reports;
    }

    /**
     * The first link of the chain that does not carry every item.
     *
     * @param array $counts The counted links, in chain order.
     * @return string|null A readable description, or null when the chain holds.
     */
    protected static function first_failure(array $counts): ?string {
        $expected = (int) $counts['lab item rows'];
        $chain = ['Moodle questions', 'CAT item rows', 'active params', 'engine-visible'];

        foreach ($chain as $link) {
            if ((int) $counts[$link] < $expected) {
                return get_string('verify:brokenlink', 'local_catquizlab', (object) [
                    'link'     => $link,
                    'found'    => (int) $counts[$link],
                    'expected' => $expected,
                ]);
            }
        }

        return null;
    }

    /**
     * Record a per-item note, keeping the list short.
     *
     * @param array $details The notes so far.
     * @param string $itemname The item.
     * @param string $stringkey The language string describing the problem.
     * @return array
     */
    protected static function note(array $details, string $itemname, string $stringkey): array {
        if (count($details) < 20) {
            $details[] = $itemname . ': ' . get_string($stringkey, 'local_catquizlab');
        }

        return $details;
    }
}
