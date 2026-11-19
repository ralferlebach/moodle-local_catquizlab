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
    /**
     * The synthetic logs of tests/fixtures/logs149: every format the scan reads, rotated and gzip files, a line without
     * a readable time. Counts, categories and hours as expected.json says, and no value anywhere in the report.
     *
     * @return void
     */
    public function test_existing_logs_by_category_and_hour_never_by_value(): void {
        $dir = __DIR__ . '/fixtures/logs149';
        $expected = json_decode((string) file_get_contents($dir . '/expected.json'), true);
        $files = log_scan::files_in($dir);
        $this->assertSame(array_keys($expected['files']), array_map(
            static fn(string $file): string => substr($file, strlen($dir) + 1),
            $files
        ));

        $total = ['lines' => 0, 'lineswithsecrets' => 0, 'categories' => []];
        foreach ($files as $file) {
            $entry = log_scan::scan_file($file, true);
            $want = $expected['files'][substr($file, strlen($dir) + 1)];
            $this->assertSame($want['lineswithsecrets'], $entry['lineswithsecrets'], $file);
            $this->assertSame($want['categories'], $entry['categories'], $file);
            $this->assertSame($want['untimed'], $entry['untimed'], $file);
            $this->assertSame($want['fatallineswithquery'], $entry['fatallineswithquery'], $file);
            $this->assertStringNotContainsString('S3CR3T149', json_encode($entry), $file);
            // Every timed line with a secret is in one of its category's hours.
            foreach ($entry['windows'] as $category => $window) {
                $this->assertSame($entry['categories'][$category], array_sum($window['hours'])
                    + ($category === log_scan::CATEGORY_TOKEN ? $entry['untimed'] : 0), $file . ' ' . $category);
            }
            $total['lines'] += $entry['lines'];
            $total['lineswithsecrets'] += $entry['lineswithsecrets'];
            foreach ($entry['categories'] as $category => $count) {
                $total['categories'][$category] = ($total['categories'][$category] ?? 0) + $count;
            }
        }
        ksort($total['categories']);
        $this->assertSame([$expected['total']['lines'], $expected['total']['lineswithsecrets'],
            $expected['total']['categories']], [$total['lines'], $total['lineswithsecrets'], $total['categories']]);
    }

    /**
     * The time of a line in each format, in UTC; a line without one is null.
     *
     * @return void
     */
    public function test_the_time_of_a_line(): void {
        $this->assertSame('2026-11-05T10:00:00Z', gmdate(
            'Y-m-d\\TH:i:s\\Z',
            log_scan::line_time('[Wed Nov 05 10:00:00.123456 2026] [php:error] x')
        ));
        $this->assertSame('2026-11-06T08:15:02Z', gmdate(
            'Y-m-d\\TH:i:s\\Z',
            log_scan::line_time('10.0.0.5 - - [06/Nov/2026:09:15:02 +0100] "GET / HTTP/1.1"')
        ));
        $this->assertSame('2026-11-07T13:00:00Z', gmdate(
            'Y-m-d\\TH:i:s\\Z',
            log_scan::line_time('[07-Nov-2026 14:00:00 Europe/Berlin] PHP Warning: x')
        ));
        $this->assertSame('2026-11-08T07:00:00Z', gmdate(
            'Y-m-d\\TH:i:s\\Z',
            log_scan::line_time('2026-11-08T08:00:00+01:00 worker said x')
        ));
        $this->assertNull(log_scan::line_time('no time here token=x'));
    }

    /**
     * Each secret name in its category.
     *
     * @return void
     */
    public function test_categories(): void {
        $this->assertSame(
            [log_scan::CATEGORY_SESSION, log_scan::CATEGORY_SESSION, log_scan::CATEGORY_TOKEN,
            log_scan::CATEGORY_TOKEN, log_scan::CATEGORY_PASSWORD, log_scan::CATEGORY_EMAIL, log_scan::CATEGORY_EMAIL],
            array_map([log_scan::class, 'category'], ['sesskey', 'moodlesession', 'wstoken', 'apikey', 'password',
            '(e-mail address)',
            'email'])
        );
    }
}
