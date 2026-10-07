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

/**
 * Which scales were active, locked or dropped after each step of a sitting.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * The state of every scale after every step, from the engine's own record of its changes (#106, #109).
 *
 * The engine's lists of active, locked and dropped scales say how a sitting
 * ended. When a scale left the selection they do not say — and guessing it from
 * the last step a scale was estimated at would not be the engine's finding. In
 * trace mode the engine is to record every change with its step
 * (local_catquiz#133): progress.scalestatetrace, a list of entries with `step`
 * (questions played so far), `scaleid` and `event` — activated, deactivated,
 * locked, unlocked or dropped. This replays them.
 *
 * Where a sitting has no such record — an engine before that change, or not in
 * trace mode — nothing is known per step, and nothing is made up: the callers
 * show "N/A" and the states at the end.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scale_states {
    /** @var string Questions are selected from the scale. */
    public const ACTIVE = 'active';

    /** @var string Out of the selection; it may come back. */
    public const INACTIVE = 'inactive';

    /** @var string Out of the selection; it comes back only when forced. */
    public const LOCKED = 'locked';

    /** @var string Removed for the rest of the sitting. */
    public const DROPPED = 'dropped';

    /** @var array<string, string> The engine's event => the state a scale is in after it. */
    protected const EVENTS = [
        'activated'   => self::ACTIVE,
        'deactivated' => self::INACTIVE,
        'locked'      => self::LOCKED,
        // The lock is lifted; the scale is not active by that alone.
        'unlocked'    => self::INACTIVE,
        'dropped'     => self::DROPPED,
    ];

    /**
     * Whether a sitting's trace has the engine's record of state changes.
     *
     * @param array $trace The sitting's trace.
     * @return bool
     */
    public static function recorded(array $trace): bool {
        return !empty($trace['progress']['scalestatetrace']) && is_array($trace['progress']['scalestatetrace']);
    }

    /**
     * The state of every scale after every step.
     *
     * A change the engine recorded at step k — after k questions were played —
     * is in force after step k and from then on. Changes before the first
     * question (step 0) are the state the sitting starts from, and are in force
     * at step 1.
     *
     * @param array $trace The sitting's trace.
     * @param int $steps The number of steps of the sitting.
     * @return array[]|null Step (1-based) => scale id => state; null where the engine recorded no changes.
     */
    public static function by_step(array $trace, int $steps): ?array {
        if (!self::recorded($trace)) {
            return null;
        }
        $events = [];
        foreach ((array) $trace['progress']['scalestatetrace'] as $event) {
            $event = (array) $event;
            // An event this version does not know is left out, not guessed at.
            if (!isset($event['step'], $event['scaleid'], $event['event'], self::EVENTS[(string) $event['event']])) {
                continue;
            }
            $events[(int) $event['step']][] = [(int) $event['scaleid'], self::EVENTS[(string) $event['event']]];
        }
        ksort($events);

        $current = [];
        $bystep = [];
        $pending = array_keys($events);
        for ($step = 1; $step <= max(1, $steps); $step++) {
            while ($pending !== [] && $pending[0] <= $step) {
                foreach ($events[array_shift($pending)] as [$scaleid, $state]) {
                    // Dropped is for good: the engine activates no dropped scale again.
                    if (($current[$scaleid] ?? '') === self::DROPPED) {
                        continue;
                    }
                    $current[$scaleid] = $state;
                }
            }
            $bystep[$step] = $current;
        }

        return $bystep;
    }

    /**
     * How many scales were in each state after a step.
     *
     * @param array $states Scale id => state, one entry of by_step().
     * @return array{active: int, locked: int, dropped: int, inactive: int}
     */
    public static function counts(array $states): array {
        $counts = [self::ACTIVE => 0, self::LOCKED => 0, self::DROPPED => 0, self::INACTIVE => 0];
        foreach ($states as $state) {
            if (isset($counts[$state])) {
                $counts[$state]++;
            }
        }

        return $counts;
    }

    /**
     * The course of one scale's state over a sitting, as runs: from which step to which it was in which state.
     *
     * @param array[] $bystep From by_step().
     * @param int $scaleid The scale.
     * @return array[] Each: from, to (steps, inclusive), state. Empty where the scale was never in the selection.
     */
    public static function course(array $bystep, int $scaleid): array {
        $runs = [];
        foreach ($bystep as $step => $states) {
            $state = $states[$scaleid] ?? null;
            if ($state === null) {
                continue;
            }
            $last = count($runs) - 1;
            if ($last >= 0 && $runs[$last]['state'] === $state && $runs[$last]['to'] === $step - 1) {
                $runs[$last]['to'] = $step;
            } else {
                $runs[] = ['from' => (int) $step, 'to' => (int) $step, 'state' => $state];
            }
        }

        return $runs;
    }

    /**
     * A course as a sentence: "steps 1–7 active; from step 8 dropped".
     *
     * @param array[] $runs From course().
     * @param int $laststep The sitting's last step.
     * @return string
     */
    public static function describe(array $runs, int $laststep): string {
        $parts = [];
        foreach ($runs as $run) {
            $a = (object) ['from' => $run['from'], 'to' => $run['to'], 'state' => self::label($run['state'])];
            if ($run['to'] >= $laststep && $run['from'] < $laststep) {
                $parts[] = get_string('scalestate:from', 'local_catquizlab', $a);
            } else if ($run['from'] === $run['to']) {
                $parts[] = get_string('scalestate:at', 'local_catquizlab', $a);
            } else {
                $parts[] = get_string('scalestate:range', 'local_catquizlab', $a);
            }
        }

        return implode('; ', $parts);
    }

    /**
     * A state as it is shown.
     *
     * @param string $state One of the constants.
     * @return string
     */
    public static function label(string $state): string {
        return get_string('scalestate:' . $state, 'local_catquizlab');
    }
}
