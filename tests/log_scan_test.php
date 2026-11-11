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

use local_catquizlab\local\log_scan;

/**
 * Existing logs are counted by secret name and line, never by value, and a copy can be written without them (#149).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\log_scan
 */
final class log_scan_test extends \advanced_testcase {
    /**
     * An old fatal line and an access log line are found, an unrelated line is left alone, and the copy has none.
     *
     * @return void
     */
    public function test_old_lines_are_counted_and_redacted(): void {
        $dir = make_request_directory();
        $log = $dir . '/error.log';
        file_put_contents($log, implode("\n", [
            '[Wed Nov 05 10:00:00 2026] local_catquizlab memory: phase=basis peak=512MB limit=512MB phases=start=20MB '
                . 'url=/local/catquizlab/results.php?experimentid=4&tab=global&sesskey=TESTSECRET&email=a%40b.de '
                . 'experimentid=4 tab=global',
            '[Wed Nov 05 10:00:01 2026] PHP Fatal error:  Allowed memory size of 536870912 bytes exhausted',
            '[Wed Nov 05 10:00:02 2026] another plugin token=OTHERVALUE',
            '1.2.3.4 - - "GET /local/catquizlab/logs.php?runid=51&download=1&ses%73key=TESTSECRET HTTP/1.1" 200',
            '',
        ]));
        file_put_contents($dir . '/access.log.gz', gzencode((string) file_get_contents($log)));

        foreach ([$log, $dir . '/access.log.gz'] as $file) {
            $entry = log_scan::scan_file($file, false, $dir . '/out');
            $this->assertSame(4, $entry['lines']);
            $this->assertSame(1, $entry['fatallines']);
            $this->assertSame(1, $entry['fatallineswithquery']);
            $this->assertSame(2, $entry['lineswithsecrets']);
            $this->assertSame([1, 4], $entry['linenumbers']);
            $this->assertSame(['(e-mail address)' => 1, 'sesskey' => 2], $entry['names']);
            $this->assertStringNotContainsString('TESTSECRET', json_encode($entry));
            $copy = (string) file_get_contents($entry['redactedcopy']);
            $this->assertStringNotContainsString('TESTSECRET', $copy);
            $this->assertStringNotContainsString('a%40b.de', $copy);
            $this->assertStringContainsString('url=/local/catquizlab/results.php experimentid=4', $copy);
            // Another plugin's line is not this plugin's to judge, unless every line is asked for.
            $this->assertStringContainsString('token=OTHERVALUE', $copy);
        }
        $this->assertSame(3, log_scan::scan_file($log, true)['lineswithsecrets']);
        $this->assertSame('not readable', log_scan::scan_file($dir . '/missing.log')['error']);
    }
}
