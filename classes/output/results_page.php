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

/**
 * Renders the results tabs.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\output;

use local_catquizlab\local\local_analysis;
use local_catquizlab\local\metrics;
use local_catquizlab\local\reason_catalog;
use local_catquizlab\local\results_export;
use local_catquizlab\local\results_query;
use local_catquizlab\local\stream_summary;
use local_catquizlab\local\robustness_analysis;
use local_catquizlab\local\test_flow;
use local_catquizlab\local\run_registry;

/**
 * The results view: filter bar, tab navigation and the tab bodies.
 *
 * Two rules from the specification shape everything here. Global measurement
 * performance and local diagnostic performance are kept apart, because they
 * answer different questions and a reader must not have to guess which one a
 * number belongs to. And every aggregate carries its dispersion and its
 * observation count, because a mean over replications without a spread invites
 * a confidence the data does not support.
 */
class results_page {
    /** @var int The most points a scatter chart draws. */
    public const MAX_POINTS = 2000;

    /** @var array|null Exposure already computed from a streamed pass. */
    protected ?array $exposureoverride = null;
    /** @var results_query The data source. */
    protected results_query $query;

    /** @var string The active tab. */
    protected string $tab;

    /** @var array The active filter. */
    protected array $filter;

    /**
     * Construct.
     *
     * @param results_query $query The data source.
     * @param string $tab The active tab.
     * @param array $filter The active filter.
     */
    public function __construct(results_query $query, string $tab, array $filter) {
        $this->query = $query;
        $this->tab = $tab;
        $this->filter = $filter;
    }

    /**
     * The tabs, in order.
     *
     * @return array<string, string> Tab key => label.
     */
    public static function tabs(): array {
        $component = 'local_catquizlab';

        return [
            'overview'    => get_string('tab:overview', $component),
            'global'      => get_string('tab:global', $component),
            'subscales'   => get_string('tab:subscales', $component),
            'deficits'    => get_string('tab:deficits', $component),
            'robustness'  => get_string('tab:robustness', $component),
            'testflow'    => get_string('tab:testflow', $component),
            'rawdata'     => get_string('tab:rawdata', $component),
            'export'      => get_string('tab:export', $component),
        ];
    }

    /**
     * The tab label for the detection tab, which depends on the strategy.
     *
     * @return string|null The label, or null when no single strategy is selected.
     */
    protected function detection_tab_label(): ?string {
        $strategies = array_keys($this->query->available('strategy'));
        if (count($strategies) !== 1) {
            return null;
        }

        return local_analysis::detection_labels($strategies[0])['title'];
    }

    /**
     * Whether a tab key is known.
     *
     * @param string $tab The tab key.
     * @return bool
     */
    public function tab_exists(string $tab): bool {
        return array_key_exists($tab, self::tabs());
    }

    /**
     * The filter bar.
     *
     * The menus offer only the values the selected runs actually contain, so a
     * filter cannot be set to a combination that yields an empty view.
     *
     * @return string
     */
    public function render_filter_bar(): string {
        global $DB;

        $component = 'local_catquizlab';
        $out = \html_writer::start_tag('form', [
            'method' => 'get',
            'action' => (new \moodle_url('/local/catquizlab/results.php'))->out(false),
            'class'  => 'form-inline mb-3 p-3 bg-light border rounded',
        ]);
        $out .= \html_writer::empty_tag('input', [
            'type' => 'hidden', 'name' => 'tab', 'value' => $this->tab,
        ]);

        // The experiment is chosen in the shell, on every step, and carried
        // here in the URL. A second selector for the same thing invited the two
        // to disagree — and made "which experiment am I looking at" a question
        // with two answers on one page.
        $out .= \html_writer::empty_tag('input', [
            'type'  => 'hidden',
            'name'  => 'experimentid',
            'value' => (int) ($this->filter['experimentid'] ?? 0),
        ]);

        // The remaining menus describe the experimental coordinates. Every one
        // of them is a factor the design varies, so all of them are filterable.
        foreach (
            [
            'tier'     => 'form:tier',
            'model'    => 'form:model',
            'strategy' => 'form:strategy',
            'variant'  => 'form:variant',
            'stratum'  => 'form:stratum',
            'severity' => 'form:severity',
            ] as $field => $labelkey
        ) {
            $values = $this->query->available($field);
            if ($field === 'tier') {
                $values = $this->tier_values();
            }
            if ($values === []) {
                continue;
            }
            $out .= $this->select(
                $field,
                ['' => get_string('filter:any' . $field, $component)] + $values,
                get_string($labelkey, $component)
            );
        }

        $out .= \html_writer::empty_tag('input', [
            'type'  => 'submit',
            'class' => 'btn btn-primary',
            'value' => get_string('filter:apply', $component),
        ]);
        $out .= \html_writer::link(
            new \moodle_url('/local/catquizlab/results.php', ['tab' => $this->tab]),
            get_string('filter:reset', $component),
            ['class' => 'btn btn-link']
        );
        $out .= \html_writer::end_tag('form');

        return $out;
    }

    /**
     * The tab navigation.
     *
     * @return string
     */
    public function render_tabs(): string {
        $items = '';
        $detection = $this->detection_tab_label();
        foreach (self::tabs() as $key => $label) {
            if ($key === 'deficits' && $detection !== null) {
                // Naming the tab "deficit detection" for a strategy that hunts
                // strengths would misdescribe what is measured there.
                $label = $detection;
            }
            $url = new \moodle_url('/local/catquizlab/results.php', $this->filter + ['tab' => $key]);
            $active = $key === $this->tab;
            $items .= \html_writer::tag(
                'li',
                \html_writer::link($url, $label, [
                    'class' => 'nav-link' . ($active ? ' active' : ''),
                    'aria-current' => $active ? 'page' : null,
                ]),
                ['class' => 'nav-item']
            );
        }

        return \html_writer::tag('ul', $items, [
            'class' => 'nav nav-tabs mb-3',
            'role'  => 'navigation',
            'aria-label' => get_string('results:tabs', 'local_catquizlab'),
        ]);
    }

    /**
     * Why there is nothing to show: not started, still running, or filtered out.
     *
     * "Nothing matches this filter" and "nothing has been run" look the same
     * from the results view and mean entirely different things. The second has
     * an obvious remedy, and naming it turns a dead end into a next step.
     *
     * @return string
     */
    protected function explain_absence(): string {
        global $DB;

        $component = 'local_catquizlab';

        // Scoped to the experiment when one is filtered for, otherwise across
        // every run: the unfiltered view is exactly where a first-time user
        // lands, and it should not be the one view that cannot say why it is
        // empty.
        $experimentid = (int) ($this->filter['experimentid'] ?? 0);
        if ($experimentid > 0) {
            $rows = $DB->get_records_sql(
                'SELECT status, COUNT(*) AS n FROM {local_catquizlab_run} WHERE experimentid = :id GROUP BY status',
                ['id' => $experimentid]
            );
        } else {
            $rows = $DB->get_records_sql(
                'SELECT status, COUNT(*) AS n FROM {local_catquizlab_run} GROUP BY status'
            );
        }
        if (!$rows) {
            return get_string('results:noobservations', $component);
        }

        $counts = ['total' => 0, 'draft' => 0, 'running' => 0, 'finished' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $n = (int) $row->n;
            $counts['total'] += $n;
            switch ((int) $row->status) {
                case \local_catquizlab\local\registry::STATUS_DRAFT:
                    $counts['draft'] += $n;
                    break;
                case \local_catquizlab\local\registry::STATUS_FINISHED:
                    $counts['finished'] += $n;
                    break;
                case \local_catquizlab\local\registry::STATUS_FAILED:
                case \local_catquizlab\local\registry::STATUS_CANCELLED:
                    $counts['failed'] += $n;
                    break;
                default:
                    $counts['running'] += $n;
            }
        }

        $summary = get_string('results:runsummary', $component, (object) $counts);

        // Every run still a draft was indistinguishable from an unlucky filter,
        // and it is the case with something to do about it.
        if ($counts['draft'] === $counts['total']) {
            return get_string('results:nothingstarted', $component) . ' ' . $summary;
        }

        return get_string('results:noobservations', $component) . ' ' . $summary;
    }

    /**
     * The population of the selected experiments, and their problems above everything (#117).
     *
     * @return array{0: string, 1: string} The problems, and the line.
     */
    protected function render_population(): array {
        $component = 'local_catquizlab';
        $problems = '';
        $lines = '';
        $runs = $this->query->runs();
        $experiments = array_unique(array_map(static fn($run): int => (int) $run['experimentid'], $runs));
        foreach ($experiments as $experimentid) {
            $population = \local_catquizlab\local\population_plan::describe($experimentid);
            if (!$population['consistent']) {
                $problems .= \html_writer::div(
                    get_string('plan:inconsistent', $component) . \html_writer::alist($population['problems']),
                    'alert alert-danger',
                    ['data-region' => 'catquizlab-population-problems']
                );
            }
            $lines .= \html_writer::div(
                s($population['text']),
                'small text-muted mb-1',
                ['data-region' => 'catquizlab-population']
            );
        }

        return [$problems, $lines];
    }

    /**
     * A statement of what the figures on this page rest on.
     *
     * @return string
     */
    public function render_provenance(): string {
        $component = 'local_catquizlab';
        $provenance = $this->query->provenance();

        // The population first, results or not: a plan the stored people do not
        // match is to be seen before there is anything to read (#117).
        [$problems, $population] = $this->render_population();
        if ($provenance['attempts'] === 0) {
            // The two cases -- "nothing matches this filter" and "nothing has been run" -- look the
            // same from here and mean entirely different things. Saying which
            // one it is turns a dead end into a next step.
            return $problems . \html_writer::div(
                $this->explain_absence(),
                'alert alert-info'
            ) . $population;
        }

        $out = \html_writer::div(
            get_string('results:provenance', $component, (object) [
                'runs'         => $provenance['runs'],
                'attempts'     => $provenance['attempts'],
                'replications' => $provenance['replications'],
                'dispersion'   => get_string('dispersion:ci95', $component),
                'computed'     => userdate($provenance['computed'], get_string('strftimedatetimeshort')),
            ]),
            'small text-muted mb-1'
        );

        $out = $problems . $out . $population . $this->render_coverage();

        // What the ground truth was drawn from, named (#105): mean and
        // standard deviation, distribution and range of the selected runs.
        $abilities = [];
        foreach ($this->query->ability_distributions() as $text => $runs) {
            $abilities[] = s($text) . ' (' . get_string('results:stoprulesruns', $component, $runs) . ')';
        }
        if ($abilities !== []) {
            $out .= \html_writer::div(
                get_string('results:abilities', $component) . ': ' . implode('; ', $abilities),
                'small text-muted mb-1',
                ['data-region' => 'catquizlab-abilities']
            );
        }

        // The stop rules the engine applied, per strategy — only those in
        // force (#101). A result read without them can be read as the effect
        // of a strategy when it was the effect of a limit.
        $items = '';
        foreach ($this->query->stop_rules() as $group) {
            $items .= \html_writer::tag('li', \html_writer::tag('strong', s($group['label'])) . ': '
                . s(implode('; ', $group['rules']))
                . ' (' . get_string('results:stoprulesruns', $component, $group['runs']) . ')', ['data-strategy' => $group['key']]);
        }
        if ($items !== '') {
            $out .= \html_writer::div(
                \html_writer::tag('span', get_string('results:stoprules', $component), [
                    'title' => get_string('results:stoprulesexplain', $component),
                ]) . \html_writer::tag('ul', $items, ['class' => 'mb-0']),
                'small text-muted mb-3',
                ['data-region' => 'catquizlab-stoprules']
            );
        }

        return $out;
    }

    /**
     * The active tab's body.
     *
     * @return string
     */
    public function render_tab(): string {
        switch ($this->tab) {
            case 'global':
                return $this->render_global();
            case 'rawdata':
                return $this->render_rawdata();
            case 'export':
                return $this->render_export();
            case 'testflow':
                return $this->render_testflow();
            case 'robustness':
                return $this->render_robustness();
            case 'subscales':
                return $this->render_subscales();
            case 'deficits':
                return $this->render_detection();
            case 'overview':
                return $this->render_overview();
            default:
                return \html_writer::div(
                    get_string('results:tabpending', 'local_catquizlab'),
                    'alert alert-secondary'
                );
        }
    }

    /**
     * The overview tab: the headline figures and how they differ by strategy.
     *
     * @return string
     */
    protected function render_overview(): string {
        $component = 'local_catquizlab';

        // One streamed pass into per-group summaries: the sittings, their
        // item lists and their rows are never held, whatever the selection's
        // size (#99). The figures are the ones the row-based tables gave.
        $overview = $this->query->overview(self::MAX_POINTS);
        if ($overview['n'] === 0) {
            return $this->render_no_data();
        }

        $all = $overview['all'];
        $exposure = metrics::exposure_from_counts($overview['itemcounts'], $overview['n'], $this->query->pool_size());

        $out = \html_writer::tag('h3', get_string('results:globalgroup', $component), ['class' => 'h5']);
        $out .= $this->render_summary_cards($all, $exposure);

        $chart = new scatter_chart(
            get_string('chart:lengthvsse', $component),
            get_string('axis:testlength', $component),
            get_string('axis:finalse', $component)
        );
        $chart->set_axes(axis_scale::INTEGER, axis_scale::LINEAR, ['yfromzero' => true, 'jitter' => 0.15]);
        $chart->set_basis($this->plot_basis());
        $this->configure_chart($chart, 'lengthprecision');
        // What the plot leaves out (#118): a short test that ended before the
        // minimum would read as an efficient one.
        $counts = $this->query->validity_counts();
        if ($counts['invalid'] > 0 && ($this->filter['validity'] ?? 'valid') === 'valid') {
            $chart->add_note(get_string('chart:excludedinvalid', 'local_catquizlab', (object) [
                'n' => $counts['invalid'], 'total' => $counts['total'],
                'percent' => format_float(100 * $counts['invalid'] / $counts['total'], 1),
            ]));
        }
        $chart->set_points($overview['points'])
            ->set_description(get_string('chart:pointisattempt', $component));
        $semin = $this->target_se();
        if ($semin !== null) {
            $chart->add_horizontal_line($semin, get_string('chart:setarget', $component, format_float($semin, 2)));
        }

        $out .= \html_writer::tag('h3', get_string('chart:lengthvsse', $component), ['class' => 'h5 mt-4']);
        $out .= $chart->render_with_summary([
            get_string('metric:testlength', $component) => $this->format_stat($all->describe('nitems')),
            get_string('metric:se', $component) => $this->format_stat($all->describe('se')),
        ]);

        $out .= \html_writer::tag('h3', get_string('chart:strategycomparison', $component), ['class' => 'h5 mt-4']);
        $out .= $this->render_summary_group_table($overview['strategy'], 'strategy');

        $out .= \html_writer::tag('h3', get_string('results:celltable', $component), ['class' => 'h5 mt-4']);
        $out .= $this->render_summary_cell_table($overview['cell']);

        // The simulated people behind the numbers (#109, section 4).
        $out .= $this->render_people();

        return $out;
    }

    /**
     * The overview's key figures from a streamed summary.
     *
     * @param stream_summary $all The whole selection.
     * @param array $exposure Exposure statistics.
     * @return string
     */
    protected function render_summary_cards(stream_summary $all, array $exposure): string {
        $component = 'local_catquizlab';
        $cards = [
            ['metric:testlength', $this->format_stat($all->describe('nitems'))],
            ['metric:se', $this->format_stat($all->describe('se'))],
            ['metric:bias', $this->format_stat($all->describe('error'))],
            ['metric:rmse', format_float($all->rmse(), 4)],
            ['metric:correlation', $this->format_number($all->correlation())],
            ['metric:stopsuccess', $this->format_percent($this->query->stop_success())],
            ['metric:concentration', $this->format_number($exposure['concentration']['gini'] ?? null)],
            ['metric:runtime', $this->format_runtime($all->describe('runtimems'))],
        ];

        $out = \html_writer::start_div('row');
        foreach ($cards as [$key, $value]) {
            $out .= \html_writer::div(
                \html_writer::div(
                    \html_writer::tag('div', get_string($key, $component), ['class' => 'small text-muted'])
                    . \html_writer::tag('div', $value, ['class' => 'h5 mb-0'])
                    . \html_writer::tag('div', get_string($key . '_help', $component), ['class' => 'small text-muted mt-1']),
                    'card-body p-3'
                ),
                'col-md-3 mb-3'
            );
        }

        return $out . \html_writer::end_div();
    }

    /**
     * One row per group, from streamed summaries.
     *
     * @param stream_summary[] $groups Group key => summary.
     * @param string $groupby The grouping, for its label.
     * @return string
     */
    protected function render_summary_group_table(array $groups, string $groupby): string {
        $component = 'local_catquizlab';
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->head = [
            get_string('form:' . $groupby, $component),
            get_string('report:runs', $component),
            get_string('metric:testlength', $component),
            get_string('metric:se', $component),
            get_string('metric:bias', $component),
            get_string('metric:rmse', $component),
            get_string('metric:stopsuccess', $component),
        ];
        foreach ($groups as $key => $summary) {
            $table->data[] = [
                s(run_registry::group_label($groupby, (string) $key)),
                $summary->count(),
                $this->format_stat($summary->describe('nitems')),
                $this->format_stat($summary->describe('se')),
                $this->format_stat($summary->describe('error')),
                format_float($summary->rmse(), 4),
                // Of all sittings, invalid ones included (#118).
                $this->format_percent($groupby === 'strategy'
                    ? $this->query->stop_success('bystrategy', (string) $key) : $summary->stop_rate()),
            ];
        }

        return \html_writer::table($table);
    }

    /**
     * One row per experimental cell, from streamed summaries.
     *
     * @param stream_summary[] $cells Cell key => summary.
     * @return string
     */
    protected function render_summary_cell_table(array $cells): string {
        $component = 'local_catquizlab';
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->head = [
            get_string('form:tier', $component),
            get_string('form:strategy', $component),
            get_string('form:model', $component),
            get_string('form:variant', $component),
            get_string('form:stratum', $component),
            get_string('form:severity', $component),
            'n',
            get_string('metric:testlength', $component),
            get_string('metric:se', $component),
            get_string('metric:bias', $component),
            get_string('metric:rmse', $component),
            get_string('metric:correlation', $component),
            get_string('metric:stopsuccess', $component),
            get_string('metric:runtime', $component),
        ];
        foreach ($cells as $key => $summary) {
            [$tier, $strategy, $model, $variant, $stratum, $severity] = explode('|', (string) $key);
            $table->data[] = [
                s(run_registry::group_label('tier', $tier)),
                s(run_registry::group_label('strategy', $strategy)),
                s(run_registry::group_label('model', $model)),
                s(run_registry::group_label('variant', $variant)),
                s(run_registry::group_label('stratum', $stratum)),
                s(run_registry::group_label('severity', $severity)),
                $summary->count(),
                $this->format_stat($summary->describe('nitems')),
                $this->format_stat($summary->describe('se')),
                $this->format_stat($summary->describe('error')),
                format_float($summary->rmse(), 4),
                $this->format_number($summary->correlation()),
                $this->format_percent($this->query->stop_success('bycell', (string) $key)),
                $this->format_runtime($summary->describe('runtimems')),
            ];
        }

        return \html_writer::table($table);
    }

    /**
     * What to show when the selection has produced nothing.
     *
     * @return string
     */
    protected function render_no_data(): string {
        global $OUTPUT;

        $component = 'local_catquizlab';

        $out = $OUTPUT->notification(
            \html_writer::tag('strong', get_string('results:nodata', $component))
                . \html_writer::tag('p', get_string('results:nodataexplain', $component), ['class' => 'mb-0']),
            \core\output\notification::NOTIFY_INFO
        );

        // Where the answer to "why is there nothing" actually is.
        $experimentid = (int) ($this->filter['experimentid'] ?? 0);
        $out .= \html_writer::tag('p', \html_writer::link(
            new \moodle_url('/local/catquizlab/runs.php', $experimentid > 0
                ? ['experimentid' => $experimentid]
                : []),
            get_string('results:toprogress', $component)
        ));

        return $out;
    }

    /**
     * The global metrics tab: the full global picture and its cost.
     *
     * @return string
     */
    protected function render_global(): string {
        $component = 'local_catquizlab';
        // One pass, as the overview (#99): the figures from every sitting,
        // streamed; the plots from a random sample of at most MAX_POINTS. It
        // used to hold every sitting — 230 MB for 50,000.
        $overview = $this->query->overview(self::MAX_POINTS);
        if ($overview['n'] === 0) {
            return '';
        }
        $all = $overview['all'];
        $rows = $overview['sample'];

        // Estimate against ground truth. The identity line is what makes bias
        // and spread readable at a glance.
        $recovery = new scatter_chart(
            get_string('chart:estimatevstruth', $component),
            get_string('axis:truetheta', $component),
            get_string('axis:esttheta', $component)
        );
        $recovery->set_axes(axis_scale::SYMMETRIC, axis_scale::SYMMETRIC, ['xatleast' => $this->ability_halfrange()]);
        $recovery->set_basis($this->plot_basis());
        $this->configure_chart($recovery, 'recovery');
        $recovery->set_points(array_map(
            static fn(array $row): array => ['x' => $row['truetheta'], 'y' => $row['esttheta']],
            $rows
        ))->set_description(get_string('chart:pointisattempt', $component))
            ->add_identity_line(get_string('chart:identity', $component));

        // True abilities outside the scale range of their own run (#105,
        // section 5): the engine cannot estimate beyond the range, and those
        // points bend the cloud at its ends for that reason alone.
        $outside = (int) $overview['outside'];
        if ($outside > 0) {
            $recovery->add_note(get_string('chart:outsiderange', $component, (object) [
                'n' => $outside, 'total' => $overview['n'],
            ]));
        }
        if ($overview['sampled'] > count($rows)) {
            $sampletext = get_string('chart:sampled', $component, (object) [
                'shown' => count($rows), 'total' => $overview['sampled'],
            ]);
            $recovery->add_note($sampletext);
        }

        $errors = $all->describe('error');
        $out = \html_writer::tag('h3', get_string('chart:estimatevstruth', $component), ['class' => 'h5']);
        $out .= $recovery->render_with_summary([
            get_string('metric:bias', $component)  => $this->format_stat($errors),
            get_string('metric:rmse', $component)  => format_float($all->rmse(), 4),
            get_string('metric:correlation', $component) => $this->format_number($all->correlation()),
        ]);

        // The error against ground truth: a bias that only appears at the ends
        // of the ability range is invisible in a single mean.
        $errorchart = new scatter_chart(
            get_string('chart:errorvstruth', $component),
            get_string('axis:truetheta', $component),
            get_string('axis:error', $component)
        );
        $errorchart->set_axes(axis_scale::SYMMETRIC, axis_scale::SYMMETRIC, ['xatleast' => $this->ability_halfrange()]);
        $errorchart->set_basis($this->plot_basis());
        $this->configure_chart($errorchart, 'error');
        $errorchart->set_points(array_map(
            static fn(array $row): array => ['x' => $row['truetheta'], 'y' => $row['error']],
            $rows
        ))->set_description(get_string('chart:pointisattempt', $component))
            ->add_horizontal_line(0.0, get_string('chart:zeroline', $component));
        if (isset($sampletext)) {
            $errorchart->add_note($sampletext);
        }

        $out .= \html_writer::tag('h3', get_string('chart:errorvstruth', $component), ['class' => 'h5 mt-4']);
        $out .= $errorchart->render_with_summary([
            get_string('metric:bias', $component) => $this->format_stat($errors),
        ]);

        $out .= \html_writer::tag('h3', get_string('results:exposure', $component), ['class' => 'h5 mt-4']);
        $out .= $this->render_exposure();

        $out .= \html_writer::tag('h3', get_string('results:celltable', $component), ['class' => 'h5 mt-4']);
        $out .= $this->render_summary_cell_table($overview['cell']);

        return $out;
    }

    /**
     * The raw-data tab: the filtered observations at a chosen level.
     *
     * @return string
     */
    protected function render_rawdata(): string {
        $component = 'local_catquizlab';
        $level = optional_param('level', results_export::LEVEL_ATTEMPT, PARAM_ALPHA);
        if (!array_key_exists($level, results_export::levels())) {
            $level = results_export::LEVEL_ATTEMPT;
        }
        $page = optional_param('rawpage', 0, PARAM_INT);
        $perpage = 100;

        $out = \html_writer::tag('h3', get_string('results:rawgroup', $component), ['class' => 'h5']);
        $out .= \html_writer::tag('p', get_string('results:rawexplain', $component), ['class' => 'text-muted']);
        $out .= $this->render_level_picker($level, 'rawdata');

        // One page read from a stream, the rest counted in passing. The whole
        // dataset used to be built so that a hundred rows could be cut out of
        // it — at the item level, fifty thousand sittings of thirty-five items
        // each, to show a hundred (#99).
        $dataset = results_export::iterate($this->query, $level);
        $first = max(0, $page) * $perpage;
        $slice = [];
        $total = 0;
        foreach ($dataset['rows'] as $row) {
            if ($total >= $first && count($slice) < $perpage) {
                $slice[] = $row;
            }
            $total++;
        }
        if ($total === 0) {
            return $out . \html_writer::div(
                get_string('results:noobservations', $component),
                'alert alert-info'
            );
        }

        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->head = $dataset['columns'];
        foreach ($slice as $row) {
            $cells = [];
            foreach ($dataset['columns'] as $column) {
                $value = $row[$column] ?? null;
                if ($value === null) {
                    // An empty cell, not a dash: this is the raw layer, and a
                    // dash would be a rendering decision the analyst did not ask for.
                    $cells[] = '';
                } else if (is_bool($value)) {
                    $cells[] = (int) $value;
                } else if (is_float($value)) {
                    $cells[] = format_float($value, 6);
                } else {
                    $cells[] = s((string) $value);
                }
            }
            $table->data[] = $cells;
        }

        $out .= \html_writer::div(
            get_string('results:rawcount', $component, (object) ['shown' => count($slice), 'total' => $total]),
            'small text-muted mb-2'
        );
        $out .= \html_writer::table($table);

        $baseurl = new \moodle_url('/local/catquizlab/results.php', $this->filter + [
            'tab' => 'rawdata', 'level' => $level,
        ]);
        $out .= $GLOBALS['OUTPUT']->paging_bar($total, $page, $perpage, $baseurl, 'rawpage');

        return $out;
    }

    /**
     * The export tab: what will be written, and the links to write it.
     *
     * @return string
     */
    protected function render_export(): string {
        $component = 'local_catquizlab';

        $out = \html_writer::tag('h3', get_string('results:exportgroup', $component), ['class' => 'h5']);
        $out .= \html_writer::tag('p', get_string('results:exportexplain', $component), ['class' => 'text-muted']);

        if (!has_capability('local/catquizlab:export', \context_system::instance())) {
            return $out . \html_writer::div(
                get_string('results:exportnopermission', $component),
                'alert alert-info'
            );
        }

        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->head = [
            get_string('export:level', $component),
            get_string('export:rowmeaning', $component),
            get_string('export:rows', $component),
            get_string('manage:col_actions', $component),
        ];

        foreach (results_export::levels() as $level => $stringkey) {
            // The size, not the contents. Building four full datasets to print
            // four numbers is how opening this tab cost as much as four
            // downloads.
            // The step level counts its rows in the same pass that estimates
            // what TI@n would cost: one pass over the traces, not two.
            $stepcost = $level === results_export::LEVEL_STEP ? results_export::engine_info_cost($this->query) : null;
            $rowcount = $stepcost !== null ? $stepcost['steps'] : results_export::row_count($this->query, $level);
            $links = \html_writer::link(
                new \moodle_url('/local/catquizlab/results.php', $this->filter + [
                    'tab' => 'export', 'level' => $level, 'action' => 'csv',
                ]),
                get_string('export:csv', $component),
                ['class' => 'btn btn-sm btn-outline-secondary mr-2']
            );
            $links .= \html_writer::link(
                new \moodle_url('/local/catquizlab/results.php', $this->filter + [
                    'tab' => 'export', 'level' => $level, 'action' => 'json',
                ]),
                get_string('export:json', $component),
                ['class' => 'btn btn-sm btn-outline-secondary']
            );

            // The step level: what TI@n would cost, said before it is asked for (#99).
            if ($stepcost !== null) {
                $cost = $stepcost;
                $minutes = format_float($cost['seconds'] / 60, 1);
                if ($cost['seconds'] > results_export::ENGINE_INFO_HARD_LIMIT) {
                    $links .= \html_writer::div(
                        get_string('export:tiatntoolong', $component, $minutes),
                        'small text-muted mt-1',
                        ['data-region' => 'catquizlab-tiatn-cost']
                    );
                } else if ($cost['seconds'] > results_export::ENGINE_INFO_SOFT_LIMIT) {
                    $with = '';
                    foreach (['csv', 'json'] as $format) {
                        $with .= \html_writer::link(new \moodle_url('/local/catquizlab/results.php', $this->filter + [
                            'tab' => 'export', 'level' => $level, 'action' => $format, 'engineinfo' => 1,
                        ]), get_string('export:' . $format, $component), ['class' => 'btn btn-sm btn-outline-secondary mr-2']);
                    }
                    $links .= \html_writer::div(
                        get_string('export:tiatnslow', $component, $minutes) . ' ' . $with,
                        'small text-muted mt-1',
                        ['data-region' => 'catquizlab-tiatn-cost']
                    );
                }
            }

            $table->data[] = [
                get_string($stringkey, $component),
                get_string($stringkey . '_desc', $component),
                $rowcount < 0 ? get_string('export:sizeunknown', $component) : $rowcount,
                $links,
            ];
        }
        $out .= \html_writer::table($table);

        // What the file will say about itself, shown before it is written.
        $metadata = results_export::metadata($this->query, results_export::LEVEL_ATTEMPT);
        $out .= \html_writer::tag('h3', get_string('export:metadata', $component), ['class' => 'h5 mt-4']);
        $out .= \html_writer::tag('p', get_string('export:metadataexplain', $component), ['class' => 'text-muted']);
        $out .= \html_writer::tag(
            'pre',
            s(json_encode(
                array_diff_key($metadata, ['columns' => true]),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )),
            ['class' => 'bg-light p-3 small', 'style' => 'max-height:20em;overflow:auto']
        );

        return $out;
    }

    /**
     * A picker for the data level.
     *
     * @param string $level The current level.
     * @param string $tab The tab to return to.
     * @return string
     */
    protected function render_level_picker(string $level, string $tab): string {
        $component = 'local_catquizlab';

        $options = [];
        foreach (results_export::levels() as $key => $stringkey) {
            $options[$key] = get_string($stringkey, $component);
        }

        $out = \html_writer::start_tag('form', [
            'method' => 'get',
            'action' => (new \moodle_url('/local/catquizlab/results.php'))->out(false),
            'class'  => 'form-inline mb-3',
        ]);
        $out .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => $tab]);
        foreach ($this->filter as $name => $value) {
            $out .= \html_writer::empty_tag('input', [
                'type' => 'hidden', 'name' => $name, 'value' => $value,
            ]);
        }
        // A visible label, not just an aria-label: a bare dropdown leaves
        // every sighted reader guessing what it selects.
        $out .= \html_writer::tag('label', get_string('export:level', $component), [
            'for'   => 'catlablevel',
            'class' => 'mr-2',
        ]);
        $out .= \html_writer::select($options, 'level', $level, false, [
            'class' => 'custom-select mr-2',
            'id'    => 'catlablevel',
        ]);
        $out .= \html_writer::empty_tag('input', [
            'type' => 'submit', 'class' => 'btn btn-secondary',
            'value' => get_string('flow:show', $component),
        ]);
        $out .= \html_writer::end_tag('form');

        return $out;
    }

    /**
     * The test-flow tab: how single tests ran, and whether their targets were reachable.
     *
     * @return string
     */
    protected function render_testflow(): string {
        global $DB;

        $component = 'local_catquizlab';
        $attemptid = optional_param('attemptid', 0, PARAM_INT);

        $out = \html_writer::tag('h3', get_string('results:flowgroup', $component), ['class' => 'h5']);
        $out .= \html_writer::tag('p', get_string('results:flowexplain', $component), ['class' => 'text-muted']);

        // One pass over the sittings (#99), keeping what each part needs and
        // nothing else: the feasibility as counts, the first few hundred for
        // the pickers, the chosen and the first sitting, the twin families as
        // sitting ids. It used to hold every sitting — 152 MB for 50,000.
        $counts = test_flow::summarise_feasibility([]);
        $targets = [];
        $sample = [];
        $selected = null;
        $first = null;
        $families = [];
        $runids = [];
        foreach ($this->query->each_observation() as $observation) {
            // The trace and the profile for this one row; see
            // results_query::detail() for why they are not in the row.
            $verdict = test_flow::feasibility(
                $observation + results_query::detail($observation),
                $this->cat_parameters($observation['runid'])
            );
            $key = $verdict['verdict'] ?? 'unknown';
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $counts['n']++;
            if ($verdict['setarget'] !== null && $verdict['required'] !== null) {
                $targets[(string) $verdict['setarget']] = $verdict['required'];
            }
            if (count($sample) < 300) {
                $sample[] = $observation;
            }
            $first = $first ?? $observation;
            if ($attemptid > 0 && (int) $observation['attemptid'] === $attemptid) {
                $selected = $observation;
            }
            if ((string) $observation['twinid'] !== '') {
                $families[(string) $observation['twinid']][] = (int) $observation['attemptid'];
            }
            $runids[(int) $observation['runid']] = true;
        }
        if ($first === null) {
            return $out . \html_writer::div(
                get_string('results:noobservations', $component),
                'alert alert-info'
            );
        }
        $families = array_filter($families, static fn(array $members): bool => count($members) > 1);

        // Feasibility first: it is the context in which a stop-rule failure has
        // to be read, so it belongs above the individual flows.
        $out .= \html_writer::tag('h3', get_string('results:feasibility', $component), ['class' => 'h5 mt-4']);
        $out .= $this->render_feasibility($counts, $targets);

        // One attempt in detail. Without a choice the first one stands in, so
        // the tab is never empty when data exist.
        $selected = $selected ?? $first;

        $out .= \html_writer::tag('h3', get_string('results:singleflow', $component), ['class' => 'h5 mt-4']);
        $out .= $this->render_attempt_picker($sample, (int) $selected['attemptid']);
        $out .= $this->render_single_flow($selected);
        $out .= $this->render_comparison($sample, $families, array_keys($runids));

        return $out;
    }

    /**
     * The feasibility summary across the filtered attempts.
     *
     * @param array $counts Verdict => count, and n, as test_flow::summarise_feasibility() counts.
     * @param array $targets SE target => the number of items it requires.
     * @return string
     */
    protected function render_feasibility(array $counts, array $targets): string {
        $component = 'local_catquizlab';
        $n = max(1, (int) $counts['n']);

        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm w-auto';
        $table->head = [
            get_string('flow:verdict', $component),
            get_string('report:runs', $component),
            get_string('flow:share', $component),
        ];
        foreach (['reached', 'stoppedearly', 'budgetexhausted', 'missed', 'unknown'] as $key) {
            $table->data[] = [
                get_string('flow:' . $key, $component),
                (int) $counts[$key],
                format_float(100 * $counts[$key] / $n, 1) . '&nbsp;%',
            ];
        }

        // The arithmetic behind the verdict, stated rather than implied.
        $explain = '';
        foreach ($targets as $se => $required) {
            $explain .= \html_writer::tag('li', get_string('flow:targetmath', $component, (object) [
                'se'       => format_float((float) $se, 2),
                'required' => format_float((float) $required, 2),
            ]));
        }

        return \html_writer::table($table)
            . ($explain === '' ? '' : \html_writer::tag('ul', $explain, ['class' => 'small text-muted']));
    }

    /**
     * A picker for the attempt shown in detail.
     *
     * @param array $observations The filtered observations.
     * @param int $selected The currently selected attempt.
     * @return string
     */
    protected function render_attempt_picker(array $observations, int $selected): string {
        $component = 'local_catquizlab';

        $options = [];
        foreach (array_slice($observations, 0, 200) as $observation) {
            $options[(int) $observation['attemptid']] = get_string('flow:attemptlabel', $component, (object) [
                'id'       => $observation['attemptid'],
                'twin'     => $observation['twinid'] !== '' ? $observation['twinid'] : '—',
                'strategy' => run_registry::group_label('strategy', $observation['strategy']),
                'items'    => $observation['nitems'],
            ]);
        }

        $out = \html_writer::start_tag('form', [
            'method' => 'get',
            'action' => (new \moodle_url('/local/catquizlab/results.php'))->out(false),
            'class'  => 'form-inline mb-3',
        ]);
        $out .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => 'testflow']);
        foreach ($this->filter as $name => $value) {
            $out .= \html_writer::empty_tag('input', [
                'type' => 'hidden', 'name' => $name, 'value' => $value,
            ]);
        }
        $out .= \html_writer::tag('label', get_string('flow:pickattempt', $component), [
            'for'   => 'catlabattempt',
            'class' => 'mr-2',
        ]);
        $out .= \html_writer::select($options, 'attemptid', $selected, false, [
            'class' => 'custom-select mr-2',
            'id'    => 'catlabattempt',
        ]);
        $out .= \html_writer::empty_tag('input', [
            'type' => 'submit', 'class' => 'btn btn-secondary',
            'value' => get_string('flow:show', $component),
        ]);
        $out .= \html_writer::end_tag('form');

        return $out;
    }

    /**
     * The course of one attempt.
     *
     * @param array $observation The attempt.
     * @return string
     */
    /** @var string[] Every plot type on the results pages, for keeping their axis settings. */
    public const PLOT_TYPES = ['lengthprecision', 'recovery', 'error', 'singletest', 'strength', 'localdeviation',
        'exposure', 'comparison', 'localerroritems', 'localseitems'];

    /**
     * A plot's axes and export, from its settings (#108).
     *
     * @param scatter_chart $chart The plot.
     * @param string $type Its type, one of PLOT_TYPES.
     * @return void
     */
    protected function configure_chart(scatter_chart $chart, string $type): void {
        $request = optional_param_array('ax_' . $type, [], PARAM_RAW);

        // Saving: as a named profile, or as the shared scale.
        if ((!empty($request['savename']) || !empty($request['saveshared'])) && confirm_sesskey()) {
            if (!empty($request['savename'])) {
                \local_catquizlab\local\axis_profiles::save((string) $request['savename'], $request);
            }
            if (!empty($request['saveshared'])) {
                \local_catquizlab\local\axis_profiles::save(\local_catquizlab\local\axis_profiles::SHARED_NAME, $request);
            }
            if (
                !empty($request['savename']) && !empty($request['saveexperiment'])
                && has_capability('local/catquizlab:edit', \context_system::instance())
            ) {
                \local_catquizlab\local\axis_profiles::save_for_experiment(
                    (int) ($this->filter['experimentid'] ?? 0),
                    (string) $request['savename'],
                    $request
                );
            }
        }

        $experimentid = (int) ($this->filter['experimentid'] ?? 0);
        $resolved = \local_catquizlab\local\axis_profiles::resolve($request, $experimentid);
        $settings = $resolved['settings'];
        if (!empty($settings['sym'])) {
            $chart->force_symmetric();
        }
        if (!empty($settings['robust'])) {
            $chart->set_robust();
        }
        if ($settings['mode'] !== \local_catquizlab\local\axis_profiles::AUTO) {
            $chart->set_fixed_bounds([
                'xmin' => $settings['xmin'], 'xmax' => $settings['xmax'],
                'ymin' => $settings['ymin'], 'ymax' => $settings['ymax'],
            ]);
            $chart->set_tick_spacing($settings['xtick'], $settings['ytick']);
        }
        $chart->set_profile($resolved['profile'] !== '' ? $resolved['profile']
            : ($settings['mode'] === \local_catquizlab\local\axis_profiles::SHARED ? 'shared' : ''));

        $context = 'exp' . (int) ($this->filter['experimentid'] ?? 0)
            . (!empty($this->filter['runid']) ? '-run' . (int) $this->filter['runid'] : '');
        $chart->set_export($type, $context, $this->filter);
        $chart->set_controls($this->axis_controls($type, $settings, $resolved['profile']));
    }

    /**
     * The axis settings form beneath a plot.
     *
     * @param string $type The plot type.
     * @param array $settings The settings in force.
     * @param string $profile The profile loaded, if any.
     * @return string
     */
    protected function axis_controls(string $type, array $settings, string $profile): string {
        $component = 'local_catquizlab';
        $name = static fn(string $field): string => 'ax_' . $type . '[' . $field . ']';
        $id = static fn(string $field): string => 'ax_' . $type . '_' . $field;

        $hidden = \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => $this->tab]);
        $hidden .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        foreach ($this->filter as $key => $value) {
            $hidden .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
        }
        // The other plots keep their settings.
        foreach (self::PLOT_TYPES as $other) {
            if ($other === $type) {
                continue;
            }
            foreach (optional_param_array('ax_' . $other, [], PARAM_RAW) as $field => $value) {
                if (in_array($field, \local_catquizlab\local\axis_profiles::FIELDS, true) || $field === 'profile') {
                    $hidden .= \html_writer::empty_tag('input', [
                        'type' => 'hidden', 'name' => 'ax_' . $other . '[' . $field . ']', 'value' => (string) $value,
                    ]);
                }
            }
        }
        foreach (['tmetric' => 'cmp_metric', 'tgroup' => 'cmp_group', 'tscale' => 'cmp_scale', 'ttwin' => 'cmp_twin'] as $param) {
            $value = optional_param($param, '', PARAM_RAW);
            if ($value !== '') {
                $hidden .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $param, 'value' => $value]);
            }
        }

        $control = static fn(string $label, string $field, string $html): string => \html_writer::div(
            \html_writer::tag('label', $label, ['for' => $id($field), 'class' => 'mr-1 small']) . $html,
            'mr-3 mb-2'
        );
        $number = static fn(string $field) => \html_writer::empty_tag('input', [
            'type' => 'text', 'size' => 4, 'class' => 'form-control form-control-sm', 'name' => $name($field),
            'id' => $id($field), 'value' => $settings[$field] === null ? '' : format_float((float) $settings[$field], 2),
        ]);

        $profiles = [];
        $available = \local_catquizlab\local\axis_profiles::all((int) ($this->filter['experimentid'] ?? 0));
        foreach (array_keys($available) as $profilename) {
            $profiles[$profilename] = $profilename;
        }
        $fields = \html_writer::start_div('d-flex flex-wrap align-items-end');
        $fields .= $control(get_string('axes:mode', $component), 'mode', \html_writer::select([
            'auto' => get_string('axes:auto', $component),
            'shared' => get_string('axes:shared', $component),
            'manual' => get_string('axes:manual', $component),
        ], $name('mode'), $settings['mode'], false, ['id' => $id('mode'), 'class' => 'custom-select custom-select-sm']));
        $fields .= $control(
            get_string('axes:symmetric', $component),
            'sym',
            \html_writer::checkbox($name('sym'), 1, !empty($settings['sym']), '', ['id' => $id('sym')])
        );
        $fields .= $control(
            get_string('axes:robust', $component),
            'robust',
            \html_writer::checkbox($name('robust'), 1, !empty($settings['robust']), '', ['id' => $id('robust')])
        );
        foreach (['xmin', 'xmax', 'ymin', 'ymax', 'xtick', 'ytick'] as $field) {
            $fields .= $control(get_string('axes:' . $field, $component), $field, $number($field));
        }
        $fields .= $control(get_string('axes:profile', $component), 'profile', \html_writer::select(
            $profiles,
            $name('profile'),
            $profile,
            ['' => '—'],
            ['id' => $id('profile'), 'class' => 'custom-select custom-select-sm']
        ));
        $fields .= $control(get_string('axes:savename', $component), 'savename', \html_writer::empty_tag('input', [
            'type' => 'text', 'size' => 14, 'class' => 'form-control form-control-sm', 'name' => $name('savename'),
            'id' => $id('savename'),
        ]));
        if (!empty($this->filter['experimentid'])) {
            $fields .= $control(
                get_string('axes:saveexperiment', $component),
                'saveexperiment',
                \html_writer::checkbox($name('saveexperiment'), 1, false, '', ['id' => $id('saveexperiment')])
            );
        }
        $fields .= $control(
            get_string('axes:saveshared', $component),
            'saveshared',
            \html_writer::checkbox($name('saveshared'), 1, false, '', ['id' => $id('saveshared')])
        );
        $fields .= \html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-sm btn-secondary mb-2',
            'value' => get_string('axes:apply', $component)]);
        $fields .= \html_writer::end_div();

        $form = \html_writer::tag('form', $hidden . $fields, [
            'method' => 'get', 'action' => (new \moodle_url('/local/catquizlab/results.php'))->out(false),
            'data-region' => 'catquizlab-axis-form', 'data-plot' => $type,
        ]);

        $summary = \html_writer::tag('summary', get_string('axes:heading', $component), ['class' => 'small']);
        return \html_writer::tag('details', $summary
            . $form, ['class' => 'mb-3']);
    }

    /** @var string|null The data basis of this page's plots, once built. */
    protected ?string $plotbasis = null;

    /**
     * Several related traces in one plot (#109).
     *
     * A twin family — the same simulated person in several runs, under
     * different strategies — or sittings picked one by one; coloured by twin
     * family, strategy, run or replication; by ability, standard error, test
     * information or scales estimated, globally or for one subscale.
     *
     * @param array[] $sample The first few hundred observations, for picking tests.
     * @param array $families Twin families with more than one test: twin id => sitting ids.
     * @param int[] $runids The runs of the selection.
     * @return string
     */
    protected function render_comparison(array $sample, array $families, array $runids): string {
        global $DB;
        $component = 'local_catquizlab';

        $metric = optional_param('cmp_metric', 'ability', PARAM_ALPHA);
        $metric = in_array($metric, \local_catquizlab\local\test_flow::METRICS, true) ? $metric : 'ability';
        $group = optional_param('cmp_group', 'strategy', PARAM_ALPHA);
        $group = in_array($group, ['twin', 'strategy', 'run', 'replication'], true) ? $group : 'strategy';
        $scaleid = optional_param('cmp_scale', 0, PARAM_INT);
        $twin = optional_param('cmp_twin', '', PARAM_ALPHANUMEXT);
        $picked = optional_param_array('cmp_attempts', [], PARAM_INT);
        $fixed = [];
        foreach (['xmin', 'xmax', 'ymin', 'ymax'] as $key) {
            $raw = optional_param('cmp_' . $key, '', PARAM_RAW_TRIMMED);
            $fixed[$key] = $raw === '' ? null : unformat_float($raw);
        }
        $layout = optional_param('cmp_layout', 'overlay', PARAM_ALPHA) === 'multiples' ? 'multiples' : 'overlay';

        // A saved comparison (#109): metric, colouring, subscale, layout and
        // axes, loaded by name — or the present one saved under a name.
        $template = optional_param('cmp_template', '', PARAM_TEXT);
        $templates = \local_catquizlab\local\compare_templates::mine();
        if ($template !== '' && isset($templates[$template])) {
            $saved = $templates[$template];
            $known = in_array($saved['metric'] ?? '', \local_catquizlab\local\test_flow::METRICS, true);
            $metric = $known ? $saved['metric'] : $metric;
            $group = in_array($saved['group'] ?? '', ['twin', 'strategy', 'run', 'replication'], true) ? $saved['group'] : $group;
            $scaleid = (int) ($saved['scale'] ?? $scaleid);
            $layout = ($saved['layout'] ?? '') === 'multiples' ? 'multiples' : 'overlay';
            foreach (['xmin', 'xmax', 'ymin', 'ymax'] as $key) {
                $fixed[$key] = isset($saved[$key]) && is_numeric($saved[$key]) ? (float) $saved[$key] : null;
            }
        }
        $savename = trim(optional_param('cmp_savetemplate', '', PARAM_TEXT));
        if ($savename !== '' && confirm_sesskey()) {
            \local_catquizlab\local\compare_templates::save($savename, [
                'metric' => $metric, 'group' => $group, 'scale' => $scaleid, 'layout' => $layout,
            ] + $fixed);
            $template = $savename;
        }

        // Twin families with more than one sitting, as sitting ids: the tests
        // compared are fetched as themselves, not found among all of them.
        ksort($families);

        if ($picked !== []) {
            $selected = $this->query->observations_of($picked);
        } else {
            if ($twin === '' || !isset($families[$twin])) {
                $twin = (string) (array_key_first($families) ?? '');
            }
            $selected = $twin === '' ? [] : $this->query->observations_of($families[$twin]);
        }

        $out = \html_writer::tag('h3', get_string('compare:heading', $component), ['class' => 'h5 mt-4']);
        $out .= \html_writer::tag('p', get_string('compare:explain', $component), ['class' => 'text-muted small']);
        $out .= $this->render_comparison_form(
            $sample,
            $families,
            $runids,
            $twin,
            $picked,
            $group,
            $metric,
            $scaleid,
            $fixed,
            $layout,
            $template
        );
        if ($selected === []) {
            return $out . \html_writer::div(get_string('compare:none', $component), 'alert alert-info');
        }

        $labels = [
            'ability' => get_string('axis:esttheta', $component),
            'se' => get_string('flow:se', $component),
            'ti' => get_string('flow:ti', $component),
            'tiatn' => get_string('flow:tiatn', $component),
            'tiremainingmin' => get_string('flow:tiremainingmin', $component),
            'tiremaining' => get_string('flow:tiremaining', $component),
            'scales' => get_string('flow:scalesestimated', $component),
        ];
        $chart = new scatter_chart(
            get_string('compare:heading', $component),
            get_string('axis:step', $component),
            $labels[$metric]
        );
        $chart->set_axes(
            axis_scale::INTEGER,
            $metric === 'ability' ? axis_scale::SYMMETRIC : ($metric === 'scales' ? axis_scale::INTEGER : axis_scale::LINEAR),
            ['yatleast' => $metric === 'ability' ? $this->ability_halfrange() : 0, 'yfromzero' => $metric !== 'ability']
        );
        $chart->set_basis($this->plot_basis());
        $chart->set_fixed_bounds($fixed);
        $this->configure_chart($chart, 'comparison');

        $rows = [];
        $notes = [];
        $traces = [];
        // Remaining TI at its least and most (#109): equal wherever every item
        // left may still be played — not a fault, and said so.
        if (in_array($metric, ['tiremaining', 'tiremainingmin'], true)) {
            $notes[] = get_string('compare:remainingnote', $component);
        }
        foreach ($selected as $observation) {
            $detail = $observation + results_query::detail($observation);
            $strategy = (string) $observation['strategy'];
            if ($scaleid > 0 && !\local_catquizlab\local\strategy_catalog::uses_subscales($strategy)) {
                $notes[] = get_string('compare:notapplicable', $component, (object) [
                    'attempt' => (int) $observation['attemptid'],
                    'strategy' => \local_catquizlab\local\strategy_catalog::display_label($strategy),
                ]);
                continue;
            }
            $trace = (array) ($detail['trace'] ?? []);
            $flow = \local_catquizlab\local\test_flow::steps($detail);
            if (in_array($metric, ['tiatn', 'tiremaining', 'tiremainingmin'], true) && $scaleid === 0) {
                $flow = \local_catquizlab\local\test_flow::with_engine_information(
                    $flow,
                    $trace,
                    (int) $observation['runid'],
                    $this->run_maxitems((int) $observation['runid'])
                );
            }
            $subtree = $scaleid > 0 ? results_query::scale_subtree((int) $observation['runid'], $scaleid) : [];
            $series = \local_catquizlab\local\test_flow::series(
                $flow,
                $trace,
                $metric,
                $scaleid,
                $subtree,
                (int) $observation['runid'],
                results_query::run_subscale_maxitems((int) $observation['runid'])
            );
            $groupvalue = [
                'twin' => (string) $observation['twinid'],
                'strategy' => \local_catquizlab\local\strategy_catalog::display_label($strategy) . ' (' . $strategy . ')',
                'run' => get_string('flow:runid', $component) . ' ' . (int) $observation['runid'],
                'replication' => get_string('compare:replication', $component, (int) ($observation['replication'] ?? 0)),
            ][$group];
            $label = get_string('compare:tracelabel', $component, (object) [
                'attempt' => (int) $observation['attemptid'],
                'strategy' => \local_catquizlab\local\strategy_catalog::display_label($strategy),
                'run' => (int) $observation['runid'],
            ]);
            $chart->add_series($label, $groupvalue, $series['points']);
            $traces[] = ['label' => $label, 'group' => $groupvalue, 'points' => $series['points']];
            if ($scaleid > 0) {
                $notes[] = get_string('compare:scalestatus', $component, (object) [
                    'attempt' => (int) $observation['attemptid'],
                    'status' => $series['status'] !== '' ? $series['status'] : get_string('flow:na', $component),
                ]) . ($metric !== 'ability' && !$series['consistent']
                    ? ' ' . get_string('compare:scaleunconfirmed', $component)
                    : '');
            }
            foreach ($series['points'] as $point) {
                $rows[] = [
                    'attemptid' => (int) $observation['attemptid'],
                    'twinid' => (string) $observation['twinid'],
                    'runid' => (int) $observation['runid'],
                    'strategy' => $strategy,
                    'replication' => (int) ($observation['replication'] ?? 0),
                    'metric' => $metric,
                    'scaleid' => $scaleid,
                    'step' => $point['x'],
                    'value' => $point['y'],
                ];
            }
        }

        // The comparison exports what #109 asks for: sitting, twin family,
        // run, strategy, replication, metric, scale — not only x and y.
        $chart->set_export('comparison-' . $metric . ($scaleid ? '-scale' . $scaleid : ''), 'exp'
            . (int) ($this->filter['experimentid'] ?? 0), $this->filter, $rows);
        if ($layout === 'multiples' && count($traces) > 1) {
            // Small multiples: one plot per test, every one on the axes the
            // overlay of all of them would have — identical scaling by
            // construction, so that the plots can be compared by eye.
            $axes = $chart->last_settings()['axis'] ?? [];
            $grid = '';
            foreach ($traces as $trace) {
                $small = new scatter_chart($trace['label'], get_string('axis:step', $component), $labels[$metric]);
                $small->set_axes(
                    axis_scale::INTEGER,
                    $metric === 'ability'
                        ? axis_scale::SYMMETRIC
                        : ($metric === 'scales' ? axis_scale::INTEGER : axis_scale::LINEAR)
                );
                $small->set_fixed_bounds([
                    'xmin' => $axes['x']['min'] ?? null, 'xmax' => $axes['x']['max'] ?? null,
                    'ymin' => $axes['y']['min'] ?? null, 'ymax' => $axes['y']['max'] ?? null,
                ]);
                $small->set_tick_spacing($axes['x']['tick_spacing'] ?? null, $axes['y']['tick_spacing'] ?? null);
                $small->add_series($trace['label'], $trace['group'], $trace['points']);
                $small->set_export(
                    'comparison-' . $metric . '-multiple',
                    'exp' . (int) ($this->filter['experimentid'] ?? 0),
                    $this->filter
                );
                $grid .= \html_writer::div(
                    \html_writer::tag('div', s($trace['label']), ['class' => 'small font-weight-bold']) . $small->render(),
                    'p-1',
                    ['style' => 'flex: 0 0 50%; max-width: 50%;', 'data-region' => 'catquizlab-multiple']
                );
            }
            $out .= \html_writer::div($grid, 'd-flex flex-wrap', ['data-region' => 'catquizlab-comparison']);
        } else {
            $out .= \html_writer::div($chart->render(), '', ['data-region' => 'catquizlab-comparison']);
        }
        foreach ($notes as $note) {
            $out .= \html_writer::div(s($note), 'small text-muted');
        }

        return $out;
    }

    /**
     * The form choosing what to compare.
     *
     * @param array[] $observations The first few hundred observations, for picking tests.
     * @param array $families Twin families with several sittings, as sitting ids.
     * @param int[] $runids The runs of the selection.
     * @param string $twin The chosen family.
     * @param int[] $picked Sittings picked one by one.
     * @param string $group Grouping.
     * @param string $metric Metric.
     * @param int $scaleid Selected subscale, 0 for global.
     * @param array $fixed Hand-set axis ranges.
     * @param string $layout Overlay or small multiples.
     * @param string $template The template loaded, if any.
     * @return string
     */
    protected function render_comparison_form(
        array $observations,
        array $families,
        array $runids,
        string $twin,
        array $picked,
        string $group,
        string $metric,
        int $scaleid,
        array $fixed,
        string $layout = 'overlay',
        string $template = ''
    ): string {
        global $DB;
        $component = 'local_catquizlab';

        $out = \html_writer::start_tag('form', [
            'method' => 'get', 'action' => (new \moodle_url('/local/catquizlab/results.php'))->out(false),
            'class' => 'mb-3', 'data-region' => 'catquizlab-compare-form',
        ]);
        $out .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => 'testflow']);
        foreach ($this->filter as $name => $value) {
            $out .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }
        // Every label bound to its control: for screen readers, and for tests
        // that find a field by what it is called.
        $field = static fn(string $id, string $label, string $control): string =>
            \html_writer::div(
                \html_writer::tag('label', $label, ['class' => 'mr-2', 'for' => $id]) . $control,
                'form-group mr-3 mb-2'
            );

        $familyoptions = [];
        foreach ($families as $id => $members) {
            $familyoptions[$id] = get_string('compare:family', $component, (object) ['id' => $id, 'n' => count($members)]);
        }
        $attemptoptions = [];
        $scaleids = [];
        foreach (array_slice($observations, 0, 300) as $observation) {
            $attemptoptions[(int) $observation['attemptid']] = get_string('compare:tracelabel', $component, (object) [
                'attempt' => (int) $observation['attemptid'],
                'strategy' => \local_catquizlab\local\strategy_catalog::display_label((string) $observation['strategy']),
                'run' => (int) $observation['runid'],
            ]) . ((string) $observation['twinid'] !== '' ? ' · ' . $observation['twinid'] : '');
        }
        foreach (
            $DB->get_records_select(
                'local_catquizlab_scalemap',
                'parentcatscaleid <> 0 AND runid IN (' . implode(',', array_map(
                    'intval',
                    $runids
                ) ?: [0]) . ')',
                null,
                'catscaleid ASC',
                'id, catscaleid'
            ) as $row
        ) {
            $scaleids[(int) $row->catscaleid] = true;
        }
        $scaleoptions = [0 => get_string('compare:global', $component)];
        if ($scaleids !== [] && $DB->get_manager()->table_exists('local_catquiz_catscales')) {
            [$in, $params] = $DB->get_in_or_equal(array_keys($scaleids));
            foreach ($DB->get_records_select('local_catquiz_catscales', 'id ' . $in, $params, 'name ASC', 'id, name') as $scale) {
                $scaleoptions[(int) $scale->id] = $scale->name;
            }
        }

        $out .= \html_writer::start_div('d-flex flex-wrap align-items-end');
        $out .= $field(
            'cmp_twin',
            get_string('compare:twinfamily', $component),
            \html_writer::select(
                $familyoptions,
                'cmp_twin',
                $twin,
                ['' => get_string('compare:nofamily', $component)],
                ['class' => 'custom-select', 'id' => 'cmp_twin']
            )
        );
        $out .= $field(
            'cmp_attempts',
            get_string('compare:orattempts', $component),
            \html_writer::select(
                $attemptoptions,
                'cmp_attempts[]',
                $picked,
                false,
                ['class' => 'custom-select', 'multiple' => 'multiple', 'size' => 4, 'id' => 'cmp_attempts']
            )
        );
        $out .= $field('cmp_group', get_string('compare:group', $component), \html_writer::select([
            'twin' => get_string('compare:bytwin', $component), 'strategy' => get_string('form:strategy', $component),
            'run' => get_string('flow:runid', $component), 'replication' => get_string('compare:byreplication', $component),
        ], 'cmp_group', $group, false, ['class' => 'custom-select', 'id' => 'cmp_group']));
        $out .= $field('cmp_metric', get_string('compare:metric', $component), \html_writer::select([
            'ability' => get_string('axis:esttheta', $component), 'se' => get_string('flow:se', $component),
            'ti' => get_string('flow:ti', $component), 'tiatn' => get_string('flow:tiatn', $component),
            'tiremainingmin' => get_string('flow:tiremainingmin', $component),
            'tiremaining' => get_string('flow:tiremaining', $component),
            'scales' => get_string('flow:scalesestimated', $component),
        ], 'cmp_metric', $metric, false, ['class' => 'custom-select', 'id' => 'cmp_metric']));
        $out .= $field(
            'cmp_scale',
            get_string('compare:selectedscale', $component),
            \html_writer::select($scaleoptions, 'cmp_scale', $scaleid, false, ['class' => 'custom-select', 'id' => 'cmp_scale'])
        );
        foreach (['xmin', 'xmax', 'ymin', 'ymax'] as $key) {
            $out .= $field('cmp_' . $key, get_string('compare:' . $key, $component), \html_writer::empty_tag('input', [
                'type' => 'text', 'name' => 'cmp_' . $key, 'id' => 'cmp_' . $key, 'size' => 4, 'class' => 'form-control',
                'value' => $fixed[$key] === null ? '' : format_float((float) $fixed[$key], 2),
            ]));
        }
        $out .= $field('cmp_layout', get_string('compare:layout', $component), \html_writer::select([
            'overlay' => get_string('compare:overlay', $component),
            'multiples' => get_string('compare:multiples', $component),
        ], 'cmp_layout', $layout, false, ['class' => 'custom-select', 'id' => 'cmp_layout']));
        $templatenames = array_keys(\local_catquizlab\local\compare_templates::mine());
        $out .= $field('cmp_template', get_string('compare:template', $component), \html_writer::select(
            array_combine($templatenames, $templatenames) ?: [],
            'cmp_template',
            $template,
            ['' => '—'],
            ['class' => 'custom-select', 'id' => 'cmp_template']
        ));
        $out .= $field('cmp_savetemplate', get_string('compare:savetemplate', $component), \html_writer::empty_tag('input', [
            'type' => 'text', 'name' => 'cmp_savetemplate', 'id' => 'cmp_savetemplate', 'size' => 12, 'class' => 'form-control',
        ]));
        $out .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $out .= \html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-secondary mb-2',
            'value' => get_string('compare:show', $component)]);
        $out .= \html_writer::end_div() . \html_writer::end_tag('form');

        return $out;
    }

    /**
     * The simulated people of the selection: how their abilities are distributed (#109, section 4).
     *
     * Read as a stream, each person once — twins in other runs share their
     * ability and are the same simulated person.
     *
     * @return string
     */
    protected function render_people(): string {
        $component = 'local_catquizlab';
        $values = [];
        foreach ($this->query->each_observation() as $observation) {
            $key = (string) $observation['twinid'] !== '' ? 'twin:' . $observation['twinid'] : 'person:' . $observation['personid'];
            $values[$key] = (float) $observation['truetheta'];
        }
        $out = \html_writer::tag('h3', get_string('people:heading', $component), ['class' => 'h5 mt-4']);
        if ($values === []) {
            return $out . \html_writer::div(get_string('chart:nodata', $component), 'alert alert-info');
        }
        $values = array_values($values);
        $n = count($values);
        $mean = array_sum($values) / $n;
        $sd = $n > 1 ? sqrt(array_sum(array_map(static fn(float $v): float => ($v - $mean) ** 2, $values)) / ($n - 1)) : 0.0;
        $half = $this->ability_halfrange();

        $out .= \html_writer::tag('p', get_string('people:explain', $component, (object) [
            'n' => $n,
            'mean' => format_float($mean, 3), 'sd' => format_float($sd, 3),
            'min' => format_float(min($values), 3), 'max' => format_float(max($values), 3),
            'distributions' => implode('; ', array_keys($this->query->ability_distributions())),
        ]), ['class' => 'small', 'data-region' => 'catquizlab-people-stats']);

        $chart = new histogram_chart(get_string('people:heading', $component), get_string('axis:truetheta', $component), $values);
        if ($half > 0) {
            $chart->set_marks([-$half, $half]);
        }
        $svg = $chart->render();
        $csv = "truetheta\n" . implode("\n", array_map(static fn(float $v): string => (string) round($v, 5), $values)) . "\n";
        $peoplename = 'catquizlab-people-exp' . (int) ($this->filter['experimentid'] ?? 0) . '-' . date('Ymd-His');
        $links = \html_writer::link(
            'data:image/svg+xml;base64,' . base64_encode($svg),
            get_string('chart:downloadsvg', $component),
            ['download' => $peoplename . '.svg', 'class' => 'btn btn-sm btn-outline-secondary mr-2', 'data-download' => 'svg']
        )
            . \html_writer::link(
                'data:text/csv;charset=utf-8;base64,' . base64_encode($csv),
                get_string('chart:downloadcsv', $component),
                ['download' => $peoplename . '.csv', 'class' => 'btn btn-sm btn-outline-secondary', 'data-download' => 'csv']
            );

        $links .= \html_writer::tag('button', get_string('chart:downloadpng', $component), [
            'type' => 'button', 'class' => 'btn btn-sm btn-outline-secondary ml-2', 'data-download' => 'png',
            'data-filename' => $peoplename . '.png',
        ]) . \html_writer::link(new \moodle_url('/local/catquizlab/plotpdf.php', [
            'key' => scatter_chart::keep_for_pdf($svg, $peoplename), 'sesskey' => sesskey(),
        ]), get_string('chart:downloadpdf', $component), [
            'class' => 'btn btn-sm btn-outline-secondary ml-2', 'data-download' => 'pdf',
        ]);
        return $out . \html_writer::div($svg, '', ['data-region' => 'catquizlab-people'])
            . \html_writer::div(s($this->plot_basis()), 'small text-muted mt-1') . \html_writer::div($links, 'mb-3')
            . $this->render_deviations();
    }

    /**
     * How the simulated local deviations are distributed (#109, section 4).
     *
     * Δ = true subscale ability − true global ability, the same quantity the
     * local-deviation plot compares; each person once, read from the persons
     * of the selected runs.
     *
     * @return string
     */
    protected function render_deviations(): string {
        global $DB;
        $component = 'local_catquizlab';

        $runids = array_keys($this->query->runs());
        if ($runids === []) {
            return '';
        }
        [$in, $params] = $DB->get_in_or_equal($runids, SQL_PARAMS_NAMED, 'run');
        $seen = [];
        $deltas = [];
        $persons = $DB->get_recordset_select(
            'local_catquizlab_person',
            'runid ' . $in,
            $params,
            'id ASC',
            'id, twinid, abilityglobal, profilejson'
        );
        foreach ($persons as $person) {
            $key = (string) $person->twinid !== '' ? 'twin:' . $person->twinid : 'person:' . $person->id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $profile = json_decode((string) $person->profilejson, true) ?: [];
            foreach ((array) ($profile['categories'] ?? []) as $category) {
                foreach ((array) ($category['subscales'] ?? []) as $subscale) {
                    $deltas[] = (float) $subscale['theta'] - (float) $person->abilityglobal;
                }
            }
        }
        $persons->close();

        $out = \html_writer::tag('h4', get_string('people:deviations', $component), ['class' => 'h6 mt-3']);
        if ($deltas === []) {
            return $out . \html_writer::div(get_string('chart:nodata', $component), 'alert alert-info');
        }
        $n = count($deltas);
        $mean = array_sum($deltas) / $n;
        $sd = $n > 1 ? sqrt(array_sum(array_map(static fn(float $d): float => ($d - $mean) ** 2, $deltas)) / ($n - 1)) : 0.0;
        $out .= \html_writer::tag('p', get_string('people:deviationstats', $component, (object) [
            'n' => $n, 'people' => count($seen), 'mean' => format_float($mean, 3), 'sd' => format_float($sd, 3),
            'min' => format_float(min($deltas), 3), 'max' => format_float(max($deltas), 3),
        ]), ['class' => 'small', 'data-region' => 'catquizlab-deviation-stats']);
        if (max($deltas) - min($deltas) < 1e-9) {
            return $out . \html_writer::div(get_string('people:novariation', $component), 'alert alert-info');
        }
        $chart = new histogram_chart(
            get_string('people:deviations', $component),
            get_string('axis:truedelta', $component),
            $deltas
        );

        return $out . \html_writer::div($chart->render(), '', ['data-region' => 'catquizlab-deviations']);
    }

    /**
     * What every plot on this page is based on (#105, section 9).
     *
     * Experiment, runs, strategies, models, sittings, the simulated abilities
     * (distribution, mean, SD, range), the filter, and the stop rules in force
     * — the effective item budget and SE rule per strategy.
     *
     * @return string
     */
    protected function plot_basis(): string {
        if ($this->plotbasis !== null) {
            return $this->plotbasis;
        }
        $component = 'local_catquizlab';
        $runs = $this->query->runs();
        $unique = static fn(string $key): string => implode(', ', array_unique(array_filter(array_map(
            static fn(array $run): string => (string) ($run[$key] ?? ''),
            $runs
        ))));
        $filter = [];
        foreach ($this->query->get_filter() as $key => $value) {
            if ($value !== '' && $value !== null && $value !== [] && $key !== 'experimentid') {
                $filter[] = $key . ' = ' . (is_array($value) ? implode('/', $value) : $value);
            }
        }
        $rules = [];
        foreach ($this->query->stop_rules() as $group) {
            $rules[] = $group['label'] . ': ' . implode('; ', $group['rules']);
        }

        $this->plotbasis = get_string('chart:basis', $component, (object) [
            'experiment' => $unique('experiment'),
            'runs'       => count($runs),
            'strategies' => $unique('strategylabel'),
            'models'     => $unique('model'),
            'attempts'   => (int) ($this->query->size_check()['count'] ?? 0),
            'abilities'  => implode('; ', array_keys($this->query->ability_distributions())),
            'filter'     => $filter === [] ? get_string('chart:nofilter', $component) : implode(', ', $filter),
            'rules'      => implode(' | ', $rules),
        ]);

        return $this->plotbasis;
    }

    /**
     * A run's effective maximum number of questions, -1 for none.
     *
     * @param int $runid The run.
     * @return int
     */
    protected function run_maxitems(int $runid): int {
        return results_query::run_maxitems($runid);
    }

    /**
     * Which sittings the figures are made of, and which were left out and why (#118).
     *
     * @return string
     */
    protected function render_coverage(): string {
        $component = 'local_catquizlab';
        $counts = $this->query->validity_counts();
        if ($counts['total'] === 0) {
            return '';
        }
        $percent = static fn(int $n): string => format_float(100 * $n / $counts['total'], 1);
        $parts = [get_string('validity:coverage', $component, (object) [
            'valid' => $counts['valid'], 'total' => $counts['total'], 'percent' => $percent($counts['valid']),
        ])];
        // Why the others are not valid — a sitting may fail several rules, and
        // each is counted (#112): an end before the minimum, and the engine's
        // definitions of SE, items and response pattern.
        foreach ($counts['invalidreasons'] as $code => $n) {
            $label = in_array($code, reason_catalog::OUTCOMES, true) ? reason_catalog::label($code)
                : \local_catquizlab\local\engine_validity::label($code);
            $parts[] = get_string('validity:excluded', $component, (object) [
                'reason' => $label, 'n' => $n, 'total' => $counts['total'], 'percent' => $percent($n),
            ]);
        }
        // Which rule decides, and the other beside it.
        $rule = (string) ($this->filter['validityrule'] ?? 'uniform');
        $parts[] = get_string('validity:rule_' . $rule, $component);
        $parts[] = get_string('validity:compare', $component, (object) [
            'uniform' => $counts['uniformvalid'], 'engine' => $counts['enginevalid'], 'total' => $counts['total'],
            'unknown' => $counts['engineunknown'],
        ]);
        $mode = (string) ($this->filter['validity'] ?? 'valid');
        $parts[] = get_string('validity:shown_' . $mode, $component);
        $parts[] = $this->validity_switches();

        return \html_writer::div(
            implode(' · ', $parts),
            'small mb-1' . ($counts['invalid'] > 0 ? ' text-warning' : ' text-muted'),
            ['data-region' => 'catquizlab-coverage']
        );
    }

    /**
     * Links to show valid, invalid or all sittings, and to judge by either rule.
     *
     * @return string
     */
    protected function validity_switches(): string {
        global $PAGE;

        $component = 'local_catquizlab';
        $base = $PAGE->has_set_url() ? $PAGE->url
            : new \moodle_url('/local/catquizlab/results.php', array_filter($this->filter, 'is_scalar'));
        $links = [];
        foreach (['validity' => ['valid', 'invalid', 'all'], 'validityrule' => ['uniform', 'engine']] as $param => $values) {
            $current = (string) ($this->filter[$param] ?? $values[0]);
            foreach ($values as $value) {
                $label = get_string('validity:switch_' . $value, $component);
                $links[] = $value === $current
                    ? \html_writer::tag('strong', $label)
                    : \html_writer::link(new \moodle_url($base, [$param => $value]), $label);
            }
            $links[] = '|';
        }
        array_pop($links);

        return \html_writer::span(implode(' ', $links), 'catquizlab-validity-switches');
    }

    /**
     * Which scale results the local figures are made of, of all with an answered item, and why not the rest (#112).
     *
     * @return string
     */
    protected function render_scale_coverage(): string {
        $component = 'local_catquizlab';
        $c = $this->query->scale_coverage();
        if ((int) $c['measured'] === 0) {
            // Nothing measured — which is not nothing to say where the item
            // counts could not be determined (#113).
            return $this->render_scale_exposure($c);
        }
        $percent = static fn(int $n): string => format_float(100 * $n / $c['measured'], 1);
        $parts = [get_string('validity:scalecoverage', $component, (object) [
            'included' => $c['included'], 'measured' => $c['measured'], 'percent' => $percent((int) $c['included']),
        ])];
        $excluded = ['frominvalid' => (int) $c['frominvalid'], 'unestimated' => (int) $c['unestimated']];
        foreach ($excluded + $c['reasons'] as $code => $n) {
            if ($n > 0) {
                $parts[] = get_string('validity:excluded', $component, (object) [
                    'reason' => \local_catquizlab\local\engine_validity::label($code), 'n' => $n,
                    'total' => $c['measured'], 'percent' => $percent((int) $n),
                ]);
            }
        }
        $parts[] = get_string('validity:compare', $component, (object) [
            'uniform' => $c['uniformvalid'], 'engine' => $c['enginevalid'], 'total' => $c['measured'],
            'unknown' => $c['engineunknown'],
        ]);

        return \html_writer::div(implode(' · ', $parts), 'small mb-2 text-warning', ['data-region' => 'catquizlab-scalecoverage'])
            . $this->render_scale_exposure($c);
    }

    /**
     * Where the item counts per scale are from, and what could not be counted (#113).
     *
     * @param array $c The scale coverage.
     * @return string
     */
    protected function render_scale_exposure(array $c): string {
        $component = 'local_catquizlab';
        $exposure = \local_catquizlab\local\scale_exposure::class;
        if ((int) ($c['sittings'] ?? 0) === 0) {
            return '';
        }
        $sources = [];
        foreach ([$exposure::SOURCE_STEPS, $exposure::SOURCE_ENGINE, $exposure::SOURCE_UNKNOWN] as $source) {
            if ((int) ($c['sources'][$source] ?? 0) > 0) {
                $sources[] = get_string('exposure:sourcepart', $component, (object) [
                    'source' => $exposure::label($source), 'n' => (int) $c['sources'][$source],
                ]);
            }
        }
        $parts = [get_string('exposure:sources', $component, implode('; ', $sources))];
        if ((int) $c['unknownitems'] > 0) {
            $parts[] = get_string('exposure:unknownitems', $component, (int) $c['unknownitems']);
        }
        if ((int) $c['withoutitems'] > 0) {
            $parts[] = get_string('exposure:withoutitems', $component, (int) $c['withoutitems']);
        }
        foreach ($c['diagnosis'] as $code => $n) {
            $parts[] = get_string('exposure:diagnosis', $component, (object) ['n' => (int) $n, 'what' => $exposure::label($code)]);
        }
        // Worth a warning only where something could not be counted or does not agree.
        $quiet = (int) $c['unknownitems'] === 0 && array_diff_key($c['diagnosis'], [$exposure::ENGINE_LOWER => 1]) === [];

        return \html_writer::div(
            implode(' · ', $parts),
            'small mb-2 ' . ($quiet ? 'text-muted' : 'text-warning'),
            ['data-region' => 'catquizlab-exposure']
        );
    }

    /**
     * The local error and the local standard error against the items administered on the scale (#113).
     *
     * The same scale results as every other figure of this tab: the valid ones
     * unless the switch above asks for others. A result whose item count is
     * not known has no place on the x axis — it is counted beneath, not drawn
     * at zero.
     *
     * @param array $rows Subscale observations of the selection.
     * @return string
     */
    protected function render_item_plots(array $rows): string {
        $component = 'local_catquizlab';
        $known = array_values(array_filter($rows, static fn(array $row): bool => $row['items'] !== null));
        if ($known === []) {
            return '';
        }
        $unknown = count($rows) - count($known);
        $mode = (string) ($this->filter['validity'] ?? \local_catquizlab\local\result_validity::VALID);
        $selection = get_string('chart:itemsshown_' . $mode, $component);
        $na = get_string('flow:na', $component);
        $label = static fn(array $row): string => get_string('chart:itemspoint', $component, (object) [
            'person' => (int) $row['personid'],
            'subscale' => (string) $row['subscale'],
            'items' => (int) $row['items'],
            'error' => format_float((float) $row['error'], 3),
            'se' => $row['localse'] === null ? $na : format_float((float) $row['localse'], 3),
        ]);

        $error = new scatter_chart(
            get_string('chart:erroritems', $component),
            get_string('axis:itemsonscale', $component),
            get_string('axis:localerror', $component)
        );
        $error->set_axes(axis_scale::INTEGER, axis_scale::SYMMETRIC, ['xfromzero' => true, 'jitter' => 0.15]);
        $error->set_basis($this->plot_basis());
        $this->configure_chart($error, 'localerroritems');
        $error->set_points(array_map(
            static fn(array $row): array => ['x' => (int) $row['items'], 'y' => $row['error'], 'label' => $label($row)],
            $known
        ))->set_description(get_string('chart:pointissubscale', $component))
            ->add_horizontal_line(0.0, get_string('chart:zeroline', $component))
            ->add_note($selection)
            ->add_note(get_string('chart:itemsjitter', $component));
        if ($unknown > 0) {
            $error->add_note(get_string('chart:itemsunknown', $component, $unknown));
        }
        $out = \html_writer::div($error->render(), '', ['data-region' => 'catquizlab-erroritems']);

        $withse = array_values(array_filter($known, static fn(array $row): bool => $row['localse'] !== null));
        if ($withse === []) {
            return $out;
        }
        $se = new scatter_chart(
            get_string('chart:seitems', $component),
            get_string('axis:itemsonscale', $component),
            get_string('axis:localse', $component)
        );
        $se->set_axes(axis_scale::INTEGER, axis_scale::LINEAR, ['xfromzero' => true, 'yfromzero' => true, 'jitter' => 0.15]);
        $se->set_basis($this->plot_basis());
        $this->configure_chart($se, 'localseitems');
        $se->set_points(array_map(
            static fn(array $row): array => ['x' => (int) $row['items'], 'y' => $row['localse'], 'label' => $label($row)],
            $withse
        ))->set_description(get_string('chart:pointissubscale', $component))
            ->add_note($selection)
            ->add_note(get_string('chart:itemsjitter', $component));
        if ($unknown > 0) {
            $se->add_note(get_string('chart:itemsunknown', $component, $unknown));
        }
        if (count($known) > count($withse)) {
            $se->add_note(get_string('chart:itemsnose', $component, count($known) - count($withse)));
        }

        return $out . \html_writer::div($se->render(), '', ['data-region' => 'catquizlab-seitems']);
    }

    /**
     * The local recovery by how many items the scale was given (#113).
     *
     * @param array $rows Subscale observations.
     * @return string
     */
    protected function render_item_classes(array $rows): string {
        $component = 'local_catquizlab';
        $classes = local_analysis::by_item_class($rows);
        if ($classes === []) {
            return '';
        }
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->attributes['data-region'] = 'catquizlab-itemclasses';
        $table->head = [
            get_string('exposure:classcol', $component),
            'n',
            get_string('metric:localbias', $component),
            get_string('metric:localrmse', $component),
            get_string('metric:localcorrelation', $component),
            get_string('metric:localse', $component),
            get_string('metric:within1se', $component),
            get_string('metric:within2se', $component),
        ];
        foreach ($classes as $class) {
            $table->data[] = [
                s($class['label']),
                $class['n'],
                $this->format_number($class['bias']),
                $this->format_number($class['rmse']),
                $this->format_number($class['correlation']),
                $this->format_number($class['meanse']),
                $this->format_share($class['within1se']),
                $this->format_share($class['within2se']),
            ];
        }

        return \html_writer::tag('h3', get_string('exposure:classheading', $component), ['class' => 'h5 mt-4'])
            . \html_writer::tag('p', get_string('exposure:classexplain', $component), ['class' => 'text-muted small'])
            . \html_writer::table($table);
    }

    /**
     * A percentage, or a dash where there is none.
     *
     * @param float|null $value Percent.
     * @return string
     */
    protected function format_percent(?float $value): string {
        return $value === null ? '–' : format_float($value, 1) . '&nbsp;%';
    }

    /**
     * Half the ability range of the selected runs, for symmetric ability axes.
     *
     * The configured bounds are part of the picture (#102): an axis that
     * stops short of them hides where the engine could not go.
     *
     * @return float
     */
    protected function ability_halfrange(): float {
        $half = 0.0;
        foreach ($this->query->runs() as $run) {
            $record = $GLOBALS['DB']->get_record('local_catquizlab_run', ['id' => $run['id']]);
            if ($record) {
                $definition = \local_catquizlab\local\run_registry::definition_for($record);
                $d = \local_catquizlab\local\ability_distribution::of($definition);
                $half = max($half, abs($d['min']), abs($d['max']));
            }
        }

        return $half;
    }

    /**
     * The head of a single test's view: who, what, how it ended.
     *
     * @param array $observation The observation.
     * @param array $flow What test_flow::steps() returned.
     * @return string
     */
    protected function render_single_head(array $observation, array $flow): string {
        $component = 'local_catquizlab';
        $na = get_string('flow:na', $component);

        // How the sitting ended, and every execution on the way (#106): a
        // test that finished for a reason the design intended, or one that
        // failed for a technical one — never the one taken for the other.
        $history = \local_catquizlab\local\attempt_history::of_attempt((int) $observation['attemptid']);
        $end = null;
        $retries = 0;
        foreach ($history as $entry) {
            if ($entry['outcome'] === \local_catquizlab\local\attempt_history::FAILED) {
                $retries++;
            }
            if ($entry['reasoncode'] !== '') {
                $end = $entry;
            }
        }
        $endtext = $na;
        if ($end !== null) {
            $finished = in_array($end['reasoncode'], \local_catquizlab\local\reason_catalog::OUTCOMES, true);
            $key = $finished ? 'flow:finishedbecause' : 'flow:failedbecause';
            $endtext = get_string($key, $component, s($end['reasonlabel']))
                . ' ' . \html_writer::tag('code', s($end['reasoncode']), ['data-reason' => $end['reasoncode']]);
        }
        $final = (array) ($flow['final'] ?? []);
        $scales = (array) ($flow['scales'] ?? []);
        $rows = [
            [get_string('flow:attemptid', $component), (int) $observation['attemptid']],
            [
                get_string('flow:twin', $component),
                s((string) ($observation['twinid'] ?? '')) . ' / ' . (int) $observation['personid'],
            ],
            [get_string('flow:runid', $component), (int) $observation['runid']],
            [get_string('form:strategy', $component),
                s(\local_catquizlab\local\strategy_catalog::display_label((string) $observation['strategy']))],
            [get_string('form:model', $component), s((string) $observation['model'])],
            [get_string('flow:length', $component), (int) ($observation['nitems'] ?? count($flow['steps']))],
            [
                get_string('axis:esttheta', $component),
                isset($observation['esttheta']) ? format_float((float) $observation['esttheta'], 3) : $na,
            ],
            [get_string('flow:finalse', $component), isset($final['se']) ? format_float((float) $final['se'], 3) : $na],
            [get_string('flow:finalti', $component), isset($final['ti']) ? format_float((float) $final['ti'], 3) : $na],
            [get_string('flow:finaltiatn', $component), isset($final['tiatn']) ? format_float((float) $final['tiatn'], 3) : $na],
            [get_string('flow:endreason', $component), $endtext],
            [get_string('flow:activeatend', $component), isset($scales['active']) ? count((array) $scales['active']) : $na],
            [get_string('flow:retries', $component), $retries],
        ];
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm w-auto';
        $table->attributes['data-region'] = 'catquizlab-flow-head';
        $table->data = $rows;

        $lines = '';
        foreach ($history as $entry) {
            $lines .= \html_writer::tag('li', get_string('attempt:historyline', $component, (object) [
                'try' => $entry['tryno'], 'outcome' => $entry['outcomelabel'], 'when' => $entry['when'],
            ]) . ($entry['reasoncode'] !== '' ? ' · ' . s($entry['reasonlabel']) . ' (' . s($entry['reasoncode']) . ')' : ''));
        }

        $historylist = $lines !== ''
            ? \html_writer::tag('ul', $lines, ['class' => 'small mb-3', 'data-region' => 'catquizlab-flow-history'])
            : '';

        return \html_writer::table($table) . $historylist;
    }

    /**
     * One test's flow, step by step.
     *
     * @param array $observation The observation.
     * @return string
     */
    protected function render_single_flow(array $observation): string {
        $component = 'local_catquizlab';
        $detail = $observation + results_query::detail($observation);
        $flow = test_flow::with_engine_information(
            test_flow::steps($detail),
            (array) ($detail['trace'] ?? []),
            (int) $observation['runid'],
            $this->run_maxitems((int) $observation['runid'])
        );

        if ($flow['source'] === test_flow::SOURCE_NONE) {
            return \html_writer::div(get_string('flow:nosteps', $component), 'alert alert-info');
        }

        $out = '';
        if ($flow['source'] === test_flow::SOURCE_DEBUG) {
            // Saying which source is in use matters: the thin one has no
            // ability path, and a reader should not take its absence for a
            // test that never moved.
            $out .= \html_writer::div(get_string('flow:thinsource', $component), 'alert alert-warning');
        }

        $abilities = [];
        foreach ($flow['steps'] as $step) {
            if ($step['ability'] !== null) {
                $abilities[] = ['x' => $step['step'], 'y' => $step['ability']];
            }
        }

        if ($abilities !== []) {
            // A trajectory (#105, section 8): the estimate after each item,
            // joined in order, with estimate ± SE where the standard error per
            // step is known (#106), and the simulated truth as a line.
            $band = [];
            foreach ($flow['steps'] as $step) {
                if ($step['ability'] !== null && $step['se'] !== null) {
                    $band[] = [
                        'x' => $step['step'],
                        'lo' => $step['ability'] - $step['se'],
                        'hi' => $step['ability'] + $step['se'],
                    ];
                }
            }
            $chart = new scatter_chart(
                get_string('chart:abilitypath', $component),
                get_string('axis:step', $component),
                get_string('axis:esttheta', $component)
            );
            $chart->set_axes(axis_scale::INTEGER, axis_scale::SYMMETRIC, ['yatleast' => $this->ability_halfrange()]);
            $chart->set_basis($this->plot_basis());
            $this->configure_chart($chart, 'singletest');
            $chart->set_points($abilities)
                ->set_connected()
                ->set_description(get_string('chart:pointisstep', $component))
                ->add_horizontal_line((float) $observation['truetheta'], get_string('chart:trueability', $component));
            if (count($band) >= 2) {
                $chart->add_band($band, get_string('chart:seband', $component));
            }
            $out .= \html_writer::tag('p', get_string('chart:abilitypathexplain', $component), ['class' => 'small text-muted']);
            $out .= $chart->render();
        }

        $out = $this->render_single_head($observation, $flow) . $out;

        // Per step: the estimate, its standard error and the test information
        // at it (#106). "N/A" where a value is not known — never left out:
        // which scales were dropped or locked at a given step the engine does
        // not record, only at the end.
        $na = get_string('flow:na', $component);
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->attributes['data-region'] = 'catquizlab-flow-steps';
        $table->head = [
            get_string('flow:step', $component),
            get_string('flow:question', $component),
            get_string('results:subscale', $component),
            get_string('flow:response', $component),
            get_string('axis:esttheta', $component),
            get_string('flow:se', $component),
            get_string('flow:ti', $component),
            get_string('flow:tiatn', $component),
            get_string('flow:tiremainingmin', $component),
            get_string('flow:tiremaining', $component),
            get_string('flow:scalesestimated', $component),
            get_string('flow:droppedlocked', $component),
        ];
        // Dropped and locked scales the engine records at the end only: N/A
        // on every step but the last, where its values are known (#106).
        $laststep = $flow['steps'] === [] ? 0 : (int) end($flow['steps'])['step'];
        $atend = get_string('flow:droppedlockedatend', $component, (object) [
            'dropped' => count((array) ($flow['scales']['dropped'] ?? [])),
            'locked'  => count((array) ($flow['scales']['locked'] ?? [])),
        ]);
        foreach ($flow['steps'] as $step) {
            $table->data[] = [
                $step['step'],
                $step['questionid'] > 0 ? $step['questionid'] : '—',
                $step['scaleid'] > 0 ? $step['scaleid'] : '—',
                $step['fraction'] === null ? '—' : format_float($step['fraction'], 2),
                $step['ability'] === null ? '—' : format_float($step['ability'], 3),
                $step['se'] === null ? $na : format_float($step['se'], 3),
                $step['ti'] === null ? $na : format_float($step['ti'], 3),
                $step['tiatn'] === null ? $na : format_float($step['tiatn'], 3),
                ($step['tiremainingmin'] ?? null) === null ? $na : format_float($step['tiremainingmin'], 3),
                $step['tiremaining'] === null ? $na : format_float($step['tiremaining'], 3),
                $step['scalesestimated'] === null ? $na : (string) $step['scalesestimated'],
                (int) $step['step'] === $laststep && !empty($flow['scales']) ? $atend : $na,
            ];
        }
        $out .= \html_writer::table($table);
        $out .= \html_writer::div(
            $flow['final']['consistent'] ?? false
                ? get_string('flow:tiexplained', $component)
                : get_string('flow:tiunavailable', $component),
            'small text-muted mb-3'
        );

        // The scale lifecycle only exists in the richer source.
        if (!empty($flow['scales']['active']) || !empty($flow['scales']['dropped'])) {
            $scales = new \html_table();
            $scales->attributes['class'] = 'generaltable table-sm w-auto';
            $scales->head = [get_string('chart:quantity', $component), get_string('preview:value', $component)];
            $scales->data = [
                [get_string('flow:activescales', $component), count((array) ($flow['scales']['active'] ?? []))],
                [get_string('flow:droppedscales', $component), count((array) ($flow['scales']['dropped'] ?? []))],
                [get_string('flow:lockedscales', $component), count((array) ($flow['scales']['locked'] ?? []))],
            ];
            $out .= \html_writer::tag('h3', get_string('flow:scalelifecycle', $component), ['class' => 'h5 mt-4']);
            $out .= \html_writer::table($scales);
        }

        return $out;
    }

    /**
     * The effective CAT parameters recorded in a run's manifest.
     *
     * @param int $runid The run.
     * @return array
     */
    protected function cat_parameters(int $runid): array {
        global $DB;

        static $cache = [];
        if (isset($cache[$runid])) {
            return $cache[$runid];
        }

        $manifest = json_decode(
            (string) $DB->get_field('local_catquizlab_run', 'manifestjson', ['id' => $runid]),
            true
        ) ?: [];

        return $cache[$runid] = (array) ($manifest['config']['cat'] ?? []);
    }

    /**
     * The robustness tab: how far each disturbed pool moves the outcomes.
     *
     * @return string
     */
    protected function render_robustness(): string {
        $component = 'local_catquizlab';

        $out = \html_writer::tag('h3', get_string('results:robustnessgroup', $component), ['class' => 'h5']);
        $out .= \html_writer::tag('p', get_string('results:robustnessexplain', $component), ['class' => 'text-muted']);

        $observations = $this->query->observations();
        if ($observations === []) {
            return $out . \html_writer::div(
                get_string('results:noobservations', $component),
                'alert alert-info'
            );
        }

        $cells = robustness_analysis::cells($observations, $this->query->scale_maps());
        $variants = robustness_analysis::variants($cells);

        if ($variants === []) {
            return $out . \html_writer::div(
                get_string('results:novariants', $component),
                'alert alert-info'
            );
        }

        $unreferenced = array_filter(
            $cells,
            static fn(array $cell): bool => !$cell['isreference'] && $cell['reference'] === null
        );
        if ($unreferenced !== []) {
            // Naming the gap beats quietly leaving rows blank: the reader needs
            // to know a comparison was impossible, not merely absent.
            $out .= \html_writer::div(
                get_string('results:noreference', $component, count($unreferenced)),
                'alert alert-warning'
            );
        }

        $out .= \html_writer::tag('h3', get_string('results:globaldeltas', $component), ['class' => 'h5 mt-4']);
        $out .= $this->render_delta_table($cells, robustness_analysis::global_metrics());

        $out .= \html_writer::tag('h3', get_string('results:localdeltas', $component), ['class' => 'h5 mt-4']);
        $out .= $this->render_delta_table($cells, robustness_analysis::local_metrics());

        // The course of an outcome over the size of the disturbance, one
        // variant at a time: the strengths of different variants are measured
        // on different scales and do not belong on one axis.
        foreach ($variants as $variant) {
            $series = robustness_analysis::by_strength($cells, $variant);
            if (count($series) < 2) {
                continue;
            }
            $out .= \html_writer::tag(
                'h3',
                get_string('results:strengthcourse', $component, get_string('variant:' . $variant, $component)),
                ['class' => 'h5 mt-4']
            );
            $out .= $this->render_strength_course($series, $variant);
        }

        return $out;
    }

    /**
     * A table of deltas against the ideal pool.
     *
     * @param array $cells Rows from {@see robustness_analysis::cells()}.
     * @param array $metrics Metric key => language string key.
     * @return string
     */
    protected function render_delta_table(array $cells, array $metrics): string {
        $component = 'local_catquizlab';

        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $head = [
            get_string('form:variant', $component),
            get_string('results:strength', $component),
            get_string('form:strategy', $component),
            get_string('form:stratum', $component),
            'n',
        ];
        foreach ($metrics as $key => $stringkey) {
            $head[] = get_string($stringkey, $component);
        }
        $table->head = $head;

        foreach ($cells as $cell) {
            if ($cell['isreference'] || $cell['reference'] === null) {
                continue;
            }
            $row = [
                s(run_registry::group_label('variant', $cell['variant'])),
                $this->format_strength($cell['variant'], $cell['strength']),
                s(run_registry::group_label('strategy', $cell['strategy'])),
                s(run_registry::group_label('stratum', $cell['stratum'])),
                $cell['n'],
            ];
            foreach (array_keys($metrics) as $metric) {
                $row[] = $this->format_delta($metric, $cell['deltas'][$metric] ?? null);
            }
            $table->data[] = $row;
        }

        if (empty($table->data)) {
            return \html_writer::div(get_string('results:nodeltas', $component), 'alert alert-info');
        }

        return \html_writer::table($table)
            . \html_writer::tag('p', get_string('results:deltalegend', $component), ['class' => 'small text-muted']);
    }

    /**
     * The course of the outcomes over the strength of one variant.
     *
     * @param array $series Cells of one variant, ascending by strength.
     * @param string $variant The variant.
     * @return string
     */
    protected function render_strength_course(array $series, string $variant): string {
        $component = 'local_catquizlab';

        $chart = new scatter_chart(
            get_string('results:strengthcourse', $component, get_string('variant:' . $variant, $component)),
            get_string('axis:strength' . (run_registry::strength_unit($variant) ?: 'share'), $component),
            get_string('axis:deltarmse', $component)
        );
        $chart->set_axes(axis_scale::LINEAR, axis_scale::LINEAR);
        $chart->set_basis($this->plot_basis());
        $this->configure_chart($chart, 'strength');
        $points = [];
        foreach ($series as $cell) {
            if (($cell['deltas']['rmse'] ?? null) !== null) {
                $points[] = ['x' => $cell['strength'], 'y' => $cell['deltas']['rmse']];
            }
        }
        $chart->set_points($points)
            ->set_description(get_string('chart:pointiscell', $component))
            ->add_horizontal_line(0.0, get_string('chart:idealreferenceline', $component));

        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm w-auto';
        $table->head = [
            get_string('results:strength', $component),
            'n',
            get_string('metric:rmse', $component),
            get_string('metric:testlength', $component),
            get_string('metric:top3', $component),
        ];
        foreach ($series as $cell) {
            $table->data[] = [
                $this->format_strength($variant, $cell['strength']),
                $cell['n'],
                $this->format_delta('rmse', $cell['deltas']['rmse'] ?? null),
                $this->format_delta('nitems', $cell['deltas']['nitems'] ?? null),
                $this->format_delta('top3', $cell['deltas']['top3'] ?? null),
            ];
        }

        return $chart->render() . \html_writer::table($table);
    }

    /**
     * Format a delta with its direction stated in words as well as in colour.
     *
     * @param string $metric The metric key.
     * @param mixed $value The delta.
     * @return string
     */
    protected function format_delta(string $metric, $value): string {
        if ($value === null) {
            return '—';
        }
        $value = (float) $value;
        $formatted = ($value > 0 ? '+' : '') . format_float($value, 4);

        $direction = robustness_analysis::direction($metric);
        if ($direction === 0 || abs($value) < 0.000001) {
            return $formatted;
        }

        // Colour alone would fail anyone who cannot distinguish it, so the
        // verdict is also spelled out for a screen reader.
        $better = ($direction > 0 && $value > 0) || ($direction < 0 && $value < 0);
        $class = $better ? 'text-success' : 'text-danger';
        $label = $better
            ? get_string('delta:better', 'local_catquizlab')
            : get_string('delta:worse', 'local_catquizlab');

        return \html_writer::tag('span', $formatted, ['class' => $class])
            . \html_writer::tag('span', ' (' . $label . ')', ['class' => 'sr-only']);
    }

    /**
     * Format a disturbance strength in the unit of its variant.
     *
     * @param string $variant The variant.
     * @param mixed $strength The strength.
     * @return string
     */
    protected function format_strength(string $variant, $strength): string {
        if ($strength === null) {
            return '—';
        }
        $unit = run_registry::strength_unit($variant);
        if ($unit === 'share') {
            return format_float(100 * (float) $strength, 1) . '&nbsp;%';
        }
        if ($unit === 'factor') {
            return '×' . format_float((float) $strength, 2);
        }

        return format_float((float) $strength, 2) . '&nbsp;' . get_string('unit:logits', 'local_catquizlab');
    }

    /**
     * The subscales tab: how well local deviations were recovered.
     *
     * @return string
     */
    protected function render_subscales(): string {
        $component = 'local_catquizlab';
        $rows = $this->query->subscale_observations();

        // The heading and the explanation come first even with nothing to show:
        // a tab that says only "no data" leaves the reader guessing what it
        // would have contained.
        $out = \html_writer::tag('h3', get_string('results:localgroup', $component), ['class' => 'h5']);
        $out .= \html_writer::tag('p', get_string('results:localexplain', $component), ['class' => 'text-muted']);
        $out .= $this->render_scale_coverage();

        if ($rows === []) {
            return $out . \html_writer::div(
                get_string('results:nosubscaledata', $component),
                'alert alert-info'
            );
        }

        $summary = local_analysis::summarise($rows);

        // The primary local plot: the true deviation against the estimated one.
        // Absolute abilities would blame the local diagnostics for a global
        // offset that every subscale shares.
        $chart = new scatter_chart(
            get_string('chart:deltarecovery', $component),
            get_string('axis:truedelta', $component),
            get_string('axis:estdelta', $component)
        );
        $chart->set_axes(axis_scale::SYMMETRIC, axis_scale::SYMMETRIC);
        $chart->set_basis($this->plot_basis());
        $this->configure_chart($chart, 'localdeviation');
        $chart->set_points(array_map(
            static fn(array $row): array => [
                'x' => $row['truedelta'],
                'y' => $row['estdelta'],
                'label' => get_string('chart:deltapoint', $component, (object) [
                    'person' => (int) $row['personid'],
                    'subscale' => (string) $row['subscale'],
                    'truedelta' => format_float((float) $row['truedelta'], 3),
                    'estdelta' => format_float((float) $row['estdelta'], 3),
                ]),
            ],
            $rows
        ))->set_description(get_string('chart:pointissubscale', $component))
            ->add_identity_line(get_string('chart:identity', $component));

        // Only where the true deviation varies (#105, section 7). In the
        // conforming stratum it is 0 by design — no category or subscale
        // variation — and every point sat on the vertical line x = 0: data
        // read correctly, a comparison with nothing to compare.
        $truedeltas = array_map(static fn(array $row): float => (float) $row['truedelta'], $rows);
        $spread = $truedeltas === [] ? 0.0 : max($truedeltas) - min($truedeltas);
        if ($spread < 1e-6) {
            $out .= \html_writer::div(
                get_string('chart:novariation', $component),
                'alert alert-info',
                ['data-region' => 'catquizlab-novariation']
            );
            $chart = null;
        }

        $out .= $chart === null ? '' : $chart->render_with_summary([
            get_string('metric:localbias', $component) => $this->format_number($summary['bias']),
            get_string('metric:localrmse', $component) => $this->format_number($summary['rmse']),
            get_string('metric:localcorrelation', $component) => $this->format_number($summary['correlation']),
            get_string('metric:localse', $component) => $this->format_number($summary['meanse']),
            get_string('metric:within1se', $component) => $this->format_share($summary['within1se']),
            get_string('metric:within2se', $component) => $this->format_share($summary['within2se']),
        ]);

        if ($summary['within1se'] === null) {
            // Saying so is better than showing a dash the reader has to guess at.
            $out .= \html_writer::div(get_string('results:nolocalse', $component), 'alert alert-warning');
        }

        $out .= \html_writer::tag('h3', get_string('results:errorbysubscale', $component), ['class' => 'h5 mt-4']);
        $out .= $this->render_local_table(local_analysis::group($rows, 'category'), 'category');

        $out .= \html_writer::tag('h3', get_string('results:subscaletable', $component), ['class' => 'h5 mt-4']);
        $out .= $this->render_local_table(local_analysis::group($rows, 'key'), 'key');
        $out .= $this->render_item_classes($rows);
        $out .= $this->render_item_plots($rows);

        return $out;
    }

    /**
     * The detection tab: did the strategy identify the right subscales?
     *
     * @return string
     */
    protected function render_detection(): string {
        $component = 'local_catquizlab';
        $observations = $this->query->observations();
        $maps = $this->query->scale_maps();

        $strategies = array_keys($this->query->available('strategy'));
        $strategy = count($strategies) === 1 ? $strategies[0] : 'lowestsub';
        $labels = local_analysis::detection_labels($strategy);

        $header = \html_writer::tag('h3', $labels['title'], ['class' => 'h5'])
            . \html_writer::tag('p', $labels['goal'], ['class' => 'text-muted']);

        if ($observations === []) {
            return $header . \html_writer::div(
                get_string('results:nosubscaledata', $component),
                'alert alert-info'
            );
        }

        $rankings = [];
        foreach ($observations as $observation) {
            $map = $maps[$observation['runid']] ?? [];
            if ($map === []) {
                continue;
            }
            $subscales = local_analysis::subscale_rows($observation + results_query::detail($observation), $map);
            $ranking = local_analysis::ranking($subscales, $observation['strategy']);
            if ($ranking !== null) {
                $rankings[] = $ranking;
            }
        }

        if ($rankings === []) {
            return $header . \html_writer::div(
                get_string('results:nosubscaledata', $component),
                'alert alert-info'
            );
        }

        $aggregate = local_analysis::aggregate_ranking($rankings);
        $out = $header;

        // Top-k across the k values, one row per k with its dispersion.
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->head = [
            'k',
            get_string('metric:topk', $component),
            get_string('metric:precisionk', $component),
            get_string('metric:recallk', $component),
            get_string('metric:ndcgk', $component),
        ];
        foreach ($aggregate['topk'] as $k => $measures) {
            $table->data[] = [
                $k,
                $this->format_stat($measures['agreement'] ?? []),
                $this->format_stat($measures['precision'] ?? []),
                $this->format_stat($measures['recall'] ?? []),
                $this->format_stat($measures['ndcg'] ?? []),
            ];
        }
        $out .= \html_writer::table($table);

        $rank = new \html_table();
        $rank->attributes['class'] = 'generaltable table-sm w-auto';
        $rank->head = [get_string('chart:quantity', $component), get_string('preview:value', $component)];
        $rank->data = [
            [get_string('metric:spearman', $component), $this->format_stat($aggregate['spearman'])],
            [get_string('metric:rankerror', $component), $this->format_stat($aggregate['rankerror'])],
            [get_string('metric:rankedattempts', $component), $aggregate['n']],
        ];
        $out .= \html_writer::tag('h3', get_string('results:ranking', $component), ['class' => 'h5 mt-4']);
        $out .= \html_writer::table($rank);

        $out .= \html_writer::tag('h3', get_string('results:confusion', $component), ['class' => 'h5 mt-4']);
        $out .= $this->render_confusion($aggregate['confusion'], $labels);

        return $out;
    }

    /**
     * The binary confusion matrix with its derived rates.
     *
     * @param array $confusion The pooled matrix plus its rates.
     * @param array $labels The strategy-specific wording.
     * @return string
     */
    protected function render_confusion(array $confusion, array $labels): string {
        $component = 'local_catquizlab';

        $matrix = new \html_table();
        $matrix->attributes['class'] = 'generaltable table-sm w-auto';
        $matrix->head = [
            '',
            get_string('confusion:truepositive', $component),
            get_string('confusion:truenegative', $component),
        ];
        $matrix->data = [
            [
                get_string('confusion:detected', $component),
                (int) $confusion['tp'],
                (int) $confusion['fp'],
            ],
            [
                get_string('confusion:notdetected', $component),
                (int) $confusion['fn'],
                (int) $confusion['tn'],
            ],
        ];

        $rates = new \html_table();
        $rates->attributes['class'] = 'generaltable table-sm w-auto';
        $rates->head = [get_string('chart:quantity', $component), get_string('preview:value', $component)];
        $rates->data = [
            [get_string('metric:precision', $component), $this->format_share($confusion['precision'] ?? null)],
            [get_string('metric:recall', $component), $this->format_share($confusion['recall'] ?? null)],
            [get_string('metric:specificity', $component), $this->format_share($confusion['specificity'] ?? null)],
            [get_string('metric:accuracy', $component), $this->format_share($confusion['accuracy'] ?? null)],
        ];

        return \html_writer::tag('p', get_string('confusion:threshold', $component, format_float(
            local_analysis::DEFAULT_DEFICIT_THRESHOLD,
            2
        )), ['class' => 'text-muted small'])
            . \html_writer::table($matrix)
            . \html_writer::table($rates);
    }

    /**
     * A table of local recovery per group.
     *
     * @param array $groups Rows from {@see local_analysis::group()}.
     * @param string $level 'category' or 'key', naming the first column.
     * @return string
     */
    protected function render_local_table(array $groups, string $level): string {
        $component = 'local_catquizlab';

        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->head = [
            $level === 'category'
                ? get_string('form:domains', $component)
                : get_string('results:subscale', $component),
            'n',
            get_string('metric:localbias', $component),
            get_string('metric:localrmse', $component),
            get_string('metric:localcorrelation', $component),
            get_string('metric:localse', $component),
            get_string('metric:within1se', $component),
            get_string('metric:within2se', $component),
        ];

        foreach ($groups as $group) {
            $table->data[] = [
                s((string) $group['group']),
                $group['n'],
                $this->format_number($group['bias']),
                $this->format_number($group['rmse']),
                $this->format_number($group['correlation']),
                $this->format_number($group['meanse']),
                $this->format_share($group['within1se']),
                $this->format_share($group['within2se']),
            ];
        }

        return \html_writer::table($table);
    }

    /**
     * Format a share as a percentage, or an em dash when it is unavailable.
     *
     * @param mixed $value The share in [0, 1].
     * @return string
     */
    protected function format_share($value): string {
        return $value === null ? '—' : format_float(100 * (float) $value, 1) . '&nbsp;%';
    }

    /**
     * The KPI cards of the overview.
     *
     * @param array $rows Observations.
     * @return string
     */
    protected function render_kpi_cards(array $rows): string {
        $component = 'local_catquizlab';
        $recovery = metrics::ability_recovery($rows);
        $exposure = $this->exposureoverride ?? $this->query->exposure();
        $stopped = 0;
        foreach ($rows as $row) {
            if ($row['stopreached']) {
                $stopped++;
            }
        }

        $cards = [
            [
                'label' => get_string('metric:testlength', $component),
                'value' => $this->format_stat(results_query::summarise($rows, 'nitems')),
                'hint'  => get_string('metric:testlength_help', $component),
            ],
            [
                'label' => get_string('metric:se', $component),
                'value' => $this->format_stat(results_query::summarise($rows, 'se')),
                'hint'  => get_string('metric:se_help', $component),
            ],
            [
                'label' => get_string('metric:bias', $component),
                'value' => $this->format_stat(results_query::summarise($rows, 'error')),
                'hint'  => get_string('metric:bias_help', $component),
            ],
            [
                'label' => get_string('metric:rmse', $component),
                'value' => format_float($this->rmse($rows), 4),
                'hint'  => get_string('metric:rmse_help', $component),
            ],
            [
                'label' => get_string('metric:correlation', $component),
                'value' => $this->format_number($recovery['correlation']),
                'hint'  => get_string('metric:correlation_help', $component),
            ],
            [
                'label' => get_string('metric:stopsuccess', $component),
                'value' => count($rows) > 0
                    ? format_float(100 * $stopped / count($rows), 1) . '&nbsp;%'
                    : '—',
                'hint'  => get_string('metric:stopsuccess_help', $component),
            ],
            [
                'label' => get_string('metric:concentration', $component),
                'value' => $this->format_number($exposure['concentration']['gini'] ?? null),
                'hint'  => get_string('metric:concentration_help', $component),
            ],
            [
                'label' => get_string('metric:runtime', $component),
                'value' => $this->format_runtime(results_query::summarise($rows, 'runtimems')),
                'hint'  => get_string('metric:runtime_help', $component),
            ],
        ];

        $out = \html_writer::start_div('row');
        foreach ($cards as $card) {
            $out .= \html_writer::div(
                \html_writer::div(
                    \html_writer::tag('div', $card['label'], ['class' => 'small text-muted'])
                    . \html_writer::tag('div', $card['value'], ['class' => 'h5 mb-0'])
                    . \html_writer::tag('div', $card['hint'], ['class' => 'small text-muted mt-1']),
                    'card-body p-3'
                ),
                'col-md-3 mb-3',
                []
            );
        }
        $out .= \html_writer::end_div();

        return str_replace('col-md-3 mb-3', 'col-md-3 mb-3', $out);
    }

    /**
     * A per-group table of the primary global metrics.
     *
     * @param array $rows Observations.
     * @param string $groupby The grouping coordinate.
     * @return string
     */
    protected function render_group_table(array $rows, string $groupby): string {
        $component = 'local_catquizlab';
        $groups = [];
        foreach ($rows as $row) {
            $groups[(string) $row[$groupby]][] = $row;
        }

        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->head = [
            get_string('form:' . $groupby, $component),
            get_string('report:runs', $component),
            get_string('metric:testlength', $component),
            get_string('metric:se', $component),
            get_string('metric:bias', $component),
            get_string('metric:rmse', $component),
            get_string('metric:stopsuccess', $component),
        ];

        foreach ($groups as $key => $members) {
            $stopped = 0;
            foreach ($members as $member) {
                if ($member['stopreached']) {
                    $stopped++;
                }
            }
            $table->data[] = [
                s(run_registry::group_label($groupby, $key)),
                count($members),
                $this->format_stat(results_query::summarise($members, 'nitems')),
                $this->format_stat(results_query::summarise($members, 'se')),
                $this->format_stat(results_query::summarise($members, 'error')),
                format_float($this->rmse($members), 4),
                format_float(100 * $stopped / max(1, count($members)), 1) . '&nbsp;%',
            ];
        }

        return \html_writer::table($table);
    }

    /**
     * The cell table: one row per experimental cell.
     *
     * @param array $rows Observations.
     * @return string
     */
    protected function render_cell_table(array $rows): string {
        $component = 'local_catquizlab';

        $cells = [];
        foreach ($rows as $row) {
            $key = implode('|', [
                $row['tier'], $row['strategy'], $row['model'],
                $row['variant'], $row['stratum'], $row['severity'],
            ]);
            $cells[$key][] = $row;
        }

        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->head = [
            get_string('form:tier', $component),
            get_string('form:strategy', $component),
            get_string('form:model', $component),
            get_string('form:variant', $component),
            get_string('form:stratum', $component),
            get_string('form:severity', $component),
            'n',
            get_string('metric:testlength', $component),
            get_string('metric:se', $component),
            get_string('metric:bias', $component),
            get_string('metric:rmse', $component),
            get_string('metric:correlation', $component),
            get_string('metric:stopsuccess', $component),
            get_string('metric:runtime', $component),
        ];

        foreach ($cells as $key => $members) {
            [$tier, $strategy, $model, $variant, $stratum, $severity] = explode('|', $key);
            $stopped = 0;
            foreach ($members as $member) {
                if ($member['stopreached']) {
                    $stopped++;
                }
            }
            $table->data[] = [
                s(run_registry::group_label('tier', $tier)),
                s(run_registry::group_label('strategy', $strategy)),
                s(run_registry::group_label('model', $model)),
                s(run_registry::group_label('variant', $variant)),
                s(run_registry::group_label('stratum', $stratum)),
                s(run_registry::group_label('severity', $severity)),
                count($members),
                $this->format_stat(results_query::summarise($members, 'nitems')),
                $this->format_stat(results_query::summarise($members, 'se')),
                $this->format_stat(results_query::summarise($members, 'error')),
                format_float($this->rmse($members), 4),
                $this->format_number(metrics::ability_recovery($members)['correlation']),
                format_float(100 * $stopped / max(1, count($members)), 1) . '&nbsp;%',
                $this->format_runtime(results_query::summarise($members, 'runtimems')),
            ];
        }

        return \html_writer::table($table);
    }

    /**
     * The exposure block: concentration figures and the sorted exposure curve.
     *
     * @return string
     */
    protected function render_exposure(): string {
        $component = 'local_catquizlab';
        $exposure = $this->query->exposure();
        $concentration = $exposure['concentration'];

        if (($concentration['n'] ?? 0) === 0) {
            return \html_writer::div(get_string('chart:nodata', $component), 'alert alert-info');
        }

        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm w-auto';
        $table->head = [
            get_string('chart:quantity', $component),
            get_string('preview:value', $component),
        ];
        $table->data = [
            [get_string('metric:concentration', $component), $this->format_number($concentration['gini'])],
            [get_string('metric:hhi', $component), $this->format_number($concentration['hhi'])],
            [get_string('metric:maxexposure', $component), $this->format_number($concentration['max'])],
            [get_string('metric:meanexposure', $component), $this->format_number($concentration['mean'])],
            [get_string('metric:aboveshare', $component), $this->format_number($concentration['above'])],
            [get_string('metric:itemsused', $component), $exposure['itemsused']],
            [get_string('metric:itemsunused', $component), $exposure['unused'] ?? '—'],
        ];

        // The sorted exposure curve: a flat line is even use, a hockey stick is
        // a pool where a few items carry the test.
        $rates = array_values($exposure['rates']);
        rsort($rates);
        $poolsize = $this->query->pool_size();
        if ($poolsize !== null && $poolsize > count($rates)) {
            $rates = array_pad($rates, $poolsize, 0.0);
        }
        $points = [];
        foreach ($rates as $index => $rate) {
            $points[] = ['x' => $index + 1, 'y' => $rate];
        }

        $chart = new scatter_chart(
            get_string('chart:exposurecurve', $component),
            get_string('axis:itemrank', $component),
            get_string('axis:exposurerate', $component)
        );
        $chart->set_axes(axis_scale::INTEGER, axis_scale::LINEAR, ['yfromzero' => true]);
        $chart->set_basis($this->plot_basis());
        $this->configure_chart($chart, 'exposure');
        $chart->set_points($points)
            ->set_description(get_string('chart:pointisitem', $component));

        return \html_writer::table($table) . $chart->render();
    }

    /**
     * The SE target the filtered runs were configured with, when they agree on one.
     *
     * @return float|null
     */
    protected function target_se(): ?float {
        global $DB;

        $targets = [];
        foreach ($this->query->runs() as $run) {
            $manifest = json_decode(
                (string) $DB->get_field('local_catquizlab_run', 'manifestjson', ['id' => $run['id']]),
                true
            ) ?: [];
            $value = $manifest['config']['cat']['se']['min'] ?? null;
            if (is_numeric($value)) {
                $targets[(string) $value] = (float) $value;
            }
        }

        // Drawing one target line when the runs used several would misrepresent
        // all but one of them.
        return count($targets) === 1 ? reset($targets) : null;
    }

    /**
     * The available tier values, labelled.
     *
     * @return array<string, string>
     */
    protected function tier_values(): array {
        $values = [];
        foreach ($this->query->runs() as $run) {
            $tier = (string) ($run['tier'] ?? '');
            if ($tier === '') {
                continue;
            }
            $key = 'tier:' . $tier;
            $values[$tier] = get_string_manager()->string_exists($key, 'local_catquizlab')
                ? get_string($key, 'local_catquizlab')
                : $tier;
        }
        ksort($values);

        return $values;
    }

    /**
     * The root mean squared error of a set of observations.
     *
     * @param array $rows Observations.
     * @return float
     */
    protected function rmse(array $rows): float {
        $sum = 0.0;
        $n = 0;
        foreach ($rows as $row) {
            $sum += $row['error'] ** 2;
            $n++;
        }

        return $n > 0 ? sqrt($sum / $n) : 0.0;
    }

    /**
     * Format a statistic as mean with its 95% interval.
     *
     * @param array $stat A block from {@see results_query::describe_values()}.
     * @return string
     */
    protected function format_stat(array $stat): string {
        if (($stat['mean'] ?? null) === null) {
            return '—';
        }
        $mean = format_float($stat['mean'], 3);
        if (($stat['ci95lo'] ?? null) === null) {
            // With a single observation there is no interval, and showing one
            // would suggest a precision that is not there.
            return $mean;
        }

        return $mean . ' <span class="text-muted small">['
            . format_float($stat['ci95lo'], 3) . '; ' . format_float($stat['ci95hi'], 3) . ']</span>';
    }

    /**
     * Format a runtime statistic as a span of time.
     *
     * An attempt that took four seconds reads as seconds; one that took an hour
     * and a half reads as an hour and a half rather than as 5400 s. The reader
     * is asking how long it took, not counting.
     *
     * @param array $stat A block from {@see results_query::describe_values()}.
     * @return string
     */
    protected function format_runtime(array $stat): string {
        if ($stat['mean'] === null || $stat['mean'] <= 0) {
            return '—';
        }

        return \local_catquizlab\local\duration::human($stat['mean'] / 1000);
    }

    /**
     * Format a number, or an em dash when it is not available.
     *
     * @param mixed $value The value.
     * @param int $decimals Decimal places.
     * @return string
     */
    protected function format_number($value, int $decimals = 4): string {
        return $value === null ? '—' : format_float((float) $value, $decimals);
    }

    /**
     * A labelled select element for the filter bar.
     *
     * @param string $name The field name.
     * @param array $options The options.
     * @param string $label The accessible label.
     * @return string
     */
    protected function select(string $name, array $options, string $label): string {
        $current = $this->filter[$name] ?? '';

        return \html_writer::div(
            \html_writer::tag('label', $label, [
                'for' => 'catlabfilter_' . $name,
                'class' => 'small text-muted d-block mb-0',
            ])
            . \html_writer::select($options, $name, $current, false, [
                'class' => 'custom-select custom-select-sm',
                'id'    => 'catlabfilter_' . $name,
            ]),
            'mr-3 mb-2'
        );
    }
}
