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
 * Tests for the exporter.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\exporter;

/**
 * Exporter tests.
 *
 * @covers \local_catquizlab\local\exporter
 */
final class exporter_test extends \advanced_testcase {
    /** @var array Rows exercising special characters and types. */
    private const ROWS = [
        ['run' => 1, 'tier' => 'baseline', 'note' => 'a,b', 'ok' => true],
        ['run' => 2, 'tier' => 'shifted', 'note' => 'plain', 'ok' => false],
    ];

    /**
     * CSV writes a header, quotes fields needing it, and renders booleans.
     *
     * @return void
     */
    public function test_csv(): void {
        $csv = exporter::to_csv(self::ROWS);
        $lines = explode("\n", trim($csv));

        $this->assertSame('run,tier,note,ok', $lines[0]);
        $this->assertSame('1,baseline,"a,b",true', $lines[1]);
        $this->assertSame('2,shifted,plain,false', $lines[2]);
    }

    /**
     * CSV honours an explicit column selection and quotes embedded quotes.
     *
     * @return void
     */
    public function test_csv_columns_and_quoting(): void {
        $this->assertSame(
            "run,tier\n1,baseline\n2,shifted\n",
            exporter::to_csv(self::ROWS, ['run', 'tier'])
        );
        $this->assertSame(
            "v\n\"say \"\"hi\"\"\"\n",
            exporter::to_csv([['v' => 'say "hi"']])
        );
        $this->assertSame('', exporter::to_csv([]));
    }

    /**
     * JSON round-trips and leaves slashes unescaped.
     *
     * @return void
     */
    public function test_json(): void {
        $json = exporter::to_json(['runs' => self::ROWS]);
        $decoded = json_decode($json, true);

        $this->assertSame(2, count($decoded['runs']));
        $this->assertTrue($decoded['runs'][0]['ok']);
        $this->assertStringContainsString('http://x/y', exporter::to_json(['u' => 'http://x/y']));
    }

    /**
     * XML is well-formed, re-parseable and escapes special characters.
     *
     * @return void
     */
    public function test_xml(): void {
        $xml = exporter::to_xml(self::ROWS, 'runs', 'run');
        $parsed = simplexml_load_string($xml);

        $this->assertNotFalse($parsed);
        $this->assertCount(2, $parsed->run);

        $escaped = exporter::to_xml([['v' => '<a> & "b"']]);
        $reparsed = simplexml_load_string($escaped);
        $this->assertNotFalse($reparsed);
        $this->assertSame('<a> & "b"', (string) $reparsed->row->v);
    }

    /**
     * Invalid XML element names are sanitised to valid ones.
     *
     * @return void
     */
    public function test_xml_sanitises_names(): void {
        $xml = exporter::to_xml([['1bad key' => 'v']]);
        $this->assertNotFalse(simplexml_load_string($xml));
        $this->assertStringContainsString('<field_1bad_key>', $xml);
    }
}
