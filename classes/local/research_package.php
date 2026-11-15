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

namespace local_catquizlab\local;

/**
 * The research data package of an experiment (#156): one ZIP with every table a re-analysis needs, joined by stable
 * keys, described by a codebook, counted and checksummed in a manifest.
 *
 * What it holds, for the runs the results filter selects:
 * - data/*.csv — experiments, runs, items, twins, truth_profiles, twin_item_responses (potential answers), attempts,
 *   steps (administered answers), estimated_profiles, validity, analysis_population; UTF-8, RFC 4180, header row.
 * - reproducibility.json — the experiment's reproducibility package (definition, manifests, seeds, effective
 *   parameters, contracts, versions, analysis basis).
 * - codebook.json — tables, keys, joins, columns with type, unit and meaning (EN/DE).
 * - manifest.json — schema, versions, selection, filter, per table rows, bytes and sha256; written last.
 * - README.md — how to read it (EN/DE).
 *
 * Every sitting is in attempts, steps and validity, whatever its validity; analysis_population says which of them the
 * results page counts under the filter. Potential answers are a table of their own and never mixed into the steps.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class research_package {
    /** @var string Schema name in the manifest. */
    public const SCHEMA = 'catquizlab-research-package';

    /** @var int Schema version: changes when a table or column changes meaning or goes. */
    public const VERSION = 1;

    /** @var int Rows above which the package is only built in the background. */
    public const DIRECT_ROWS = 1000000;

    /** @var string File name prefix of stored packages. */
    public const FILEPREFIX = 'catquizlab-research-';

    /** @var string[] Columns of the tables the package composes itself. */
    protected const OWN_COLUMNS = [
        'experiments' => ['experimentid', 'name', 'tier', 'designhash', 'definitionsha256', 'runs'],
        'runs' => ['runid', 'experimentid', 'replication', 'cellkey', 'tier', 'strategy', 'model', 'variant', 'strength',
            'stratum', 'severity', 'status', 'masterseed', 'seed', 'rngschema', 'itemidentityschema', 'poolfingerprint',
            'poolitems', 'manifestsha256', 'persons', 'sittings', 'sittingswithtrace', 'sittingsfailed', 'setarget',
            'globalminitems', 'globalmaxitems', 'subscalemaps'],
        'twins' => ['runid', 'personid', 'twinid', 'twinindex', 'replication', 'stratum', 'severity', 'abilityglobal',
            'deviancemagnitude', 'deviancesubscales'],
        'twin_item_responses' => ['runid', 'personid', 'twinid', 'replication', 'itemkey', 'questionid', 'rngschema',
            'rngseed', 'uniform', 'trueability', 'trueprobs', 'responsecategory', 'potentialfraction'],
        'validity' => ['attemptid', 'runid', 'validitystate', 'valid', 'validityreason', 'statisticalvalid', 'uniformvalid',
            'enginevalid', 'engineverdict', 'engineverdictavailable', 'engineunknownreason', 'enginefinished',
            'designstopreached', 'stopreached', 'contractviolations', 'terminalreasoncode'],
        'analysis_population' => ['attemptid', 'runid', 'inpopulation', 'reason'],
    ];

    /** @var array<string, array> Open table streams while a package is written. */
    protected static array $open = [];

    /**
     * The columns of every table, in the package's order.
     *
     * @return array<string, string[]>
     */
    public static function columns(): array {
        $out = [];
        foreach (array_keys(research_codebook::tables()) as $table) {
            switch ($table) {
                case 'items':
                    $out[$table] = export_dataset::ITEM_COLUMNS;
                    break;
                case 'truth_profiles':
                    $out[$table] = export_dataset::GROUNDTRUTH_COLUMNS;
                    break;
                case 'attempts':
                    $out[$table] = results_export::ATTEMPT_COLUMNS;
                    break;
                case 'steps':
                    $out[$table] = results_export::STEP_COLUMNS;
                    break;
                case 'estimated_profiles':
                    $out[$table] = results_export::SUBSCALE_COLUMNS;
                    break;
                default:
                    $out[$table] = self::OWN_COLUMNS[$table];
            }
        }

        return $out;
    }

    /**
     * How large the package of a selection will be, before it is built (#156 AK6): rows per table and in all.
     *
     * Counted in grouped queries, never by reading the sittings: the export tab must not cost what the download costs
     * (a pass over every sitting doubled the tab's time for 50 000 sittings). Exact for every table but two, which are
     * bounded from above: steps by each run's sittings times its question budget (or its pool, if smaller), subscale
     * estimates by its sittings times its mapped subscales. Whether the package streams directly is decided on that
     * bound, so a package said to fit does fit.
     *
     * @param array $filter The results filter.
     * @return array{tables: array<string, int>, exact: array<string, bool>, rows: int, runs: int, direct: bool}
     */
    public static function forecast(array $filter): array {
        global $DB;

        $runs = (new results_query($filter))->runs();
        $tables = array_fill_keys(array_keys(research_codebook::tables()), 0);
        $exact = array_fill_keys(array_keys($tables), true);
        $exact['steps'] = false;
        $exact['estimated_profiles'] = false;
        $tables['experiments'] = count(array_unique(array_column($runs, 'experimentid')));
        $tables['runs'] = count($runs);
        if ($runs !== []) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($runs), SQL_PARAMS_NAMED, 'fr');
            $items = $DB->get_records_sql_menu("SELECT runid, COUNT(1) FROM {local_catquizlab_item} WHERE runid {$insql}
                GROUP BY runid", $params);
            // The items and twins a potential answer can be drawn for: keyed items with a true category, twins with an id.
            $keyed = $DB->get_records_sql_menu("SELECT runid, COUNT(1) FROM {local_catquizlab_item}
                WHERE runid {$insql} AND itemkey IS NOT NULL AND itemkey <> '' AND truecategory > 0 GROUP BY runid", $params);
            $persons = $DB->get_records_sql_menu("SELECT runid, COUNT(1) FROM {local_catquizlab_person} WHERE runid {$insql}
                GROUP BY runid", $params);
            $twins = $DB->get_records_sql_menu("SELECT runid, COUNT(1) FROM {local_catquizlab_person}
                WHERE runid {$insql} AND twinid IS NOT NULL AND twinid <> '' GROUP BY runid", $params);
            $sittings = $DB->get_records_sql_menu("SELECT runid, COUNT(1) FROM {local_catquizlab_attempt}
                WHERE runid {$insql} AND tracejson IS NOT NULL GROUP BY runid", $params);
            $maps = $DB->get_records_sql_menu("SELECT runid, COUNT(1) FROM {local_catquizlab_scalemap}
                WHERE runid {$insql} AND level = :sublevel AND categoryindex IS NOT NULL AND subscaleindex IS NOT NULL
                GROUP BY runid", $params + ['sublevel' => scale_provisioner::LEVEL_SUBSCALE]);
            $tables['items'] = (int) array_sum($items);
            $tables['twins'] = (int) array_sum($persons);
            $manifests = $DB->get_records_select_menu('local_catquizlab_run', "id {$insql}", $params, '', 'id, manifestjson');
            foreach ($runs as $runid => $run) {
                $manifest = json_decode((string) ($manifests[$runid] ?? ''), true) ?: [];
                $definition = (array) ($manifest['config']['definition'] ?? []);
                $scales = (array) ($definition['pool']['scales'] ?? []);
                $levels = 1 + (int) ($scales['categories'] ?? 0) * (1 + (int) ($scales['subcategories'] ?? 0));
                $tables['truth_profiles'] += (int) ($persons[$runid] ?? 0) * $levels;
                if (seed_domains::rng_schema($manifest) >= 2) {
                    $tables['twin_item_responses'] += (int) ($twins[$runid] ?? 0) * (int) ($keyed[$runid] ?? 0);
                }
                $n = (int) ($sittings[$runid] ?? 0);
                $budget = (int) ($manifest['config']['cat']['budgets']['global']['maxitems'] ?? 0);
                $pool = (int) ($items[$runid] ?? 0);
                $length = $budget > 0 && $pool > 0 ? min($budget, $pool) : max($budget, $pool);
                $tables['steps'] += $n * $length;
                $tables['estimated_profiles'] += $n * (int) ($maps[$runid] ?? 0);
                $tables['attempts'] += $n;
            }
            $tables['validity'] = $tables['attempts'];
            $tables['analysis_population'] = $tables['attempts'];
        }
        $rows = (int) array_sum($tables);

        return ['tables' => $tables, 'exact' => $exact, 'rows' => $rows, 'runs' => count($runs),
            'direct' => $rows <= self::DIRECT_ROWS];
    }

    /**
     * The file name of a package.
     *
     * @param int $experimentid The experiment.
     * @param int $time When it was made.
     * @return string
     */
    public static function filename(int $experimentid, int $time): string {
        return self::FILEPREFIX . $experimentid . '-' . date('Ymd-His', $time) . '.zip';
    }

    /**
     * Stream the package of a selection to the browser.
     *
     * @param array $filter The results filter; must name an experiment.
     * @return void
     */
    public static function send(array $filter): void {
        $zip = \core_files\archive_writer::get_stream_writer(
            self::filename((int) ($filter['experimentid'] ?? 0), time()),
            \core_files\archive_writer::ZIP_WRITER
        );
        self::build($filter, $zip);
        $zip->finish();
    }

    /**
     * Build the package into a file (tests, the background task).
     *
     * @param array $filter The results filter; must name an experiment.
     * @param string $path Where the ZIP goes.
     * @return array The manifest.
     */
    public static function write_file(array $filter, string $path): array {
        // The writer makes the file in a request directory of its own, under the name it is given.
        $zip = \core_files\archive_writer::get_file_writer(basename($path), \core_files\archive_writer::ZIP_WRITER);
        $manifest = self::build($filter, $zip);
        $zip->finish();
        rename($zip->get_path_to_zip(), $path);

        return $manifest;
    }

    /**
     * Build the package into an archive.
     *
     * @param array $filter The results filter; must name an experiment.
     * @param \core_files\archive_writer $zip Where the files go.
     * @return array The manifest.
     */
    public static function build(array $filter, \core_files\archive_writer $zip): array {
        global $CFG, $DB;

        $experimentid = (int) ($filter['experimentid'] ?? 0);
        if ($experimentid <= 0) {
            throw new \invalid_parameter_exception('A research package is of one experiment.');
        }
        \core_php_time_limit::raise(0);
        raise_memory_limit(MEMORY_HUGE);
        results_query::reset_caches();
        results_export::reset_caches();

        $population = new results_query($filter);
        $runs = $population->runs();
        $complete = new results_query(['validity' => result_validity::ALL] + $filter);
        $runids = array_keys($runs);
        $schemas = [];
        foreach ($DB->get_records_list('local_catquizlab_run', 'id', $runids ?: [0], 'id', 'id, manifestjson') as $run) {
            $schemas[(int) $run->id] = seed_domains::rng_schema(json_decode((string) $run->manifestjson, true) ?: []);
        }

        // TI@n per step only where it fits, by the step export's own rule (#99); the manifest says which.
        $cost = results_export::engine_info_cost($complete);
        $engineinfo = $cost['seconds'] <= results_export::ENGINE_INFO_SOFT_LIMIT;
        $previous = results_export::$engineinfo;
        results_export::$engineinfo = $engineinfo;

        $manifest = [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'generated' => date('c'),
            'generatedunix' => time(),
            'experimentid' => $experimentid,
            'filter' => $filter,
            'validityrule' => (string) ($filter['validityrule'] ?? engine_validity::RULE_UNIFORM),
            'populationvalidity' => (string) ($filter['validity'] ?? result_validity::VALID),
            'runs' => [],
            'versions' => reproducibility::versions(),
            'moodle' => ['release' => $CFG->release, 'version' => $CFG->version],
            'formats' => ['encoding' => 'UTF-8', 'csv' => 'RFC 4180, comma, header row, "\n"', 'na' => 'empty cell',
                'nonfinite' => 'NaN, Inf, -Inf', 'boolean' => '0/1', 'timestamp' => 'Unix seconds, UTC',
                'list' => 'values separated by ";"', 'decimal' => 'point, shortest exact representation'],
            'tiatn' => ['included' => $engineinfo, 'estimatedseconds' => round($cost['seconds'], 1),
                'limitseconds' => results_export::ENGINE_INFO_SOFT_LIMIT],
            'potentialresponses' => ['runs' => array_keys(array_filter($schemas, static fn(int $s): bool => $s >= 2)),
                'note' => 'Potential answers only for runs under RNG schema 2; never administered unless in steps.'],
            'tables' => [],
        ];

        try {
            self::table('experiments', self::experiments($experimentid, count($runs)), $zip, $manifest);
            self::table('runs', self::runs($runs, $schemas, $manifest), $zip, $manifest);
            self::table(
                'items',
                self::per_run($runids, static fn(int $id): array => export_dataset::items([$id])['rows']),
                $zip,
                $manifest
            );
            self::table('twins', self::twins($runs), $zip, $manifest);
            self::table('truth_profiles', self::per_run(
                $runids,
                static fn(int $id): array => export_dataset::ground_truth([$id])['rows']
            ), $zip, $manifest);
            self::table('twin_item_responses', self::potential($runs, $schemas), $zip, $manifest);

            // Attempts, their validity and the analysis population in one pass over every sitting.
            $counted = [];
            foreach ($population->each_observation() as $observation) {
                $counted[(int) $observation['attemptid']] = true;
            }
            self::open('attempts');
            self::open('validity');
            self::open('analysis_population');
            foreach (results_export::iterate($complete, results_export::LEVEL_ATTEMPT)['rows'] as $row) {
                self::add('attempts', $row);
                self::add('validity', $row);
                $in = isset($counted[(int) $row['attemptid']]);
                self::add('analysis_population', ['attemptid' => $row['attemptid'], 'runid' => $row['runid'],
                    'inpopulation' => $in ? 1 : 0, 'reason' => $in ? '' : (string) ($row['validitystate'] ?? '')]);
            }
            foreach (['attempts', 'validity', 'analysis_population'] as $table) {
                self::close($table, $zip, $manifest);
            }
            self::table('steps', results_export::iterate($complete, results_export::LEVEL_STEP)['rows'], $zip, $manifest);
            self::table(
                'estimated_profiles',
                results_export::iterate($complete, results_export::LEVEL_SUBSCALE)['rows'],
                $zip,
                $manifest
            );
        } finally {
            results_export::$engineinfo = $previous;
            foreach (array_keys(self::$open) as $table) {
                fclose(self::$open[$table]['handle']);
            }
            self::$open = [];
        }

        // What the dashboard shows, for the R re-analysis to compare against (#157), and what R reads without a JSON
        // parser: the codebook and the tables' counts and checksums as CSV.
        self::add_csv(
            $zip,
            'reference/dashboard.csv',
            research_reference::COLUMNS,
            research_reference::figures($filter),
            $manifest
        );
        $codebookrows = [];
        foreach (self::codebook()['tables'] as $table => $meta) {
            foreach ($meta['columns'] as $column) {
                $codebookrows[] = ['table' => $table] + $column;
            }
        }
        self::add_csv($zip, 'codebook.csv', ['table', 'name', 'type', 'unit', 'en', 'de'], $codebookrows, $manifest);
        $filterrows = [];
        foreach ($filter as $key => $value) {
            $filterrows[] = ['key' => (string) $key, 'value' => is_scalar($value) ? (string) $value : json_encode($value)];
        }
        // The filter's validity decides what the dashboard counts; R reads it from here.
        if (!array_key_exists('validity', $filter)) {
            $filterrows[] = ['key' => 'validity', 'value' => result_validity::VALID];
        }
        self::add_csv($zip, 'filter.csv', ['key', 'value'], $filterrows, $manifest);
        $tablerows = [];
        foreach ($manifest['tables'] as $table => $entry) {
            $tablerows[] = ['table' => $table] + $entry;
        }
        self::add_csv(
            $zip,
            'manifest-tables.csv',
            ['table', 'file', 'rows', 'bytes', 'sha256', 'md5'],
            $tablerows,
            $manifest
        );
        // The R project that recomputes the dashboard from the tables (#157).
        foreach (self::r_files() as $name => $path) {
            $content = (string) file_get_contents($path);
            $zip->add_file_from_string($name, $content);
            $manifest['files'][$name] = ['bytes' => strlen($content), 'sha256' => hash('sha256', $content)];
        }

        $package = reproducibility::package_json($experimentid, $filter);
        $zip->add_file_from_string('reproducibility.json', $package);
        $manifest['files']['reproducibility.json'] = ['bytes' => strlen($package), 'sha256' => hash('sha256', $package)];
        $codebook = json_encode(self::codebook(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $zip->add_file_from_string('codebook.json', $codebook);
        $manifest['files']['codebook.json'] = ['bytes' => strlen($codebook), 'sha256' => hash('sha256', $codebook)];
        $readme = self::readme();
        $zip->add_file_from_string('README.md', $readme);
        $manifest['files']['README.md'] = ['bytes' => strlen($readme), 'sha256' => hash('sha256', $readme)];
        $zip->add_file_from_string('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

        return $manifest;
    }

    /**
     * A CSV file outside data/: written as the tables are, listed with its rows and checksum under files.
     *
     * @param \core_files\archive_writer $zip The archive.
     * @param string $name Its path in the archive.
     * @param string[] $columns Its columns.
     * @param iterable $rows Its rows.
     * @param array $manifest The manifest.
     * @return void
     */
    protected static function add_csv(
        \core_files\archive_writer $zip,
        string $name,
        array $columns,
        iterable $rows,
        array &$manifest
    ): void {
        $handle = fopen('php://temp/maxmemory:' . (8 * 1024 * 1024), 'w+b');
        fputcsv($handle, $columns, ',', '"', '');
        $count = 0;
        foreach ($rows as $row) {
            fputcsv(
                $handle,
                array_map(static fn(string $c): string => self::cell($row[$c] ?? null), $columns),
                ',',
                '"',
                ''
            );
            $count++;
        }
        $bytes = ftell($handle);
        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);
        $zip->add_file_from_string($name, $content);
        $manifest['files'][$name] = ['rows' => $count, 'bytes' => $bytes, 'sha256' => hash('sha256', $content),
            'md5' => md5($content)];
    }

    /**
     * The files of the R re-analysis project, by their path in the package.
     *
     * @return array<string, string> Path in the package => file on disk.
     */
    public static function r_files(): array {
        global $CFG;

        $root = $CFG->dirroot . '/local/catquizlab/research';
        $out = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $root,
            \FilesystemIterator::SKIP_DOTS
        ));
        foreach ($iterator as $file) {
            if ($file->isFile() && preg_match('/\.(R|md)$/', $file->getFilename())) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $out['reanalysis/' . $relative] = $file->getPathname();
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * The codebook: tables with key, joins, columns, and every column's definition.
     *
     * @return array
     */
    public static function codebook(): array {
        $columns = self::columns();
        $definitions = research_codebook::columns();
        $tables = [];
        foreach (research_codebook::tables() as $table => $meta) {
            $tables[$table] = $meta + ['file' => 'data/' . $table . '.csv', 'columns' => array_map(
                static fn(string $column): array => ['name' => $column] + ($definitions[$column]
                    ?? ['type' => 'string', 'unit' => '', 'en' => '', 'de' => '']),
                $columns[$table]
            )];
        }

        return ['schema' => self::SCHEMA, 'version' => self::VERSION, 'na' => '',
            'types' => ['integer', 'number', 'string', 'boolean', 'timestamp', 'json', 'list'], 'tables' => $tables];
    }

    /**
     * Write a whole table.
     *
     * @param string $table The table.
     * @param iterable $rows Its rows.
     * @param \core_files\archive_writer $zip The archive.
     * @param array $manifest The manifest, to which its entry is added.
     * @return void
     */
    protected static function table(string $table, iterable $rows, \core_files\archive_writer $zip, array &$manifest): void {
        self::open($table);
        foreach ($rows as $row) {
            self::add($table, $row);
        }
        self::close($table, $zip, $manifest);
    }

    /**
     * Start a table: a temporary stream and its header.
     *
     * @param string $table The table.
     * @return void
     */
    protected static function open(string $table): void {
        $columns = self::columns()[$table];
        $handle = fopen('php://temp/maxmemory:' . (8 * 1024 * 1024), 'w+b');
        fputcsv($handle, $columns, ',', '"', '');
        self::$open[$table] = ['handle' => $handle, 'columns' => $columns, 'rows' => 0];
    }

    /**
     * Add a row to an open table, in its columns' order.
     *
     * @param string $table The table.
     * @param array $row The row, by column.
     * @return void
     */
    protected static function add(string $table, array $row): void {
        $values = [];
        foreach (self::$open[$table]['columns'] as $column) {
            $values[] = self::cell($row[$column] ?? null);
        }
        fputcsv(self::$open[$table]['handle'], $values, ',', '"', '');
        self::$open[$table]['rows']++;
    }

    /**
     * Finish a table: its checksum into the manifest, the file into the archive.
     *
     * @param string $table The table.
     * @param \core_files\archive_writer $zip The archive.
     * @param array $manifest The manifest.
     * @return void
     */
    protected static function close(string $table, \core_files\archive_writer $zip, array &$manifest): void {
        $open = self::$open[$table];
        $handle = $open['handle'];
        $bytes = ftell($handle);
        rewind($handle);
        $hash = hash_init('sha256');
        // An md5 too: base R can check it (tools::md5sum), sha256 it cannot without a package.
        $md5 = hash_init('md5');
        while (!feof($handle)) {
            $chunk = (string) fread($handle, 1 << 20);
            hash_update($hash, $chunk);
            hash_update($md5, $chunk);
        }
        rewind($handle);
        $file = 'data/' . $table . '.csv';
        // The writer reads the stream and closes it.
        unset(self::$open[$table]);
        $zip->add_file_from_stream($file, $handle);
        $manifest['tables'][$table] = ['file' => $file, 'rows' => $open['rows'], 'bytes' => $bytes,
            'sha256' => hash_final($hash), 'md5' => hash_final($md5), 'columns' => $open['columns']];
    }

    /**
     * One CSV cell: missing is empty, never zero; booleans 0/1; numbers exact; arrays as JSON.
     *
     * @param mixed $value The value.
     * @return string
     */
    public static function cell($value): string {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_float($value)) {
            if (is_nan($value)) {
                return 'NaN';
            }
            if (is_infinite($value)) {
                return $value > 0 ? 'Inf' : '-Inf';
            }
            // The shortest representation that reads back as the same number.
            return var_export($value, true);
        }
        if (is_array($value) || is_object($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }

    /**
     * The experiments row.
     *
     * @param int $experimentid The experiment.
     * @param int $runs Its runs in the package.
     * @return array[]
     */
    protected static function experiments(int $experimentid, int $runs): array {
        global $DB;

        $experiment = $DB->get_record(
            'local_catquizlab_experiment',
            ['id' => $experimentid],
            'id, name, tier, configjson',
            MUST_EXIST
        );
        try {
            $designhash = experiment_metadata::design_hash((string) $experiment->configjson);
        } catch (\Throwable $e) {
            $designhash = null;
        }

        return [['experimentid' => (int) $experiment->id, 'name' => (string) $experiment->name,
            'tier' => (string) $experiment->tier, 'designhash' => $designhash,
            'definitionsha256' => hash('sha256', (string) $experiment->configjson), 'runs' => $runs]];
    }

    /**
     * The runs rows, from the runs, their pools and grouped counts of persons and sittings.
     *
     * @param array $runs The selection's runs (results_query::runs()).
     * @param int[] $schemas RNG schema by run.
     * @param array $manifest The manifest, to which each run's summary is added.
     * @return array[]
     */
    protected static function runs(array $runs, array $schemas, array &$manifest): array {
        global $DB;

        if ($runs === []) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($runs), SQL_PARAMS_NAMED, 'rr');
        $records = $DB->get_records_select('local_catquizlab_run', "id {$insql}", $params, 'id', 'id, manifestjson');
        $pools = $DB->get_records_select(
            'local_catquizlab_pool',
            "runid {$insql}",
            $params,
            'id',
            'id, runid, fingerprint, itemcount'
        );
        $bypool = [];
        foreach ($pools as $pool) {
            $bypool[(int) $pool->runid] = $pool;
        }
        $persons = $DB->get_records_sql_menu("SELECT runid, COUNT(1) FROM {local_catquizlab_person} WHERE runid {$insql}
            GROUP BY runid", $params);
        // The subscale scale maps: a run without one has no subscale results, in any tab.
        $maps = $DB->get_records_sql_menu("SELECT runid, COUNT(1) FROM {local_catquizlab_scalemap}
            WHERE runid {$insql} AND level = :sublevel AND categoryindex IS NOT NULL AND subscaleindex IS NOT NULL
            GROUP BY runid", $params + ['sublevel' => scale_provisioner::LEVEL_SUBSCALE]);
        $sittings = $DB->get_records_sql(
            "SELECT runid, COUNT(1) AS n,
                SUM(CASE WHEN tracejson IS NOT NULL THEN 1 ELSE 0 END) AS traced,
                SUM(CASE WHEN status = :failed THEN 1 ELSE 0 END) AS failed
              FROM {local_catquizlab_attempt} WHERE runid {$insql} GROUP BY runid",
            $params + ['failed' => attempt_scheduler::STATUS_FAILED]
        );
        $rows = [];
        foreach ($runs as $runid => $run) {
            $pool = $bypool[$runid] ?? null;
            $count = $sittings[$runid] ?? null;
            $row = [
                'runid' => (int) $runid, 'experimentid' => (int) $run['experimentid'],
                'replication' => (int) $run['replication'], 'cellkey' => (string) $run['cellkey'],
                'tier' => (string) ($run['tier'] ?? ''), 'strategy' => (string) $run['strategy'],
                'model' => (string) $run['model'], 'variant' => (string) $run['variant'],
                'strength' => $run['strength'] ?? null, 'stratum' => (string) $run['stratum'],
                'severity' => (string) $run['severity'], 'status' => self::status_key((int) $run['status']),
                'masterseed' => (int) $run['masterseed'], 'seed' => (int) $run['seed'],
                'rngschema' => $schemas[$runid] ?? 1,
                'itemidentityschema' => $pool !== null && (string) $pool->fingerprint !== '' ? item_identity::SCHEMA : 0,
                'poolfingerprint' => $pool !== null && (string) $pool->fingerprint !== '' ? (string) $pool->fingerprint : null,
                'poolitems' => $pool !== null ? (int) $pool->itemcount : null,
                'manifestsha256' => hash('sha256', (string) ($records[$runid]->manifestjson ?? '')),
                'persons' => (int) ($persons[$runid] ?? 0),
                'sittings' => $count ? (int) $count->n : 0,
                'sittingswithtrace' => $count ? (int) $count->traced : 0,
                'sittingsfailed' => $count ? (int) $count->failed : 0,
            ];
            // The run's precision target and test length budget, as the engine was given them (the test flow's
            // feasibility is judged against them).
            $cat = (array) ((json_decode((string) ($records[$runid]->manifestjson ?? ''), true) ?: [])['config']['cat'] ?? []);
            $row['setarget'] = isset($cat['se']['min']) && is_numeric($cat['se']['min']) ? (float) $cat['se']['min'] : null;
            $row['globalminitems'] = isset($cat['budgets']['global']['minitems'])
                ? (int) $cat['budgets']['global']['minitems'] : null;
            $row['globalmaxitems'] = isset($cat['budgets']['global']['maxitems'])
                ? (int) $cat['budgets']['global']['maxitems'] : null;
            $row['subscalemaps'] = (int) ($maps[$runid] ?? 0);
            $rows[] = $row;
            $manifest['runs'][] = ['runid' => $row['runid'], 'replication' => $row['replication'],
                'strategy' => $row['strategy'], 'rngschema' => $row['rngschema'],
                'itemidentityschema' => $row['itemidentityschema'], 'poolfingerprint' => $row['poolfingerprint'],
                'persons' => $row['persons'], 'sittingswithtrace' => $row['sittingswithtrace'],
                'sittingsfailed' => $row['sittingsfailed']];
        }

        return $rows;
    }

    /**
     * A run status as a stable word (draft, scheduled, ready, running, aggregating, finished, failed, cancelled, …),
     * not a label in somebody's language.
     *
     * @param int $status The status.
     * @return string
     */
    public static function status_key(int $status): string {
        foreach ((new \ReflectionClass(registry::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'STATUS_') && $value === $status) {
                return strtolower(substr($name, 7));
            }
        }

        return (string) $status;
    }

    /**
     * Rows produced run by run, so that one run's rows are held at a time.
     *
     * @param int[] $runids The runs.
     * @param callable $rows fn(int $runid): array[]
     * @return \Generator<array>
     */
    protected static function per_run(array $runids, callable $rows): \Generator {
        foreach ($runids as $runid) {
            foreach ($rows((int) $runid) as $row) {
                yield $row;
            }
        }
    }

    /**
     * Every twin of every run, with the deviance of its profile.
     *
     * @param array $runs The selection's runs.
     * @return \Generator<array>
     */
    protected static function twins(array $runs): \Generator {
        global $DB;

        foreach ($runs as $runid => $run) {
            $persons = $DB->get_recordset(
                'local_catquizlab_person',
                ['runid' => $runid],
                'id',
                'id, runid, twinid, twinindex, stratum, severity, abilityglobal, profilejson'
            );
            foreach ($persons as $person) {
                $profile = json_decode((string) $person->profilejson, true) ?: [];
                $deviance = (array) ($profile['deviance'] ?? []);
                yield [
                    'runid' => (int) $runid, 'personid' => (int) $person->id, 'twinid' => (string) $person->twinid,
                    'twinindex' => (int) $person->twinindex, 'replication' => (int) $run['replication'],
                    'stratum' => (string) $person->stratum, 'severity' => (string) $person->severity,
                    'abilityglobal' => (float) $person->abilityglobal,
                    'deviancemagnitude' => isset($deviance['magnitude']) ? (float) $deviance['magnitude'] : null,
                    'deviancesubscales' => implode(';', array_map(
                        static fn($pair): string => implode(':', array_map('intval', (array) $pair)),
                        (array) ($deviance['subscales'] ?? [])
                    )),
                ];
            }
            $persons->close();
        }
    }

    /**
     * The potential answer of every twin to every item of its run's pool (#156 AK3): under RNG schema 2 only, by the
     * function that answers in a sitting (twin_response::draw()).
     *
     * @param array $runs The selection's runs.
     * @param int[] $schemas RNG schema by run.
     * @return \Generator<array>
     */
    protected static function potential(array $runs, array $schemas): \Generator {
        global $DB;

        foreach ($runs as $runid => $run) {
            if (($schemas[$runid] ?? 1) < 2) {
                continue;
            }
            $record = $DB->get_record('local_catquizlab_run', ['id' => $runid], 'id, masterseed, replication, manifestjson');
            $items = $DB->get_records('local_catquizlab_item', ['runid' => $runid], 'id', 'id, questionid, itemkey, model, '
                . 'truedifficulty, discrimination, guessing, stepsjson, truecategory, truesubscale');
            $items = array_filter($items, static fn(\stdClass $i): bool => (string) $i->itemkey !== ''
                && (int) $i->truecategory > 0);
            $persons = $DB->get_recordset('local_catquizlab_person', ['runid' => $runid], 'id', 'id, twinid, profilejson');
            foreach ($persons as $person) {
                if ((string) $person->twinid === '') {
                    continue;
                }
                $profile = json_decode((string) $person->profilejson, true) ?: [];
                foreach ($items as $item) {
                    $draw = twin_response::draw($record, $person, $profile, $item, [], (int) $item->questionid);
                    yield [
                        'runid' => (int) $runid, 'personid' => (int) $person->id, 'twinid' => (string) $person->twinid,
                        'replication' => (int) $record->replication, 'itemkey' => (string) $item->itemkey,
                        'questionid' => (int) $item->questionid, 'rngschema' => $draw['schema'],
                        'rngseed' => $draw['seed'], 'uniform' => round($draw['uniform'], 9),
                        'trueability' => round($draw['ability'], 6),
                        'trueprobs' => implode(';', array_map(
                            static fn(float $p): string => sprintf('%.6f', $p),
                            $draw['probabilities']
                        )),
                        'responsecategory' => $draw['category'], 'potentialfraction' => $draw['fraction'],
                    ];
                }
            }
            $persons->close();
        }
    }

    /**
     * The README of the package, English and German.
     *
     * @return string
     */
    protected static function readme(): string {
        global $CFG;

        // The text lives beside the R project, as Markdown: a README is easier to keep right where it reads as one.
        $text = (string) file_get_contents($CFG->dirroot . '/local/catquizlab/research/package-readme.txt');

        return strtr($text, ['{SCHEMA}' => self::SCHEMA, '{VERSION}' => (string) self::VERSION]);
    }
}
