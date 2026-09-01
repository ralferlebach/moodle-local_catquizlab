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
 * Tests for the shared experiment service and the JSON exchange.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_io;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\sweep;

/**
 * Service and JSON-exchange tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\experiment_service
 * @covers     \local_catquizlab\local\experiment_io
 */
final class experiment_service_test extends \advanced_testcase {
    /**
     * A definition sweeping two strategies over two pool variants.
     *
     * @return array
     */
    protected function swept_definition(): array {
        $definition = experiment_definition::example_baseline();
        $definition['name'] = 'Sweep demo';
        $definition['replications'] = 2;
        $definition['sweep'] = [
            'factors' => [
                'strategy' => ['fastest', 'lowestsub'],
                'variant'  => ['ideal', 'shifted'],
            ],
        ];

        return $definition;
    }

    /**
     * A valid definition is saved and reported as validated.
     *
     * @return void
     */
    public function test_save_creates_a_validated_experiment(): void {
        global $DB;
        $this->resetAfterTest();

        $result = experiment_service::save(experiment_definition::example_baseline());

        $this->assertTrue($result['created']);
        $this->assertTrue($result['valid']);

        $record = $DB->get_record('local_catquizlab_experiment', ['id' => $result['id']], '*', MUST_EXIST);
        $this->assertSame(experiment_service::STATUS_VALIDATED, (int) $record->status);
    }

    /**
     * An invalid definition is stored as a draft with its errors reported.
     *
     * @return void
     */
    public function test_save_keeps_an_invalid_definition_as_a_draft(): void {
        global $DB;
        $this->resetAfterTest();

        $definition = experiment_definition::example_baseline();
        unset($definition['pool']['scales']);

        $result = experiment_service::save($definition);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertSame(
            experiment_service::STATUS_DRAFT,
            (int) $DB->get_field('local_catquizlab_experiment', 'status', ['id' => $result['id']])
        );
    }

    /**
     * An experiment with runs is not rewritten behind the results' back.
     *
     * @return void
     */
    public function test_experiment_with_runs_is_immutable(): void {
        global $DB;
        $this->resetAfterTest();

        $id = (int) experiment_service::save(experiment_definition::example_baseline())['id'];
        $DB->insert_record('local_catquizlab_run', (object) [
            'experimentid' => $id,
            'cellkey'      => 'c1',
            'masterseed'   => 42,
            'seed'         => 42,
            'replication'  => 1,
            'status'       => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);

        $this->expectException(\moodle_exception::class);
        experiment_service::save(experiment_definition::example_baseline(), $id);
    }

    /**
     * The preview counts what the sweep would create.
     *
     * @return void
     */
    public function test_preview_matches_the_expansion(): void {
        $this->resetAfterTest();

        $definition = $this->swept_definition();
        $preview = experiment_service::preview($definition);

        // Two strategies x two variants x two replications.
        $this->assertSame(4, $preview['cells']);
        $this->assertSame(2, $preview['replications']);
        $this->assertSame(8, $preview['runs']);
        $this->assertFalse($preview['large']);
    }

    /**
     * The UI preview and a direct expansion agree, because both go through the
     * same specification builder.
     *
     * @return void
     */
    public function test_preview_and_cli_expansion_agree(): void {
        $this->resetAfterTest();

        $definition = $this->swept_definition();
        $normalised = (new experiment_definition($definition))->get_normalised();

        $preview = experiment_service::preview($definition);
        $expansion = sweep::expand(experiment_service::sweep_spec($normalised));

        $this->assertSame($preview['runs'], count($expansion['runs']));
        $this->assertSame($preview['cells'], count($expansion['cells']));
    }

    /**
     * An invalid definition previews as zero runs with its errors.
     *
     * @return void
     */
    public function test_preview_of_an_invalid_definition_reports_errors(): void {
        $this->resetAfterTest();

        $definition = experiment_definition::example_baseline();
        $definition['strategy'] = 'vibes';

        $preview = experiment_service::preview($definition);

        $this->assertSame(0, $preview['runs']);
        $this->assertNotEmpty($preview['errors']);
    }

    /**
     * Creating a sweep persists one run per cell and replication.
     *
     * @return void
     */
    public function test_create_sweep_persists_runs(): void {
        global $DB;
        $this->resetAfterTest();

        $id = (int) experiment_service::save($this->swept_definition())['id'];
        $result = experiment_service::create_sweep($id);

        $this->assertSame(8, $result['created']);
        $this->assertSame(8, $DB->count_records('local_catquizlab_run', ['experimentid' => $id]));

        $run = $DB->get_records('local_catquizlab_run', ['experimentid' => $id], 'id', '*', 0, 1);
        $run = reset($run);
        $manifest = json_decode((string) $run->manifestjson, true);

        // The manifest has to say what was executed, in publication terms.
        $this->assertArrayHasKey('strategy', $manifest['config']);
        $this->assertNotEmpty($manifest['config']['strategy']['label']);
        $this->assertArrayHasKey('seeds', $manifest['config']);
        $this->assertSame(42, (int) $run->masterseed);
    }

    /**
     * A severity sweep varies only the severity.
     *
     * @return void
     */
    public function test_severity_is_a_sweep_factor(): void {
        $this->resetAfterTest();

        $definition = experiment_definition::example_baseline();
        $definition['persons']['stratum'] = 'subscalevariation';
        $definition['persons']['severity'] = 'mild';
        $definition['sweep'] = ['factors' => ['severity' => ['mild', 'medium', 'strong']]];

        $preview = experiment_service::preview($definition);

        $this->assertSame(3, $preview['cells']);
    }

    /**
     * Duplicating produces an independent draft.
     *
     * @return void
     */
    public function test_duplicate_creates_an_independent_copy(): void {
        global $DB;
        $this->resetAfterTest();

        $id = (int) experiment_service::save(experiment_definition::example_baseline())['id'];
        $copyid = experiment_service::duplicate($id);

        $this->assertNotSame($id, $copyid);
        $this->assertNotSame(
            $DB->get_field('local_catquizlab_experiment', 'name', ['id' => $id]),
            $DB->get_field('local_catquizlab_experiment', 'name', ['id' => $copyid])
        );
    }

    /**
     * The export carries an explicit schema and both variants round-trip.
     *
     * @return void
     */
    public function test_export_round_trips(): void {
        $this->resetAfterTest();

        $id = (int) experiment_service::save(experiment_definition::example_baseline())['id'];

        foreach ([experiment_io::VARIANT_DECLARATIVE, experiment_io::VARIANT_NORMALISED] as $variant) {
            $json = experiment_io::export($id, $variant);
            $decoded = json_decode($json, true);

            $this->assertSame(experiment_definition::SCHEMA, $decoded['schema']);
            $this->assertSame(experiment_definition::SCHEMAVERSION, $decoded['schemaversion']);
            $this->assertSame($variant, $decoded['variant']);

            $inspected = experiment_io::inspect($json);
            $this->assertTrue($inspected['ok'], implode('; ', $inspected['errors']));
        }
    }

    /**
     * The normalised export differs from the declarative one by its resolved defaults.
     *
     * @return void
     */
    public function test_normalised_export_resolves_defaults(): void {
        $this->resetAfterTest();

        $definition = experiment_definition::example_baseline();
        $definition['model'] = 'raschbirnbaum';
        $id = (int) experiment_service::save($definition)['id'];

        $declarative = json_decode(experiment_io::export($id, experiment_io::VARIANT_DECLARATIVE), true);
        $normalised = json_decode(experiment_io::export($id, experiment_io::VARIANT_NORMALISED), true);

        $this->assertSame('raschbirnbaum', $declarative['definition']['model']);
        $this->assertSame('2pl', $normalised['definition']['model']);
        $this->assertSame('raschbirnbaum', $normalised['definition']['enginemodel']);
    }

    /**
     * A newer schema version is refused rather than reinterpreted.
     *
     * @return void
     */
    public function test_import_refuses_a_newer_schema(): void {
        $this->resetAfterTest();

        $payload = [
            'schema'        => experiment_definition::SCHEMA,
            'schemaversion' => experiment_definition::SCHEMAVERSION + 1,
            'definition'    => experiment_definition::example_baseline(),
        ];

        $result = experiment_io::inspect(json_encode($payload));

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['errors']);
    }

    /**
     * A foreign schema is refused.
     *
     * @return void
     */
    public function test_import_refuses_a_foreign_schema(): void {
        $this->resetAfterTest();

        $result = experiment_io::inspect(json_encode([
            'schema'     => 'someone_else/experiment',
            'definition' => [],
        ]));

        $this->assertFalse($result['ok']);
    }

    /**
     * Garbage input is rejected without a fatal.
     *
     * @return void
     */
    public function test_import_refuses_garbage(): void {
        $this->resetAfterTest();

        $this->assertFalse(experiment_io::inspect('not json at all')['ok']);
        $this->assertFalse(experiment_io::inspect('')['ok']);
    }

    /**
     * A schema-1 file is migrated and the migrations are reported.
     *
     * @return void
     */
    public function test_import_migrates_schema_one(): void {
        $this->resetAfterTest();

        $legacy = experiment_definition::example_baseline();
        $legacy['schemaversion'] = 1;
        $legacy['model'] = 'raschbirnbaum';
        unset($legacy['modelparams']);
        $legacy['budgets'] = ['minitems' => 10, 'maxitems' => 250, 'setarget' => 0.35];

        $result = experiment_io::inspect(json_encode($legacy));

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertNotEmpty($result['migrations']);
        $this->assertSame('2pl', $result['definition']['model']);
    }

    /**
     * An import over an existing name reports the conflict instead of overwriting.
     *
     * @return void
     */
    public function test_import_reports_a_name_conflict(): void {
        $this->resetAfterTest();

        $definition = experiment_definition::example_baseline();
        experiment_service::save($definition);

        $result = experiment_io::inspect(json_encode($definition));

        $this->assertNotNull($result['conflict']);
        $this->assertTrue($result['conflict']['canreplace']);
    }

    /**
     * An experiment with runs cannot be replaced by an import.
     *
     * @return void
     */
    public function test_import_cannot_replace_an_executed_experiment(): void {
        $this->resetAfterTest();

        $definition = $this->swept_definition();
        $id = (int) experiment_service::save($definition)['id'];
        experiment_service::create_sweep($id);

        $result = experiment_io::inspect(json_encode($definition));
        $this->assertFalse($result['conflict']['canreplace']);

        $this->expectException(\moodle_exception::class);
        experiment_io::store($definition, experiment_io::CONFLICT_REPLACE, $id);
    }

    /**
     * Importing as a new version keeps the original untouched.
     *
     * @return void
     */
    public function test_import_as_new_version(): void {
        global $DB;
        $this->resetAfterTest();

        $definition = experiment_definition::example_baseline();
        $originalid = (int) experiment_service::save($definition)['id'];

        $stored = experiment_io::store($definition, experiment_io::CONFLICT_VERSION);

        $this->assertNotSame($originalid, $stored['id']);
        $this->assertTrue($DB->record_exists('local_catquizlab_experiment', ['id' => $originalid]));
    }

    /**
     * An import never starts a sweep by itself.
     *
     * @return void
     */
    public function test_import_does_not_start_runs(): void {
        global $DB;
        $this->resetAfterTest();

        $stored = experiment_io::store($this->swept_definition(), experiment_io::CONFLICT_NEW);

        $this->assertSame(0, $DB->count_records('local_catquizlab_run', ['experimentid' => $stored['id']]));
    }

    /**
     * An oversized upload is refused before it is parsed.
     *
     * @return void
     */
    public function test_import_refuses_an_oversized_upload(): void {
        $this->resetAfterTest();

        $result = experiment_io::inspect(str_repeat('x', experiment_io::MAX_BYTES + 1));

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['errors']);
    }

    /**
     * The overview shows publication labels alongside the internal keys.
     *
     * @return void
     */
    public function test_overview_carries_publication_labels(): void {
        $this->resetAfterTest();

        experiment_service::save(experiment_definition::example_baseline());
        $rows = experiment_service::overview();

        $this->assertCount(1, $rows);
        $this->assertSame('classic', $rows[0]['strategy']);
        $this->assertSame('Fixed-form baseline', $rows[0]['strategylabel']);
        $this->assertSame('2pl', $rows[0]['model']);
    }
}
