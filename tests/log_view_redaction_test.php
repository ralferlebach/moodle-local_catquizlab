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

use local_catquizlab\local\log_view;
use local_catquizlab\local\run_log;

/**
 * Logs: no secrets in what is shown, copied or downloaded; the technical part folded; the same selection everywhere (#131).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\log_view
 */
final class log_view_redaction_test extends \advanced_testcase {
    /**
     * Tokens, session keys, passwords, cookies and authorisation headers are removed; the rest stays.
     *
     * @return void
     */
    public function test_secrets_are_removed(): void {
        $this->resetAfterTest();

        $cases = [
            'GET /webservice/rest/server.php?wstoken=0123456789abcdef0123456789abcdef&wsfunction=x'
                => 'GET /webservice/rest/server.php?wstoken=[redacted]&wsfunction=x',
            'POST /mod/adaptivequiz/attempt.php sesskey=AbC123xyz slot=4'
                => 'POST /mod/adaptivequiz/attempt.php sesskey=[redacted] slot=4',
            '{"username":"twin1","password":"S3cret!","token":"abc"}'
                => '{"username":"twin1","password":"[redacted]","token":"[redacted]"}',
            'Cookie: MoodleSession=deadbeef; other=1' => 'Cookie: [redacted]',
            'set MoodleSessionprod=deadbeef; path=/' => 'set MoodleSessionprod=[redacted]; path=/',
            'Authorization: Bearer eyJhbGciOi.payload.sig' => 'Authorization: Bearer [redacted]',
            'run=4 attempt#1 run_started' => 'run=4 attempt#1 run_started',
        ];
        foreach ($cases as $in => $out) {
            $this->assertSame($out, log_view::redact($in));
        }
    }

    /**
     * The page window, the TXT and the JSON download all carry the line without its secret, and say their time zone.
     *
     * @return void
     */
    public function test_every_output_is_redacted(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        run_log::record(77, run_log::RUN_FAILED, ['url' => 'https://x/login?token=abcdef0123456789&next=1']);
        $filter = ['since' => 0, 'runid' => 77];

        $window = log_view::window($filter, 10, 0);
        $this->assertCount(1, $window['lines']);
        $this->assertStringContainsString('token=[redacted]', $window['lines'][0]['text']);
        $this->assertStringNotContainsString('abcdef0123456789', $window['lines'][0]['text']);

        $txt = log_view::export($filter, 'txt');
        $json = json_decode(log_view::export($filter, 'json'), true);
        $this->assertStringNotContainsString('abcdef0123456789', $txt);
        $this->assertStringContainsString('# timezone:', $txt);
        $this->assertSame(1, $json['count']);
        $this->assertSame(usertimezone(), $json['timezone']);
        $this->assertStringNotContainsString('abcdef0123456789', json_encode($json));
        // The same line in all of them.
        $this->assertStringContainsString($window['lines'][0]['text'], $txt);
        $this->assertSame($window['lines'][0]['text'], $json['lines'][0]['text']);
    }

    /**
     * The technical part of a line is split off for folding; a short line stays whole.
     *
     * @return void
     */
    public function test_the_technical_part_is_split_off(): void {
        $this->resetAfterTest();

        [$head, $tail] = log_view::split_detail('run=4 attempt#1 run_failed {"error":"Division by zero","trace":"#0 x.php(7)"}');
        $this->assertSame('run=4 attempt#1 run_failed', $head);
        $this->assertStringStartsWith('{"error"', $tail);
        $this->assertSame(['run=4 started {"a":1}', ''], log_view::split_detail('run=4 started {"a":1}'));
        $this->assertSame(['plain line', ''], log_view::split_detail('plain line'));
    }
}
