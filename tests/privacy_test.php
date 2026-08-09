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
 * Tests for the privacy provider.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\privacy\provider;

/**
 * Privacy provider tests.
 *
 * @covers \local_catquizlab\privacy\provider
 */
final class privacy_test extends \advanced_testcase {
    /**
     * The provider is a null provider and its reason resolves to a real string.
     *
     * @return void
     */
    public function test_null_provider_reason(): void {
        $this->assertInstanceOf(\core_privacy\local\metadata\null_provider::class, new provider());

        $reason = provider::get_reason();
        $this->assertSame('privacy:metadata', $reason);

        $resolved = get_string($reason, 'local_catquizlab');
        $this->assertNotEmpty($resolved);
        $this->assertStringNotContainsString('[[', $resolved, 'The privacy reason string must exist in the language pack.');
    }
}
