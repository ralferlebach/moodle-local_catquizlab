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

use local_catquizlab\external\job_claim;
use local_catquizlab\external\job_complete;
use local_catquizlab\local\artefact_store;
use local_catquizlab\local\attempt_history;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\reason_catalog;
use local_catquizlab\local\registry;

/**
 * Debug artefacts: reason codes, documentation, ZIP, retention (#107).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\artefact_store
 * @covers     \local_catquizlab\local\reason_catalog
 */
final class artefact_store_test extends \advanced_testcase {
    /**
     * Reports from the live installation, and the codes they belong to.
     *
     * @return array[]
     */
    public static function reports(): array {
        return [
            'context lost' => ['Execution context was destroyed, most likely because of a navigation.', [],
                'execution_context_destroyed'],
            'no finish page' => ['Attempt did not reach the finish page after 33 answer(s). url=…', [], 'navigation_error'],
            'fetch failed' => ['fetch failed', [], 'fetch_failed'],
            'transport with status' => ['local_catquizlab_oracle_answer failed after 12 ms HTTP 503', ['transport' => [
                'wsfunction' => 'local_catquizlab_oracle_answer', 'status' => 503]], 'http_error'],
            'server error page' => ['page="This page isn\'t working … HTTP ERROR 500 Reload"', [], 'http_error'],
            'engine' => ['Moodle showed an error page after 1 answer(s). | server replay: DivisionByZeroError at x.php:734',
                [], 'engine_exception'],
            'login' => ['Login as catlab_r12_p-conforming-0001 failed: Invalid login, please try again', [], 'login_failed'],
            'lease' => ['Its worker stopped reporting before it finished.', [], 'worker_timeout'],
            'unknown' => ['Something nobody has seen yet', [], 'unknown_failure'],
        ];
    }

    /**
     * A reported failure gets its normalised code.
     *
     * @dataProvider reports
     * @param string $message The report.
     * @param array $diagnosis The diagnosis beside it.
     * @param string $expected The code.
     * @return void
     */
    public function test_a_failure_gets_its_code(string $message, array $diagnosis, string $expected): void {
        $this->assertSame($expected, reason_catalog::failure($message, $diagnosis));
    }

    /**
     * A finished sitting gets the code of the end its test reached.
     *
     * @return void
     */
    public function test_a_finished_sitting_gets_its_end(): void {
        // From the engine's statement first, then from facts (#106).
        $this->assertSame('max_items_reached', reason_catalog::outcome('Reached maximum number of questions'));
        $this->assertSame('timeout', reason_catalog::outcome('Time exeeded'));
        $this->assertSame('fixed_form_complete', reason_catalog::outcome('', ['strategy' => 'classic']));
        $this->assertSame('target_se_reached', reason_catalog::outcome('', ['strategy' => 'fastest',
            'finalse' => 0.31, 'semin' => 0.35]));
        $this->assertSame('subscale_rule_satisfied', reason_catalog::outcome('', ['strategy' => 'allsubs',
            'finalse' => 0.5, 'semin' => 0.35, 'dropped' => 2]));
        $this->assertSame('pool_exhausted', reason_catalog::outcome('', ['strategy' => 'fastest',
            'played' => 40, 'poolsize' => 40]));
        $this->assertSame('no_eligible_item_remaining', reason_catalog::outcome('noremainingquestions', [
            'strategy' => 'fastest', 'played' => 12, 'poolsize' => 40]));
        $this->assertSame('finished_other', reason_catalog::outcome(''));
    }

    /**
     * A run with one claimed and failed sitting, as the worker leaves it.
     *
     * @return array [runid, attemptid, execution directory]
     */
    protected function failed_sitting(): array {
        global $DB;

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_READY, ['id' => $run->id]);
        $person = $generator->create_person(['runid' => $run->id]);
        $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $run->id, 'personid' => $person->id, 'status' => attempt_scheduler::STATUS_QUEUED,
            'tries' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);

        $claim = job_claim::execute('worker-z');
        $attemptid = (int) $claim['attemptid'];

        // What the worker writes before it reports.
        $dir = artefact_store::execution_dir((int) $run->experimentid, (int) $run->id, $attemptid, (int) $claim['execution']);
        check_dir_exists($dir, true, true);
        file_put_contents($dir . '/screenshot-previous.jpg', 'jpeg-previous');
        file_put_contents($dir . '/screenshot-last.jpg', 'jpeg-last');
        file_put_contents($dir . '/dom.html', '<script>M.cfg = {"sesskey":"Zz99Yy88"};</script><p>Fehler</p>');
        file_put_contents($dir . '/events.json', json_encode(['events' => [
            ['type' => 'navigated', 'detail' => 'https://x.org/mod/adaptivequiz/attempt.php?sesskey=Zz99Yy88'],
        ]]));

        // A failure on the first try: documented although the sitting is
        // queued again — every failed execution is documented, not only the
        // last. The try number is the one the claim handed out.
        job_complete::execute(
            $attemptid,
            'failed',
            0,
            0,
            'Execution context was destroyed, most likely because of a navigation.',
            ''
        );

        return [(int) $run->id, $attemptid, $dir];
    }

    /**
     * A failure is documented in moodledata, with its code, the run's design and a log excerpt.
     *
     * @return void
     */
    public function test_a_failure_is_documented_in_moodledata(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$runid, $attemptid, $dir] = $this->failed_sitting();

        $code = $DB->get_field(
            'local_catquizlab_attemptlog',
            'reasoncode',
            ['attemptid' => $attemptid, 'outcome' => attempt_history::FAILED]
        );
        $this->assertSame('execution_context_destroyed', $code);

        $reason = json_decode((string) file_get_contents($dir . '/reason.json'), true);
        $this->assertSame('execution_context_destroyed', $reason['reason_code']);
        $this->assertSame($runid, $reason['run']['id']);
        $this->assertArrayHasKey('strategy', $reason['run']);
        $this->assertArrayHasKey('log_excerpt', $reason);

        $lines = file(dirname(dirname($dir)) . '/reasons.jsonl');
        $this->assertCount(1, $lines);
        $this->assertSame('failed', json_decode($lines[0], true)['outcome']);

        // And the end of a finished sitting is recorded as a code too.
        $this->assertSame(
            'max_items_reached',
            attempt_history::record_outcome_reason($attemptid, 'Reached maximum number of questions.')
        );
    }

    /**
     * The ZIP has the promised structure and no session key.
     *
     * @return void
     */
    public function test_the_zip_is_readable_and_redacted(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$runid, $attemptid] = $this->failed_sitting();
        set_config('artefacts_include_html', 1, 'local_catquizlab');

        foreach ([artefact_store::SCOPE_ATTEMPT, artefact_store::SCOPE_RUN, artefact_store::SCOPE_FAILED] as $scope) {
            $zip = artefact_store::zip($scope, $runid, $attemptid);
            $this->assertNotNull($zip, $scope);
            $names = array_map(static fn($f) => $f->pathname, get_file_packer('application/zip')->list_files($zip));
            $prefix = 'attempt-' . $attemptid . '/';
            foreach (
                ['manifest.json', $prefix . 'attempt.json', $prefix . 'logs.json',
                    $prefix . 'screenshots/execution-1-screenshot-previous.jpg',
                    $prefix . 'screenshots/execution-1-screenshot-last.jpg',
                    $prefix . 'html/execution-1-dom.html', $prefix . 'network/execution-1-events.json',
                    $prefix . 'network/execution-1-reason.json'] as $expected
            ) {
                $this->assertContains($expected, $names, $scope . ': ' . $expected);
            }
        }

        // Nothing inside carries the session key.
        $target = make_request_directory();
        get_file_packer('application/zip')->extract_to_pathname(artefact_store::zip(artefact_store::SCOPE_RUN, $runid), $target);
        $all = '';
        $tree = new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS);
        foreach (new \RecursiveIteratorIterator($tree) as $file) {
            $all .= file_get_contents((string) $file);
        }
        $this->assertStringNotContainsString('Zz99Yy88', $all);

        // Without HTML where that is switched off.
        set_config('artefacts_include_html', 0, 'local_catquizlab');
        $names = array_map(
            static fn($f) => $f->pathname,
            get_file_packer('application/zip')->list_files(artefact_store::zip(artefact_store::SCOPE_RUN, $runid))
        );
        $this->assertEmpty(array_filter($names, static fn(string $n): bool => str_contains($n, '/html/')));
    }

    /**
     * Artefacts go when their retention is over, and not before.
     *
     * @return void
     */
    public function test_artefacts_are_removed_after_their_retention(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [, , $dir] = $this->failed_sitting();
        set_config('artefact_retention_days', 14, 'local_catquizlab');

        $this->assertSame(0, artefact_store::cleanup());
        $this->assertDirectoryExists($dir);

        $this->assertSame(1, artefact_store::cleanup(time() + 15 * DAYSECS));
        $this->assertDirectoryDoesNotExist($dir);

        // Zero keeps everything.
        set_config('artefact_retention_days', 0, 'local_catquizlab');
        $this->assertSame(0, artefact_store::cleanup(time() + 365 * DAYSECS));
    }

    /**
     * Without the debug capability there is no download, whatever the request.
     *
     * @return void
     */
    public function test_the_download_needs_the_debug_capability(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$runid, $attemptid] = $this->failed_sitting();

        // A manager — who may see the plugin — with debugging taken away.
        $user = $this->getDataGenerator()->create_user();
        $manager = (int) $DB->get_field('role', 'id', ['shortname' => 'manager']);
        $system = \context_system::instance();
        role_assign($manager, $user->id, $system->id);
        assign_capability('local/catquizlab:debug', CAP_PROHIBIT, $manager, $system->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($user);
        $this->assertTrue(has_capability('local/catquizlab:view', $system));
        $this->assertFalse(has_capability('local/catquizlab:debug', $system));

        $this->expectException(\required_capability_exception::class);
        artefact_store::zip_for_download(artefact_store::SCOPE_RUN, $runid, $attemptid, $system);
    }
}
