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
use local_catquizlab\local\person_generator;
use local_catquizlab\local\population_plan;
use local_catquizlab\local\registry;
use local_catquizlab\local\results_query;
use local_catquizlab\local\run_registry;
use local_catquizlab\local\sweep;

/**
 * People, digital twins and sittings: planned, materialised, compared (#117).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\population_plan
 */
final class population_plan_test extends \advanced_testcase {
    /**
     * Fifty twins in the six cells of the strategy sweep.
     *
     * @param int $replications Replications.
     * @return array
     */
    protected function definition(int $replications): array {
        $definition = experiment_definition::example_baseline();
        $definition['persons']['count'] = 50;
        $definition['replications'] = $replications;
        $definition['sweep']['factors']['strategy'] = ['fastest', 'allsubs', 'relsubs', 'lowestsub', 'highestsub', 'classic'];

        return $definition;
    }

    /**
     * The sittings follow from the plan: twins × cells × replications.
     *
     * @return void
     */
    public function test_sittings_follow_from_the_plan(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $one = population_plan::plan(sweep::expand(experiment_service::sweep_spec($this->definition(1))));
        $this->assertSame([50, 6, 1, 300, 300], [$one['twins'], $one['cells'], $one['replications'], $one['runpersons'],
            $one['sittings']]);

        $two = population_plan::plan(sweep::expand(experiment_service::sweep_spec($this->definition(2))));
        $this->assertSame([50, 2, 600, 600], [$two['twins'], $two['replications'], $two['runpersons'], $two['sittings']]);

        // The preview says it so, the three numbers apart.
        $preview = experiment_service::preview($this->definition(1));
        $this->assertSame([50, 300, 300], [$preview['twins'], $preview['runpersons'], $preview['sittings']]);
    }

    /**
     * A run of five twins, stored and scheduled as planned.
     *
     * @return \stdClass The run.
     */
    protected function run_as_planned(): \stdClass {
        global $DB;

        $definition = experiment_definition::example_baseline();
        $definition['persons']['count'] = 5;
        $id = (int) experiment_service::save($definition)['id'];
        experiment_service::create_sweep($id);
        $run = $DB->get_record('local_catquizlab_run', ['experimentid' => $id], '*', IGNORE_MULTIPLE);
        $definition = run_registry::definition_for($run);
        person_generator::persist((int) $run->id, person_generator::generate($definition, 7, ['replication' => 1]));
        $DB->set_field('local_catquizlab_person', 'moodleuserid', 2, ['runid' => $run->id]);
        attempt_scheduler::schedule((int) $run->id);

        return $run;
    }

    /**
     * As planned: consistent; a person row twice or missing: a visible mismatch.
     *
     * @return void
     */
    public function test_a_doubled_or_missing_person_is_seen(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $run = $this->run_as_planned();
        $actual = population_plan::actual((int) $run->experimentid);
        $this->assertTrue($actual['consistent']);
        $this->assertSame([5, 5, 5, 5], [$actual['twins'], $actual['expected'], $actual['materialised'], $actual['sittings']]);

        // A row twice — as the runs set up twice at once had.
        $copy = $DB->get_record('local_catquizlab_person', ['runid' => $run->id], '*', IGNORE_MULTIPLE);
        unset($copy->id);
        $copyid = $DB->insert_record('local_catquizlab_person', $copy);
        $described = population_plan::describe((int) $run->experimentid);
        $this->assertFalse($described['consistent']);
        $this->assertSame(population_plan::DUPLICATE_TWINS, population_plan::actual((int) $run->experimentid)['runs'][0]['state']);
        $this->assertStringContainsString('more than once', $described['problems'][0]);

        // A row missing.
        $DB->delete_records('local_catquizlab_person', ['id' => $copyid]);
        $DB->delete_records_select(
            'local_catquizlab_person',
            'id = (SELECT MAX(id) FROM {local_catquizlab_person} WHERE runid = ?)',
            [$run->id]
        );
        $this->assertSame(population_plan::PEOPLE_MISMATCH, population_plan::actual((int) $run->experimentid)['runs'][0]['state']);
    }

    /**
     * A run with a twin twice is not executed: the claim fails the run instead of handing out its sittings.
     *
     * @return void
     */
    public function test_an_inconsistent_run_is_not_executed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $run = $this->run_as_planned();
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_READY, ['id' => $run->id]);
        $copy = $DB->get_record('local_catquizlab_person', ['runid' => $run->id], '*', IGNORE_MULTIPLE);
        unset($copy->id);
        $DB->insert_record('local_catquizlab_person', $copy);

        $job = \local_catquizlab\external\job_claim::execute('w-117');
        $this->assertFalse($job['hasjob']);
        $this->assertSame(registry::STATUS_FAILED, (int) $DB->get_field('local_catquizlab_run', 'status', ['id' => $run->id]));
        $this->assertSame(0, $DB->count_records('local_catquizlab_attempt', ['runid' => $run->id,
            'status' => attempt_scheduler::STATUS_RUNNING]));
    }

    /**
     * Progress and results say the same numbers.
     *
     * @return void
     */
    public function test_progress_and_results_agree(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $run = $this->run_as_planned();
        $experimentid = (int) $run->experimentid;
        $progress = \local_catquizlab\local\progress_view::context($experimentid)['population']['text'];
        $html = (new \local_catquizlab\output\results_page(
            new results_query(['experimentid' => $experimentid]),
            'overview',
            ['experimentid' => $experimentid]
        ))->render_provenance();
        $this->assertStringContainsString(s($progress), $html);
    }

    /**
     * Experiment 12 as observed: six strategies of fifty twins, relsubs with every twin twice —
     * 350 sittings for 300 planned — is recognised without anybody looking (#117, criterion 10).
     *
     * @return void
     */
    public function test_the_350_against_300_case_is_recognised(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $definition = experiment_definition::example_baseline();
        $definition['persons']['count'] = 50;
        $definition['sweep']['factors']['strategy'] = ['allsubs', 'classic', 'fastest', 'highestsub', 'lowestsub', 'relsubs'];
        $id = (int) experiment_service::save($definition)['id'];
        experiment_service::create_sweep($id);
        $relsubs = 0;
        foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => $id], 'id ASC') as $run) {
            $own = run_registry::definition_for($run);
            person_generator::persist((int) $run->id, person_generator::generate($own, 7, ['replication' => 1]));
            $DB->set_field('local_catquizlab_person', 'moodleuserid', 2, ['runid' => $run->id]);
            attempt_scheduler::schedule((int) $run->id);
            if (($own['strategy'] ?? '') === 'relsubs') {
                $relsubs = (int) $run->id;
            }
        }
        $this->assertTrue(population_plan::actual($id)['consistent'], 'as planned first');

        // What a setup done twice at once left before 0.7.26: every relsubs twin
        // stored again, around the claims, and each copy given a sitting.
        foreach ($DB->get_records('local_catquizlab_person', ['runid' => $relsubs]) as $person) {
            unset($person->id);
            $copy = $DB->insert_record('local_catquizlab_person', $person);
            $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $relsubs, 'personid' => $copy,
                'status' => attempt_scheduler::STATUS_QUEUED, 'timecreated' => time(), 'timemodified' => time()]);
        }

        $actual = population_plan::actual($id);
        $this->assertFalse($actual['consistent']);
        $this->assertSame([50, 300, 350, 350], [$actual['twins'], $actual['expected'], $actual['materialised'],
            $actual['sittings']]);
        $bad = array_values(array_filter($actual['runs'], static fn(array $r): bool => $r['state'] !== population_plan::OK));
        $this->assertCount(1, $bad, 'only relsubs is named');
        $this->assertSame([$relsubs, 50, 100, 50, 100], [$bad[0]['runid'], $bad[0]['expected'], $bad[0]['materialised'],
            $bad[0]['distinct'], $bad[0]['sittings']]);
        $this->assertSame(population_plan::DUPLICATE_TWINS, $bad[0]['state']);
    }
}
