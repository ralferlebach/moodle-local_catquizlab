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
 * Privacy provider for local_catquizlab.
 *
 * The stub stores experiment definitions only (name, tier, configuration) —
 * no personal data. NOTE for later milestones: as soon as the lab store gains
 * cohort/person tables (simulated users with ground-truth ability profiles)
 * or attempt traces referencing user ids, this must be replaced by a full
 * metadata/request provider. Tracked in docs/design/backlog.md (E0.2).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\privacy;

/**
 * Null privacy provider — the stub stores no personal data.
 */
class provider implements \core_privacy\local\metadata\null_provider {
    /**
     * Return the language string explaining why no data is stored.
     *
     * @return string
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
