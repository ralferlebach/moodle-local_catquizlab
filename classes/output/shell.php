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

use local_catquizlab\local\situation;

/**
 * The one page frame every CatQuizLab page renders inside.
 *
 * The navigation existed on `index.php` and nowhere else, so opening a run or a
 * result dropped the reader out of the process they were in the middle of and
 * onto a page that looked like it belonged somewhere else. Worse, the tabs were
 * cut by object — "experiments and runs", "settings" — rather than by the work,
 * so there was no place that meant "what is happening right now".
 *
 * Four steps, in the order the work happens:
 *
 *   1. Vorbereitung      — can this installation run anything
 *   2. Experimentenplan  — what shall be run
 *   3. Verlauf           — what is happening
 *   4. Ergebnisse        — what came out
 *
 * Settings are not a step. They are reached from preparation, where somebody
 * setting an installation up is already looking.
 *
 * The chosen experiment travels with the reader: every page keeps it in its
 * links, so moving between steps does not mean choosing it again.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class shell {
    /** @var string Step 1: is the installation able to run anything. */
    public const STEP_PREPARE = 'prepare';

    /** @var string Step 2: what shall be run. */
    public const STEP_PLAN = 'plan';

    /** @var string Step 3: what is happening. */
    public const STEP_PROGRESS = 'progress';

    /** @var string Step 4: what came out. */
    public const STEP_RESULTS = 'results';

    /**
     * The four steps, in order, with where each one lives.
     *
     * @return array<string, string>
     */
    public static function steps(): array {
        return [
            self::STEP_PREPARE  => '/local/catquizlab/index.php',
            self::STEP_PLAN     => '/local/catquizlab/index.php',
            self::STEP_PROGRESS => '/local/catquizlab/runs.php',
            self::STEP_RESULTS  => '/local/catquizlab/results.php',
        ];
    }

    /**
     * Render the frame: title, experiment selector, state, and the four steps.
     *
     * @param string $current Which step this page belongs to.
     * @param int $experimentid The experiment in context, or 0.
     * @return string
     */
    public static function render(string $current, int $experimentid = 0): string {
        global $OUTPUT;

        return $OUTPUT->render_from_template('local_catquizlab/shell', self::context($current, $experimentid));
    }

    /**
     * Everything the frame shows.
     *
     * @param string $current Which step this page belongs to.
     * @param int $experimentid The experiment in context, or 0.
     * @return array
     */
    public static function context(string $current, int $experimentid = 0): array {
        global $DB;

        $component = 'local_catquizlab';

        $steps = [];
        $number = 0;
        foreach (self::steps() as $key => $path) {
            $number++;
            $params = $experimentid > 0 ? ['experimentid' => $experimentid] : [];
            if ($key === self::STEP_PREPARE) {
                $params['tab'] = 'setup';
            } else if ($key === self::STEP_PLAN) {
                $params['tab'] = 'experiments';
            }

            $steps[] = [
                'key'     => $key,
                'number'  => $number,
                'label'   => get_string('step:' . $key, $component),
                'url'     => (new \moodle_url($path, $params))->out(false),
                'current' => $key === $current,
            ];
        }

        // The selector is on every step, because the question "which
        // experiment" does not stop being relevant when the reader moves from
        // planning to watching.
        $experiments = [];
        foreach ($DB->get_records('local_catquizlab_experiment', null, 'timemodified DESC', 'id, name', 0, 50) as $row) {
            $experiments[] = [
                'id'       => (int) $row->id,
                'name'     => format_string($row->name),
                'selected' => (int) $row->id === $experimentid,
            ];
        }

        $verdict = situation::assess();

        return [
            'title'        => get_string('pluginname', $component),
            'steps'        => $steps,
            'experiments'  => $experiments,
            'hasexperiments' => $experiments !== [],
            'experimentid' => $experimentid,
            'selecturl'    => (new \moodle_url(self::steps()[$current] ?? '/local/catquizlab/index.php'))->out(false),
            'currentstep'  => $current,
            'situation'    => $verdict,
            'settingsurl'  => (new \moodle_url('/local/catquizlab/index.php', ['tab' => 'settings']))->out(false),
        ];
    }
}
