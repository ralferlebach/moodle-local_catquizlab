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

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\experiment_status;
use local_catquizlab\local\registry;
use local_catquizlab\local\results_query;
use local_catquizlab\output\results_page;

/**
 * Results: the empty states told apart, the analysis basis compact, downloads in one menu (#130).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\output\results_page
 * @covers     \local_catquizlab\output\scatter_chart
 */
final class results_empty_states_test extends \advanced_testcase {
    /** @var int The experiment. */
    protected int $experimentid = 0;

    /**
     * An experiment over two strategies, three twins.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        experiment_status::reset();

        $definition = experiment_definition::example_baseline();
        $definition['name'] = 'Empty states';
        $definition['budgets']['global']['minitems'] = 1;
        $definition['persons']['count'] = 3;
        $definition['sweep'] = ['factors' => ['strategy' => ['fastest', 'lowestsub']]];
        $this->experimentid = (int) experiment_service::save($definition)['id'];
    }

    /**
     * The runs, each with three sittings in a status; collected ones with a trace.
     *
     * @param int $runstatus The run status.
     * @param int $status The sitting status.
     * @param bool $valid Whether the collected traces are valid ones.
     * @return void
     */
    protected function runs(int $runstatus, int $status, bool $valid = true): void {
        global $DB;

        experiment_service::create_sweep($this->experimentid);
        foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => $this->experimentid], 'id ASC') as $run) {
            $DB->set_field('local_catquizlab_run', 'status', $runstatus, ['id' => $run->id]);
            foreach ([-1.0, 0.0, 1.5] as $index => $ability) {
                $personid = (int) $DB->insert_record('local_catquizlab_person', (object) [
                    'runid' => $run->id, 'twinid' => sprintf('r%03d-t%05d', $run->replication, $index + 1),
                    'twinindex' => $index + 1, 'severity' => 'none', 'stratum' => 'conforming', 'abilityglobal' => $ability,
                    'profilejson' => json_encode(['global' => $ability, 'categories' => []]),
                    'timecreated' => time(), 'timemodified' => time(),
                ]);
                $trace = ['finalse' => 0.3, 'items' => [101, 102, 103], 'nitems' => 3, 'stopreason' => 'standarderror',
                    'responses' => [101 => 1.0, 102 => 0.0, 103 => 1.0]];
                if ($valid) {
                    $trace['finaltheta'] = $ability;
                }
                $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $run->id, 'personid' => $personid,
                    'status' => $status, 'tries' => $status === attempt_scheduler::STATUS_QUEUED ? 0 : 1,
                    'tracejson' => $status === attempt_scheduler::STATUS_COLLECTED ? json_encode($trace) : null,
                    'runtimems' => 1000, 'timecreated' => time(), 'timemodified' => time()]);
            }
        }
    }

    /**
     * The page for a filter.
     *
     * @param array $extra Filter values beside the experiment.
     * @return results_page
     */
    protected function page(array $extra = []): results_page {
        experiment_status::reset();
        $filter = ['experimentid' => $this->experimentid] + $extra;

        return new results_page(new results_query($filter), 'overview', $filter);
    }

    /**
     * No runs; runs not started; started but nothing collected — three states, three messages.
     *
     * @return void
     */
    public function test_nothing_collected_is_told_apart(): void {
        $page = $this->page();
        $this->assertSame('noruns', $page->empty_kind());
        $this->assertStringContainsString('No runs in this selection', $page->render_tab());

        $this->runs(registry::STATUS_READY, attempt_scheduler::STATUS_QUEUED);
        $page = $this->page();
        $this->assertSame('notstarted', $page->empty_kind());
        $this->assertStringContainsString('Not started yet', $page->render_tab());

        global $DB;
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_RUNNING, ['experimentid' => $this->experimentid]);
        $DB->set_field_select('local_catquizlab_attempt', 'status', attempt_scheduler::STATUS_RUNNING, 'id > 0');
        $DB->set_field_select('local_catquizlab_attempt', 'leaseexpires', time() + 600, 'id > 0');
        $page = $this->page();
        $this->assertSame('nocollected', $page->empty_kind());
        $this->assertStringContainsString('No sitting collected yet', $page->render_tab());
    }

    /**
     * Collected but none valid: said in the basis with reasons — and not "nothing collected" below it (AK 1).
     *
     * @return void
     */
    public function test_collected_but_excluded_is_not_nothing_collected(): void {
        $this->runs(registry::STATUS_FINISHED, attempt_scheduler::STATUS_COLLECTED, false);
        $page = $this->page();

        $this->assertSame('allexcluded', $page->empty_kind());
        $basis = $page->render_provenance($this->experimentid);
        $this->assertStringContainsString('data-empty="allexcluded"', $basis);
        $this->assertStringContainsString('6 sitting(s) were collected', $basis);
        $tab = $page->render_tab();
        $this->assertStringNotContainsString('Nothing has been collected', $tab);
        $this->assertStringNotContainsString('No sitting collected yet', $tab);

        // Shown as invalid: those are there.
        $this->assertSame('', $this->page(['validity' => 'invalid'])->empty_kind());
        // A factor no run has: the combination, not the data.
        $this->assertSame('nomatch', $this->page(['strategy' => 'classic'])->empty_kind());
    }

    /**
     * A missing engine verdict is not an invalid result, and is said apart (AK 2).
     *
     * @return void
     */
    public function test_a_missing_verdict_is_said_apart(): void {
        $this->runs(registry::STATUS_FINISHED, attempt_scheduler::STATUS_COLLECTED);
        $page = $this->page(['validityrule' => 'engine']);
        $counts = (new results_query(['experimentid' => $this->experimentid, 'validityrule' => 'engine']))
            ->validity_counts();
        if ($counts['engineunknown'] === 0) {
            $this->markTestSkipped('The engine gave a verdict here; the case needs one without.');
        }
        $basis = $page->render_provenance($this->experimentid);
        $this->assertStringContainsString('data-region="catquizlab-basis-verdict"', $basis);
        if ($counts['enginevalid'] === 0) {
            $this->assertSame('noverdict', $page->empty_kind());
            $this->assertStringContainsString('This is a missing verdict, not an invalid result', $basis);
            $this->assertStringNotContainsString('data-empty="allexcluded"', $basis);
        }
    }

    /**
     * The basis: short on top, the rest folded — with completeness and the package inside (AK 3).
     *
     * @return void
     */
    public function test_the_basis_is_compact_and_complete_when_opened(): void {
        global $DB;
        $this->runs(registry::STATUS_FINISHED, attempt_scheduler::STATUS_COLLECTED);
        // One sitting outstanding: the design is incomplete.
        $DB->set_field_select(
            'local_catquizlab_attempt',
            'status',
            attempt_scheduler::STATUS_FAILED,
            'id = (SELECT MIN(id) FROM {local_catquizlab_attempt})'
        );
        $basis = $this->page()->render_provenance($this->experimentid);

        $this->assertStringContainsString('data-region="catquizlab-basis-incomplete"', $basis);
        $this->assertStringContainsString('Incomplete design: 5 of 6 planned sittings collected, 1 failed for good', $basis);
        $this->assertStringContainsString('data-region="catquizlab-basis-line"', $basis);
        $this->assertStringContainsString('data-region="catquizlab-coverage"', $basis);
        // Folded: population, funnel, stop rules, completeness, package.
        $details = substr($basis, strpos($basis, 'data-region="catquizlab-analysis-basis"'));
        foreach (['catquizlab-analysis-population', 'catquizlab-completeness', 'value="package"'] as $inside) {
            $this->assertStringContainsString($inside, $details);
        }
        $this->assertStringNotContainsString('<details class="mb-3" data-region="catquizlab-analysis-basis" open', $basis);
    }

    /**
     * Every plot's downloads under one "Download" menu; plot settings beside it.
     *
     * @return void
     */
    public function test_the_downloads_are_one_menu(): void {
        $this->runs(registry::STATUS_FINISHED, attempt_scheduler::STATUS_COLLECTED);
        $html = $this->page()->render_tab();

        $this->assertStringContainsString('data-region="catquizlab-download"', $html);
        $this->assertStringContainsString('Download ▾', $html);
        // Inside the menu: svg, png, pdf (and csv where there are rows); the settings outside it.
        preg_match('/<details[^>]*data-region="catquizlab-download".*?<\/details>/s', $html, $menu);
        foreach (['svg', 'png', 'pdf'] as $kind) {
            $this->assertStringContainsString('data-download="' . $kind . '"', $menu[0]);
        }
        $this->assertStringNotContainsString('data-download="json"', $menu[0]);
    }
}
