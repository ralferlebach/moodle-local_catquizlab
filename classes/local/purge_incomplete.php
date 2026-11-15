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
 * A deep deletion that could not be completed as it must be (#161).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Thrown when deleting a run deeply leaves something of it in the engine or the host, takes something of somebody
 * else's, or its activity cannot be deleted. The run, its lab rows and its experiment stay; the message names what.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purge_incomplete extends \moodle_exception {
    /** @var int The run. */
    public int $runid;

    /** @var array From engine_footprint::check(): left, lost, ok. */
    public array $check;

    /**
     * The failure, named.
     *
     * @param int $runid The run.
     * @param array $check What was left and what was lost (engine_footprint::check()).
     * @param string $cause The error that stopped it, if one did.
     */
    public function __construct(int $runid, array $check, string $cause = '') {
        $this->runid = $runid;
        $this->check = $check;
        $a = (object) [
            'run' => $runid,
            'left' => engine_footprint::line($check['left'] ?? []) ?: '-',
            'lost' => engine_footprint::line($check['lost'] ?? []) ?: '-',
            'cause' => $cause !== '' ? $cause : '-',
        ];
        parent::__construct(
            ($check['lost'] ?? []) !== [] ? 'purge:foreignlost' : 'purge:engineleft',
            'local_catquizlab',
            '',
            $a,
            $cause
        );
    }
}
