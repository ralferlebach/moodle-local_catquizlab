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
 * Tests for the user provisioner.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\user_provisioner;
use local_catquizlab\local\person_generator;

/**
 * User provisioner tests.
 *
 * @covers \local_catquizlab\local\user_provisioner
 */
final class user_provisioner_test extends \advanced_testcase {
    /**
     * A run whose four persons have been generated and persisted.
     *
     * @return int The run id.
     */
    protected function seeded_run(): int {
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();

        $definition = [
            'persons' => [
                'count'   => 4,
                'stratum' => 'conforming',
                'naming'  => ['pattern' => 'P-{stratum}-{index:03d}'],
            ],
            'pool'    => ['scales' => ['categories' => 1, 'subcategories' => 1]],
        ];
        person_generator::generate_and_persist($run->id, $definition, 42);

        return $run->id;
    }

    /**
     * Provisioning creates one user per person and links them.
     *
     * @return void
     */
    public function test_provision_creates_and_links_users(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->seeded_run();
        $before = $DB->count_records('user');

        $result = user_provisioner::provision($runid, ['cohortname' => 'CAT lab simulants']);

        $this->assertSame(4, $result['created']);
        $this->assertSame($before + 4, $DB->count_records('user'));
        $this->assertSame(0, $DB->count_records_select(
            'local_catquizlab_person',
            'runid = ? AND moodleuserid IS NULL',
            [$runid]
        ));

        // Users are cohort members.
        $this->assertGreaterThan(0, $result['cohortid']);
        $this->assertSame(4, $DB->count_records('cohort_members', ['cohortid' => $result['cohortid']]));

        // A provisioned user's name reflects the person label.
        $person = $DB->get_records('local_catquizlab_person', ['runid' => $runid], 'id ASC', '*', 0, 1);
        $person = reset($person);
        $user = $DB->get_record('user', ['id' => $person->moodleuserid], '*', MUST_EXIST);
        $this->assertStringContainsString('P-conforming', $user->firstname);
    }

    /**
     * Provisioning is idempotent: a second call creates no further users.
     *
     * @return void
     */
    public function test_provision_is_idempotent(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->seeded_run();
        $first = user_provisioner::provision($runid);
        $second = user_provisioner::provision($runid);

        $this->assertSame(4, $first['created']);
        $this->assertSame(0, $second['created']);
    }
}
