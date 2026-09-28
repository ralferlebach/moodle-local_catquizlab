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
 * What the engine actually holds for a run's test, against what it should hold.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Reads a provisioned test back from the engine and compares it (#101, #104).
 *
 * Provisioning writes settings; the engine stores them as it sees fit. The only
 * proof that a run tests what its definition says is the engine's own record of
 * the test — so that record is read back, field by field, and compared with
 * what this plugin intended to send.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provisioning_check {
    /**
     * The engine's stored settings for a run's test.
     *
     * @param int $runid The run.
     * @return array|null The decoded local_catquiz_tests json, or null when there is no test.
     */
    public static function engine_settings(int $runid): ?array {
        global $DB;

        $testcmid = (int) $DB->get_field('local_catquizlab_run', 'testcmid', ['id' => $runid]);
        if ($testcmid <= 0) {
            return null;
        }

        $instance = (int) $DB->get_field_sql(
            "SELECT cm.instance
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = 'adaptivequiz'
              WHERE cm.id = :cmid",
            ['cmid' => $testcmid]
        );
        if ($instance <= 0) {
            return null;
        }

        $json = $DB->get_field('local_catquiz_tests', 'json', [
            'componentid' => $instance,
            'component'   => test_binder::TEST_COMPONENT,
        ]);
        $settings = json_decode((string) $json, true);

        return is_array($settings) ? $settings : null;
    }

    /**
     * Compare a run's test in the engine with what its definition asks for.
     *
     * @param int $runid The run.
     * @return array{ok: bool, differences: string[], checked: array<string, array{expected: mixed, actual: mixed}>}
     */
    public static function compare(int $runid): array {
        global $DB;

        // The run's own definition, from where the run keeps it: the manifest's
        // config block, not the manifest itself.
        $record = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
        $definition = $record ? run_registry::definition_for($record) : null;
        $settings = self::engine_settings($runid);

        if (!is_array($definition) || $definition === [] || $settings === null) {
            return [
                'ok'          => false,
                'differences' => [get_string('check:notest', 'local_catquizlab')],
                'checked'     => [],
            ];
        }

        $options = test_provisioner::options_from_definition($definition);
        $expected = [
            'strategy'               => (int) $options['teststrategy'],
            'maxquestions'           => (int) $options['maxquestions'],
            'minquestions'           => (int) $options['minquestions'],
            'maxquestionspersubscale' => (int) $options['maxquestionspersubscale'],
            'minquestionspersubscale' => (int) $options['minquestionspersubscale'],
            'se_min'                 => round((float) $options['se_min'], 4),
            'se_max'                 => round((float) $options['se_max'], 4),
            'includepilot'           => !empty($options['includepilot']) ? 1 : 0,
        ];
        $actual = [
            'strategy'               => (int) ($settings['catquiz_selectteststrategy'] ?? 0),
            'maxquestions'           => (int) ($settings['maxquestionsgroup']['catquiz_maxquestions'] ?? PHP_INT_MIN),
            'minquestions'           => (int) ($settings['maxquestionsgroup']['catquiz_minquestions'] ?? PHP_INT_MIN),
            'maxquestionspersubscale' => (int) ($settings['maxquestionsscalegroup']['catquiz_maxquestionspersubscale']
                ?? PHP_INT_MIN),
            'minquestionspersubscale' => (int) ($settings['maxquestionsscalegroup']['catquiz_minquestionspersubscale']
                ?? PHP_INT_MIN),
            'se_min'                 => round((float) ($settings['catquiz_standarderrorgroup']['catquiz_standarderror_min']
                ?? -1), 4),
            'se_max'                 => round((float) ($settings['catquiz_standarderrorgroup']['catquiz_standarderror_max']
                ?? -1), 4),
            'includepilot'           => !empty($settings['catquiz_includepilotquestions']) ? 1 : 0,
        ];

        $differences = [];
        $checked = [];
        foreach ($expected as $field => $value) {
            $checked[$field] = ['expected' => $value, 'actual' => $actual[$field]];
            if ($actual[$field] !== $value) {
                $differences[] = get_string('check:difference', 'local_catquizlab', (object) [
                    'field'    => $field,
                    'expected' => json_encode($value),
                    'actual'   => json_encode($actual[$field]),
                ]);
            }
        }

        return ['ok' => $differences === [], 'differences' => $differences, 'checked' => $checked];
    }
}
