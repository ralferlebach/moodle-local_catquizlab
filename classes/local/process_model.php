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
     * @return array{stages: array[], step: string}
     */
    public static function chain(string $currentstep = ''): array {
        $component = 'local_catquizlab';

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
                'here'      => $currentstep !== '' && $step === $currentstep,
            ];
        }

        return ['stages' => $stages, 'step' => $currentstep];
    }
}
