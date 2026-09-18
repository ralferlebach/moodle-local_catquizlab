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

namespace local_catquizlab\local;

/**
 * What has to be true before a run can be started.
 *
 * A run that is queued without an engine, without a course or without a worker
 * does not fail immediately — it sits in the queue looking started, and the
 * person who pressed the button learns nothing until they go looking. Checking
 * first turns a silent non-start into a sentence.
 *
 * The checks are split into blockers and warnings on purpose. A missing engine
 * makes provisioning impossible and must stop the start. A missing worker does
 * not: the run provisions fine and its attempts simply wait, which is a
 * legitimate state when a worker is started later.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preflight {
    /**
     * Run every check.
     *
     * @param \context|null $context The context the capability is checked in.
     * @return array{ok: bool, blockers: string[], warnings: string[], course: int}
     */
    public static function check(?\context $context = null): array {
        global $DB;

        $component = 'local_catquizlab';
        $context = $context ?? \context_system::instance();

        $blockers = [];
        $warnings = [];

        if (!environment::catquiz_available()) {
            $blockers[] = get_string('preflight:noengine', $component);
        }
        if (!environment::adaptivequiz_available()) {
            $blockers[] = get_string('preflight:noactivity', $component);
        }

        $courseid = (int) get_config($component, 'experimentcourseid');
        if ($courseid <= 0) {
            $blockers[] = get_string('preflight:nocourse', $component);
        } else if (!$DB->record_exists('course', ['id' => $courseid])) {
            // A course that was configured and later deleted is worse than none
            // at all: the setting looks right and the provisioning fails deep
            // inside a stage.
            $blockers[] = get_string('preflight:coursemissing', $component);
            $courseid = 0;
        }

        if (!has_capability('local/catquizlab:execute', $context)) {
            $blockers[] = get_string('preflight:nocapability', $component);
        }

        if (!self::worker_configured()) {
            // Not a blocker: the run provisions and its attempts wait for a
            // worker that may be started afterwards.
            $warnings[] = get_string('preflight:noworker', $component);
        }

        return [
            'ok'       => $blockers === [],
            'blockers' => $blockers,
            'warnings' => $warnings,
            'course'   => $courseid,
        ];
    }

    /**
     * Whether anything is configured that could play a queued attempt.
     *
     * @return bool
     */
    public static function worker_configured(): bool {
        global $DB;

        $component = 'local_catquizlab';

        // An external worker announces itself by having a token for the
        // worker web service; an exec worker by having a node binary set.
        if (trim((string) get_config($component, 'workernodepath')) !== '') {
            return true;
        }

        $service = $DB->get_record('external_services', ['shortname' => 'local_catquizlab_worker']);
        if ($service && (int) $service->enabled === 1) {
            return $DB->record_exists('external_tokens', ['externalserviceid' => (int) $service->id]);
        }

        return false;
    }

    /**
     * The blockers as one sentence, for a redirect message.
     *
     * @param array $result The result of {@see self::check()}.
     * @return string
     */
    public static function summary(array $result): string {
        return implode(' ', $result['blockers']);
    }
}
