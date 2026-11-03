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

/**
 * The stored run exports (file area "export") ask for the same right as every other export (#121).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::local_catquizlab_pluginfile
 */
final class pluginfile_access_test extends \advanced_testcase {
    /**
     * Viewing is not exporting: a user who may look at the plugin but not export is refused the file.
     *
     * @return void
     */
    public function test_viewing_is_not_exporting(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/catquizlab/lib.php');
        $this->resetAfterTest();

        $context = \context_system::instance();
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'catquizlabviewer']);
        assign_capability('local/catquizlab:view', CAP_ALLOW, $roleid, $context->id);
        role_assign($roleid, $user->id, $context->id);
        $this->setUser($user);
        $this->assertTrue(has_capability('local/catquizlab:view', $context));
        $this->assertFalse(has_capability('local/catquizlab:export', $context));

        $this->expectException(\required_capability_exception::class);
        local_catquizlab_pluginfile(null, null, $context, 'export', ['run-1.csv'], true);
    }

    /**
     * Another file area, or another context, is not served at all.
     *
     * @return void
     */
    public function test_only_the_export_area_in_the_system_context(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/catquizlab/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertFalse(local_catquizlab_pluginfile(null, null, \context_system::instance(), 'other', ['x.csv'], true));
        $course = $this->getDataGenerator()->create_course();
        $this->assertFalse(local_catquizlab_pluginfile(
            null,
            null,
            \context_course::instance($course->id),
            'export',
            ['x.csv'],
            true
        ));
        // The right area and context, a file that is not there: not served, no error.
        $this->assertFalse(local_catquizlab_pluginfile(
            null,
            null,
            \context_system::instance(),
            'export',
            ['missing.csv'],
            true
        ));
    }

    /**
     * The files a background export wrote are listed per run with their links — no export without a way to it.
     *
     * @return void
     */
    public function test_stored_exports_are_listed_with_their_links(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $other = $generator->create_run();
        $this->assertSame([], \local_catquizlab\local\run_exporter::stored_files((int) $run->id));

        \local_catquizlab\local\run_exporter::export_to_files((int) $run->id, ['csv', 'json']);
        \local_catquizlab\local\run_exporter::export_to_files((int) $other->id, ['csv']);
        $files = \local_catquizlab\local\run_exporter::stored_files((int) $run->id);
        $names = array_column($files, 'filename');
        sort($names);
        $this->assertSame(['run-' . $run->id . '-answermatrix.csv', 'run-' . $run->id . '-answermatrix.json'], $names);
        foreach ($files as $file) {
            $url = $file['url']->out(false);
            $this->assertStringContainsString('/pluginfile.php/' . \context_system::instance()->id
                . '/local_catquizlab/export/0/' . $file['filename'], $url);
        }
    }
}
