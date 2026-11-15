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
 * Progress of all experiments at once: the whole population, each experiment, each run (#158).
 *
 * "All experiments" showed the last 25 unfinished runs and nothing else: no total, no experiment, nothing finished.
 * This builds every experiment's snapshot exactly as its own page does (live_progress::build), from one reading of
 * every run and one forecast, and adds them up with exact denominators — never an average of percentages.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class progress_overview {
    /** @var int Experiments shown per page; the totals cover all of them. */
    public const PERPAGE = 20;

    /**
     * Every experiment's progress, the totals, and when everything open will be done.
     *
     * @param int $page The page of experiment sections, from 0.
     * @param int $perpage Sections per page.
     * @param int|null $now The clock; null for the real one.
     * @return array totals, time, experiments (this page's, A–Z), counts by state, pages.
     */
    public static function build(int $page = 0, int $perpage = self::PERPAGE, ?int $now = null): array {
        global $DB;

        $component = 'local_catquizlab';
        $now = $now ?? time();
        // Every experiment not being deleted, A–Z as the library lists them (#153), case and accents folded.
        $experiments = $DB->get_records_select(
            'local_catquizlab_experiment',
            'timedeleting IS NULL OR timedeleting = 0',
            null,
            '',
            'id, name, status'
        );
        $experiments = experiment_order::sort($experiments);

        $runs = $experiments === [] ? [] : $DB->get_records_list(
            'local_catquizlab_run',
            'experimentid',
            array_keys($experiments),
            'id ASC'
        );
        $byexperiment = [];
        foreach ($runs as $run) {
            $byexperiment[(int) $run->experimentid][(int) $run->id] = $run;
        }
        $runids = array_map('intval', array_keys($runs));
        $figures = run_figures::read($runids, $now);
        $forecast = eta::forecast($runids, $now, $figures);
        $queue = self::queue_positions();

        $snapshots = [];
        foreach ($experiments as $id => $experiment) {
            $snapshot = live_progress::build((int) $id, $byexperiment[(int) $id] ?? [], $figures, $forecast, $now);
            // Unescaped: the template escapes it, once (#152).
            $snapshot['name'] = format_string((string) $experiment->name, true, ['escape' => false]);
            $snapshot['url'] = (new \moodle_url('/local/catquizlab/runs.php', ['experimentid' => (int) $id]))->out(false);
            $snapshot['queueposition'] = $queue[(int) $id] ?? null;
            $snapshot['open'] = !in_array($snapshot['state'], [live_progress::FINISHED, live_progress::DRAFT], true);
            $snapshot['runcount'] = count($snapshot['runs']);
            $snapshots[(int) $id] = $snapshot;
        }

        $totals = self::totals($snapshots, $forecast);
        $paged = array_slice($snapshots, $page * $perpage, $perpage, true);

        return [
            'totals' => $totals,
            'time' => self::time($snapshots, $forecast, $queue, $now),
            'experiments' => array_values($paged),
            'hasexperiments' => $paged !== [],
            'page' => $page,
            'perpage' => $perpage,
            'pages' => (int) max(1, ceil(count($snapshots) / max(1, $perpage))),
            'pager' => self::pager($page, (int) ceil(count($snapshots) / max(1, $perpage))),
            'computed' => $now,
            'computedtext' => userdate($now, get_string('strftimetime', 'langconfig')),
            'sittingstext' => get_string(
                'progress:sittingsvalue',
                $component,
                (object) ['done' => $totals['done'], 'total' => $totals['total']]
            ),
        ];
    }

    /**
     * The whole population added up: experiments and runs by state, sittings and questions with exact denominators.
     *
     * @param array[] $snapshots Every experiment's snapshot.
     * @param array $forecast The forecast they were built from.
     * @return array
     */
    protected static function totals(array $snapshots, array $forecast): array {
        $component = 'local_catquizlab';
        $states = [];
        $out = ['experiments' => count($snapshots), 'runs' => 0, 'done' => 0, 'total' => 0, 'failed' => 0,
            'inflight' => 0, 'notstarted' => 0];
        foreach ($snapshots as $s) {
            $states[$s['state']] = ($states[$s['state']] ?? 0) + 1;
            $out['runs'] += count($s['runs']);
            $out['done'] += (int) $s['done'];
            $out['total'] += (int) $s['total'];
            $out['failed'] += (int) $s['now']['failed'];
            $out['inflight'] += (int) $s['now']['inflight'];
            if ($s['state'] === live_progress::DRAFT || ($s['time']['started'] ?? null) === null) {
                $out['notstarted']++;
            }
        }
        // Questions summed over every run of every experiment, the least certain kind wins (#158 AK2).
        $runids = [];
        foreach ($snapshots as $s) {
            foreach ($s['runs'] as $r) {
                $runids[] = (int) $r['runid'];
            }
        }
        $questions = question_progress::total(array_intersect_key($forecast['questions'], array_flip($runids)));
        $a = (object) ['done' => $questions['done'], 'expected' => $questions['expected'] ?? '–',
            'percent' => $questions['percent'] ?? '–'];
        $questions['text'] = $questions['expected'] === null
            ? get_string('questions:doneunknown', $component, $a)
            : get_string('questions:done' . $questions['kind'], $component, $a);
        $questions['kindlabel'] = get_string('questions:kind' . $questions['kind'], $component);
        $questions['percent'] = $questions['percent'] ?? 0;

        $out['percent'] = $out['total'] > 0
            ? ($out['done'] < $out['total'] ? min(99, (int) floor(100 * $out['done'] / $out['total'])) : 100) : 0;
        $out['questions'] = $questions;
        $out['states'] = [];
        foreach (
            [live_progress::RUNNING, live_progress::STARTING, live_progress::WAITING, live_progress::PAUSED,
                live_progress::BLOCKED, live_progress::AGGREGATING, live_progress::FINISHED, live_progress::DRAFT] as $state
        ) {
            $out['states'][] = ['state' => $state, 'count' => (int) ($states[$state] ?? 0),
                'label' => get_string('progress:state' . $state, $component)];
        }
        $out['text'] = get_string('overview:population', $component, (object) [
            'experiments' => $out['experiments'], 'runs' => $out['runs'], 'done' => $out['done'], 'total' => $out['total'],
            'notstarted' => $out['notstarted'],
        ]);

        return $out;
    }

    /**
     * When everything open will be done: the open experiments' forecasts, then those waiting in the execution queue
     * one after the other (#158 AK3). Without a basis for any of them, no time, and what is known as a lower bound.
     *
     * @param array[] $snapshots Every experiment's snapshot.
     * @param array $forecast The forecast.
     * @param int[] $queue Experiments waiting in the execution queue, by position.
     * @param int $now The clock.
     * @return array reliability, remaining, finish, lowerbound, reason, text.
     */
    protected static function time(array $snapshots, array $forecast, array $queue, int $now): array {
        $component = 'local_catquizlab';
        $out = ['reliability' => eta::FINAL, 'remaining' => 0, 'finish' => null, 'lowerbound' => null, 'reason' => '',
            'margin' => null];
        $latest = $now;
        $lastend = 0;
        $open = 0;
        $missing = '';
        $provisional = false;
        foreach ($snapshots as $id => $s) {
            $time = $s['time'];
            if ($time['reliability'] === eta::FINAL) {
                $lastend = max($lastend, (int) $time['finish']);
                continue;
            }
            if ($time['reliability'] === eta::NONE || isset($queue[$id]) && $time['started'] === null) {
                // Planned and never started, or waiting in line: counted below, or not at all.
                continue;
            }
            $open++;
            if (in_array($time['reliability'], [eta::ESTIMATE, eta::PROVISIONAL], true)) {
                $provisional = $provisional || $time['reliability'] === eta::PROVISIONAL;
                if ((int) $time['finish'] > $latest) {
                    $latest = (int) $time['finish'];
                    $out['margin'] = $time['margin'];
                }
            } else if ($missing === '') {
                $missing = $time['reason'] !== '' ? $time['reason'] : 'eta:whysample';
                $out['reliability'] = $time['reliability'];
            }
        }
        // The line of experiments waiting their turn: one after the other, each when the one before it is done.
        $slots = (float) $forecast['capacity']['slots'];
        asort($queue);
        foreach (array_keys($queue) as $id) {
            if (!isset($snapshots[$id]) || $snapshots[$id]['time']['started'] !== null) {
                continue;
            }
            $open++;
            $work = $snapshots[$id]['time']['own']['workseconds'] ?? null;
            if ($work === null || $slots <= 0) {
                $missing = $missing !== '' ? $missing : 'eta:whyqueuedunknown';
                $out['reliability'] = $out['reliability'] === eta::UNRELIABLE ? eta::UNRELIABLE : eta::CALCULATING;
                continue;
            }
            $latest += (int) ceil($work / $slots);
            $provisional = true;
        }

        if ($open === 0) {
            $out['finish'] = $lastend > 0 ? $lastend : null;
            $out['reliability'] = $snapshots === [] ? eta::NONE : eta::FINAL;
        } else if ($missing !== '') {
            $out['remaining'] = null;
            $out['reason'] = get_string($missing, $component);
            // What is known: at least until the open experiments with a forecast are done.
            $out['lowerbound'] = $latest > $now ? $latest : null;
        } else {
            $out['reliability'] = $provisional ? eta::PROVISIONAL : eta::ESTIMATE;
            $out['remaining'] = $latest - $now;
            $out['finish'] = $latest;
        }

        $na = get_string('eta:na', $component);
        $out['text'] = [
            'status' => get_string('eta:' . $out['reliability'], $component),
            'reason' => $out['reason'],
            'remaining' => $out['remaining'] !== null && $out['reliability'] !== eta::FINAL
                ? get_string('eta:approx', $component, eta::span((int) $out['remaining']))
                : ($out['reliability'] === eta::FINAL ? get_string('eta:nothingleft', $component)
                    : ($out['lowerbound'] !== null
                        ? get_string('overview:atleast', $component, eta::when((int) $out['lowerbound'])) : $na)),
            'finish' => $out['finish'] !== null ? ($out['reliability'] === eta::FINAL
                ? get_string('eta:finishedat', $component, eta::when((int) $out['finish']))
                : get_string('eta:approx', $component, eta::when((int) $out['finish']))) : $na,
        ];

        return $out;
    }

    /**
     * Links to the pages of experiment sections, or null for one page.
     *
     * @param int $page The current page.
     * @param int $pages How many.
     * @return array|null
     */
    protected static function pager(int $page, int $pages): ?array {
        if ($pages <= 1) {
            return null;
        }
        $links = [];
        for ($i = 0; $i < $pages; $i++) {
            $links[] = ['label' => $i + 1, 'current' => $i === $page,
                'url' => (new \moodle_url('/local/catquizlab/runs.php', ['xpage' => $i]))->out(false)];
        }

        return ['links' => $links];
    }

    /**
     * Experiments waiting in the execution queue, by their position.
     *
     * @return array<int, int> Experiment id => position.
     */
    protected static function queue_positions(): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_catquizlab_execqueue')) {
            return [];
        }

        return array_map('intval', $DB->get_records_menu(
            'local_catquizlab_execqueue',
            ['state' => execution_queue::WAITING],
            'position ASC',
            'experimentid, position'
        ));
    }
}
