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

use local_catquizlab\local\attempt_history;
use local_catquizlab\local\debug_trace;
use local_catquizlab\local\log_redactor;
use local_catquizlab\local\log_view;
use local_catquizlab\local\request_trace;

/**
 * One rule for every log the plugin writes (#149): the names the audit lists, encoded, repeated and as arrays, in
 * every channel that can carry a URI or a parameter list.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\log_redactor
 */
final class log_redactor_test extends \advanced_testcase {
    /**
     * The names AK2 lists, and their disguises, are secret; ordinary names are not.
     *
     * @return void
     */
    public function test_which_names_are_secret(): void {
        foreach (
            ['sesskey', 'SESSKEY', 'ses%73key', 'ses%2573key', 'sesskey[]', 'sess_key', 'token', 'wstoken',
                'privatetoken', 'key', 'apikey', 'password', 'passwd', 'secret', 'client_secret', 'authorization',
                'Authorization', 'email', 'e-mail', 'useremail', 'MoodleSession', 'logintoken'] as $name
        ) {
            $this->assertTrue(log_redactor::is_secret_name($name), $name);
        }
        foreach (
            ['experimentid', 'tab', 'runid', 'cellkey', 'errorcode', 'cmid', 'phase', 'status', 'limit',
                'replication', 'download', 'wsfunction', ''] as $name
        ) {
            $this->assertFalse(log_redactor::is_secret_name($name), $name);
        }
    }

    /**
     * A URI keeps its path and its harmless parameters and loses every secret value, its fragment and any
     * control character.
     *
     * @return void
     */
    public function test_a_uri_loses_its_secrets(): void {
        $cases = [
            '/mod/adaptivequiz/attempt.php?cmid=47&sesskey=TESTSECRET'
                => '/mod/adaptivequiz/attempt.php?cmid=47&sesskey=[redacted]',
            '/a.php?ses%73key=TESTSECRET&sesskey=TESTSECRET2&SESSKEY=TESTSECRET3'
                => '/a.php?ses%73key=[redacted]&sesskey=[redacted]&SESSKEY=[redacted]',
            '/a.php?wstoken[]=TESTSECRET&wstoken[]=TESTSECRET2&x=1'
                => '/a.php?wstoken[]=[redacted]&wstoken[]=[redacted]&x=1',
            '/a.php?q=probe%40example.org&key=TESTSECRET&authorization=Bearer%20TESTSECRET'
                => '/a.php?q=[redacted]&key=[redacted]&authorization=[redacted]',
            '/a.php?password=TESTSECRET;secret=TESTSECRET#token=TESTSECRET'
                => '/a.php?password=[redacted]&secret=[redacted]',
            "/a.php?x=1\r\nInjected: yes&token=TESTSECRET"
                => '/a.php?x=1Injected: yes&token=[redacted]',
            '/tokenpluginfile.php/TESTSECRET/12/mod_page/content/0/a.png'
                => '/tokenpluginfile.php/[redacted]/12/mod_page/content/0/a.png',
            '/webservice/pluginfile.php/TESTSECRET/12/a.png?token=TESTSECRET'
                => '/webservice/pluginfile.php/[redacted]/12/a.png?token=[redacted]',
            '/a.php?flag&tab=rawdata' => '/a.php?flag&tab=rawdata',
        ];
        foreach ($cases as $in => $out) {
            $this->assertSame($out, log_redactor::redact_uri($in));
        }
        $long = log_redactor::redact_uri('/a.php?x=' . str_repeat('y', 5000) . '&token=TESTSECRET');
        $this->assertLessThanOrEqual(512 + 20, strlen($long));
        $this->assertStringNotContainsString('TESTSECRET', $long);
    }

    /**
     * Free text — a failure report, a log line — loses secret pairs wherever they stand, also inside a URL.
     *
     * @return void
     */
    public function test_free_text_loses_its_secret_pairs(): void {
        $text = 'Attempt failed url=https://x.example/mod/adaptivequiz/attempt.php?sesskey=TESTSECRET&cmid=4 '
            . 'title="t" (ses%73key=TESTSECRET, key=TESTSECRET|email=probe@example.org cellkey=strategy errorcode=x';
        $out = log_redactor::redact_pairs($text);
        $this->assertStringNotContainsString('TESTSECRET', $out);
        $this->assertStringNotContainsString('probe@', $out);
        $this->assertStringContainsString('cmid=4', $out);
        $this->assertStringContainsString('cellkey=strategy', $out);
        $this->assertStringContainsString('errorcode=x', $out);
    }

    /**
     * AK4: every other channel that carries a URI or parameters follows the same rule.
     *
     * @return void
     */
    public function test_every_channel_follows_the_rule(): void {
        $uri = '/mod/adaptivequiz/attempt.php?cmid=47&ses%73key=TESTSECRET&wstoken[]=TESTSECRET&key=TESTSECRET'
            . '&email=probe%40example.org';

        // The worker's request lines.
        $this->assertStringNotContainsString('TESTSECRET', request_trace::redact_uri($uri));
        $this->assertStringNotContainsString('probe', request_trace::redact_uri($uri));

        // Failure reports and attempt history.
        $history = attempt_history::redact('Failed url=https://x.example' . $uri . ' Authorization: Bearer TESTSECRET '
            . '{"secret":"TESTSECRET","email":"probe@example.org"}');
        $this->assertStringNotContainsString('TESTSECRET', $history);
        $this->assertStringNotContainsString('probe@', $history);

        // The log viewer, its downloads and the reproduction package.
        $view = log_view::redact('GET ' . $uri . ' secret=TESTSECRET');
        $this->assertStringNotContainsString('TESTSECRET', $view);
        $this->assertStringNotContainsString('probe', $view);

        // Submitted form data in the debug trace.
        $submitted = debug_trace::submitted(['client_secret' => 'TESTSECRET', 'authorization' => 'TESTSECRET',
            'email' => 'probe@example.org', 'name' => 'kept']);
        $this->assertSame('[redacted]', $submitted['client_secret']);
        $this->assertSame('[redacted]', $submitted['authorization']);
        $this->assertSame('[redacted]', $submitted['email']);
        $this->assertSame('kept', $submitted['name']);
    }

    /**
     * A log field is one printable token of bounded length, and says when it was cut.
     *
     * @return void
     */
    public function test_a_field_is_one_bounded_token(): void {
        $this->assertSame('a??b?c', log_redactor::field("a\r\nb c"));
        $this->assertSame('?[31m', log_redactor::field("\x1b[31m"));
        $cut = log_redactor::field(str_repeat('x', 1000), 50);
        $this->assertLessThanOrEqual(50 + 8, strlen($cut));
        $this->assertMatchesRegularExpression('/^x+~cut\d+$/', $cut);
        $this->assertSame("ab", log_redactor::strip_controls("a\x00\x1b\x7f\xc2\x85\xe2\x80\xa8b"));
    }
}
