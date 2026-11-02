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

namespace local_catquizlab;

use local_catquizlab\local\analysis_population;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\engine_validity;
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\experiment_status;
use local_catquizlab\local\reproducibility;
use local_catquizlab\local\result_validity;
use local_catquizlab\local\results_export;
use local_catquizlab\local\results_query;
use local_catquizlab\local\registry;
use local_catquizlab\output\results_page;

/**
 * The analysis basis in the numbers the issues name (#112, #115, #127, #130).
 *
 * Two scenarios from the target system, at their real size:
 * - 1500 sittings planned in six runs of 250 people each (six replications); 1202 collected, 48 failed for good, 250 not yet
 *   played. The counting chain must say exactly that, per run as in total, and the raw-data export must hold the
 *   collected sittings and nothing else.
 * - One run of 250 sittings, all collected, none with an engine verdict. Under the engine's rule that is 250 "no
 *   verdict", not 250 invalid and not "nothing collected" — on the page, in the export metadata and in the
 *   reproducibility package.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\analysis_population
 * @covers     \local_catquizlab\local\reproducibility
 * @covers     \local_catquizlab\output\results_page
 */
final class analysis_basis_test extends \advanced_testcase {
    /** @var int The experiment. */
    protected int $experimentid = 0;

    /**
     * An experiment of the given strategies with $people simulated people each, its runs created.
     *
     * @param string[] $strategies The strategies.
     * @param int $people People per run.
     * @param int $replications Replications of each strategy.
     * @return array<int, \stdClass> The runs, by id.
     */
    protected function experiment(array $strategies, int $people, int $replications = 1): array {
        global $DB;

        $definition = experiment_definition::example_baseline();
        $definition['name'] = 'Analysis basis ' . count($strategies) . 'x' . $people;
        $definition['budgets']['global']['minitems'] = 1;
        $definition['persons']['count'] = $people;
        $definition['replications'] = $replications;
        $definition['sweep'] = count($strategies) > 1 ? ['factors' => ['strategy' => $strategies]] : [];
        if (count($strategies) === 1) {
            $definition['strategy'] = $strategies[0];
        }
        $this->experimentid = (int) experiment_service::save($definition)['id'];
        experiment_service::create_sweep($this->experimentid);

        return $DB->get_records('local_catquizlab_run', ['experimentid' => $this->experimentid], 'id ASC');
    }

    /**
     * Sittings for a run: people and attempts, $collected of them with a trace, the rest in $rest.
     *
     * @param \stdClass $run The run.
     * @param int $people How many.
     * @param int[] $statuses The status of each sitting in order (missing: collected).
     * @return void
     */
    protected function sittings(\stdClass $run, int $people, array $statuses = []): void {
        global $DB;

        $now = time();
        for ($i = 0; $i < $people; $i++) {
            $ability = -2.0 + 4.0 * $i / max(1, $people - 1);
            $personid = (int) $DB->insert_record('local_catquizlab_person', (object) [
                'runid' => $run->id, 'twinid' => sprintf('r%03d-t%05d', $run->replication, $i + 1),
                'twinindex' => $i + 1, 'severity' => 'none', 'stratum' => 'conforming', 'abilityglobal' => $ability,
                'profilejson' => json_encode(['global' => $ability, 'categories' => []]),
                'timecreated' => $now, 'timemodified' => $now,
            ]);
            $status = $statuses[$i] ?? attempt_scheduler::STATUS_COLLECTED;
            $collected = $status === attempt_scheduler::STATUS_COLLECTED;
            $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid' => $run->id, 'personid' => $personid, 'status' => $status,
                'tries' => $status === attempt_scheduler::STATUS_QUEUED ? 0 : 1, 'engineattemptid' => 0,
                'tracejson' => $collected ? json_encode(['finaltheta' => $ability + 0.1, 'finalse' => 0.3,
                    'items' => [101, 102, 103], 'nitems' => 3, 'stopreason' => 'standarderror',
                    'responses' => [101 => 1.0, 102 => 0.0, 103 => 1.0]]) : null,
                'runtimems' => 1000, 'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
    }

    /**
     * 1500 planned, 1202 collected, 48 failed, 250 still queued: the chain, per run and in total, and the export.
     *
     * @return void
     */
    public function test_1500_planned_1202_collected_48_failed(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        experiment_status::reset();

        // Six runs of 250: six replications of one strategy, which every engine version can play.
        $runs = array_values($this->experiment(['fastest'], 250, 6));
        $this->assertCount(6, $runs);
        // Four runs played to the end; one with 48 failed for good (202 collected); one not yet begun.
        foreach ($runs as $index => $run) {
            $statuses = [];
            if ($index === 4) {
                $statuses = array_fill(0, 48, attempt_scheduler::STATUS_FAILED);
            } else if ($index === 5) {
                $statuses = array_fill(0, 250, attempt_scheduler::STATUS_QUEUED);
            }
            $this->sittings($run, 250, $statuses);
        }

        $filter = ['experimentid' => $this->experimentid, 'validity' => result_validity::ALL];
        $funnel = analysis_population::funnel(new results_query($filter));
        $this->assertSame(
            ['planned' => 1500, 'scheduled' => 1500, 'started' => 1250, 'failed' => 48, 'collected' => 1202],
            array_intersect_key($funnel, array_flip(['planned', 'scheduled', 'started', 'failed', 'collected']))
        );
        // What was evaluated is what was collected, and every evaluated sitting has exactly one state.
        $this->assertSame(1202, $funnel['evaluated']);
        $this->assertSame(1202, $funnel['valid'] + $funnel['invalid'] + $funnel['unknown']);
        $this->assertSame(1202, array_sum($funnel['engine']), 'the engine verdicts add up to what was evaluated');
        // Per run: each says its own 250, and the run with failures says 202 and 48.
        foreach (array_values($funnel['runs']) as $index => $run) {
            $this->assertSame(250, $run['planned'], 'run ' . $index);
            $this->assertSame([5 => [202, 48], 6 => [0, 0]][$index + 1] ?? [250, 0], [$run['collected'], $run['failed']]);
        }

        // The metadata of an export carries the same chain.
        $meta = results_export::metadata(new results_query($filter), results_export::LEVEL_ATTEMPT);
        $this->assertSame(1500, $meta['population']['attempts']['planned']);
        $this->assertSame(1202, $meta['population']['attempts']['collected']);
        $this->assertSame(48, $meta['population']['attempts']['failed']);
        $this->assertSame(6, count($meta['population']['runids']));

        // Reconciled against the raw data: one export row per collected sitting, none for failed or queued ones.
        $data = results_export::dataset(new results_query($filter), results_export::LEVEL_ATTEMPT);
        $this->assertCount(1202, $data['rows']);
        $this->assertSame(1202, count(array_unique(array_column($data['rows'], 'attemptid'))));
    }

    /**
     * 250 collected, 250 without an engine verdict: "no verdict" everywhere, never "invalid" or "nothing collected".
     *
     * @return void
     */
    public function test_250_collected_250_without_verdict(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        experiment_status::reset();

        $runs = $this->experiment(['fastest'], 250);
        $this->assertCount(1, $runs);
        $run = reset($runs);
        $this->sittings($run, 250);
        global $DB;
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_FINISHED, ['id' => $run->id]);

        $filter = ['experimentid' => $this->experimentid, 'validityrule' => engine_validity::RULE_ENGINE];
        $counts = (new results_query($filter + ['validity' => result_validity::ALL]))->validity_counts();
        $this->assertSame([250, 0, 0, 250], [$counts['total'], $counts['valid'], $counts['invalid'], $counts['unknown']]);
        $this->assertSame(250, array_sum($counts['unknownreasons']), 'each missing verdict has its cause');

        // Export metadata: the 250 are "unknown", with their causes, and the engine's three counts add up.
        $meta = results_export::metadata(
            new results_query($filter + ['validity' => result_validity::ALL]),
            results_export::LEVEL_ATTEMPT
        );
        $population = $meta['population'];
        $this->assertSame(250, $population['attempts']['collected']);
        $this->assertSame(250, $population['attempts']['unknown']);
        $this->assertSame(0, $population['attempts']['invalid']);
        $this->assertSame(['valid' => 0, 'invalid' => 0, 'unknown' => 250], array_intersect_key(
            $population['engineverdicts'],
            array_flip(['valid', 'invalid', 'unknown'])
        ));
        $this->assertSame(250, array_sum($population['engineverdicts']['unknownreasons']));
        $this->assertSame([], $population['invalidreasons'], 'no reason for invalid where nothing was judged');
        $this->assertSame(engine_validity::RULE_ENGINE, $population['validityrule']);

        // The reproducibility package says the same, with the selection it was made from.
        $selection = $filter + ['validity' => result_validity::ALL];
        $analysis = reproducibility::package($this->experimentid, false, $selection)['analysis'];
        $this->assertSame(engine_validity::RULE_ENGINE, $analysis['validityrule']);
        $this->assertSame(result_validity::ALL, $analysis['validityshown']);
        $this->assertSame($this->experimentid, $analysis['filter']['experimentid']);
        $this->assertSame(250, $analysis['population']['attempts']['unknown']);
        $this->assertSame(250, $analysis['population']['engineverdicts']['unknown']);

        // The page: a missing verdict, not an empty or an invalid result.
        $page = new results_page(
            new results_query($filter + ['validity' => result_validity::VALID]),
            'overview',
            $filter + ['validity' => result_validity::VALID]
        );
        $this->assertSame('noverdict', $page->empty_kind());
        $basis = $page->render_provenance($this->experimentid);
        $this->assertStringContainsString('This is a missing verdict, not an invalid result', $basis);
        $this->assertStringNotContainsString('No sitting collected yet', $basis);
        $this->assertStringNotContainsString('data-empty="nocollected"', $basis);
        $this->assertStringNotContainsString('data-empty="allexcluded"', $basis);
    }

    /**
     * The package defaults to the results page's selection and names it; another experiment's filter is not taken.
     *
     * @return void
     */
    public function test_the_package_names_its_selection(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $runs = $this->experiment(['fastest'], 4);
        $this->sittings(reset($runs), 4);

        $analysis = reproducibility::package($this->experimentid, false)['analysis'];
        $this->assertSame(result_validity::VALID, $analysis['validityshown']);
        $this->assertSame(engine_validity::RULE_UNIFORM, $analysis['validityrule']);
        $this->assertSame(4, $analysis['population']['attempts']['collected']);

        $analysis = reproducibility::analysis($this->experimentid, ['experimentid' => 999999, 'strategy' => 'fastest']);
        $this->assertSame($this->experimentid, $analysis['filter']['experimentid']);
        $this->assertSame('fastest', $analysis['filter']['strategy']);
    }

    /**
     * The tabs keep each tab's own default for validity; a choice that is not the default travels (#112, #122).
     *
     * @return void
     */
    public function test_tab_links_keep_each_tabs_default(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->experiment(['fastest'], 2);

        $links = static function (string $tab, string $validity): array {
            $filter = ['experimentid' => 1, 'validity' => $validity];
            $html = (new results_page(new results_query($filter), $tab, $filter))->render_tabs();
            preg_match_all('/href="([^"]+)"/', $html, $m);
            return array_map('html_entity_decode', $m[1]);
        };
        // On the raw data with its default "all": the Overview link does not carry "all".
        foreach ($links('rawdata', 'all') as $href) {
            $this->assertStringNotContainsString('validity=', $href);
        }
        // On the overview with its default "valid": the Raw data link does not carry "valid".
        foreach ($links('overview', 'valid') as $href) {
            $this->assertStringNotContainsString('validity=', $href);
        }
        // A choice that is no tab's default goes along.
        foreach ($links('overview', 'invalid') as $href) {
            $this->assertStringContainsString('validity=invalid', $href);
        }
        $this->assertSame('all', results_page::default_validity('rawdata'));
        $this->assertSame('valid', results_page::default_validity('overview'));
    }
}
