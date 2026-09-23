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
 * The Moodle tasks this plugin runs on, in terms of this plugin's own objects.
 *
 * Everything here is visible in Moodle's task administration already, and that
 * is the problem: an ad-hoc task there is a class name and a blob of JSON, so
 * answering "is run 4 waiting for something, and for what" meant reading
 * `{"runid":4,"options":[]}` out of a list of identical-looking rows.
 *
 * The same information, said in the plugin's own terms — which run, which
 * experiment, due when — is the difference between a task list and an answer.
 *
 * Scheduled tasks get one further thing the administration cannot: whether cron
 * is actually running them. A task that is enabled and never runs looks exactly
 * like one that is disabled, from every angle except the last-run time.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class task_overview {
    /**
     * The scheduled tasks this plugin owns.
     *
     * One, and it is the one everything else waits on: without it nothing
     * reclaims lapsed claims, nothing dispatches workers and nothing moves.
     *
     * @var string[]
     */
    public const SCHEDULED = [
        '\local_catquizlab\task\pipeline_tick',
    ];

    /**
     * The ad-hoc tasks this plugin queues.
     *
     * These are the ones that carry a subject — a run, an experiment — and in
     * Moodle's own list they are a class name beside a JSON blob.
     *
     * @var string[]
     */
    public const ADHOC = [
        '\local_catquizlab\task\orchestrate_run',
        '\local_catquizlab\task\aggregate_results',
        '\local_catquizlab\task\dispatch_worker',
        '\local_catquizlab\task\collect_attempts',
        '\local_catquizlab\task\schedule_attempts',
        '\local_catquizlab\task\export_run',
    ];

    /**
     * Everything worth knowing about the tasks, for the interface.
     *
     * @return array{scheduled: array[], adhoc: array[], cron: array, hasadhoc: bool}
     */
    public static function state(): array {
        return [
            'scheduled' => self::scheduled_tasks(),
            'adhoc'     => self::adhoc_tasks(),
            'cron'      => self::cron_state(),
            'hasadhoc'  => self::adhoc_tasks() !== [],
        ];
    }

    /**
     * The scheduled tasks, with when they last ran and when they are due.
     *
     * @return array[]
     */
    public static function scheduled_tasks(): array {
        $component = 'local_catquizlab';
        $rows = [];

        foreach (self::SCHEDULED as $classname) {
            $task = \core\task\manager::get_scheduled_task($classname);
            if ($task === false) {
                continue;
            }

            $lastrun = (int) $task->get_last_run_time();
            $nextrun = (int) $task->get_next_run_time();
            $disabled = $task->get_disabled();

            $rows[] = [
                'classname' => $classname,
                'name'      => $task->get_name(),
                'disabled'  => $disabled,
                'enabled'   => !$disabled,
                'lastrun'   => $lastrun > 0
                    ? get_string('task:ranago', $component, duration::human(time() - $lastrun))
                    : get_string('task:neverran', $component),
                'nextrun'   => $disabled
                    ? get_string('task:notscheduled', $component)
                    : ($nextrun <= time()
                        ? get_string('task:duenow', $component)
                        : get_string('task:duein', $component, duration::human($nextrun - time()))),
                // Overdue by more than a quarter of an hour is not slow cron,
                // it is cron that is not running — and that is the single most
                // common reason a pipeline sits still.
                'overdue'   => !$disabled && $nextrun > 0 && (time() - $nextrun) > 900,
                // Running a task is a state change, so it is posted. The URL
                // stays bare and the parameters travel in the form.
                'runurl'    => (new \moodle_url('/local/catquizlab/tasks.php'))->out(false),
                'sesskey'   => sesskey(),
            ];
        }

        return $rows;
    }

    /**
     * The queued ad-hoc tasks, named after what they will do.
     *
     * @return array[]
     */
    public static function adhoc_tasks(): array {
        global $DB;

        $component = 'local_catquizlab';
        [$insql, $params] = $DB->get_in_or_equal(self::ADHOC, SQL_PARAMS_NAMED, 'cls');

        $rows = [];
        foreach ($DB->get_records_select('task_adhoc', 'classname ' . $insql, $params, 'nextruntime ASC') as $row) {
            $data = json_decode((string) $row->customdata, true) ?: [];

            $rows[] = [
                'id'        => (int) $row->id,
                'name'      => self::short_name((string) $row->classname),
                'subject'   => self::describe($data),
                'due'       => (int) $row->nextruntime <= time()
                    ? get_string('task:duenow', $component)
                    : get_string('task:duein', $component, duration::human((int) $row->nextruntime - time())),
                // A task that has failed and is waiting out its backoff is not
                // the same as one that has not started, and the count is the
                // only thing that says which.
                // The delay a failed task is waiting out. This is the value the
                // report named: 30720 s, where "8 hours 32 mins" says whether
                // it is worth waiting for.
                'failures'  => (int) $row->faildelay > 0
                    ? get_string('task:failing', $component, duration::human((int) $row->faildelay))
                    : '',
                'running'   => !empty($row->timestarted),
                // Offered only where it would do something: a task that is
                // waiting out a failure delay.
                'canrequeue' => (int) $row->faildelay > 0 && empty($row->timestarted),
            ];
        }

        return $rows;
    }

    /**
     * Whether cron is running at all, and when it last did.
     *
     * @return array{ok: bool, detail: string}
     */
    public static function cron_state(): array {
        $component = 'local_catquizlab';

        $last = (int) get_config('tool_task', 'lastcronstart');
        if ($last === 0) {
            $last = (int) get_config('core', 'lastcron');
        }

        if ($last === 0) {
            return ['ok' => false, 'detail' => get_string('task:cronnever', $component)];
        }

        $ago = time() - $last;

        // An hour: cron normally runs every minute, so this is not a tight
        // threshold — it is the difference between "running" and "not".
        return [
            'ok'     => $ago < HOURSECS,
            'detail' => get_string('task:cronlast', $component, duration::human($ago)),
        ];
    }

    /**
     * Turn a task's stored data into the object it is about.
     *
     * @param array $data The task's custom data.
     * @return string
     */
    protected static function describe(array $data): string {
        global $DB;

        $component = 'local_catquizlab';

        if (!empty($data['runid'])) {
            $runid = (int) $data['runid'];
            $cellkey = (string) $DB->get_field('local_catquizlab_run', 'cellkey', ['id' => $runid]);

            return $cellkey !== ''
                ? get_string('task:forruncell', $component, (object) ['run' => $runid, 'cell' => $cellkey])
                : get_string('task:forrun', $component, $runid);
        }

        if (!empty($data['experimentid'])) {
            return get_string('task:forexperiment', $component, (int) $data['experimentid']);
        }

        if (!empty($data['attemptid'])) {
            return get_string('task:forattempt', $component, (int) $data['attemptid']);
        }

        // Better an honest blank than a JSON blob pasted into a table cell,
        // which is what this replaces.
        return '';
    }

    /**
     * The readable part of a task class name.
     *
     * @param string $classname The fully qualified class.
     * @return string
     */
    protected static function short_name(string $classname): string {
        $short = substr((string) strrchr($classname, '\\'), 1);
        $key = 'task:' . str_replace('_', '', $short);

        $manager = get_string_manager();

        return $manager->string_exists($key, 'local_catquizlab')
            ? get_string($key, 'local_catquizlab')
            : $short;
    }
}
