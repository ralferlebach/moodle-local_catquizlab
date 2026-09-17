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
 * What happens, in what order, and what makes it happen.
 *
 * The interface shows technical objects — a queue, a task, a worker, a run —
 * without saying how they follow from one another. Somebody can read every
 * panel on every tab and still not know what happens first, what produces what,
 * what runs by itself, or when results appear.
 *
 * These eight stages are that chain, said once. Each says what it produces and
 * whether a person has to do anything, because "this runs by itself" and "this
 * is waiting for you" are the two things somebody actually needs from a status
 * they do not recognise.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_model {
    /** @var array<string, string> Which of the four steps each stage lives on. */
    protected const STEP_OF_STAGE = [
        'define'    => 'plan',
        'variants'  => 'plan',
        'prepare'   => 'progress',
        'queue'     => 'progress',
        'simulate'  => 'progress',
        'collect'   => 'progress',
        'aggregate' => 'progress',
        'evaluate'  => 'results',
    ];

    /**
     * The chain, with the current position marked.
     *
     * @param string $currentstep One of the four process steps.
     * @param int $experimentid The experiment whose real position to mark, or 0.
     * @return array{stages: array[], step: string, reached: string}
     */
    public static function chain(string $currentstep = '', int $experimentid = 0): array {
        $component = 'local_catquizlab';

        // Where the work actually is, not which tab is open. Marking by tab put
        // "you are here" on five stages at once on the progress step, which
        // tells somebody nothing they did not already know from the tab.
        $reached = $experimentid > 0 ? self::reached_stage($experimentid) : '';

        $stages = [];
        $number = 0;
        foreach (self::STEP_OF_STAGE as $id => $step) {
            $number++;
            $stages[] = [
                'id'        => $id,
                'number'    => $number,
                'label'     => get_string('flow:' . $id, $component),
                'produces'  => get_string('flow:' . $id . 'produces', $component),
                // The distinction that matters most to somebody looking at a
                // status they do not recognise: is this waiting for me.
                'automatic' => in_array($id, ['prepare', 'queue', 'collect', 'aggregate'], true),
                'manual'    => in_array($id, ['define', 'variants', 'simulate', 'evaluate'], true),
                'here'      => $reached !== '' ? $id === $reached : false,
                // The tab still colours the group, which is a weaker claim and
                // an honest one.
                'onthisstep' => $currentstep !== '' && $step === $currentstep,
            ];
        }

        return ['stages' => $stages, 'step' => $currentstep, 'reached' => $reached];
    }

    /**
     * The stage this experiment has actually got to.
     *
     * The furthest thing that has happened, not the furthest thing that could:
     * an experiment with thirty runs of which two are finished is still
     * simulating, and saying it is evaluating would be a lie told by a maximum.
     *
     * @param int $experimentid The experiment.
     * @return string The stage id, or '' when it cannot be told.
     */
    public static function reached_stage(int $experimentid): string {
        global $DB;

        if (!$DB->record_exists('local_catquizlab_experiment', ['id' => $experimentid])) {
            return '';
        }

        $runs = $DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid]);
        if ($runs === []) {
            // Defined, with nothing built from it yet.
            return 'variants';
        }

        $runids = array_keys($runs);
        [$insql, $params] = $DB->get_in_or_equal($runids, SQL_PARAMS_NAMED, 'r');

        $statuses = [];
        foreach ($runs as $run) {
            $statuses[(int) $run->status] = true;
        }

        // Everything finished: the experiment is there to be read.
        $unfinished = array_diff(array_keys($statuses), [registry::STATUS_FINISHED]);
        if ($unfinished === []) {
            return 'evaluate';
        }

        if (isset($statuses[registry::STATUS_AGGREGATING])) {
            return 'aggregate';
        }

        $collected = $DB->count_records_select(
            'local_catquizlab_attempt',
            'runid ' . $insql . ' AND status = :collected',
            $params + ['collected' => attempt_scheduler::STATUS_COLLECTED]
        );

        if (isset($statuses[registry::STATUS_RUNNING])) {
            // Something has come back already, so collection is under way too;
            // the simulating is what a person is waiting on.
            return 'simulate';
        }

        if (isset($statuses[registry::STATUS_READY])) {
            return $collected > 0 ? 'collect' : 'queue';
        }

        if (isset($statuses[registry::STATUS_SCHEDULED])) {
            return 'prepare';
        }

        return 'variants';
    }
}
