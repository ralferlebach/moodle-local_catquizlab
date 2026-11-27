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

namespace local_catquizlab\local;

/**
 * How many answers one sitting of a run can take at most, from what was provisioned (#172).
 *
 * The worker stopped every sitting after 1000 answers of an execution, whatever the test: a classical test of 2500
 * items (CLARA run 103) could never finish, and a retry that continued at 264 stopped at 1264. The bound is the run's
 * own: the engine presents each item of its pool at most once in an attempt (the pool the materialiser created from
 * this run's items, local_catquizlab_item — the poolsize the collector and the validity rules use), so a sitting can
 * never hold more answers than the pool has items. Not a length rule of the test: the test ends where its strategy
 * and stop rules end it, the bound only says when an answer more would be impossible. A run without recorded items has
 * no bound, and none is invented: the worker then relies on its watch for progress alone.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sitting_budget {
    /** @var string The bound is the run's pool: no sitting can hold more answers. */
    public const KIND_POOL = 'pool';

    /** @var string No bound is known; none is invented. */
    public const KIND_UNKNOWN = 'unknown';

    /**
     * The bound of a run's sittings and the length its design expects.
     *
     * @param int $runid The run.
     * @return array{limit: int, kind: string, expected: int} limit 0 and kind 'unknown' without a pool; expected 0
     *     when the design does not fix the length (an adaptive test may stop anywhere up to its maximum).
     */
    public static function for_run(int $runid): array {
        global $DB;

        $pool = $runid > 0 ? (int) $DB->count_records('local_catquizlab_item', ['runid' => $runid]) : 0;
        if ($pool <= 0) {
            return ['limit' => 0, 'kind' => self::KIND_UNKNOWN, 'expected' => 0];
        }
        $expected = 0;
        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
        try {
            $definition = $run ? run_registry::definition_for($run) : [];
        } catch (\Throwable $e) {
            // The expected length is a note for the worker; a definition that cannot be read does not stop a claim.
            $definition = [];
        }
        if ((string) ($definition['strategy'] ?? '') === 'classic') {
            // The classical test plays its whole form.
            $expected = $pool;
        } else {
            $max = experiment_definition::engine_maximum($definition['budgets']['global']['maxitems'] ?? null, 0);
            $expected = $max > 0 ? min($max, $pool) : 0;
        }

        return ['limit' => $pool, 'kind' => self::KIND_POOL, 'expected' => $expected];
    }
}
