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

use local_catquizlab\local\reason_catalog;
use local_catquizlab\local\run_log;

/**
 * Machine codes said in words where they reach a person; unknown ones kept as they are.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\reason_catalog
 * @covers     \local_catquizlab\local\run_log
 */
final class reason_text_test extends \advanced_testcase {
    /**
     * A code, a code with its detail, a step with its cause, an unknown code.
     *
     * @return void
     */
    public function test_codes_in_words(): void {
        $this->assertSame('Already queued.', reason_catalog::text('already-queued'));
        $this->assertSame(
            'The installation is not set up for runs yet: cron, worker.',
            reason_catalog::text('setup-incomplete: cron, worker')
        );
        $this->assertSame(
            'Step "items" did not complete: the step returned no result',
            reason_catalog::text('stage:materialise (stage-returned-no-result)')
        );
        $this->assertSame(
            'Step "test activity" did not complete: the pool is smaller than the minimum number of questions '
                . '(12 items for a minimum of 15)',
            reason_catalog::text('stage:test (pool-smaller-than-minimum-questions (12 items for a minimum of 15))')
        );
        // Not known: shown as it is, nothing hidden.
        $this->assertSame('something-new: detail', reason_catalog::text('something-new: detail'));
        $this->assertSame('Free text, not a code.', reason_catalog::text('Free text, not a code.'));
        $this->assertSame('', reason_catalog::text(''));
    }

    /**
     * Every event of the run log has words; an unknown one shows its code.
     *
     * @return void
     */
    public function test_every_run_log_event_has_words(): void {
        $events = array_filter(
            (new \ReflectionClass(run_log::class))->getConstants(),
            static fn($value, string $name): bool => is_string($value) && preg_match('/^[a-z_]+$/', $value)
                && !in_array($name, ['TABLE'], true),
            ARRAY_FILTER_USE_BOTH
        );
        $this->assertNotEmpty($events);
        foreach ($events as $event) {
            $this->assertNotSame($event, run_log::event_label($event), 'words for ' . $event);
        }
        $this->assertSame('not_an_event', run_log::event_label('not_an_event'));
    }
}
