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
 * One shape for every stateful thing this plugin shows: state, why, what next.
 *
 * The pieces were all there and each said its piece differently. A run showed a
 * status word, the queue a number, a worker a count, a task a class name — and
 * the reader had to hold four vocabularies at once to work out whether anything
 * was wrong. "Scheduled", "1 worker", "150 waiting", "0%": every one accurate
 * and none of them actionable.
 *
 * The contract here is deliberately narrow, because a contract that allows
 * exceptions is a style guide:
 *
 *   state   — what it is, in words that mean something without the schema
 *   reason  — the evidence: which task, which attempt, since when, how many
 *   action  — the one thing to do about it, or nothing
 *
 * A component with nothing to do about it has no action, and that is a positive
 * statement rather than a gap: buttons that appear on healthy things teach
 * people to press buttons.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class status_report {
    /** @var string Working as intended. */
    public const GOOD = 'good';

    /** @var string Usable, but something needs attention. */
    public const WATCH = 'watch';

    /** @var string Stopped, or about to be. */
    public const BAD = 'bad';

    /**
     * The state of one run, with what it is waiting for.
     *
     * @param int|\stdClass $run The run or its id.
     * @return array
     */
    public static function run($run): array {
        global $DB;

        $component = 'local_catquizlab';
        $run = is_object($run) ? $run : $DB->get_record('local_catquizlab_run', ['id' => $run]);
        if (!$run) {
            return self::card(self::BAD, get_string('report:runmissing', $component), '', null);
        }

        $runid = (int) $run->id;
        $status = (int) $run->status;
        $counts = run_lifecycle::attempt_counts($runid);

        if ($status === registry::STATUS_SCHEDULED) {
            // "Scheduled" says nothing about whether anything is happening. The
            // useful facts are which task it waits for and whether that task is
            // being run at all.
            $task = self::pending_task_for($runid, '\local_catquizlab\task\orchestrate_run');
            $cron = task_overview::cron_state();

            return self::card(
                $cron['ok'] && $task !== null ? self::WATCH : self::BAD,
                get_string('report:provisionpending', $component),
                $task !== null
                    ? get_string('report:waitingfortask', $component, (object) [
                        'task' => get_string('task:orchestraterun', $component),
                        'due'  => $task['due'],
                    ]) . ' ' . $cron['detail']
                    : get_string('report:notaskqueued', $component) . ' ' . $cron['detail'],
                [
                    'label' => get_string('action:provision', $component),
                    'url'   => (new \moodle_url('/local/catquizlab/runs.php', [
                        'runid' => $runid, 'action' => 'provision', 'sesskey' => sesskey(),
                    ]))->out(false),
                ]
            );
        }

        if ($status === registry::STATUS_FAILED) {
            $failure = run_lifecycle::failure_details($runid);

            return self::card(
                self::BAD,
                get_string('report:runfailed', $component),
                $failure['reason'] !== '' ? $failure['reason'] : get_string('report:noreason', $component),
                [
                    'label' => get_string('action:recheck', $component),
                    'url'   => (new \moodle_url('/local/catquizlab/runs.php', [
                        'runid' => $runid, 'action' => 'recheck', 'sesskey' => sesskey(),
                    ]))->out(false),
                ]
            );
        }

        if ($status === registry::STATUS_READY || $status === registry::STATUS_RUNNING) {
            $breakdown = attempt_scheduler::queue_breakdown();
            $workers = worker_registry::summary();

            // Ready with work and nobody to do it is the state that never
            // resolves itself, and it reads as "running" without this.
            if ($counts['open'] > 0 && $workers['live'] === 0 && $breakdown['claimable'] > 0) {
                return self::card(
                    self::BAD,
                    get_string('report:waitingforworker', $component),
                    get_string('report:openattempts', $component, $counts['open']),
                    [
                        'label' => get_string('ops:startworkers', $component),
                        'url'   => (new \moodle_url('/local/catquizlab/index.php', ['tab' => 'setup']))->out(false),
                    ]
                );
            }

            return self::card(
                self::GOOD,
                get_string('report:runrunning', $component),
                get_string('report:progress', $component, (object) [
                    'done'  => $counts['collected'],
                    'total' => $counts['total'],
                ]),
                null
            );
        }

        if ($status === registry::STATUS_FINISHED) {
            return self::card(
                self::GOOD,
                get_string('report:runfinished', $component),
                get_string('report:progress', $component, (object) [
                    'done'  => $counts['collected'],
                    'total' => $counts['total'],
                ]),
                null
            );
        }

        return self::card(
            self::WATCH,
            run_registry::status_label($status),
            get_string('report:progress', $component, (object) [
                'done'  => $counts['collected'],
                'total' => $counts['total'],
            ]),
            null
        );
    }

    /**
     * The state of one worker, with what it is playing.
     *
     * @param \stdClass $worker The registry row.
     * @return array
     */
    public static function worker(\stdClass $worker): array {
        global $DB;

        $component = 'local_catquizlab';
        $ago = time() - (int) $worker->heartbeat;
        $attemptid = (int) ($worker->currentattempt ?? 0);

        if ($attemptid > 0) {
            $runid = (int) $DB->get_field('local_catquizlab_attempt', 'runid', ['id' => $attemptid]);

            return self::card(
                self::GOOD,
                get_string('report:workerworking', $component),
                get_string('report:workeron', $component, (object) [
                    'attempt'   => $attemptid,
                    'run'       => $runid,
                    'heartbeat' => format_time($ago),
                ]),
                null
            );
        }

        // Idle is not a problem, but "idle" alone invites the question this
        // answers: there is nothing for it to pick up.
        $claimable = attempt_scheduler::queue_breakdown()['claimable'];

        return self::card(
            self::GOOD,
            get_string('report:workeridle', $component),
            $claimable === 0
                ? get_string('report:noclaimable', $component)
                : get_string('report:claimablewaiting', $component, $claimable),
            null
        );
    }

    /**
     * The state of the attempt queue.
     *
     * @return array
     */
    public static function queue(): array {
        $component = 'local_catquizlab';
        $breakdown = attempt_scheduler::queue_breakdown();

        if ($breakdown['queued'] === 0 && $breakdown['running'] === 0) {
            return self::card(self::GOOD, get_string('report:queueempty', $component), '', null);
        }

        // The reported case: 150 waiting, none of them claimable. One number
        // said "waiting", which is true and reads as "a worker will get to it".
        if ($breakdown['claimable'] === 0 && $breakdown['blocked'] > 0) {
            return self::card(
                self::BAD,
                get_string('report:queueblocked', $component, $breakdown['blocked']),
                get_string('report:queueblockedwhy', $component),
                [
                    'label' => get_string('situation:openfailed', $component),
                    'url'   => (new \moodle_url('/local/catquizlab/runs.php', [
                        'status' => registry::STATUS_FAILED,
                    ]))->out(false),
                ]
            );
        }

        return self::card(
            $breakdown['claimable'] > 0 && worker_registry::summary()['live'] === 0
                ? self::BAD
                : self::GOOD,
            get_string('report:queuestate', $component, (object) [
                'claimable' => $breakdown['claimable'],
                'running'   => $breakdown['running'],
            ]),
            self::queue_reason($breakdown, $component),
            null
        );
    }

    /**
     * The state of the pipeline itself.
     *
     * @return array
     */
    public static function pipeline(): array {
        $component = 'local_catquizlab';
        $cron = task_overview::cron_state();
        $scheduled = task_overview::scheduled_tasks();
        $tick = $scheduled[0] ?? null;

        if (!$cron['ok']) {
            return self::card(
                self::BAD,
                get_string('report:pipelinestopped', $component),
                $cron['detail'],
                $tick !== null ? ['label' => get_string('task:runnow', $component), 'url' => $tick['runurl']] : null
            );
        }

        if ($tick !== null && !empty($tick['disabled'])) {
            return self::card(
                self::BAD,
                get_string('report:pipelinedisabled', $component),
                $tick['lastrun'],
                [
                    'label' => get_string('wizard:runandenable', $component),
                    'url'   => (new \moodle_url('/local/catquizlab/index.php', ['tab' => 'setup']))->out(false),
                ]
            );
        }

        return self::card(
            self::GOOD,
            get_string('report:pipelineactive', $component),
            $tick !== null
                ? $tick['lastrun'] . ', ' . $tick['nextrun']
                : $cron['detail'],
            null
        );
    }

    /**
     * Why the queue looks the way it does.
     *
     * @param array $breakdown The queue breakdown.
     * @param string $component For the strings.
     * @return string
     */
    protected static function queue_reason(array $breakdown, string $component): string {
        $parts = [];
        foreach (['blocked', 'notdue', 'paused'] as $kind) {
            if ($breakdown[$kind] > 0) {
                $parts[] = get_string('queue:' . $kind, $component) . ' ' . $breakdown[$kind];
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * The queued ad-hoc task of a given class for a run, if there is one.
     *
     * @param int $runid The run.
     * @param string $classname The task class.
     * @return array|null
     */
    protected static function pending_task_for(int $runid, string $classname): ?array {
        global $DB;

        $component = 'local_catquizlab';
        foreach ($DB->get_records('task_adhoc', ['classname' => $classname]) as $row) {
            $data = json_decode((string) $row->customdata, true) ?: [];
            if ((int) ($data['runid'] ?? 0) !== $runid) {
                continue;
            }

            $due = (int) $row->nextruntime;

            return [
                'id'  => (int) $row->id,
                'due' => $due <= time()
                    ? get_string('task:duenow', $component)
                    : get_string('task:duein', $component, format_time($due - time())),
            ];
        }

        return null;
    }

    /**
     * Assemble one card.
     *
     * @param string $level GOOD, WATCH or BAD.
     * @param string $state What it is.
     * @param string $reason The evidence.
     * @param array|null $action The one thing to do, or null.
     * @return array
     */
    protected static function card(string $level, string $state, string $reason, ?array $action): array {
        return [
            'level'  => $level,
            'good'   => $level === self::GOOD,
            'watch'  => $level === self::WATCH,
            'bad'    => $level === self::BAD,
            'state'  => $state,
            'reason' => $reason,
            'action' => $action,
        ];
    }
}
