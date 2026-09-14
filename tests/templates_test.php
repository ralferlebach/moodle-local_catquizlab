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
 * Templates render from their own documented example context.
 *
 * `moodle-plugin-ci mustache` renders every template against the example
 * context in its docblock and validates the HTML. A variable the template uses
 * and the example omits produces an empty attribute — and an empty form action
 * is not only invalid markup: it posts to the current page, which in this
 * plugin means a button that looks right and does nothing.
 *
 * That is exactly how the setup buttons came to be inert, so it is checked here
 * rather than only in CI.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

/**
 * Template tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class templates_test extends \advanced_testcase {
    /**
     * Every template, with its example context and rendered output.
     *
     * @return array[]
     */
    public static function template_provider(): array {
        global $CFG;

        $cases = [];
        foreach (glob($CFG->dirroot . '/local/catquizlab/templates/*.mustache') ?: [] as $file) {
            $cases[basename($file, '.mustache')] = [basename($file, '.mustache'), $file];
        }

        return $cases;
    }

    /**
     * A template renders from its example context without empty links or actions.
     *
     * @dataProvider template_provider
     * @param string $name The template name.
     * @param string $file Its path.
     * @return void
     */
    public function test_template_renders_from_its_example(string $name, string $file): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $source = file_get_contents($file);
        $this->assertMatchesRegularExpression(
            '/Example context \(json\):/',
            $source,
            $name . ' has no example context, so nothing can check it renders.'
        );

        preg_match('/Example context \(json\):(.*?)\n}}/s', $source, $matches);
        $context = json_decode(trim($matches[1]), true);
        $this->assertIsArray($context, $name . ' has an example context that is not valid JSON.');

        $html = $OUTPUT->render_from_template('local_catquizlab/' . $name, $context);

        preg_match_all('/<(?:form|a)[^>]*(?:action|href)="([^"]*)"/', $html, $found);
        foreach ($found[1] as $value) {
            // An empty action posts to the current page. In this plugin the
            // handler lives elsewhere, so the button does nothing at all — and
            // the page still looks correct.
            $this->assertNotSame(
                '',
                trim($value),
                $name . ' renders an empty href or action from its example context.'
            );
        }
    }
}
