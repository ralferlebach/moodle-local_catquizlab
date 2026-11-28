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
 * Every web service function takes its parameters in the order it describes them (0.7.85, found by the real long run).
 *
 * Moodle hands a web service call's parameters to execute() by position, in the order execute_parameters() lists
 * them — not by name. job_complete described executionid before reason, while execute() takes the reason first: through
 * the web service every report failed ("Argument #9 ($execution) must be of type int, string given"), the finished
 * sitting of 2502 answers stayed running. Unit tests that call execute() directly with arguments in the signature's
 * order could not see it. Here: position by position, the described parameter and the signature's, by name and type;
 * and job_complete through Moodle's own dispatcher, as the worker reaches it.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class external_parameter_order_test extends \advanced_testcase {
    /** @var array<string, string> Described names that execute() takes under another name. */
    protected const RENAMED = ['executionid' => 'execution'];

    /**
     * Position by position, the same parameter, of a type that takes it.
     *
     * @return void
     */
    public function test_every_function_takes_its_parameters_in_the_described_order(): void {
        global $CFG;
        $checked = 0;
        foreach (glob($CFG->dirroot . '/local/catquizlab/classes/external/*.php') as $file) {
            $class = '\\local_catquizlab\\external\\' . basename($file, '.php');
            if (!class_exists($class) || !method_exists($class, 'execute_parameters')) {
                continue;
            }
            $described = $class::execute_parameters()->keys;
            $signature = (new \ReflectionMethod($class, 'execute'))->getParameters();
            $this->assertCount(count($described), $signature, $class);
            $i = 0;
            foreach ($described as $name => $value) {
                $param = $signature[$i++];
                $this->assertSame(self::RENAMED[$name] ?? $name, $param->getName(), "{$class}: position {$i}");
                if ($value instanceof \core_external\external_value && $param->getType() !== null) {
                    $type = $param->getType()->getName();
                    $expected = $value->type === PARAM_INT ? 'int' : ($value->type === PARAM_BOOL ? 'bool' : 'string');
                    $this->assertSame($expected, $type, "{$class}: {$name}");
                }
                $checked++;
            }
        }
        $this->assertGreaterThan(20, $checked);
    }

    /**
     * job_complete through Moodle's dispatcher with a reason code and an execution: it runs, and fences a report of an
     * execution that holds nothing instead of breaking on its arguments.
     *
     * @return void
     */
    public function test_job_complete_through_the_dispatcher(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \local_catquizlab_generator $lab */
        $lab = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $lab->create_run()->id;
        $sitting = (int) $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $runid, 'personid' => 0,
            'status' => \local_catquizlab\local\attempt_scheduler::STATUS_QUEUED, 'tries' => 1, 'timecreated' => time(),
            'timemodified' => time()]);
        // As a session of the web service layer has it.
        $_POST['sesskey'] = sesskey();
        $answer = \core_external\external_api::call_external_function('local_catquizlab_job_complete', [
            'attemptid' => $sitting, 'status' => 'failed', 'runtimems' => 1000, 'engineattemptid' => 0,
            'message' => 'test', 'diagnostics' => '', 'workerid' => 'w1', 'executionid' => 77, 'reason' => 'no_progress',
        ]);
        $this->assertFalse($answer['error'], json_encode($answer['exception'] ?? null));
        $this->assertSame('not-running', $answer['data']['fence'] ?? '');
        $stale = $DB->get_record('local_catquizlab_attemptlog', ['attemptid' => $sitting,
            'outcome' => \local_catquizlab\local\attempt_history::STALE]);
        $this->assertSame(77, (int) $stale->executionid);
    }
}
