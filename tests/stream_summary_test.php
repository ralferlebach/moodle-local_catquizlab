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

use local_catquizlab\local\stream_summary;

/**
 * The streaming summary grows with the sittings, not with their square (re-audit 2026-11-09).
 *
 * Its value buffers were built as "$a = ($a ?? '') . $b", which copies the whole buffer on every sitting: the
 * overview took 0.7 s for 9,000 sittings and 18 s for 50,000. Measured here on the summary alone, in milliseconds,
 * without a database.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\stream_summary
 */
final class stream_summary_test extends \advanced_testcase {
    /**
     * Seconds to add n sittings.
     *
     * @param int $n How many.
     * @return float
     */
    protected function time_to_add(int $n): float {
        $summary = new stream_summary();
        $started = microtime(true);
        for ($i = 0; $i < $n; $i++) {
            $summary->add(['attemptid' => $i + 1, 'runid' => 1, 'nitems' => 35, 'se' => 0.3, 'error' => sin($i),
                'runtimems' => 4000, 'truetheta' => 0.1, 'esttheta' => 0.1 + sin($i)]);
        }
        $took = microtime(true) - $started;
        $this->assertSame($n, $summary->count());
        $error = $summary->describe('error');
        $this->assertSame($n, $error['n'], 'every value kept');
        $this->assertSame($n, $error['units'], 'every sitting its own unit');

        return $took;
    }

    /**
     * 200,000 sittings take at most three times their linear share of 20,000, and every value is kept.
     *
     * @return void
     */
    public function test_adding_grows_linearly(): void {
        $this->time_to_add(2000);
        $small = $this->time_to_add(20000);
        $large = $this->time_to_add(200000);

        $this->assertLessThan(30.0 * max($small, 0.001), $large, sprintf(
            '20,000 sittings %.3f s, 200,000 sittings %.3f s: grows faster than the sittings',
            $small,
            $large
        ));
    }
}
