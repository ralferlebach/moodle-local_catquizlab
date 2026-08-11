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
 * Tests for the subscale evaluator (DPF diagnostics).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\subscale_evaluator;
use local_catquizlab\local\scale_provisioner;
use local_catquizlab\local\attempt_scheduler;

/**
 * Subscale evaluator tests.
 *
 * @covers \local_catquizlab\local\subscale_evaluator
 */
final class subscale_evaluator_test extends \advanced_testcase {
    /** @var array A 2x2 profile with global 0. */
    private const PROFILE = [
        'global'     => 0.0,
        'categories' => [
            ['index' => 1, 'theta' => 0.2, 'subscales' => [
                ['index' => 1, 'theta' => -1.0], ['index' => 2, 'theta' => 0.5]]],
            ['index' => 2, 'theta' => -0.3, 'subscales' => [
                ['index' => 1, 'theta' => 0.3], ['index' => 2, 'theta' => -0.8]]],
        ],
    ];

    /** @var array Engine scale id to profile key. */
    private const MAP = [101 => '1:1', 102 => '1:2', 201 => '2:1', 202 => '2:2'];

    /**
     * A perfect estimate recovers order and detects the below-global deficits.
     *
     * @return void
     */
    public function test_evaluate_person_perfect(): void {
        $perfect = [101 => -1.0, 102 => 0.5, 201 => 0.3, 202 => -0.8];
        $r = subscale_evaluator::evaluate_person(self::PROFILE, $perfect, self::MAP);

        $this->assertEqualsWithDelta(1.0, $r['spearman'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $r['topk'], 1e-9);
        // Deficits are subscales below global 0: 1:1 and 2:2.
        $this->assertSame([2, 0, 0, 2], $r['confusion']);
        $this->assertEqualsWithDelta(1.0, $r['recall'], 1e-9);
    }

    /**
     * Fewer than two aligned subscales yields null.
     *
     * @return void
     */
    public function test_evaluate_person_insufficient(): void {
        $this->assertNull(subscale_evaluator::evaluate_person(self::PROFILE, [101 => -1.0], self::MAP));
    }

    /**
     * A run aggregates its persons' DPF diagnostics and stores dpf_* rows.
     *
     * @return void
     */
    public function test_evaluate_run(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $now = time();

        // Scale map: 4 subscales.
        foreach (self::MAP as $catscaleid => $key) {
            [$c, $s] = explode(':', $key);
            $DB->insert_record('local_catquizlab_scalemap', (object) [
                'runid' => $run->id, 'catscaleid' => $catscaleid, 'parentcatscaleid' => 0, 'contextid' => 10,
                'level' => scale_provisioner::LEVEL_SUBSCALE, 'categoryindex' => (int) $c, 'subscaleindex' => (int) $s,
                'name' => $key, 'timecreated' => $now, 'timemodified' => $now,
            ]);
        }

        // Two persons with a perfect estimate trace.
        for ($i = 0; $i < 2; $i++) {
            $person = $generator->create_person([
                'runid' => $run->id, 'profilejson' => json_encode(self::PROFILE),
            ]);
            $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid' => $run->id, 'personid' => $person->id,
                'status' => attempt_scheduler::STATUS_COLLECTED,
                'tracejson' => json_encode([
                    'finaltheta' => 0.0, 'finalse' => 0.3, 'items' => ['q1'],
                    'scaleabilities' => [101 => -1.0, 102 => 0.5, 201 => 0.3, 202 => -0.8],
                ]),
                'timecreated' => $now, 'timemodified' => $now,
            ]);
        }

        $summary = subscale_evaluator::evaluate_run($run->id);

        $this->assertSame(2, $summary['n']);
        $this->assertEqualsWithDelta(1.0, $summary['spearman'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $summary['recall'], 1e-9);

        // The dpf_* rows are stored.
        $spearman = $DB->get_field(
            'local_catquizlab_result',
            'value',
            ['runid' => $run->id, 'scope' => 'dpf', 'metric' => 'dpf_spearman']
        );
        $this->assertEqualsWithDelta(1.0, (float) $spearman, 1e-9);
        $this->assertTrue($DB->record_exists(
            'local_catquizlab_result',
            ['runid' => $run->id, 'scope' => 'dpf', 'metric' => 'dpf_confusion']
        ));
    }
}
