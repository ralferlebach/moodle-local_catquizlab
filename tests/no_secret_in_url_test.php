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

use local_catquizlab\local\situation;
use local_catquizlab\local\worker_launcher;
use local_catquizlab\output\scatter_chart;

/**
 * No session key and no other key in a URL the plugin renders (#149).
 *
 * The crawler cli/scan_get_secrets.php checks the rendered pages over HTTP; these check the pieces that used to put a
 * key into a link: the plot's PDF download, the "start workers" action of the installation's state line, and the
 * shell template that renders it.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\output\scatter_chart
 * @covers     \local_catquizlab\local\situation
 */
final class no_secret_in_url_test extends \advanced_testcase {
    /**
     * The PDF of a plot is a form posting its key and the session key, not a link carrying them.
     *
     * @return void
     */
    public function test_the_plot_pdf_is_posted(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $html = scatter_chart::pdf_control('0123abcd');
        $this->assertMatchesRegularExpression('/<form[^>]*method="post"/', $html);
        $this->assertStringContainsString('name="key" value="0123abcd"', $html);
        $this->assertStringContainsString('name="sesskey" value="' . sesskey() . '"', $html);
        $this->assertDoesNotMatchRegularExpression('/(href|action)="[^"]*(sesskey|key)=/', $html);
        $this->assertStringContainsString('data-download="pdf"', $html);
    }

    /**
     * An action of the state line that carries the session key is posted; one that only leads somewhere stays a link.
     *
     * @return void
     */
    public function test_the_state_line_posts_an_action_with_a_session_key(): void {
        global $OUTPUT;
        $this->resetAfterTest();
        $this->setAdminUser();
        $verdict = (new \ReflectionMethod(situation::class, 'verdict'))->invoke(
            null,
            situation::STALLED,
            'Stalled',
            'no worker',
            'Start workers',
            worker_launcher::start_url()
        );
        $this->assertTrue($verdict['action']['post']);
        $this->assertStringNotContainsString('?', $verdict['action']['url']);
        $this->assertContains(['name' => 'sesskey', 'value' => sesskey()], $verdict['action']['params']);

        $html = $OUTPUT->render_from_template('local_catquizlab/shell', ['situation' => $verdict]);
        $this->assertMatchesRegularExpression('/<form method="post" action="[^"?]*operations\.php"/', $html);
        $this->assertDoesNotMatchRegularExpression('/href="[^"]*sesskey=/', $html);

        $plain = (new \ReflectionMethod(situation::class, 'verdict'))->invoke(
            null,
            situation::NOTREADY,
            'Not ready',
            'setup',
            'Set up',
            new \moodle_url('/local/catquizlab/index.php', ['tab' => 'setup'])
        );
        $this->assertFalse($plain['action']['post']);
        $html = $OUTPUT->render_from_template('local_catquizlab/shell', ['situation' => $plain]);
        $this->assertStringContainsString('href="' . s($plain['action']['url']) . '"', $html);
    }
}
