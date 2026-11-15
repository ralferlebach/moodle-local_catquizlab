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

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\item_identity;
use local_catquizlab\local\materialiser;
use local_catquizlab\local\person_generator;
use local_catquizlab\local\pool_mutator;
use local_catquizlab\local\pool_planner;
use local_catquizlab\local\question_template;
use local_catquizlab\local\registry;
use local_catquizlab\local\research_codebook;
use local_catquizlab\local\research_package;
use local_catquizlab\local\results_export;
use local_catquizlab\local\results_query;
use local_catquizlab\local\scale_provisioner;
use local_catquizlab\local\seed_domains;
use local_catquizlab\local\twin_response;

/**
 * The research data package (#156): complete, joinable without the database, counted and checksummed.
 *
 * An experiment as DoD1 asks for it: three strategies × two replications × two pool variants, twelve twins per run,
 * sittings of different lengths, valid, invalid and unknown ones, and twins without a sitting. Runs, persons and items
 * are built as the setup builds them (create_sweep(), person_generator, the materialiser's plan, its fingerprints);
 * the sittings' answers are what the twin answers.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\research_package
 * @covers     \local_catquizlab\local\research_codebook
 */
final class research_package_test extends \advanced_testcase {
    /** @var int Twins per run. */
    protected const TWINS = 12;

    /**
     * Build the experiment.
     *
     * @param int $sittings How many of the twins sit the test, per run.
     * @param string $model The model.
     * @param array $persons Overrides of the persons block (stratum, severity).
     * @param string[] $strategies Three strategies.
     * @return array{experimentid: int, runs: int[], truth: array} truth: per attempt [finaltheta, finalse].
     */
    protected function experiment(
        int $sittings = 10,
        string $model = '2pl',
        array $persons = [],
        array $strategies = ['classic', 'fastest', 'allsubs']
    ): array {
        global $DB;

        $definition = experiment_definition::example_baseline();
        $definition['name'] = 'Package ' . $sittings . ' ' . $model . ' ' . json_encode($persons);
        $definition['model'] = $model;
        $definition['replications'] = 2;
        $definition['persons'] = array_merge($definition['persons'], ['count' => self::TWINS], $persons);
        $definition['pool']['scales'] = ['categories' => 2, 'subcategories' => 2, 'itemspersubscale' => 10];
        $definition['sweep']['factors']['strategy'] = $strategies;
        $definition['sweep']['factors']['variant'] = ['ideal', 'shifted'];
        $saved = experiment_service::save($definition);
        $experimentid = (int) $saved['id'];
        $sweep = experiment_service::create_sweep($experimentid);
        $truth = [];
        foreach ($sweep['runs'] as $n => $runid) {
            $run = $DB->get_record('local_catquizlab_run', ['id' => $runid], 'id, masterseed, replication, manifestjson');
            $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_FINISHED, ['id' => $runid]);
            $def = json_decode($run->manifestjson, true)['config']['definition'];
            $master = (int) $run->masterseed;
            $rep = (int) $run->replication;
            $model = (string) $def['model'];
            $variant = (string) $def['pool']['variant'];
            $scalemap = [];
            foreach ([1, 2] as $c) {
                foreach ([1, 2] as $s) {
                    $catscaleid = $runid * 100 + $c * 10 + $s;
                    $DB->insert_record('local_catquizlab_scalemap', (object) ['runid' => $runid,
                        'level' => scale_provisioner::LEVEL_SUBSCALE, 'catscaleid' => $catscaleid,
                        'parentcatscaleid' => $runid * 100 + $c, 'contextid' => 1, 'nodekey' => 'c' . $c . 's' . $s,
                        'generation' => 1, 'categoryindex' => $c, 'subscaleindex' => $s, 'name' => 'K' . $c . '.' . $s,
                        'timecreated' => 1]);
                    $scalemap[] = ['level' => scale_provisioner::LEVEL_SUBSCALE, 'categoryindex' => $c, 'subscaleindex' => $s,
                        'catscaleid' => $catscaleid, 'contextid' => 1, 'name' => 'Run ' . $runid];
                }
            }
            // The pool as the materialiser plans, mutates, renders and fingerprints it.
            $blueprint = pool_mutator::mutate(
                pool_planner::plan($def, seed_domains::pool($master, $rep, $model)),
                $variant,
                (array) ($def['pool']['recipe'] ?? []),
                seed_domains::mutation($master, $rep, $variant, $model)
            );
            $poolid = $DB->insert_record('local_catquizlab_pool', (object) ['experimentid' => $experimentid, 'runid' => $runid,
                'variant' => $variant, 'timecreated' => 1, 'timemodified' => 1]);
            $items = [];
            $poolitems = [];
            $q = 0;
            foreach (materialiser::plan_items($blueprint, $scalemap, ['model' => $model, 'replication' => $rep]) as $spec) {
                $spec['runid'] = $runid;
                $spec += item_identity::fingerprints($spec, question_template::render($spec));
                $item = (object) ['runid' => $runid, 'poolid' => $poolid, 'questionid' => $runid * 1000 + (++$q),
                    'itemname' => $spec['itemname'], 'itemkey' => $spec['itemkey'], 'contentfp' => $spec['contentfp'],
                    'fingerprint' => $spec['fingerprint'], 'model' => $spec['model'],
                    'truedifficulty' => $spec['truedifficulty'], 'storeddifficulty' => $spec['difficulty'],
                    'discrimination' => $spec['discrimination'], 'guessing' => $spec['guessing'],
                    'stepsjson' => isset($spec['steps']) ? json_encode($spec['steps']) : null,
                    'truecategory' => $spec['truecategory'], 'truesubscale' => $spec['truesubscale'],
                    'truecatscaleid' => $spec['truecatscaleid'], 'miscalibrated' => (int) $spec['miscalibrated'],
                    'mistagged' => (int) $spec['mistagged'],
                    'assignedcatscaleid' => $spec['catscaleid'], 'timecreated' => 1];
                $item->id = $DB->insert_record('local_catquizlab_item', $item);
                $items[] = $item;
                $poolitems[$item->itemkey] = $item->fingerprint;
            }
            $DB->update_record('local_catquizlab_pool', (object) ['id' => $poolid, 'itemcount' => count($items),
                'fingerprint' => item_identity::pool_fingerprint($poolitems)]);

            $generated = person_generator::generate($def, seed_domains::person_base($master, $rep), ['replication' => $rep,
                'deviationseed' => seed_domains::person_deviation(
                    $master,
                    $rep,
                    (string) $def['persons']['stratum'],
                    (string) $def['persons']['severity']
                )]);
            foreach (array_values($generated) as $i => $p) {
                $person = (object) ['runid' => $runid, 'twinid' => $p['twinid'], 'twinindex' => $i,
                    'severity' => $p['severity'] ?? 'none', 'stratum' => $p['stratum'], 'abilityglobal' => $p['abilityglobal'],
                    'profilejson' => json_encode($p['profile']), 'timecreated' => 1, 'timemodified' => 1];
                $person->id = $DB->insert_record('local_catquizlab_person', $person);
                if ($i >= $sittings) {
                    // A twin without a sitting: in twins, never in attempts.
                    continue;
                }
                // Mixed lengths; the order differs per strategy cell.
                $length = 12 + ($i + $n) % 7;
                // Round robin over the subscales, so that a sitting has several subscale results to rank.
                $order = $items;
                usort($order, static fn(\stdClass $a, \stdClass $b): int => [substr($a->itemkey, -4), $a->itemkey]
                    <=> [substr($b->itemkey, -4), $b->itemkey]);
                $played = array_slice($n % 2 ? array_reverse($order) : $order, $i % 3, $length);
                $responses = [];
                foreach ($played as $item) {
                    $responses[(int) $item->questionid] = ['questionid' => (string) $item->questionid,
                        'slot' => count($responses) + 1, 'timecreated' => 1000 + count($responses),
                        'fraction' => twin_response::answer($run, $person, $item, [], (int) $item->questionid)['fraction']];
                }
                $theta = round(sin($person->id), 6);
                $se = 0.3 + ($i % 4) / 10;
                $trace = ['items' => array_keys($responses), 'nitems' => count($responses), 'steps' => count($responses),
                    // Each subscale its own estimate, so that the subscale figures and rankings have something to rank.
                    'scaleabilities' => array_combine(
                        array_map(static fn(array $m): int => $m['catscaleid'], $scalemap),
                        array_map(
                            static fn(array $m): float => round($theta + 0.4 * sin($person->id * $m['catscaleid']), 6),
                            $scalemap
                        )
                    ),
                    'scalestandarderrors' => array_fill_keys(
                        array_map(static fn(array $m): int => $m['catscaleid'], $scalemap),
                        $se
                    ),
                    'progress' => ['playedquestions' => array_map(
                        static fn(int $id): array => ['id' => $id],
                        array_keys($responses)
                    ), 'responses' => $responses]];
                if ($i === 0) {
                    // Invalid: the engine ended on an error of its own.
                    $trace += ['finaltheta' => $theta, 'finalse' => $se, 'stopreason' => 'errorfetchnextquestion'];
                } else if ($i === 1) {
                    // No final ability.
                    $trace += ['stopreason' => 'maxitems'];
                } else {
                    $trace += ['finaltheta' => $theta, 'finalse' => $se, 'stopreason' => 'se'];
                }
                $attemptid = $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $runid,
                    'personid' => $person->id, 'status' => attempt_scheduler::STATUS_COLLECTED, 'tries' => 1 + $i % 2,
                    'runtimems' => 1000 + $i, 'timecreated' => 1, 'timemodified' => 1, 'tracejson' => json_encode($trace)]);
                $truth[$attemptid] = [$trace['finaltheta'] ?? null, $trace['finalse'] ?? null];
            }
        }

        return ['experimentid' => $experimentid, 'runs' => $sweep['runs'], 'truth' => $truth];
    }

    /**
     * Read a package: its manifest, codebook and every table as rows by column.
     *
     * @param string $path The ZIP.
     * @return array{manifest: array, codebook: array, tables: array<string, array[]>, raw: array<string, string>}
     */
    protected function read(string $path): array {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $codebook = json_decode((string) $zip->getFromName('codebook.json'), true);
        $tables = [];
        $raw = [];
        foreach ($manifest['tables'] as $table => $entry) {
            $content = (string) $zip->getFromName($entry['file']);
            $raw[$table] = $content;
            $handle = fopen('php://memory', 'w+b');
            fwrite($handle, $content);
            rewind($handle);
            $header = fgetcsv($handle, null, ',', '"', '');
            $rows = [];
            while (($line = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $rows[] = array_combine($header, $line);
            }
            fclose($handle);
            $tables[$table] = ['header' => $header, 'rows' => $rows];
        }
        $this->assertNotFalse($zip->getFromName('reproducibility.json'));
        $this->assertNotFalse($zip->getFromName('README.md'));
        $zip->close();

        return ['manifest' => $manifest, 'codebook' => $codebook, 'tables' => $tables, 'raw' => $raw];
    }

    /**
     * DoD1–DoD3: every table there, counted and checksummed as the manifest says, every key joining, one potential
     * answer per twin and item, the administered answers reconstructable from them, the final estimates as recorded,
     * the population as the results page counts it, and the forecast equal to what was written.
     *
     * @return void
     */
    public function test_the_package_is_complete_and_joins_without_the_database(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $e = $this->experiment();
        $filter = ['experimentid' => $e['experimentid']];
        $forecast = research_package::forecast($filter);
        $path = make_request_directory() . '/package.zip';
        research_package::write_file($filter, $path);
        $p = $this->read($path);
        $t = array_map(static fn(array $table): array => $table['rows'], $p['tables']);

        // The manifest: schema, every table, its rows, its columns, its checksum; the forecast said the same.
        $this->assertSame(research_package::SCHEMA, $p['manifest']['schema']);
        $this->assertSame(array_keys(research_codebook::tables()), array_keys($p['manifest']['tables']));
        foreach ($p['manifest']['tables'] as $table => $entry) {
            $this->assertSame($entry['rows'], count($t[$table]), $table);
            $this->assertSame($entry['columns'], $p['tables'][$table]['header'], $table);
            $this->assertSame($entry['sha256'], hash('sha256', $p['raw'][$table]), $table);
            // Exact where the forecast says so; otherwise an upper bound.
            if ($forecast['exact'][$table]) {
                $this->assertSame($entry['rows'], $forecast['tables'][$table], 'forecast of ' . $table);
            } else {
                $this->assertLessThanOrEqual($forecast['tables'][$table], $entry['rows'], 'bound of ' . $table);
                $this->assertGreaterThan(0, $entry['rows'], $table);
            }
            // The codebook names every column.
            $this->assertSame($entry['columns'], array_column($p['codebook']['tables'][$table]['columns'], 'name'));
        }
        $this->assertCount(12, $t['runs']);
        $this->assertCount(12 * self::TWINS, $t['twins']);
        $this->assertCount(12 * 10, $t['attempts'], 'two twins per run without a sitting');
        $this->assertCount(12 * self::TWINS * 40, $t['twin_item_responses']);

        // Joins, without the database.
        $index = static function (array $rows, array $columns): array {
            $out = [];
            foreach ($rows as $row) {
                $out[implode('|', array_map(static fn(string $c): string => $row[$c], $columns))][] = $row;
            }
            return $out;
        };
        $runs = $index($t['runs'], ['runid']);
        $twins = $index($t['twins'], ['runid', 'personid']);
        $items = $index($t['items'], ['runid', 'questionid']);
        $attempts = $index($t['attempts'], ['attemptid']);
        $this->assertSame([(string) $e['experimentid']], array_values(array_unique(array_column($t['runs'], 'experimentid'))));
        foreach (['items', 'twins', 'truth_profiles', 'twin_item_responses', 'attempts'] as $table) {
            foreach ($t[$table] as $row) {
                $this->assertArrayHasKey($row['runid'], $runs, $table);
            }
        }
        foreach (['truth_profiles', 'twin_item_responses', 'attempts'] as $table) {
            foreach ($t[$table] as $row) {
                $this->assertArrayHasKey($row['runid'] . '|' . $row['personid'], $twins, $table);
            }
        }
        foreach (['twin_item_responses', 'steps'] as $table) {
            foreach ($t[$table] as $row) {
                $this->assertArrayHasKey($row['runid'] . '|' . $row['questionid'], $items, $table);
                $this->assertSame($items[$row['runid'] . '|' . $row['questionid']][0]['itemkey'], $row['itemkey']);
            }
        }
        foreach (['steps', 'estimated_profiles', 'validity', 'analysis_population'] as $table) {
            foreach ($t[$table] as $row) {
                $this->assertArrayHasKey($row['attemptid'], $attempts, $table);
            }
        }
        $this->assertSame(array_keys($attempts), array_keys($index($t['validity'], ['attemptid'])));
        $this->assertSame(array_keys($attempts), array_keys($index($t['analysis_population'], ['attemptid'])));
        $this->assertNotEmpty($t['estimated_profiles']);

        // One potential answer per twin and item of its run's pool, and the administered ones are those answers.
        $potential = $index($t['twin_item_responses'], ['runid', 'twinid', 'itemkey']);
        $this->assertCount(count($t['twin_item_responses']), $potential, 'exactly one per twin and item');
        $steps = 0;
        foreach ($t['steps'] as $step) {
            $row = $potential[$step['runid'] . '|' . $step['twinid'] . '|' . $step['itemkey']][0];
            $this->assertSame($row['responsecategory'], $step['responsecategory']);
            $this->assertEqualsWithDelta((float) $row['potentialfraction'], (float) $step['score'], 1e-6);
            $this->assertSame($row['uniform'], $step['uniform']);
            $steps++;
        }
        $this->assertGreaterThan(12 * 10 * 12 - 1, $steps);
        // Across the runs of a replication: the same twin, the same item, the same potential answer.
        foreach ($index($t['twin_item_responses'], ['replication', 'twinid', 'itemkey']) as $rows) {
            $byvariant = [];
            foreach ($rows as $row) {
                $byvariant[$runs[$row['runid']][0]['variant']][] = $row['responsecategory'] . '|' . $row['uniform'];
            }
            foreach ($byvariant as $answers) {
                $this->assertCount(1, array_unique($answers));
            }
            $this->assertCount(1, array_unique(array_column($rows, 'uniform')), 'one random number per twin and item');
        }

        // The final estimates as recorded; a missing one is empty, never 0.
        foreach ($t['attempts'] as $row) {
            [$theta, $se] = $e['truth'][(int) $row['attemptid']];
            $this->assertSame($theta === null ? '' : (string) $theta, $theta === null ? $row['esttheta']
                : (string) (float) $row['esttheta']);
            if ($se !== null) {
                $this->assertEqualsWithDelta($se, (float) $row['se'], 1e-9);
            }
        }
        $states = array_count_values(array_column($t['attempts'], 'validitystate'));
        $this->assertArrayHasKey('valid', $states);
        $this->assertGreaterThanOrEqual(2, count($states), 'valid and not valid: ' . json_encode($states));

        // The population is what the results page counts under the filter.
        $counted = [];
        foreach ((new results_query($filter))->each_observation() as $observation) {
            $counted[] = (string) $observation['attemptid'];
        }
        $in = array_column(
            array_filter($t['analysis_population'], static fn(array $r): bool => $r['inpopulation'] === '1'),
            'attemptid'
        );
        sort($counted);
        sort($in);
        $this->assertSame($counted, $in);
        foreach ($t['analysis_population'] as $row) {
            $this->assertSame(
                $row['inpopulation'] === '1' ? '' : $attempts[$row['attemptid']][0]['validitystate'],
                $row['reason']
            );
        }

        // Runs: the same pool within a replication and variant, whatever the strategy; schema 2.
        foreach ($index($t['runs'], ['replication', 'variant']) as $group) {
            $this->assertCount(3, $group);
            $this->assertCount(1, array_unique(array_column($group, 'poolfingerprint')));
            $this->assertSame(['2'], array_values(array_unique(array_column($group, 'rngschema'))));
        }
        $this->assertCount(4, array_unique(array_column($t['runs'], 'poolfingerprint')));
    }

    /**
     * DoD3: the query count does not grow with the sittings — no query per sitting, per step or per twin; only with the
     * batches they are read in.
     *
     * @return void
     */
    public function test_no_query_per_sitting(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $counts = [];
        foreach ([4, 10] as $sittings) {
            $e = $this->experiment($sittings);
            // Built twice, counted the second time: the first warms the request's caches (strings, config).
            research_package::write_file(['experimentid' => $e['experimentid']], make_request_directory() . '/p.zip');
            $before = $DB->perf_get_queries();
            research_package::write_file(['experimentid' => $e['experimentid']], make_request_directory() . '/p.zip');
            $counts[$sittings] = $DB->perf_get_queries() - $before;
        }
        // 72 sittings more (12 runs × 6). A query per sitting, step or twin would add at least 72; what may grow is
        // the number of batches the sittings are read in (bounded by sittings and bytes of trace, #147).
        $this->assertLessThan(8, $counts[10] - $counts[4], json_encode($counts));
    }

    /**
     * AK7: missing is empty, never 0; NaN and Inf as such; booleans 0/1; numbers exact.
     *
     * @return void
     */
    public function test_cells_say_what_they_are(): void {
        $this->assertSame('', research_package::cell(null));
        $this->assertSame('0', research_package::cell(0));
        $this->assertSame('0.0', research_package::cell(0.0));
        $this->assertSame('1', research_package::cell(true));
        $this->assertSame('0', research_package::cell(false));
        $this->assertSame('NaN', research_package::cell(NAN));
        $this->assertSame('Inf', research_package::cell(INF));
        $this->assertSame('-Inf', research_package::cell(-INF));
        $this->assertSame(0.1 + 0.2, (float) research_package::cell(0.1 + 0.2), 'reads back exactly');
        $this->assertSame('["a",1]', research_package::cell(['a', 1]));
    }

    /**
     * AK7: the codebook defines every column of every table, in English and German, with a known type.
     *
     * @return void
     */
    public function test_the_codebook_defines_every_column(): void {
        $definitions = research_codebook::columns();
        $types = research_package::codebook()['types'];
        foreach (research_package::columns() as $table => $columns) {
            $this->assertSame($columns, array_values(array_unique($columns)), $table . ': no column twice');
            foreach ($columns as $column) {
                $this->assertArrayHasKey($column, $definitions, $table . '.' . $column);
                $this->assertContains($definitions[$column]['type'], $types);
                $this->assertNotSame('', $definitions[$column]['en']);
                $this->assertNotSame('', $definitions[$column]['de']);
            }
            foreach (research_codebook::tables()[$table]['key'] as $key) {
                $this->assertContains($key, $columns, $table . ' key');
            }
        }
        $used = array_merge(...array_values(research_package::columns()));
        $this->assertSame([], array_values(array_diff(array_keys($definitions), $used)), 'no definition without a column');
    }
    /**
     * AK6: a package built in the background is stored for download, listed with its experiment, and served only to
     * whoever may export.
     *
     * @return void
     */
    public function test_a_package_built_in_the_background_is_kept(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/catquizlab/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $e = $this->experiment(3);
        $filter = ['experimentid' => $e['experimentid'], 'validity' => 'valid'];
        \local_catquizlab\task\build_research_package::queue($filter, (int) get_admin()->id);
        $this->assertCount(1, \local_catquizlab\task\build_research_package::pending($e['experimentid']));
        $this->assertSame([], \local_catquizlab\task\build_research_package::pending($e['experimentid'] + 1));
        ob_start();
        $this->runAdhocTasks(\local_catquizlab\task\build_research_package::class);
        ob_end_clean();
        $this->assertSame([], \local_catquizlab\task\build_research_package::pending($e['experimentid']));
        $stored = \local_catquizlab\task\build_research_package::stored($e['experimentid']);
        $this->assertCount(1, $stored);
        $path = make_request_directory() . '/stored.zip';
        $stored[0]->copy_content_to($path);
        $p = $this->read($path);
        $this->assertSame($filter, $p['manifest']['filter'], 'the filter it was asked with, keys and all');
        $this->assertSame(12 * 3, $p['manifest']['tables']['attempts']['rows']);

        // Served by the plugin's file handler to whoever may export, and to nobody else.
        $user = $this->getDataGenerator()->create_user();
        $role = $this->getDataGenerator()->create_role();
        assign_capability('local/catquizlab:view', CAP_ALLOW, $role, \context_system::instance());
        role_assign($role, $user->id, \context_system::instance());
        $this->setUser($user);
        $this->expectException(\required_capability_exception::class);
        local_catquizlab_pluginfile(null, null, \context_system::instance(), 'export', [0, $stored[0]->get_filename()], true);
    }
    /**
     * The R re-analysis (#157 DoD2, DoD3): run on the package of a 2PL and a GPCM experiment (three strategies, two
     * replications, two pool variants, two strata, sittings of different lengths, valid and not), it recomputes every
     * figure it covers from the raw tables and finds no difference to the dashboard's; a figure changed in the data
     * makes it fail, and so does a table that is not the one the manifest names.
     *
     * Needs Rscript (CATQUIZLAB_RSCRIPT or on the path). Skipped without it, unless CATQUIZLAB_REQUIRE_R is set, as in
     * CI, where a missing R fails the test.
     *
     * @return void
     */
    public function test_the_r_reanalysis_reproduces_the_dashboard(): void {
        $rscript = (string) (getenv('CATQUIZLAB_RSCRIPT') ?: trim((string) shell_exec('command -v Rscript 2>/dev/null')));
        if ($rscript === '') {
            if (getenv('CATQUIZLAB_REQUIRE_R')) {
                $this->fail('Rscript is required (CATQUIZLAB_REQUIRE_R) and was not found.');
            }
            $this->markTestSkipped('Rscript not found: the R re-analysis is not run here.');
        }
        $this->resetAfterTest();
        $this->setAdminUser();
        $run = static function (string $dir) use ($rscript): array {
            $output = [];
            exec(escapeshellarg($rscript) . ' ' . escapeshellarg($dir . '/reanalysis/run_all.R') . ' ' . escapeshellarg($dir)
                . ' --out=' . escapeshellarg($dir . '/out') . ' 2>&1', $output, $status);
            $comparison = [];
            if (is_readable($dir . '/out/comparison.csv')) {
                $handle = fopen($dir . '/out/comparison.csv', 'r');
                $header = fgetcsv($handle, null, ',', '"', '');
                while (($line = fgetcsv($handle, null, ',', '"', '')) !== false) {
                    $comparison[] = array_combine($header, $line);
                }
                fclose($handle);
            }
            return [$status, implode("\n", $output), $comparison];
        };
        // With strategies that have a role in mode-specific detection: a deficit and a strength mode, the relevant
        // mode, both baselines.
        $designs = [['2pl', ['stratum' => 'subscalevariation', 'severity' => 'medium'], ['lowestsub', 'allsubs', 'classic']],
            ['gpcm', ['stratum' => 'chaotic', 'severity' => 'strong'], ['highestsub', 'relsubs', 'allsubs']]];
        foreach ($designs as [$model, $persons, $strategies]) {
            $e = $this->experiment(10, $model, $persons, $strategies);
            $zip = make_request_directory() . '/package.zip';
            research_package::write_file(['experimentid' => $e['experimentid']], $zip);
            $dir = make_request_directory();
            $archive = new \ZipArchive();
            $this->assertTrue($archive->open($zip) === true);
            $archive->extractTo($dir);
            $archive->close();

            [$status, $output, $comparison] = $run($dir);
            $this->assertSame(0, $status, $model . ":\n" . $output);
            $bystatus = array_count_values(array_column($comparison, 'status'));
            $this->assertArrayNotHasKey('FAIL', $bystatus, $model . ":\n" . $output);
            $passed = [];
            foreach ($comparison as $row) {
                if ($row['status'] === 'PASS') {
                    $passed[$row['tab']] = ($passed[$row['tab']] ?? 0) + 1;
                }
                if ($row['status'] === 'NOT_REPRODUCED') {
                    // Only the figure the coverage matrix names as not reproduced.
                    $this->assertSame(['detection', 'scaleswithitems'], [$row['tab'], $row['metric']], json_encode($row));
                }
            }
            // Compared on values, not on two empty cells: every family has figures with a value on both sides.
            $families = [['overview', 'error', 'mean'], ['overview', 'rmse', 'value'], ['global', 'exposure', 'gini'],
                ['subscales', 'local', 'rmse'], ['robustness', 'rmse', 'delta'], ['robustness', 'localrmse', 'value'],
                ['robustness', 'spearman', 'value'], ['robustness', 'top3', 'value'], ['robustness', 'ndcg3', 'value'],
                ['testflow', 'verdict', 'n'], ['detection', 'rmse', 'value'], ['detection', 'recall', 'value'],
                ['detection', 'spearman', 'value'], ['detection', 'meandifference', 'value'], ['detection', 'pairs', 'value']];
            foreach ($families as [$tab, $metric, $stat]) {
                $this->assertNotEmpty(
                    array_filter($comparison, static fn(array $r): bool => $r['tab'] === $tab
                    && $r['metric'] === $metric && $r['stat'] === $stat && $r['status'] === 'PASS' && $r['expected'] !== ''),
                    $model . ': no compared value for ' . $tab . ' ' . $metric . ' ' . $stat
                );
            }
            foreach (['package', 'overview', 'global', 'subscales', 'robustness', 'testflow', 'detection'] as $tab) {
                $this->assertGreaterThan(0, $passed[$tab] ?? 0, $model . ': nothing compared on ' . $tab . "\n" . $output);
            }
            $this->assertFileExists($dir . '/out/plotdata/global_recovery.csv');
            $this->assertFileExists($dir . '/out/coverage.csv');

            // A deliberate error in the data, with the manifest made to match: the figures must disagree. The estimate
            // of a sitting the dashboard counts moves by half a logit.
            $counted = [];
            foreach (array_slice(file($dir . '/data/analysis_population.csv'), 1) as $line) {
                $cells = str_getcsv($line, ',', '"', '');
                if ($cells[2] === '1') {
                    $counted[$cells[0]] = true;
                }
            }
            $this->assertNotEmpty($counted);
            $attempts = file($dir . '/data/attempts.csv');
            $header = str_getcsv($attempts[0], ',', '"', '');
            $column = array_search('esttheta', $header, true);
            foreach ($attempts as $i => $line) {
                $cells = str_getcsv($line, ',', '"', '');
                if ($i > 0 && isset($counted[$cells[0]]) && is_numeric($cells[$column])) {
                    $cells[$column] = (string) ((float) $cells[$column] + 0.5);
                    $handle = fopen('php://memory', 'w+');
                    fputcsv($handle, $cells, ',', '"', '');
                    rewind($handle);
                    $attempts[$i] = stream_get_contents($handle);
                    fclose($handle);
                    break;
                }
            }
            file_put_contents($dir . '/data/attempts.csv', implode('', $attempts));
            $tables = file($dir . '/manifest-tables.csv');
            foreach ($tables as $i => $line) {
                if (str_starts_with($line, 'attempts,')) {
                    $cells = str_getcsv($line, ',', '"', '');
                    $cells[5] = md5_file($dir . '/data/attempts.csv');
                    $tables[$i] = implode(',', $cells) . "\n";
                }
            }
            file_put_contents($dir . '/manifest-tables.csv', implode('', $tables));
            [$status, $output, $comparison] = $run($dir);
            $this->assertSame(1, $status, $model . ': a changed estimate must fail' . "\n" . $output);
            $failed = array_filter($comparison, static fn(array $r): bool => $r['status'] === 'FAIL');
            $this->assertNotEmpty(array_filter($failed, static fn(array $r): bool => $r['tab'] === 'overview'
                && $r['metric'] === 'error'), 'the bias moved');
            $this->assertEmpty(
                array_filter($failed, static fn(array $r): bool => $r['tab'] === 'package'),
                'the package itself is consistent'
            );

            // A table that is not the one the manifest names: the package check fails.
            $steps = file($dir . '/data/steps.csv');
            array_pop($steps);
            file_put_contents($dir . '/data/steps.csv', implode('', $steps));
            [$status, , $comparison] = $run($dir);
            $this->assertSame(1, $status);
            $this->assertNotEmpty(array_filter($comparison, static fn(array $r): bool => $r['tab'] === 'package'
                && $r['group'] === 'steps' && $r['status'] === 'FAIL'));
        }
    }
}
