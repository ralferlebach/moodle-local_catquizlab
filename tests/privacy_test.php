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
 * Tests for the privacy provider.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\privacy\provider;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider tests.
 *
 * @covers \local_catquizlab\privacy\provider
 */
final class privacy_test extends \core_privacy\tests\provider_testcase {
    /**
     * Create a run with one person linked to the given user.
     *
     * @param \stdClass $user The Moodle user to link.
     * @return void
     */
    protected function link_person(\stdClass $user): void {
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $generator->create_person([
            'runid'        => $run->id,
            'moodleuserid' => $user->id,
            'stratum'      => 'conforming',
        ]);
    }

    /**
     * The metadata declares the person table.
     *
     * @return void
     */
    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new collection('local_catquizlab'));
        $this->assertNotEmpty($collection->get_collection());
    }

    /**
     * A linked user has data in the system context, and appears in the userlist.
     *
     * @return void
     */
    public function test_contexts_and_userlist(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->link_person($user);

        $contextlist = provider::get_contexts_for_userid($user->id);
        $this->assertCount(1, $contextlist);
        $this->assertSame(
            \context_system::instance()->id,
            (int) $contextlist->get_contextids()[0]
        );

        $userlist = new userlist(\context_system::instance(), 'local_catquizlab');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $user->id, array_map('intval', $userlist->get_userids()));
    }

    /**
     * Export writes the person data for the user.
     *
     * @return void
     */
    public function test_export(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->link_person($user);

        $approved = new approved_contextlist($user, 'local_catquizlab', [\context_system::instance()->id]);
        provider::export_user_data($approved);

        $this->assertTrue(writer::with_context(\context_system::instance())->has_any_data());
    }

    /**
     * Deleting for a user removes that user's person rows only.
     *
     * @return void
     */
    public function test_delete_for_user(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->link_person($user);
        $this->link_person($other);

        $approved = new approved_contextlist($user, 'local_catquizlab', [\context_system::instance()->id]);
        provider::delete_data_for_user($approved);

        $this->assertFalse($DB->record_exists('local_catquizlab_person', ['moodleuserid' => $user->id]));
        $this->assertTrue($DB->record_exists('local_catquizlab_person', ['moodleuserid' => $other->id]));
    }
}
