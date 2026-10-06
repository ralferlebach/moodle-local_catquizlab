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

/**
 * Whether a sitting's result may go into the results.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Three questions about a sitting, kept apart (#118).
 *
 * Did the engine finish it? Did it end as the design planned — a stop rule met?
 * And may its result go into the figures? A low standard error after seven of
 * fifteen questions is an engine that finished, a design stop not reached and a
 * result that is not valid: the design does not allow an end there.
 *
 * The one place results ask this; #112 widens what it checks.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_validity {
    /** @var string Results of valid sittings only: the default of every aggregated view. */
    public const VALID = 'valid';

    /** @var string Results of invalid sittings only. */
    public const INVALID = 'invalid';

    /** @var string Every collected sitting, marked. */
    public const ALL = 'all';

    /**
     * Evaluate a collected sitting by its end.
     *
     * @param string $reasoncode Its end, as reason_catalog::outcome() classifies it.
     * @return array{valid: bool, reason: string, enginefinished: bool, designstop: bool, criterionstop: bool}
     */
    public static function evaluate(string $reasoncode): array {
        $invalid = in_array($reasoncode, reason_catalog::INVALID_OUTCOMES, true);

        return [
            'valid' => !$invalid,
            'reason' => $invalid ? $reasoncode : '',
            // A collected sitting is one the engine finished; failures are not collected.
            'enginefinished' => true,
            'designstop' => reason_catalog::is_design_stop($reasoncode),
            // The stop rules' success: a criterion met, not the maximum or exhaustion.
            'criterionstop' => reason_catalog::is_criterion_stop($reasoncode),
        ];
    }
}
