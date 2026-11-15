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

use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\item_identity;
use local_catquizlab\local\materialiser;
use local_catquizlab\local\pool_mutator;
use local_catquizlab\local\pool_planner;
use local_catquizlab\local\question_template;
use local_catquizlab\local\scale_provisioner;
use local_catquizlab\local\seed_domains;

/**
 * The same item across runs, proven (#154).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\item_identity
 * @covers     \local_catquizlab\local\materialiser
 */
final class item_identity_test extends \advanced_testcase {
    /** @var int The master seed of the experiment. */
    protected const MASTER = 2026;

    /** @var string[] Five strategies: none of them is part of the pool. */
    protected const STRATEGIES = ['classic', 'fastest', 'allsubs', 'highestsub', 'lowestsub'];

    /**
     * A definition: 2 categories × 2 subscales × 50 items = 200 items.
     *
     * @param string $model The model.
     * @param string $variant The pool variant.
     * @return array
     */
    protected function definition(string $model, string $variant = 'ideal'): array {
        $definition = experiment_definition::example_baseline();
        $definition['model'] = $model;
        $definition['pool']['scales'] = ['categories' => 2, 'subcategories' => 2, 'itemspersubscale' => 50];
        $definition['pool']['variant'] = $variant;
        $definition['pool']['recipe'] = pool_mutator::recipe_defaults($variant);
        $validation = experiment_service::validate($definition);
        $this->assertTrue($validation['valid'], implode('; ', $validation['errors'] ?? []));

        return $validation['normalised'];
    }

    /**
     * A run's realised pool as materialise() would record it, without the engine: the scale map with this run's own
     * engine ids, the questions rendered with this run's id.
     *
     * @param array $definition The definition.
     * @param int $replication The replication.
     * @param int $runid A run id (only in the question's ID number, never in the item).
     * @param int $ids Offset of this run's engine scale and context ids.
     * @return array<string, array> Item key => spec with contentfp and fingerprint.
     */
    protected function realise(array $definition, int $replication, int $runid, int $ids): array {
        $model = (string) $definition['model'];
        $blueprint = pool_planner::plan($definition, seed_domains::pool(self::MASTER, $replication, $model));
        $variant = (string) $definition['pool']['variant'];
        $blueprint = pool_mutator::mutate(
            $blueprint,
            $variant,
            (array) $definition['pool']['recipe'],
            seed_domains::mutation(self::MASTER, $replication, $variant, $model)
        );
        $scalemap = [];
        foreach ([1, 2] as $c) {
            foreach ([1, 2] as $s) {
                $scalemap[] = ['level' => scale_provisioner::LEVEL_SUBSCALE, 'categoryindex' => $c, 'subscaleindex' => $s,
                    'catscaleid' => $ids + 10 * $c + $s, 'contextid' => $ids, 'name' => 'Run ' . $runid . ' K' . $c . '.' . $s];
            }
        }
        // The order a scale map comes back in is the database's: reversed here for every other run.
        if ($runid % 2) {
            $scalemap = array_reverse($scalemap);
        }
        $out = [];
        $polytomous = \local_catquizlab\local\model_catalog::is_polytomous($model);
        foreach (materialiser::plan_items($blueprint, $scalemap, ['model' => $model, 'replication' => $replication]) as $spec) {
            $rendered = question_template::render($spec + ['polytomous' => $polytomous, 'runid' => $runid]);
            $out[$spec['itemkey']] = $spec + item_identity::fingerprints($spec, $rendered);
        }
        ksort($out);

        return $out;
    }

    /**
     * The pool's fingerprint.
     *
     * @param array $items From realise().
     * @return string
     */
    protected function pool(array $items): string {
        return item_identity::pool_fingerprint(array_map(static fn(array $i): string => $i['fingerprint'], $items));
    }

    /**
     * DoD1, AK1, AK2, AK5: two replications, five strategies each, 2PL and GPCM. Within a replication every pool
     * is the same item for item, whatever the run's ids, its scale ids and the order its scale map comes in; the two
     * replications differ.
     *
     * @return void
     */
    public function test_five_strategies_share_the_pool_of_their_replication(): void {
        $this->resetAfterTest();
        foreach (['2pl', 'gpcm'] as $model) {
            $definition = $this->definition($model);
            $pools = [];
            $runid = 100;
            foreach ([1, 2] as $replication) {
                foreach (self::STRATEGIES as $i => $strategy) {
                    $runid++;
                    $items = $this->realise(
                        array_merge($definition, ['strategy' => $strategy]),
                        $replication,
                        $runid,
                        1000 * $runid
                    );
                    $this->assertCount(200, $items, $model);
                    $pools[$replication][$strategy] = $items;
                }
            }
            foreach ([1, 2] as $replication) {
                $first = $pools[$replication]['classic'];
                foreach ($pools[$replication] as $strategy => $items) {
                    $this->assertSame(array_keys($first), array_keys($items), "{$model} r{$replication} {$strategy}");
                    $this->assertSame(array_column($first, 'fingerprint'), array_column($items, 'fingerprint'));
                    $this->assertSame(array_column($first, 'contentfp'), array_column($items, 'contentfp'));
                    $this->assertSame($this->pool($first), $this->pool($items));
                }
            }
            // Another replication is another pool: other keys, another fingerprint.
            $this->assertSame([], array_intersect(array_keys($pools[1]['classic']), array_keys($pools[2]['classic'])));
            $this->assertNotSame($this->pool($pools[1]['classic']), $this->pool($pools[2]['classic']));
            $this->assertStringStartsWith('r001-' . $model . '-c01-s01-i0001', array_key_first($pools[1]['classic']));
        }
    }

    /**
     * DoD2, AK3: variants differ on purpose and only where they should. Errors of what the engine is told keep the
     * item's content and change its realised fingerprint; a shift changes the content; a depleted pool loses items.
     *
     * @return void
     */
    public function test_pool_variants_differ_where_they_should(): void {
        $this->resetAfterTest();
        $ideal = $this->realise($this->definition('2pl'), 1, 1, 1000);
        foreach (['calibrationerror' => 'miscalibrated', 'taggingerror' => 'mistagged'] as $variant => $flag) {
            $items = $this->realise($this->definition('2pl', $variant), 1, 2, 2000);
            $this->assertSame(array_keys($ideal), array_keys($items), $variant . ': the same items');
            $this->assertSame(
                array_column($ideal, 'contentfp'),
                array_column($items, 'contentfp'),
                $variant . ': the same content'
            );
            $touched = array_keys(array_filter($items, static fn(array $i): bool => !empty($i[$flag])));
            $this->assertNotEmpty($touched, $variant . ' changes some items');
            foreach ($items as $key => $item) {
                $this->assertSame(
                    in_array($key, $touched, true),
                    $ideal[$key]['fingerprint'] !== $item['fingerprint'],
                    $variant . ': realised fingerprint differs exactly where the error is injected (' . $key . ')'
                );
            }
        }
        $shifted = $this->realise($this->definition('2pl', 'shifted'), 1, 3, 3000);
        $this->assertSame(array_keys($ideal), array_keys($shifted));
        $this->assertSame(
            [],
            array_intersect(array_column($ideal, 'contentfp'), array_column($shifted, 'contentfp')),
            'shifted: every difficulty moved, so every content differs'
        );
        $depleted = $this->realise($this->definition('2pl', 'depleted'), 1, 4, 4000);
        $this->assertLessThan(count($ideal), count($depleted));
        $this->assertSame([], array_diff_key($depleted, $ideal), 'depleted: a subset of the same keys');
        foreach ($depleted as $key => $item) {
            $this->assertSame($ideal[$key]['contentfp'], $item['contentfp']);
        }
    }

    /**
     * AK3, AK4: the comparison of an experiment's runs. Identical groups say so; a run whose pool differs from its
     * group's is named with what differs; duplicates and runs from before the keys are said; variants are contrasted
     * against the ideal pool; nothing is reported for a difference the design makes.
     *
     * @return void
     */
    public function test_the_comparison_names_defects_and_design(): void {
        global $DB;

        $this->resetAfterTest();
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $experimentid = (int) $generator->create_experiment()->id;
        $store = function (
            array $definition,
            int $replication,
            string $strategy,
            ?callable $tamper = null
        ) use (
            $generator,
            $experimentid,
            $DB
        ): int {
            $definition['strategy'] = $strategy;
            $runid = (int) $generator->create_run(['experimentid' => $experimentid, 'replication' => $replication,
                'cellkey' => $strategy . '|r' . $replication . '|' . $definition['pool']['variant'],
                'manifestjson' => json_encode(['config' => ['definition' => $definition,
                    'strategy' => ['key' => $strategy]]])])->id;
            $items = $this->realise($definition, $replication, $runid, 1000 * $runid);
            if ($tamper !== null) {
                $items = $tamper($items);
            }
            $poolid = (int) $DB->insert_record('local_catquizlab_pool', (object) ['experimentid' => $experimentid,
                'runid' => $runid, 'variant' => $definition['pool']['variant'], 'timecreated' => 1, 'timemodified' => 1,
                'fingerprint' => $this->pool($items)]);
            $q = 0;
            foreach ($items as $item) {
                $DB->insert_record('local_catquizlab_item', (object) ['runid' => $runid, 'poolid' => $poolid,
                    'questionid' => $runid * 1000 + (++$q), 'itemname' => $item['itemname'], 'model' => $item['model'],
                    'itemkey' => $item['itemkey'], 'contentfp' => $item['contentfp'],
                    'fingerprint' => $item['fingerprint'], 'timecreated' => 1]);
            }

            return $runid;
        };
        $ideal = $this->definition('2pl');
        $a = $store($ideal, 1, 'classic');
        $store($ideal, 1, 'fastest');
        $store($ideal, 2, 'classic');
        $store($this->definition('2pl', 'calibrationerror'), 1, 'classic');

        $report = item_identity::compare($experimentid);
        $this->assertTrue($report['ok'], json_encode($report['problems']));
        $this->assertSame(['replication' => [1, 2], 'variant' => ['calibrationerror', 'ideal']], $report['factors']);
        $sharing = array_values(array_filter($report['groups'], static fn(array $g): bool => count($g['runs']) === 2));
        $this->assertCount(1, $sharing);
        $this->assertTrue($sharing[0]['identical']);
        // The variant against the ideal pool: the same 200 items, some told the engine otherwise, none removed.
        $this->assertCount(1, $report['contrasts']);
        $contrast = $report['contrasts'][0];
        $this->assertSame(0, $contrast['contentchanged'] + $contrast['removed'] + $contrast['added']);
        $this->assertGreaterThan(0, $contrast['realisedchanged']);
        $this->assertSame(200, $contrast['same'] + $contrast['realisedchanged']);

        // A third strategy whose pool came out different: one item changed, one missing. A defect, named.
        $broken = $store($ideal, 1, 'allsubs', static function (array $items): array {
            $keys = array_keys($items);
            $items[$keys[0]]['fingerprint'] = str_repeat('0', 64);
            unset($items[$keys[1]]);
            return $items;
        });
        $report = item_identity::compare($experimentid);
        $this->assertFalse($report['ok']);
        $this->assertCount(1, $report['problems']);
        $this->assertStringContainsString('Run ' . $broken . ' differs from run ' . $a, $report['problems'][0]);
        $this->assertStringContainsString('1 item(s) changed, 1 missing, 0 extra', $report['problems'][0]);

        // A run from before the keys: listed, not guessed.
        $legacy = (int) $generator->create_run(['experimentid' => $experimentid, 'replication' => 3,
            'manifestjson' => json_encode(['config' => ['definition' => $ideal]])])->id;
        $DB->insert_record('local_catquizlab_item', (object) ['runid' => $legacy, 'questionid' => 1, 'itemname' => 'x',
            'model' => '2pl', 'timecreated' => 1]);
        $this->assertContains($legacy, item_identity::compare($experimentid)['legacy']);
    }

    /**
     * DoD3: the exported item table joins run and question to the canonical key without the database.
     *
     * @return void
     */
    public function test_the_item_export_carries_the_keys(): void {
        global $DB;

        $this->resetAfterTest();
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $experimentid = (int) $generator->create_experiment()->id;
        $runid = (int) $generator->create_run(['experimentid' => $experimentid, 'replication' => 2])->id;
        $poolid = (int) $DB->insert_record('local_catquizlab_pool', (object) ['experimentid' => $experimentid,
            'runid' => $runid, 'variant' => 'ideal', 'timecreated' => 1, 'timemodified' => 1]);
        $DB->insert_record('local_catquizlab_item', (object) ['runid' => $runid, 'poolid' => $poolid, 'questionid' => 77,
            'itemname' => 'Q', 'model' => '2pl', 'itemkey' => 'r002-2pl-c01-s01-i0001', 'contentfp' => str_repeat('a', 64),
            'fingerprint' => str_repeat('b', 64), 'timecreated' => 1]);
        $export = \local_catquizlab\local\export_dataset::items([$runid]);
        foreach (['replication', 'poolvariant', 'itemkey', 'contentfp', 'fingerprint'] as $column) {
            $this->assertContains($column, $export['columns']);
        }
        $row = $export['rows'][0];
        $this->assertSame(
            [$runid, 77, 'r002-2pl-c01-s01-i0001', 2, 'ideal'],
            [$row['runid'], $row['questionid'], $row['itemkey'], $row['replication'], $row['poolvariant']]
        );
    }
}
