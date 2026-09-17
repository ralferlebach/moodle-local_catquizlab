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
    public function test_the_example_survives_the_linters_extraction(string $name, string $file): void {
        $this->resetAfterTest();

        // The `moodle-plugin-ci mustache` check does not read the docblock the
        // way a person does: it takes everything between `{{!` and the *first* `}}`,
        // non-greedily. A template that parses here and fails there is a CI
        // failure with no local symptom, which is how one shipped.
        $content = file_get_contents($file);

        $docs = '';
        preg_match_all('/{{!([\s\S]*?)}}/', $content, $sections);
        foreach ($sections[0] as $section) {
            $section = trim($section);
            $pos = strpos($section, '@template');
            if ($pos !== false) {
                $docs = substr($section, $pos, -2);
                break;
            }
        }

        $this->assertNotSame('', $docs, $name . ' has no @template section the linter can find.');
        $this->assertMatchesRegularExpression(
            '/Example context \(json\):/',
            $docs,
            $name . ' has no example context inside the part the linter reads.'
        );

        preg_match('/Example context \(json\):([\s\S]*)/', $docs, $matches);
        $this->assertNotNull(
            json_decode($matches[1]),
            $name . ' has an example context the linter cannot parse: ' . json_last_error_msg()
        );
    }

    /**
     * A template renders from its example context.
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

    /**
     * The operations view supplies every top-level key its template uses.
     *
     * @return void
     */
    public function test_the_operations_context_covers_its_template(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();

        $source = file_get_contents($CFG->dirroot . '/local/catquizlab/templates/operations.mustache');
        $context = \local_catquizlab\local\operations_view::context();

        // Only the names the context itself has to provide. A name used inside
        // a section belongs to that section's data, so the nesting is followed
        // rather than pattern-matched.
        preg_match_all('/\{\{([#^\/]?)([a-z][a-z0-9_.]*)\}?\}/i', $source, $tags, PREG_SET_ORDER);

        $depth = 0;
        $toplevel = [];
        foreach ($tags as $tag) {
            [, $sigil, $name] = $tag;

            if ($sigil === '/') {
                $depth = max(0, $depth - 1);
                continue;
            }

            if ($depth === 0 && !in_array($name, ['str', 'js', 'pix'], true)) {
                // A dotted path is rooted in a top-level key too: {{a.b}}
                // needs a.
                $toplevel[] = explode('.', $name)[0];
            }

            if ($sigil === '#' || $sigil === '^') {
                $depth++;
            }
        }

        // A missing key renders as nothing at all: the section is skipped, the
        // table comes out empty, and the page looks correct. That is how a
        // whole tasks panel rendered with its headings and no rows.
        foreach (array_unique($toplevel) as $key) {
            $this->assertArrayHasKey(
                $key,
                $context,
                'operations.mustache uses {{' . $key . '}} and the context does not provide it.'
            );
        }
    }

    /**
     * No form posts an empty sesskey.
     *
     * @dataProvider template_provider
     * @param string $name The template name.
     * @param string $file Its path.
     * @return void
     */
    public function test_no_form_posts_an_empty_sesskey(string $name, string $file): void {
        global $OUTPUT;
        $this->resetAfterTest();
        $this->setAdminUser();

        $source = file_get_contents($file);
        preg_match('/Example context \(json\):([\s\S]*?)\n}}/', $source, $matches);
        $context = json_decode(trim($matches[1] ?? ''), true);
        if (!is_array($context)) {
            $this->markTestSkipped($name . ' has no usable example context.');
        }

        $html = $OUTPUT->render_from_template('local_catquizlab/' . $name, $context);

        preg_match_all('/name="sesskey" value="([^"]*)"/', $html, $found);
        foreach ($found[1] as $value) {
            // An empty key makes Moodle answer "your session has most likely
            // timed out", which sends people to look at their login for what is
            // a template bug. It happened: {{../../sesskey}} was one level
            // short, and two buttons on the setup tab were unusable.
            $this->assertNotSame(
                '',
                trim($value),
                $name . ' renders a form with an empty sesskey.'
            );
        }
    }

    /**
     * No state change sits behind a link.
     *
     * @dataProvider template_provider
     * @param string $name The template name.
     * @param string $file Its path.
     * @return void
     */
    public function test_no_state_change_behind_a_get(string $name, string $file): void {
        global $OUTPUT;
        $this->resetAfterTest();
        $this->setAdminUser();

        $source = file_get_contents($file);
        preg_match('/Example context \(json\):([\s\S]*?)\n}}/', $source, $matches);
        $context = json_decode(trim($matches[1] ?? ''), true);
        if (!is_array($context)) {
            $this->markTestSkipped($name . ' has no usable example context.');
        }

        $html = $OUTPUT->render_from_template('local_catquizlab/' . $name, $context);

        preg_match_all('/<a[^>]+href="([^"]*\baction=[^"]*)"/', $html, $found);

        // A state change behind a GET is one a prefetcher, a crawler or a back
        // button can make on somebody's behalf — and pausing a run somebody is
        // watching then looks like a bug in the plugin.
        $this->assertSame(
            [],
            $found[1],
            $name . ' links to an action instead of posting it: ' . implode(', ', $found[1])
        );
    }
}
