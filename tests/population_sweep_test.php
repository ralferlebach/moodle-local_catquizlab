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
use local_catquizlab\local\environment;
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\person_generator;
use local_catquizlab\local\person_integrity;
use local_catquizlab\local\run_orchestrator;

/**
 * Fifty twins in six strategy cells stay fifty per run, however often they are provisioned (#116).
 *
 * The observed experiment planned 6 × 50 sittings and had 350: one run's people
 * stage had run twice and stored every twin again. This is that experiment, set
 * up as the plugin sets it up, then provisioned again and at the same time —
 * without playing a sitting: the duplicates came from provisioning, not from
 * playing.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\run_orchestrator
 * @covers     \local_catquizlab\local\person_generator
 * @covers     \local_catquizlab\local\attempt_scheduler
 */
final class population_sweep_test extends \advanced_testcase {
    /** @var string[] The six strategy cells of the observed experiment. */
    protected const STRATEGIES = ['allsubs', 'classic', 'fastest', 'highestsub', 'lowestsub', 'relsubs'];

    /** @var int The twins of every run. */
    protected const TWINS = 50;

    /**
     * The experiment: fifty twins, six strategies, a pool small enough to set up six times.
     *
     * @return int[] The run ids, one per strategy.
     */
    protected function sweep(): array {
        $definition = experiment_definition::example_baseline();
        $definition['name'] = 'Population 50 x 6';
        $definition['persons']['count'] = self::TWINS;
        $definition['pool']['scales'] = ['categories' => 2, 'subcategories' => 2, 'itemspersubscale' => 6];
        $definition['budgets'] = [
            'global'   => ['minitems' => 4, 'maxitems' => 20],
            'subscale' => ['minitems' => 1, 'maxitems' => 5],
            'se'       => ['min' => 0.35, 'max' => 1.0],
        ];
        $definition['sweep']['factors']['strategy'] = self::STRATEGIES;
        $saved = experiment_service::save($definition);
        $sweep = experiment_service::create_sweep((int) $saved['id']);
        $this->assertCount(count(self::STRATEGIES), $sweep['runs'], 'one run per strategy cell');

        return $sweep['runs'];
    }

    /**
     * What a run holds: people, different twins, claims, sittings.
     *
     * @param int $runid The run.
     * @return int[] [person rows, distinct twins, twin claims, sittings, people with a sitting]
     */
    protected function population(int $runid): array {
        global $DB;

        return [
            $DB->count_records('local_catquizlab_person', ['runid' => $runid]),
            (int) $DB->count_records_sql(
                'SELECT COUNT(DISTINCT twinid) FROM {local_catquizlab_person} WHERE runid = :runid',
                ['runid' => $runid]
            ),
            $DB->count_records('local_catquizlab_twinclaim', ['runid' => $runid]),
            $DB->count_records('local_catquizlab_attempt', ['runid' => $runid]),
            (int) $DB->count_records_sql(
                'SELECT COUNT(DISTINCT personid) FROM {local_catquizlab_attempt} WHERE runid = :runid',
                ['runid' => $runid]
            ),
        ];
    }

    /**
     * Set up, set up again, and once more while a setup holds the lock: 50 per run, 300 in all.
     *
     * @return void
     */
    public function test_fifty_twins_in_six_cells_stay_fifty_per_run(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        // The readiness stage plays a first question in a transaction and rolls
        // it back, as on a real site: not inside a transaction of the test's.
        $this->preventResetByRollback();
        $this->setAdminUser();
        if (!environment::engine_available() || !environment::adaptivequiz_available()) {
            $this->markTestSkipped('Needs local_catquiz and mod_adaptivequiz: the runs are set up as on a real site.');
        }
        // File locks behave within one process as two processes do; the
        // database's advisory locks are re-entrant within a session.
        $CFG->lock_factory = '\\core\\lock\\file_lock_factory';
        $expected = array_fill(0, 5, self::TWINS);
        // The course every experiment is provisioned into, as the setup wizard configures it.
        $course = $this->getDataGenerator()->create_course();
        set_config(\local_catquizlab\local\experiment_container::SETTING_COURSE, $course->id, 'local_catquizlab');

        $runids = $this->sweep();
        foreach ($runids as $runid) {
            $result = run_orchestrator::setup($runid);
            $this->assertTrue($result['ok'], 'run ' . $runid . ': ' . json_encode($result['reason'] ?? $result));
            $this->assertSame($expected, $this->population($runid), 'run ' . $runid . ' after its setup');
        }
        $twins = [];
        foreach ($runids as $runid) {
            $twins[$runid] = $DB->get_fieldset_select('local_catquizlab_person', 'twinid', 'runid = :r', ['r' => $runid]);
            sort($twins[$runid]);
            // The same twins in every cell: that is what makes the cells comparable.
            $this->assertSame($twins[$runids[0]], $twins[$runid]);
        }
        $this->assertSame(300, $DB->count_records_select(
            'local_catquizlab_attempt',
            'runid IN (' . implode(',', array_map('intval', $runids)) . ')'
        ));

        // Provisioned again, every way it happened or could: the setup called
        // once more ("provision now" after the queued task), the people stored
        // again directly, the sittings scheduled again — and a second setup
        // while one holds the lock.
        foreach ($runids as $runid) {
            // The case that happened: the whole setup, people stage included, a second time.
            $again = run_orchestrator::setup($runid);
            $this->assertTrue($again['ok'], 'run ' . $runid . ' again: ' . json_encode($again['reason'] ?? ''));
            $this->assertSame($expected, $this->population($runid), 'run ' . $runid . ' after its second setup');

            // The people stage's own storing, with the same twins once more.
            $twinsagain = [];
            foreach ($DB->get_records('local_catquizlab_person', ['runid' => $runid], 'twinindex ASC') as $person) {
                $profile = json_decode((string) $person->profilejson, true);
                $twinsagain[] = [
                    'twinid' => $person->twinid, 'twinindex' => (int) $person->twinindex, 'severity' => $person->severity,
                    'stratum' => $person->stratum, 'abilityglobal' => $person->abilityglobal,
                    'label' => $profile['label'], 'profile' => array_diff_key($profile, ['label' => true]),
                ];
            }
            $this->assertCount(self::TWINS, $twinsagain);
            $this->assertSame(self::TWINS, person_generator::persist($runid, $twinsagain));
            $this->assertSame(0, attempt_scheduler::schedule($runid), 'no sitting more for run ' . $runid);

            $lock = \core\lock\lock_config::get_lock_factory('local_catquizlab_setup')->get_lock('run_' . $runid, 0);
            $this->assertNotFalse($lock);
            try {
                $concurrent = run_orchestrator::setup($runid);
            } finally {
                $lock->release();
            }
            $this->assertSame(run_orchestrator::REASON_SETUP_IN_PROGRESS, $concurrent['reason']);

            $this->assertSame($expected, $this->population($runid), 'run ' . $runid . ' after provisioning again');
            $this->assertTrue(person_integrity::check_run($runid, self::TWINS)['ok']);
            $this->assertSame([], person_integrity::check_run($runid)['duplicates']);
        }

        $in = implode(',', array_map('intval', $runids));
        $this->assertSame(300, $DB->count_records_select('local_catquizlab_person', "runid IN ({$in})"));
        $this->assertSame(300, $DB->count_records_select('local_catquizlab_attempt', "runid IN ({$in})"));
        $this->assertSame([], person_integrity::runs_with_duplicates());
    }
}
