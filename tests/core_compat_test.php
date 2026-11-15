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

use local_catquizlab\local\core_compat;

/**
 * No core function the running Moodle calls deprecated (#139).
 *
 * On Moodle 5.2 and 5.3 the plugin's calls of course_delete_module(), user_create_user() and user_get_users_by_id()
 * each said "deprecated" through debugging(), 34 notices in the suite on 5.3. The calls now go through core_compat,
 * which takes the new core call where there is one; these tests run on every Moodle of the matrix, so the old path
 * is tested on 4.5 and the new one on 5.2 and 5.3.
 *
 * @package    local_catquizlab
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\core_compat
 */
final class core_compat_test extends \advanced_testcase {
    /**
     * A new user record of this test.
     *
     * @param string $name The user name, also the first name.
     * @param array $extra More fields.
     * @return \stdClass The record to create.
     */
    protected function user(string $name, array $extra = []): \stdClass {
        global $CFG;
        return (object) ($extra + [
            'username' => $name,
            'firstname' => ucfirst($name),
            'lastname' => 'User',
            'email' => $name . '@example.invalid',
            'auth' => 'manual',
            'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id,
        ]);
    }

    /**
     * A user is created, with or without its event, the password policy holds, and nothing says "deprecated".
     *
     * @return void
     */
    public function test_a_user_is_created_and_read_without_a_deprecation(): void {
        global $DB;
        $this->resetAfterTest();
        $password = 'Cql#compat-Strong-9a';

        $sink = $this->redirectEvents();
        $quiet = core_compat::create_user($this->user('cqlcompatquiet'), false, false);
        $told = core_compat::create_user($this->user('cqlcompattold', ['password' => $password]), true, true);
        $events = array_values(array_filter($sink->get_events(), static fn($e) => $e instanceof \core\event\user_created));
        $sink->close();

        $this->assertSame('cqlcompatquiet', $DB->get_field('user', 'username', ['id' => $quiet]));
        $this->assertCount(1, $events, 'only the second asked for its event');
        $this->assertSame($told, (int) $events[0]->objectid);
        $this->assertTrue(validate_internal_user_password($DB->get_record('user', ['id' => $told]), $password));

        $users = core_compat::users_by_id([$quiet, $told, $quiet + $told + 1000]);
        $this->assertEqualsCanonicalizing([$quiet, $told], array_map('intval', array_keys($users)));
        $this->assertSame([], core_compat::users_by_id([]));
        $this->assertDebuggingNotCalled();

        // The password policy holds on both paths.
        set_config('passwordpolicy', 1);
        try {
            core_compat::create_user($this->user('cqlcompatweak', ['password' => 'a']), true, false);
            $this->fail('a password against the policy was accepted');
        } catch (\moodle_exception $e) {
            $this->assertFalse($DB->record_exists('user', ['username' => 'cqlcompatweak']));
        }
        $this->assertDebuggingNotCalled();
    }

    /**
     * A course module goes at once, with its instance, and nothing says "deprecated".
     *
     * @return void
     */
    public function test_a_module_is_deleted_at_once_without_a_deprecation(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $gone = $this->getDataGenerator()->create_module('label', ['course' => $course->id]);
        $kept = $this->getDataGenerator()->create_module('label', ['course' => $course->id]);

        core_compat::delete_module((int) $gone->cmid);

        $this->assertFalse($DB->record_exists('course_modules', ['id' => $gone->cmid]));
        $this->assertFalse($DB->record_exists('label', ['id' => $gone->id]));
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $kept->cmid]));
        $background = $DB->count_records('task_adhoc', ['classname' => '\\core_course\\task\\course_delete_modules']);
        $this->assertSame(0, $background, 'deleted now, not in the background');

        // A module that is not there: nothing happens, as on every Moodle of the matrix.
        core_compat::delete_module((int) $gone->cmid);
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $kept->cmid]));
        $this->assertDebuggingNotCalled();
    }

    /**
     * The plain function calls in a PHP file: no methods, no declarations, nothing in comments.
     *
     * @param string $path The file.
     * @return array[] Each [line, name], the name without a leading backslash.
     */
    protected function function_calls(string $path): array {
        $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
        $tokens = array_values(array_filter(
            token_get_all(file_get_contents($path)),
            static fn($t) => !is_array($t) || !in_array($t[0], $skip, true)
        ));
        $notplain = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW];
        $calls = [];
        foreach ($tokens as $i => $token) {
            $isname = is_array($token) && in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true);
            if (!$isname || ($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }
            $before = $tokens[$i - 1] ?? null;
            if (is_array($before) && in_array($before[0], $notplain, true)) {
                continue;
            }
            $calls[] = [$token[2], ltrim($token[1], '\\')];
        }
        return $calls;
    }

    /**
     * Nowhere in the plugin a call of a core function that this Moodle marks deprecated.
     *
     * Every plain function call in the plugin's PHP (classes, scripts, tests; not methods), read with PHP's own
     * tokenizer so comments do not count, checked against the function's #[\core\attribute\deprecated] where the
     * function is loaded. On 5.3 this names the three this test was written for, if one comes back.
     *
     * @return void
     */
    public function test_the_plugin_calls_no_core_function_this_moodle_deprecates(): void {
        global $CFG;
        // Moodle 4.4 brought the attribute; the plugin requires 4.5.
        $this->assertTrue(class_exists(\core\attribute\deprecated::class));
        // The libraries whose functions the plugin calls (lib/externallib.php refuses to be loaded in a test).
        $libs = ['/user/lib.php', '/course/lib.php', '/lib/questionlib.php', '/lib/gradelib.php', '/lib/deprecatedlib.php'];
        foreach ($libs as $lib) {
            if (is_readable($CFG->dirroot . $lib)) {
                require_once($CFG->dirroot . $lib);
            }
        }
        $root = dirname(__DIR__);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $found = [];
        $checked = 0;
        foreach ($files as $file) {
            $path = $file->getPathname();
            $relative = substr($path, strlen($root) + 1);
            $isphp = substr($path, -4) === '.php' && !preg_match('~(^|/)(node_modules|vendor)/~', $relative);
            // The compat class keeps the old calls for the Moodle that has no new one; they run only there.
            if (!$isphp || $relative === 'classes/local/core_compat.php') {
                continue;
            }
            foreach ($this->function_calls($path) as [$line, $name]) {
                if (strpos($name, '\\') !== false || !function_exists($name)) {
                    continue;
                }
                $function = new \ReflectionFunction($name);
                if ($function->isInternal()) {
                    continue;
                }
                $checked++;
                if ($function->getAttributes(\core\attribute\deprecated::class) !== []) {
                    $found[] = $relative . ':' . $line . ' ' . $name . '()';
                }
            }
        }
        $this->assertGreaterThan(100, $checked, 'the scan saw the plugin\'s calls of Moodle functions');
        $this->assertSame([], $found, 'calls of core functions this Moodle marks deprecated');
    }
}
