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

use local_catquizlab\local\registry;
use local_catquizlab\local\run_lifecycle;
use local_catquizlab\local\run_log;

/**
 * Every change of a run's status is recorded, and there is one way to make it (#90).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\run_lifecycle
 */
final class run_status_log_test extends \advanced_testcase {
    /**
     * The status changes recorded for a run, as "from>to:why".
     *
     * @param int $runid The run.
     * @return string[]
     */
    protected function changes(int $runid): array {
        global $DB;
        $out = [];
        $rows = $DB->get_records('local_catquizlab_runlog', ['runid' => $runid, 'event' => run_log::STATUS_CHANGED], 'id ASC');
        foreach ($rows as $row) {
            $detail = json_decode((string) ($row->detail ?? $row->detailjson ?? ''), true) ?: [];
            $out[] = ($detail['from'] ?? '?') . '>' . ($detail['to'] ?? '?') . ':' . ($detail['why'] ?? '?');
        }

        return $out;
    }

    /**
     * Each way of changing a status records from, to and why; no change, no record.
     *
     * @return void
     */
    public function test_every_status_change_is_recorded(): void {
        global $DB;
        $this->resetAfterTest();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run(['status' => registry::STATUS_DRAFT]);
        $id = (int) $run->id;

        run_lifecycle::set_status($id, registry::STATUS_SCHEDULED, 'setup_complete');
        run_lifecycle::update_run(
            (object) ['id' => $id, 'status' => registry::STATUS_READY, 'timemodified' => time()],
            'provisioned'
        );
        // The same status again is not a change.
        run_lifecycle::set_status($id, registry::STATUS_READY, 'again');
        // A record without a status is not one either.
        run_lifecycle::update_run((object) ['id' => $id, 'timemodified' => time()], 'touched');

        // Conditional: from ready only — the first call changes it, the second finds it changed.
        $this->assertTrue(run_lifecycle::set_status_if($id, registry::STATUS_READY, registry::STATUS_RUNNING, 'claimed'));
        $this->assertFalse(run_lifecycle::set_status_if($id, registry::STATUS_READY, registry::STATUS_RUNNING, 'claimed'));
        $this->assertSame(registry::STATUS_RUNNING, (int) $DB->get_field('local_catquizlab_run', 'status', ['id' => $id]));

        $this->assertSame([
            'draft>scheduled:setup_complete',
            'scheduled>ready:provisioned',
            'ready>running:claimed',
        ], $this->changes($id));
    }

    /**
     * Nothing in the plugin writes a run's status except through run_lifecycle.
     *
     * A guard over the source: a status written directly would not be recorded.
     *
     * @return void
     */
    public function test_a_run_status_is_written_in_one_place_only(): void {
        global $CFG;
        $root = $CFG->dirroot . '/local/catquizlab';
        $offences = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (substr($path, -4) !== '.php' || preg_match('#/(tests|vendor|node_modules)/#', $path)) {
                continue;
            }
            $source = file_get_contents($path);
            $relative = substr($path, strlen($root) + 1);
            if (preg_match("/set_field\\('local_catquizlab_run',\\s*'status'/", $source)) {
                $offences[] = $relative . ': set_field on the run status';
            }
            if (
                preg_match_all("/update_record\\('local_catquizlab_run'/", $source) > 0
                    && $relative !== 'classes/local/run_lifecycle.php'
            ) {
                $offences[] = $relative . ': update_record on a run';
            }
            if (
                preg_match('/UPDATE \\{local_catquizlab_run\\}\\s+SET[^\']*status/i', $source)
                    && $relative !== 'classes/local/run_lifecycle.php'
            ) {
                $offences[] = $relative . ': SQL update of the run status';
            }
        }
        // Within run_lifecycle, only the helpers and set_paused (which writes the manifest, not the status).
        $source = file_get_contents($root . '/classes/local/run_lifecycle.php');
        preg_match_all('/function ([a-z_]+)\\(/', $source, $functions, PREG_OFFSET_CAPTURE);
        preg_match_all("/update_record\\('local_catquizlab_run'/", $source, $writes, PREG_OFFSET_CAPTURE);
        foreach ($writes[0] as [, $offset]) {
            $in = '';
            foreach ($functions[1] as [$name, $at]) {
                if ($at < $offset) {
                    $in = $name;
                }
            }
            if (!in_array($in, ['set_status', 'update_run', 'set_paused'], true)) {
                $offences[] = 'run_lifecycle::' . $in . ': update_record on a run';
            }
        }

        $this->assertSame([], $offences);
    }

    /**
     * Every retry decision is recorded: requeued with its backoff, or given up (#90).
     *
     * @return void
     */
    public function test_every_retry_decision_is_recorded(): void {
        global $DB;
        $this->resetAfterTest();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $attemptid = (int) $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $run->id, 'personid' => 0, 'status' => \local_catquizlab\local\attempt_scheduler::STATUS_RUNNING,
            'tries' => 1, 'timecreated' => time(), 'timemodified' => time(),
        ]);

        \local_catquizlab\local\attempt_scheduler::retry_or_fail($attemptid);
        $DB->set_field(
            'local_catquizlab_attempt',
            'tries',
            \local_catquizlab\local\attempt_scheduler::MAX_TRIES,
            ['id' => $attemptid]
        );
        \local_catquizlab\local\attempt_scheduler::retry_or_fail($attemptid);

        $entries = array_values($DB->get_records('local_catquizlab_attemptlog', ['attemptid' => $attemptid], 'id ASC'));
        $this->assertSame(
            [\local_catquizlab\local\attempt_history::REQUEUED, \local_catquizlab\local\attempt_history::ABANDONED],
            array_column($entries, 'outcome')
        );
        $this->assertMatchesRegularExpression('/^failed; retry after \d+ s, at /', $entries[0]->detail);
        $this->assertStringContainsString('no tries left after', $entries[1]->detail);
    }

    /**
     * Each worker in the progress view links to the log filtered to it (#90).
     *
     * @return void
     */
    public function test_a_worker_links_to_its_log(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $DB->insert_record('local_catquizlab_worker', (object) [
            'workerid' => 'catquizlab-exec-7', 'slot' => 7, 'jobsdone' => 3, 'currentattempt' => 0,
            'status' => \local_catquizlab\local\worker_registry::STATUS_RUNNING,
            'stoprequested' => 0, 'heartbeat' => time(), 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $rows = \local_catquizlab\local\progress_view::context(0)['workers']['rows'];
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('/local/catquizlab/logs.php', $rows[0]['logurl']);
        $this->assertStringContainsString('workerid=catquizlab-exec-7', $rows[0]['logurl']);
    }
}
