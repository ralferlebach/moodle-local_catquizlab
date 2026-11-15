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

use local_catquizlab\local\environment;
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\item_identity;
use local_catquizlab\local\run_orchestrator;

/**
 * The same items in every strategy cell, on a real setup with the engine (#154 DoD1, DoD4).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\item_identity
 * @covers     \local_catquizlab\local\materialiser
 */
final class item_identity_setup_test extends \advanced_testcase {
    /**
     * Five strategies, two replications, set up for real — the second replication in reverse order, one run set up
     * twice. Within a replication the pools are identical item by item, down to the question text; across
     * replications they are not; the comparison says so and the package carries it.
     *
     * @return void
     */
    public function test_a_real_setup_gives_every_strategy_the_same_items(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        if (!environment::engine_available() || !environment::adaptivequiz_available()) {
            $this->markTestSkipped('Needs local_catquiz and mod_adaptivequiz: the runs are set up as on a real site.');
        }
        $CFG->lock_factory = '\\core\\lock\\file_lock_factory';
        $course = $this->getDataGenerator()->create_course();
        set_config(\local_catquizlab\local\experiment_container::SETTING_COURSE, $course->id, 'local_catquizlab');

        $definition = experiment_definition::example_baseline();
        $definition['name'] = 'Identity 5 x 2';
        $definition['replications'] = 2;
        $definition['persons']['count'] = 2;
        $definition['pool']['scales'] = ['categories' => 2, 'subcategories' => 2, 'itemspersubscale' => 3];
        $definition['sweep']['factors']['strategy'] = ['classic', 'fastest', 'allsubs', 'highestsub', 'lowestsub'];
        $saved = experiment_service::save($definition);
        $runs = experiment_service::create_sweep((int) $saved['id'])['runs'];
        $this->assertCount(10, $runs);
        $replication = $DB->get_records_menu(
            'local_catquizlab_run',
            ['experimentid' => (int) $saved['id']],
            '',
            'id, replication'
        );
        // The first replication in creation order, the second the other way round: the order is no part of the pool.
        $first = array_values(array_filter($runs, static fn(int $id): bool => (int) $replication[$id] === 1));
        $second = array_reverse(array_values(array_filter($runs, static fn(int $id): bool => (int) $replication[$id] === 2)));
        foreach (array_merge($first, $second) as $runid) {
            $result = run_orchestrator::setup($runid);
            $this->assertTrue($result['ok'], 'run ' . $runid . ': ' . json_encode($result['reason'] ?? $result));
        }
        // A retry of a set-up run keeps its pool: the same keys, the same fingerprint.
        $before = $DB->get_field('local_catquizlab_pool', 'fingerprint', ['runid' => $first[0]]);
        $this->assertTrue(run_orchestrator::setup($first[0])['ok']);
        $this->assertSame($before, $DB->get_field('local_catquizlab_pool', 'fingerprint', ['runid' => $first[0]]));

        $report = item_identity::compare((int) $saved['id']);
        $this->assertTrue($report['ok'], json_encode($report['problems']));
        $this->assertSame([], $report['legacy']);
        $this->assertCount(2, $report['groups']);
        foreach ($report['groups'] as $group) {
            $this->assertCount(5, $group['runs']);
            $this->assertTrue($group['identical'], $group['label']);
            $this->assertSame([12], array_values(array_unique(array_column($group['runs'], 'distinct'))));
        }
        $this->assertNotSame($report['groups'][0]['fingerprint'], $report['groups'][1]['fingerprint']);

        // Down to what a person reads: the same question text and options per key in every run of a replication,
        // while every run has questions of its own.
        $texts = [];
        foreach ($first as $runid) {
            $rows = $DB->get_records_sql(
                'SELECT i.itemkey, q.id, q.questiontext
                   FROM {local_catquizlab_item} i
                   JOIN {question} q ON q.id = i.questionid
                  WHERE i.runid = :runid
               ORDER BY i.itemkey',
                ['runid' => $runid]
            );
            $texts[$runid] = array_map(static fn(\stdClass $r): string => $r->questiontext, $rows);
            $this->assertCount(12, $texts[$runid]);
        }
        $reference = reset($texts);
        foreach ($texts as $runid => $text) {
            $this->assertSame($reference, $text, 'run ' . $runid . ': the same text for the same item');
        }
        $questionids = $DB->get_fieldset_sql('SELECT questionid FROM {local_catquizlab_item} WHERE runid IN ('
            . implode(',', array_map('intval', $first)) . ')');
        $this->assertCount(60, array_unique($questionids), 'each run its own questions');

        // The package carries the comparison and each run's pool fingerprint and RNG schema.
        $package = \local_catquizlab\local\reproducibility::package((int) $saved['id'], false);
        $this->assertTrue($package['itemidentity']['ok']);
        foreach ($package['runs'] as $run) {
            $this->assertSame(1, $run['itempool']['schema']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $run['itempool']['fingerprint']);
            $this->assertSame(2, $run['rng']['schema']);
        }
    }
}
