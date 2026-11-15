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
 * The figures "4. Results" shows, as the dashboard computes them, for the R re-analysis to compare against (#157 AK5).
 *
 * Each figure is one row: tab, section, grouping, group, metric, statistic, value. They come from the same calls the
 * results page makes (results_query::overview(), stream_summary, exposure(), stop_success(), local_analysis,
 * robustness_analysis, test_flow::feasibility()), under the same filter, not from a computation of their own: the R
 * scripts recompute them from the raw tables, and a difference is a difference between the two.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class research_reference {
    /** @var string[] Columns of reference/dashboard.csv. */
    public const COLUMNS = ['tab', 'section', 'groupby', 'group', 'metric', 'stat', 'value'];

    /** @var string[] Statistics of a described field. */
    public const STATS = ['n', 'mean', 'sd', 'se', 'ci95lo', 'ci95hi', 'median', 'q1', 'q3', 'min', 'max', 'units'];

    /** @var array[] The rows while they are collected. */
    protected static array $rows = [];

    /**
     * The dashboard's figures for a filter.
     *
     * @param array $filter The results filter.
     * @return array[] Rows by COLUMNS.
     */
    public static function figures(array $filter): array {
        self::$rows = [];
        results_query::reset_caches();
        $query = new results_query($filter);
        $overview = $query->overview();
        if ($overview['n'] === 0) {
            return [];
        }

        // Overview: the key figures, by strategy, by cell.
        $all = $overview['all'];
        self::summary('overview', 'kpi', '', '', $all, ['nitems', 'se', 'error', 'runtimems']);
        self::add('overview', 'kpi', '', '', 'correlation', 'value', $all->correlation());
        self::add('overview', 'kpi', '', '', 'stopsuccess', 'percent', $query->stop_success());
        $exposure = metrics::exposure_from_counts($overview['itemcounts'], $overview['n'], $query->pool_size());
        self::add('overview', 'kpi', '', '', 'concentration', 'gini', $exposure['concentration']['gini'] ?? null);
        foreach ($overview['strategy'] as $key => $summary) {
            self::summary('overview', 'strategy', 'strategy', (string) $key, $summary, ['nitems', 'se', 'error']);
            self::add(
                'overview',
                'strategy',
                'strategy',
                (string) $key,
                'stopsuccess',
                'percent',
                $query->stop_success('bystrategy', (string) $key)
            );
        }
        foreach ($overview['cell'] as $key => $summary) {
            self::summary('overview', 'cell', 'cell', (string) $key, $summary, ['nitems', 'se', 'error', 'runtimems']);
            self::add('overview', 'cell', 'cell', (string) $key, 'correlation', 'value', $summary->correlation());
            self::add(
                'overview',
                'cell',
                'cell',
                (string) $key,
                'stopsuccess',
                'percent',
                $query->stop_success('bycell', (string) $key)
            );
        }

        // Global: recovery, the ability range, exposure.
        self::add('global', 'recovery', '', '', 'sittings', 'withestimate', $overview['sampled']);
        self::add('global', 'recovery', '', '', 'outsiderange', 'count', $overview['outside']);
        $exposure = $query->exposure();
        foreach (['gini', 'hhi', 'max', 'mean', 'above', 'n'] as $stat) {
            self::add('global', 'exposure', '', '', 'exposure', $stat, $exposure['concentration'][$stat] ?? null);
        }
        self::add('global', 'exposure', '', '', 'exposure', 'itemsused', $exposure['itemsused']);
        self::add('global', 'exposure', '', '', 'exposure', 'unused', $exposure['unused']);
        self::add('global', 'exposure', '', '', 'exposure', 'maxrate', $exposure['maxrate']);

        // Subscales: the summary and its groups.
        $rows = iterator_to_array($query->each_subscale_observation(), false);
        self::local('subscales', 'summary', '', '', local_analysis::summarise($rows));
        foreach (['category', 'key'] as $groupby) {
            foreach (local_analysis::group($rows, $groupby) as $group) {
                self::local('subscales', $groupby, $groupby, (string) $group['group'], $group);
            }
        }
        unset($rows);

        // Robustness: every cell's global and local outcomes and their differences to the ideal pool, as the tab
        // computes them (with the runs' scale maps).
        $observations = iterator_to_array($query->each_observation(), false);
        $cells = robustness_analysis::cells($observations, $query->scale_maps(), (string) ($filter['validityrule']
            ?? engine_validity::RULE_UNIFORM));
        $metrics = array_merge(
            array_keys(robustness_analysis::global_metrics()),
            ['localn'],
            array_keys(robustness_analysis::local_metrics())
        );
        foreach ($cells as $cell) {
            foreach ($metrics as $metric) {
                self::add('robustness', 'cell', 'cell', $cell['key'], $metric, 'value', $cell['outcomes'][$metric] ?? null);
                if ($cell['reference'] !== null) {
                    self::add('robustness', 'cell', 'cell', $cell['key'], $metric, 'delta', $cell['deltas'][$metric] ?? null);
                }
            }
            self::add('robustness', 'cell', 'cell', $cell['key'], 'sittings', 'count', $cell['n']);
        }

        // Test flow: the feasibility of the precision target.
        $counts = test_flow::summarise_feasibility([]);
        $parameters = [];
        foreach ($observations as $observation) {
            $runid = (int) $observation['runid'];
            $parameters[$runid] ??= self::cat_parameters($runid);
            $verdict = test_flow::feasibility($observation, $parameters[$runid]);
            $counts[$verdict['verdict']] = ($counts[$verdict['verdict']] ?? 0) + 1;
            $counts['n']++;
        }
        foreach (['reached', 'stoppedearly', 'budgetexhausted', 'missed', 'unknown', 'n'] as $key) {
            self::add('testflow', 'feasibility', '', '', 'verdict', $key, $counts[$key] ?? 0);
        }
        unset($observations);

        // Mode-specific detection: the summary table, every mode and comparison.
        $analysis = mode_detection::analyse(mode_detection::sittings($query));
        foreach (mode_detection::summary_rows($analysis)['rows'] as $row) {
            $group = implode('|', [$row['mode'], $row['compared'], $row['baseline'], $row['pairing']]);
            foreach ($row as $metric => $value) {
                if (in_array($metric, ['mode', 'compared', 'baseline', 'pairing'], true)) {
                    continue;
                }
                self::add('detection', 'summary', 'mode', $group, $metric, 'value', $value);
            }
        }

        return self::$rows;
    }

    /**
     * A streamed summary's figures: count, runs, people, the described fields, RMSE.
     *
     * @param string $tab The tab.
     * @param string $section The section.
     * @param string $groupby The grouping.
     * @param string $group The group.
     * @param stream_summary $summary The summary.
     * @param string[] $fields The fields it describes.
     * @return void
     */
    protected static function summary(
        string $tab,
        string $section,
        string $groupby,
        string $group,
        stream_summary $summary,
        array $fields
    ): void {
        self::add($tab, $section, $groupby, $group, 'sittings', 'count', $summary->count());
        self::add($tab, $section, $groupby, $group, 'runs', 'count', $summary->runs());
        self::add($tab, $section, $groupby, $group, 'people', 'count', $summary->people());
        foreach ($fields as $field) {
            $described = $summary->describe($field);
            foreach (self::STATS as $stat) {
                self::add($tab, $section, $groupby, $group, $field, $stat, $described[$stat] ?? null);
            }
        }
        self::add($tab, $section, $groupby, $group, 'rmse', 'value', round($summary->rmse(), 6));
    }

    /**
     * A subscale summary's figures.
     *
     * @param string $tab The tab.
     * @param string $section The section.
     * @param string $groupby The grouping.
     * @param string $group The group.
     * @param array $summary From local_analysis::summarise() or group().
     * @return void
     */
    protected static function local(string $tab, string $section, string $groupby, string $group, array $summary): void {
        foreach (['n', 'bias', 'rmse', 'mae', 'correlation', 'meanse', 'within1se', 'within2se', 'nwithse'] as $stat) {
            self::add($tab, $section, $groupby, $group, 'local', $stat, $summary[$stat] ?? null);
        }
    }

    /**
     * One figure.
     *
     * @param string $tab The tab.
     * @param string $section The section.
     * @param string $groupby The grouping.
     * @param string $group The group.
     * @param string $metric The metric.
     * @param string $stat The statistic.
     * @param mixed $value The value; null where the dashboard shows none.
     * @return void
     */
    protected static function add(
        string $tab,
        string $section,
        string $groupby,
        string $group,
        string $metric,
        string $stat,
        $value
    ): void {
        self::$rows[] = ['tab' => $tab, 'section' => $section, 'groupby' => $groupby, 'group' => $group,
            'metric' => $metric, 'stat' => $stat, 'value' => $value === null ? null : (is_bool($value) ? (int) $value
                : (is_numeric($value) ? (float) $value : $value))];
    }

    /**
     * A run's CAT parameters, as the test flow tab reads them.
     *
     * @param int $runid The run.
     * @return array
     */
    protected static function cat_parameters(int $runid): array {
        global $DB;

        $manifest = json_decode((string) $DB->get_field('local_catquizlab_run', 'manifestjson', ['id' => $runid]), true)
            ?: [];

        return (array) ($manifest['config']['cat'] ?? []);
    }
}
