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
 * One sentence about what is going on, and what to do about it.
 *
 * The overview shows worker counts, queue counts, experiment status and run
 * status, and every one of them is correct. Read together they were not a
 * picture:
 *
 *     Live workers: 0, crashed: 1
 *     Attempts queued/running/collected/failed: 150 / 0 / 0 / 0
 *     Experiment "Smoke Test 2": Running
 *     Run 4: Scheduled — progress 0%
 *
 * An operator reading that has to work out for themselves that the experiment
 * is not progressing, that the crashed worker is why, and that starting one is
 * the thing to do. Each number answers a question nobody asked; none of them
 * answers "is this working, and if not, what now".
 *
 * The states are ordered by how much they need doing about them, and the first
 * that applies wins — because an overview that reports three problems at once
 * makes the reader rank them, which is the work this class exists to do.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class situation {
    /** @var string Work is moving. */
    public const WORKING = 'working';

    /** @var string Nothing to do, and nothing wrong. */
    public const IDLE = 'idle';

    /** @var string Work is waiting with nobody to do it. */
    public const STALLED = 'stalled';

    /** @var string The installation cannot run anything yet. */
    public const NOTREADY = 'notready';

    /** @var string Runs failed and their reason is worth reading. */
    public const FAILING = 'failing';

    /**
     * Assess the installation as a whole.
     *
     * @return array{state: string, headline: string, detail: string, action: array|null}
     */
    public static function assess(): array {
        global $DB;

        return self::rank([
            'workers'    => worker_registry::summary(),
            'queued'     => $DB->count_records(
                'local_catquizlab_attempt',
                ['status' => attempt_scheduler::STATUS_QUEUED]
            ),
            'running'    => $DB->count_records(
                'local_catquizlab_attempt',
                ['status' => attempt_scheduler::STATUS_RUNNING]
            ),
            'failedruns' => $DB->count_records(
                'local_catquizlab_run',
                ['status' => registry::STATUS_FAILED]
            ),
            'wizard'     => setup_wizard::state(),
        ]);
    }

    /**
     * Turn a set of facts into the one thing worth saying about them.
     *
     * Separate from gathering them so the ranking can be exercised on its own.
     * The order is the point of this class, and an order that can only be tested
     * by building a whole installation into each state is an order nobody tests.
     *
     * @param array $facts workers, queued, running, failedruns, wizard.
     * @return array{state: string, headline: string, detail: string, action: array|null}
     */
    public static function rank(array $facts): array {
        $component = 'local_catquizlab';
        $workers = (array) $facts['workers'];
        $queue = ['queued' => (int) $facts['queued'], 'running' => (int) $facts['running']];
        $failedruns = (int) $facts['failedruns'];
        $wizard = (array) $facts['wizard'];

        $setupurl = new \moodle_url('/local/catquizlab/index.php', ['tab' => 'setup']);
        $runsurl = new \moodle_url('/local/catquizlab/runs.php', ['status' => registry::STATUS_FAILED]);

        // Not ready comes first: nothing else can be acted on until it is. The
        // exception is work already in the queue — attempts mean the
        // installation ran at some point, so the setup warning is stale and the
        // stalled queue is the live problem.
        if (!$wizard['ready'] && $queue['queued'] === 0 && $queue['running'] === 0) {
            return self::verdict(
                self::NOTREADY,
                get_string('situation:notready', $component),
                implode(', ', array_slice($wizard['blockers'], 0, 3)),
                get_string('setup:open', $component),
                $setupurl
            );
        }

        // Work waiting with nobody to do it: the one combination that never
        // resolves itself, and the one the reported installation sat in.
        if ($queue['queued'] > 0 && $workers['live'] === 0) {
            // Why nobody is doing it. "Stalled" with a start button, on an
            // installation whose worker switch was off, sent people pressing a
            // button that could not work and never said so.
            $missing = worker_launcher::missing(worker_launcher::config_from_settings());

            if ($missing !== []) {
                return self::verdict(
                    self::STALLED,
                    get_string('situation:stalled', $component, $queue['queued']),
                    get_string('situation:stallednotconfigured', $component, implode(', ', $missing)),
                    get_string('wizard:runandenable', $component),
                    $setupurl
                );
            }

            return self::verdict(
                self::STALLED,
                get_string('situation:stalled', $component, $queue['queued']),
                $workers['crashed'] > 0
                    ? get_string('situation:stalledcrashed', $component, $workers['crashed'])
                    : get_string('situation:stallednoworker', $component),
                get_string('ops:startworkers', $component),
                $setupurl
            );
        }

        if ($queue['running'] > 0 || ($queue['queued'] > 0 && $workers['live'] > 0)) {
            return self::verdict(
                self::WORKING,
                get_string('situation:working', $component, (object) [
                    'workers' => $workers['live'],
                    'queued'  => $queue['queued'],
                    'running' => $queue['running'],
                ]),
                '',
                '',
                null
            );
        }

        // Only once the queue is empty: a failed run matters less than work
        // that is stuck, because nobody is waiting on it.
        if ($failedruns > 0) {
            return self::verdict(
                self::FAILING,
                get_string('situation:failing', $component, $failedruns),
                get_string('situation:failinghint', $component),
                get_string('situation:openfailed', $component),
                $runsurl
            );
        }

        return self::verdict(self::IDLE, get_string('situation:idle', $component), '', '', null);
    }

    /**
     * Build one verdict.
     *
     * @param string $state One of the class constants.
     * @param string $headline What is going on.
     * @param string $detail Why, when that helps.
     * @param string $actionlabel What to do, when there is something.
     * @param \moodle_url|null $actionurl Where to do it.
     * @return array
     */
    protected static function verdict(
        string $state,
        string $headline,
        string $detail,
        string $actionlabel,
        ?\moodle_url $actionurl
    ): array {
        return [
            'state'    => $state,
            'headline' => $headline,
            'detail'   => $detail,
            'ok'       => in_array($state, [self::WORKING, self::IDLE], true),
            'warn'     => $state === self::FAILING,
            'problem'  => in_array($state, [self::STALLED, self::NOTREADY], true),
            'action'   => $actionurl === null ? null : [
                'label' => $actionlabel,
                'url'   => $actionurl->out(false),
            ],
        ];
    }
}
