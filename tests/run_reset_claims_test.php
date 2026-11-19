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

namespace local_catquizlab;

use local_catquizlab\local\acceptance_inventory;
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\person_generator;
use local_catquizlab\local\registry;
use local_catquizlab\local\run_cleanup;
use local_catquizlab\local\run_lifecycle;

/**
 * A reset or a cleanup takes the twins' claims and the sittings' history with the persons and sittings (found by the
 * acceptance inventory on 18.11.2026: 220 claims and 8 history rows without their run or sitting).
 *
 * Before 0.7.71 a reset left the claims behind. Provisioned again, every twin was found "provisioned before" — its
 * claim pointed at a person the reset had removed — and no person was stored: a run of five twins had none.
 *
 * @package    local_catquizlab
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\run_lifecycle
 * @covers     \local_catquizlab\local\run_cleanup
 * @covers     \local_catquizlab\local\person_generator
 */
final class run_reset_claims_test extends \advanced_testcase {
    /**
     * A run with five twins stored and one sitting with a line of history.
     *
     * @return array [runid, persons]
     */
    protected function run_with_twins(): array {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $generator->create_run(['status' => registry::STATUS_FINISHED])->id;
        $definition = experiment_definition::example_baseline();
        $definition['persons']['count'] = 5;
        $persons = person_generator::generate($definition, 4242, ['replication' => 1]);
        person_generator::persist($runid, $persons);
        $personid = (int) $DB->get_field_sql('SELECT MIN(id) FROM {local_catquizlab_person} WHERE runid = ?', [$runid]);
        $attemptid = $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $runid, 'personid' => $personid,
            'status' => 20, 'tries' => 1, 'nextruntime' => 0, 'timecreated' => time(), 'timemodified' => time()]);
        $DB->insert_record('local_catquizlab_attemptlog', (object) ['attemptid' => $attemptid, 'runid' => $runid,
            'tryno' => 1, 'outcome' => 'collected', 'workerid' => 'w1', 'engineattemptid' => 0, 'runtimems' => 10,
            'detail' => '', 'timecreated' => time()]);

        return [$runid, $persons];
    }

    /**
     * Reset, then provisioned again: five persons, five claims, each claim on a stored person, no history of a sitting
     * that is gone.
     *
     * @return void
     */
    public function test_a_reset_run_gets_its_persons_again(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$runid, $persons] = $this->run_with_twins();

        $reset = run_lifecycle::reset($runid);
        $this->assertTrue($reset['ok'], json_encode($reset));
        $this->assertSame(5, $reset['removed']['twinclaims']);
        $this->assertSame(1, $reset['removed']['history']);
        $this->assertSame(0, $DB->count_records('local_catquizlab_twinclaim', ['runid' => $runid]));
        $this->assertSame(0, $DB->count_records('local_catquizlab_attemptlog', ['runid' => $runid]));

        person_generator::persist($runid, $persons);
        $this->assertSame(5, $DB->count_records('local_catquizlab_person', ['runid' => $runid]));
        $this->assertSame(0, (int) $DB->count_records_sql(
            'SELECT COUNT(1) FROM {local_catquizlab_twinclaim} c
          LEFT JOIN {local_catquizlab_person} p ON p.id = c.personid
              WHERE c.runid = ? AND p.id IS NULL',
            [$runid]
        ));
    }

    /**
     * A claim left behind by an older reset claims nothing: the twin is stored again, the stale claim replaced.
     *
     * @return void
     */
    public function test_a_stale_claim_from_before_is_replaced(): void {
        global $DB;
        $this->resetAfterTest();
        [$runid, $persons] = $this->run_with_twins();

        // What a reset before 0.7.71 left: persons gone, claims kept.
        $DB->delete_records('local_catquizlab_person', ['runid' => $runid]);
        $this->assertSame(5, $DB->count_records('local_catquizlab_twinclaim', ['runid' => $runid]));

        person_generator::persist($runid, $persons);
        $this->assertSame(5, $DB->count_records('local_catquizlab_person', ['runid' => $runid]));
        $this->assertSame(5, $DB->count_records('local_catquizlab_twinclaim', ['runid' => $runid]));
        $this->assertSame(0, (int) $DB->count_records_sql(
            'SELECT COUNT(1) FROM {local_catquizlab_twinclaim} c
          LEFT JOIN {local_catquizlab_person} p ON p.id = c.personid
              WHERE c.runid = ? AND p.id IS NULL',
            [$runid]
        ));
    }

    /**
     * A run cleaned up with the run itself leaves no claim and no history behind; the inventory finds no orphan.
     *
     * @return void
     */
    public function test_a_cleanup_leaves_no_orphans(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$runid] = $this->run_with_twins();

        run_cleanup::cleanup($runid, ['users' => false, 'course' => false, 'run' => true]);

        $checks = array_column((new acceptance_inventory())->run()['checks'], null, 'name');
        $this->assertSame(0, $checks['twin claims without their run']['value']);
        $this->assertSame(0, $checks['attempt history without its sitting']['value']);
    }
}
