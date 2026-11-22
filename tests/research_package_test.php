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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/catquizlab/tests/fixtures/sittings_experiment.php');

use local_catquizlab\local\research_codebook;
use local_catquizlab\local\research_package;
use local_catquizlab\local\results_query;

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
    use \local_catquizlab\tests\sittings_experiment;

    /** @var int Twins per run. */
    protected const TWINS = 12;

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
     * #169 AK1/AK3/AK4: the export tab says each package's state in data-state — queued, building, failed, ready —
     * whichever the background has reached by the time the page is drawn; deterministic here, raced in the browser.
     *
     * @return void
     */
    public function test_the_package_states_are_said(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $e = $this->experiment(2);
        $filter = ['experimentid' => $e['experimentid'], 'validity' => 'valid'];
        $section = function () use ($filter): string {
            $query = new \local_catquizlab\local\results_query($filter);
            $page = new \local_catquizlab\output\results_page($query, 'export', $filter);
            $method = new \ReflectionMethod($page, 'render_research_package');
            return $method->invoke($page);
        };
        $states = static function (string $html): array {
            preg_match_all('/data-state="(\w+)"/', $html, $m);
            return $m[1];
        };
        $this->assertSame([], $states($section()), 'nothing queued, nothing built');

        \local_catquizlab\task\build_research_package::queue($filter, (int) get_admin()->id);
        $this->assertSame(['queued'], $states($section()));
        $classname = '\\' . \local_catquizlab\task\build_research_package::class;
        $id = (int) $DB->get_field('task_adhoc', 'id', ['classname' => $classname]);
        $DB->set_field('task_adhoc', 'timestarted', time(), ['id' => $id]);
        $this->assertSame(['building'], $states($section()));
        // A try that failed: queued again with a delay — said as failed, never as ready.
        $DB->set_field('task_adhoc', 'timestarted', null, ['id' => $id]);
        $DB->set_field('task_adhoc', 'faildelay', 60, ['id' => $id]);
        $this->assertSame(['failed'], $states($section()));
        $DB->set_field('task_adhoc', 'faildelay', 0, ['id' => $id]);

        ob_start();
        $this->runAdhocTasks(\local_catquizlab\task\build_research_package::class);
        ob_end_clean();
        $html = $section();
        $this->assertSame(['ready'], $states($html), 'built: the file, and nothing pending');
        $stored = \local_catquizlab\task\build_research_package::stored($e['experimentid']);
        $this->assertStringContainsString('data-file="' . $stored[0]->get_filename() . '"', $html);
    }

    /**
     * Run the R re-analysis on an unpacked package.
     *
     * @param string $rscript Rscript.
     * @param string $dir The unpacked package.
     * @return array{0: int, 1: string, 2: array[]} Exit status, output, the rows of comparison.csv.
     */
    protected function reanalyse(string $rscript, string $dir): array {
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
        @unlink($dir . '/out/comparison.csv');

        return [$status, implode("\n", $output), $comparison];
    }

    /**
     * Write a package of a filter and unpack it.
     *
     * @param array $filter The filter.
     * @return string The directory.
     */
    protected function unpacked(array $filter): string {
        $zip = make_request_directory() . '/package.zip';
        research_package::write_file($filter, $zip);
        $dir = make_request_directory();
        $archive = new \ZipArchive();
        $this->assertTrue($archive->open($zip) === true);
        $archive->extractTo($dir);
        $archive->close();

        return $dir;
    }

    /**
     * Change one table of an unpacked package and make its manifest match: an error in the data, not in the package.
     *
     * @param string $dir The unpacked package.
     * @param string $table The table.
     * @param callable $change Gets the header and one row's cells, returns the changed cells or null (unchanged).
     * @param int $limit How many rows to change at most.
     * @return int How many rows were changed.
     */
    protected function falsify(string $dir, string $table, callable $change, int $limit = 1): int {
        $lines = file($dir . '/data/' . $table . '.csv');
        $header = str_getcsv($lines[0], ',', '"', '');
        $changed = 0;
        foreach ($lines as $i => $line) {
            if ($i === 0 || $changed >= $limit) {
                continue;
            }
            $cells = $change($header, str_getcsv(rtrim($line, "\n"), ',', '"', ''));
            if ($cells === null) {
                continue;
            }
            $handle = fopen('php://memory', 'w+');
            fputcsv($handle, $cells, ',', '"', '');
            rewind($handle);
            $lines[$i] = stream_get_contents($handle);
            fclose($handle);
            $changed++;
        }
        file_put_contents($dir . '/data/' . $table . '.csv', implode('', $lines));
        $tables = file($dir . '/manifest-tables.csv');
        foreach ($tables as $i => $line) {
            if (str_starts_with($line, $table . ',')) {
                $cells = str_getcsv($line, ',', '"', '');
                $cells[5] = md5_file($dir . '/data/' . $table . '.csv');
                $tables[$i] = implode(',', $cells) . "\n";
            }
        }
        file_put_contents($dir . '/manifest-tables.csv', implode('', $tables));

        return $changed;
    }

    /**
     * The attempts the dashboard counts in an unpacked package.
     *
     * @param string $dir The unpacked package.
     * @return array<string, bool>
     */
    protected function counted(string $dir): array {
        $counted = [];
        foreach (array_slice(file($dir . '/data/analysis_population.csv'), 1) as $line) {
            $cells = str_getcsv($line, ',', '"', '');
            if ($cells[2] === '1') {
                $counted[$cells[0]] = true;
            }
        }
        $this->assertNotEmpty($counted);

        return $counted;
    }

    /**
     * The R re-analysis (#157): run on the packages of 2PL and GPCM experiments — three strategies, two replications,
     * two pool variants, sittings of different lengths, valid, invalid and without a final value, with modes and their
     * baselines, without a baseline, with one subscale only, and with 625-question sittings — it recomputes every
     * figure the dashboard shows from the raw tables (theta, item parameters, answers, steps) and finds no difference,
     * under every validity filter (valid, all, invalid, unknown), and leaves nothing "not reproduced". A bias in an
     * estimate, a changed step and a broken join (scale map) each make it fail; so does a table the manifest does not
     * name.
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
        // Label => model, persons, strategies, scales, questions per sitting, variants, filters, falsify.
        $designs = [
            'modes 2pl' => ['2pl', ['stratum' => 'subscalevariation', 'severity' => 'medium'],
                ['lowestsub', 'allsubs', 'classic'], [2, 2, 10], null, ['ideal', 'shifted'],
                ['valid', 'all', 'invalid', 'unknown'], true],
            'modes gpcm' => ['gpcm', ['stratum' => 'chaotic', 'severity' => 'strong'],
                ['highestsub', 'relsubs', 'allsubs'], [2, 2, 10], null, ['ideal', 'shifted'], ['valid', 'all'], true],
            'no baseline' => ['2pl', ['stratum' => 'subscalevariation', 'severity' => 'medium'],
                ['lowestsub', 'highestsub', 'classic'], [2, 2, 10], null, ['ideal', 'shifted'], ['valid'], false],
            'one subscale' => ['2pl', [], ['lowestsub', 'allsubs', 'classic'], [1, 1, 20], null, ['ideal'],
                ['valid', 'all'], false],
            '625 questions' => ['gpcm', [], ['allsubs'], [5, 5, 25], 625, ['ideal'], ['valid'], false],
        ];
        foreach ($designs as $label => [$model, $persons, $strategies, $scales, $length, $variants, $filters, $falsify]) {
            $e = $this->experiment(
                $length === null ? 10 : 3,
                $model,
                $persons,
                $strategies,
                $scales,
                $length,
                $variants,
                in_array('unknown', $filters, true)
            );
            foreach ($filters as $validity) {
                $what = $label . ', ' . $validity;
                $dir = $this->unpacked(['experimentid' => $e['experimentid'], 'validity' => $validity]);
                [$status, $output, $comparison] = $this->reanalyse($rscript, $dir);
                $this->assertSame(0, $status, $what . ":\n" . $output);
                $this->assertNotEmpty($comparison, $what);
                $passed = [];
                foreach ($comparison as $row) {
                    $this->assertSame('PASS', $row['status'], $what . ': ' . json_encode($row));
                    if ($row['expected'] !== '') {
                        $passed[$row['tab'] . '/' . $row['section'] . '/' . $row['metric']] = true;
                    }
                }
                if ($length === 625) {
                    // Every sitting played the whole pool: 625 steps each, in the flows.
                    $this->assertNotEmpty(array_filter($comparison, static fn(array $r): bool => $r['section'] === 'flow'
                        && $r['metric'] === 'steps' && $r['expected'] === '625'), $what);
                }
                if ($validity !== 'valid') {
                    $this->assertArrayHasKey('subscales/coverage/included', $passed, $what);
                    continue;
                }
                // Compared on values, not on two empty cells: every family has figures with a value on both sides.
                $families = ['overview/*/error', 'overview/*/rmse', 'global/*/exposure', 'subscales/summary/local',
                    'subscales/itemclass/local', 'subscales/coverage/measured', 'subscales/coverage/chain',
                    'subscales/spread/spreadall', 'subscales/spread/spreadvalid', 'testflow/*/verdict', 'testflow/flow/steps',
                    'testflow/flow/ability'];
                if (count($variants) > 1) {
                    array_push(
                        $families,
                        'robustness/*/rmse',
                        'robustness/*/localrmse',
                        'robustness/*/spearman',
                        'robustness/*/top3',
                        'robustness/*/ndcg3'
                    );
                }
                if (
                    array_intersect(['lowestsub', 'highestsub'], $strategies) && array_intersect(
                        ['allsubs', 'relsubs'],
                        $strategies
                    )
                ) {
                    array_push($families, 'detection/*/rmse', 'detection/*/scaleswithitems');
                    if ($scales[0] * $scales[1] > 1) {
                        // A ranking needs two subscales: with one, recall and rank correlation are empty on both sides.
                        array_push($families, 'detection/*/recall', 'detection/*/spearman');
                    }
                }
                foreach ($families as $family) {
                    [$tab, $section, $metric] = explode('/', $family);
                    $this->assertNotEmpty(
                        array_filter(array_keys($passed), static fn(string $k): bool =>
                        (bool) preg_match('#^' . preg_quote($tab, '#') . '/' . ($section === '*' ? '[^/]*'
                        : preg_quote($section, '#')) . '/' . preg_quote($metric, '#') . '$#', $k)),
                        $what . ': no compared value for ' . $family . "\n" . implode("\n", array_keys($passed))
                    );
                }
                foreach (['global_recovery', 'subscales_items', 'testflow_flows'] as $plot) {
                    $this->assertFileExists($dir . '/out/plotdata/' . $plot . '.csv');
                }
                // The coverage matrix leaves nothing "not reproduced" (#157): every part compared, plot data or data.
                $this->assertDoesNotMatchRegularExpression('/not reproduced/', file_get_contents($dir . '/out/coverage.csv'));
                $this->assertFileExists($dir . '/out/coverage.csv');
                if (!$falsify) {
                    continue;
                }

                // A bias: the estimate of a sitting the dashboard counts moves by half a logit.
                $counted = $this->counted($dir);
                $clean = make_request_directory();
                $this->assertTrue(check_dir_exists($clean));
                exec('cp -a ' . escapeshellarg($dir . '/.') . ' ' . escapeshellarg($clean));
                $this->assertSame(1, $this->falsify($dir, 'attempts', static function (array $h, array $c) use ($counted): ?array {
                    $column = array_search('esttheta', $h, true);
                    if (!isset($counted[$c[0]]) || !is_numeric($c[$column])) {
                        return null;
                    }
                    $c[$column] = (string) ((float) $c[$column] + 0.5);
                    return $c;
                }));
                [$status, $output, $comparison] = $this->reanalyse($rscript, $dir);
                $this->assertSame(1, $status, $what . ': a changed estimate must fail' . "\n" . $output);
                $failed = array_filter($comparison, static fn(array $r): bool => $r['status'] === 'FAIL');
                $this->assertNotEmpty(array_filter($failed, static fn(array $r): bool => $r['tab'] === 'overview'
                    && $r['metric'] === 'error'), $what . ': the bias moved');
                $this->assertEmpty(
                    array_filter($failed, static fn(array $r): bool => $r['tab'] === 'package'),
                    $what . ': the package itself is consistent'
                );

                // A step: the estimate after one step of a counted sitting moves; the flows disagree.
                $dir = make_request_directory();
                exec('cp -a ' . escapeshellarg($clean . '/.') . ' ' . escapeshellarg($dir));
                $this->assertSame(1, $this->falsify($dir, 'steps', static function (array $h, array $c) use ($counted): ?array {
                    $row = array_combine($h, $c);
                    if (!isset($counted[$row['attemptid']]) || !is_numeric($row['esttheta'])) {
                        return null;
                    }
                    $c[array_search('esttheta', $h, true)] = (string) ((float) $row['esttheta'] + 0.5);
                    return $c;
                }));
                [$status, $output, $comparison] = $this->reanalyse($rscript, $dir);
                $this->assertSame(1, $status, $what . ': a changed step must fail' . "\n" . $output);
                $this->assertNotEmpty(array_filter($comparison, static fn(array $r): bool => $r['status'] === 'FAIL'
                    && $r['section'] === 'flow'), $what . ': the flow moved');

                // A join: the scale map of the first run points at engine scales no item is assigned to.
                $dir = make_request_directory();
                exec('cp -a ' . escapeshellarg($clean . '/.') . ' ' . escapeshellarg($dir));
                $first = null;
                $broken = static function (array $h, array $c) use (&$first): ?array {
                    $row = array_combine($h, $c);
                    $first = $first ?? $row['runid'];
                    if ($row['runid'] !== $first || $row['level'] !== 'subscale') {
                        return null;
                    }
                    $c[array_search('catscaleid', $h, true)] = (string) ((int) $row['catscaleid'] + 7777);
                    return $c;
                };
                $this->assertGreaterThan(0, $this->falsify($dir, 'scale_maps', $broken, PHP_INT_MAX));
                [$status, $output, $comparison] = $this->reanalyse($rscript, $dir);
                $this->assertSame(1, $status, $what . ': a broken join must fail' . "\n" . $output);
                $this->assertNotEmpty(
                    array_filter($comparison, static fn(array $r): bool => $r['status'] === 'FAIL'
                    && in_array($r['metric'], ['scaleswithitems', 'measured', 'fromvalid', 'unestimated'], true)),
                    $what . ': the subscales given an item moved'
                );

                // A table that is not the one the manifest names: the package check fails.
                $steps = file($dir . '/data/steps.csv');
                array_pop($steps);
                file_put_contents($dir . '/data/steps.csv', implode('', $steps));
                [$status, , $comparison] = $this->reanalyse($rscript, $dir);
                $this->assertSame(1, $status);
                $this->assertNotEmpty(array_filter($comparison, static fn(array $r): bool => $r['tab'] === 'package'
                    && $r['group'] === 'steps' && $r['status'] === 'FAIL'));
            }
        }
    }
}
