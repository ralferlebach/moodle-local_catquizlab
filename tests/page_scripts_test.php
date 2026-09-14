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
 * The page scripts hold together as scripts.
 *
 * These files are long, procedural and share one variable scope, which is
 * normal for Moodle pages and makes one mistake easy: reusing a name that is
 * still needed further down. That is how `$detail` — the run's data, read
 * later for the reproducibility manifest — came to be overwritten with an HTML
 * string, after which every access to it read a character out of that string.
 *
 * A unit test cannot execute these files without a request, but it can read
 * them, and the specific mistake is visible in the text: a variable assigned a
 * plain string after it was assigned a structure, then used as a structure
 * again.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

/**
 * Page script tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class page_scripts_test extends \advanced_testcase {
    /**
     * Every page script of this plugin.
     *
     * @return array[]
     */
    public static function script_provider(): array {
        global $CFG;

        $cases = [];
        foreach (glob($CFG->dirroot . '/local/catquizlab/*.php') ?: [] as $file) {
            if (basename($file) === 'version.php' || basename($file) === 'settings.php') {
                continue;
            }
            $cases[basename($file)] = [basename($file), $file];
        }

        return $cases;
    }

    /**
     * A page script parses.
     *
     * @dataProvider script_provider
     * @param string $name The file name.
     * @param string $file Its path.
     * @return void
     */
    public function test_script_parses(string $name, string $file): void {
        $this->resetAfterTest();

        $tokens = @token_get_all(file_get_contents($file));

        $this->assertIsArray($tokens, $name . ' does not parse.');
        $this->assertNotEmpty($tokens);
    }

    /**
     * A variable used as an array is not reassigned a plain string first.
     *
     * @dataProvider script_provider
     * @param string $name The file name.
     * @param string $file Its path.
     * @return void
     */
    public function test_array_variables_are_not_overwritten_with_strings(string $name, string $file): void {
        $this->resetAfterTest();

        $source = file_get_contents($file);

        // Variables the script reads with an array offset somewhere.
        preg_match_all('/\$([a-z_][a-z0-9_]*)\s*\[/i', $source, $used);
        $asarray = array_unique($used[1]);

        foreach ($asarray as $variable) {
            // ... and assigns a string concatenation or a string literal to.
            $pattern = '/\$' . preg_quote($variable, '/') . '\s*=\s*(?:html_writer::|\'|")/';
            $this->assertDoesNotMatchRegularExpression(
                $pattern,
                $source,
                $name . ' assigns a string to $' . $variable . ', which is also used as an array. '
                    . 'Reusing a name that is still needed further down replaces a structure with text, '
                    . 'and every later access reads a character out of it.'
            );
        }
    }
}
