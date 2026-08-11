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
 * Tests for the run exporter.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\run_exporter;
use local_catquizlab\local\attempt_scheduler;

/**
 * Run exporter tests.
 *
 * @covers \local_catquizlab\local\run_exporter
 */
final class run_exporter_test extends \advanced_testcase {
    /**
     * Exporting stores a CSV file and logs the export.
     *
     * @return void
     */
    public function test_export_to_files_csv(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $now = time();
        $person = $generator->create_person([
            'runid' => $run->id,
            'profilejson' => json_encode(['label' => 'Alice', 'global' => 0.0, 'categories' => []]),
        ]);
        $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $run->id, 'personid' => $person->id,
            'status' => attempt_scheduler::STATUS_COLLECTED,
            'tracejson' => json_encode(['responses' => [10 => 1.0, 11 => 0.0]]),
            'timecreated' => $now, 'timemodified' => $now,
        ]);

        $files = run_exporter::export_to_files($run->id, ['csv']);
        $this->assertSame(["run-{$run->id}-answermatrix.csv"], $files);

        $fs = get_file_storage();
        $context = \context_system::instance();
        $file = $fs->get_file(
            $context->id,
            'local_catquizlab',
            run_exporter::FILEAREA,
            0,
            '/',
            "run-{$run->id}-answermatrix.csv"
        );
        $this->assertNotFalse($file);
        $this->assertStringContainsString('Alice', $file->get_content());

        $this->assertTrue($DB->record_exists(
            'local_catquizlab_exportlog',
            ['runid' => $run->id, 'format' => 'csv', 'dataset' => 'answermatrix']
        ));
    }
}
