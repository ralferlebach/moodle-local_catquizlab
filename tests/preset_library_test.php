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
 * Tests for the reusable building-block library.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\preset_library;

/**
 * Preset-library tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\preset_library
 */
final class preset_library_test extends \advanced_testcase {
    /**
     * A normalised baseline definition.
     *
     * @return array
     */
    protected function definition(): array {
        return (new experiment_definition(experiment_definition::example_baseline()))->get_normalised();
    }

    /**
     * A pool block carries the structure but not the disturbance.
     *
     * @return void
     */
    public function test_pool_extraction_leaves_the_variant_behind(): void {
        $this->resetAfterTest();

        $definition = $this->definition();
        $definition['pool']['variant'] = 'shifted';
        $definition['pool']['recipe'] = ['shift' => 1.0];

        $payload = preset_library::extract($definition, preset_library::KIND_POOL);

        // A robustness condition belongs to the study, not to the pool it
        // disturbs; reusing a "shifted pool" everywhere would smuggle the
        // condition into experiments that never asked for it.
        $this->assertArrayNotHasKey('variant', $payload);
        $this->assertArrayNotHasKey('recipe', $payload);
        $this->assertArrayHasKey('scales', $payload);
        $this->assertSame('2pl', $payload['model']);
    }

    /**
     * A person block carries the model but not the sample size.
     *
     * @return void
     */
    public function test_person_extraction_leaves_the_count_behind(): void {
        $this->resetAfterTest();

        $payload = preset_library::extract($this->definition(), preset_library::KIND_PERSONS);

        $this->assertArrayNotHasKey('count', $payload);
        $this->assertArrayHasKey('stratum', $payload);
        $this->assertArrayHasKey('severity', $payload);
    }

    /**
     * The same parameters produce the same fingerprint regardless of key order.
     *
     * @return void
     */
    public function test_fingerprint_ignores_key_order(): void {
        $this->resetAfterTest();

        $a = ['scales' => ['categories' => 10, 'subcategories' => 4], 'model' => '2pl'];
        $b = ['model' => '2pl', 'scales' => ['subcategories' => 4, 'categories' => 10]];

        $this->assertSame(preset_library::fingerprint($a), preset_library::fingerprint($b));
    }

    /**
     * Different parameters produce different fingerprints.
     *
     * @return void
     */
    public function test_fingerprint_distinguishes_different_blueprints(): void {
        $this->resetAfterTest();

        $a = ['scales' => ['categories' => 10]];
        $b = ['scales' => ['categories' => 11]];

        $this->assertNotSame(preset_library::fingerprint($a), preset_library::fingerprint($b));
    }

    /**
     * A saved block round-trips through the store.
     *
     * @return void
     */
    public function test_save_and_read(): void {
        $this->resetAfterTest();

        $payload = preset_library::extract($this->definition(), preset_library::KIND_POOL);
        $id = preset_library::save(preset_library::KIND_POOL, 'Maths pool v2', $payload, 'From the pilot.');

        $preset = preset_library::get($id);

        $this->assertSame('Maths pool v2', $preset['name']);
        $this->assertSame(preset_library::KIND_POOL, $preset['kind']);
        $this->assertSame($payload, $preset['payload']);
        $this->assertSame(preset_library::fingerprint($payload), $preset['fingerprint']);
        $this->assertFalse($preset['locked']);
    }

    /**
     * Applying a block fills what the experiment has not decided itself.
     *
     * @return void
     */
    public function test_apply_fills_only_what_is_missing(): void {
        $this->resetAfterTest();

        $payload = preset_library::extract($this->definition(), preset_library::KIND_POOL);
        $payload['scales']['categories'] = 7;
        $id = preset_library::save(preset_library::KIND_POOL, 'Seven domains', $payload);

        $empty = preset_library::apply(['name' => 'New'], $id);
        $this->assertSame(7, $empty['pool']['scales']['categories']);
        $this->assertSame($id, $empty['poolpreset']);

        // An author who set a value afterwards keeps it.
        $decided = preset_library::apply(
            ['name' => 'New', 'pool' => ['scales' => ['categories' => 3]]],
            $id
        );
        $this->assertSame(3, $decided['pool']['scales']['categories']);
    }

    /**
     * Two experiments citing the same block carry the same fingerprint.
     *
     * @return void
     */
    public function test_two_experiments_can_prove_they_share_a_blueprint(): void {
        $this->resetAfterTest();

        $payload = preset_library::extract($this->definition(), preset_library::KIND_POOL);
        $id = preset_library::save(preset_library::KIND_POOL, 'Shared pool', $payload);

        $first = preset_library::apply(['name' => 'Study A'], $id);
        $second = preset_library::apply(['name' => 'Study B'], $id);

        $this->assertSame($first['poolpresetfingerprint'], $second['poolpresetfingerprint']);
        $this->assertNotEmpty($first['poolpresetfingerprint']);
    }

    /**
     * A block used by an executed experiment is locked against editing.
     *
     * @return void
     */
    public function test_used_block_is_locked(): void {
        $this->resetAfterTest();

        $payload = preset_library::extract($this->definition(), preset_library::KIND_POOL);
        $id = preset_library::save(preset_library::KIND_POOL, 'Locked pool', $payload);

        preset_library::record_use($id, true);

        $this->assertTrue(preset_library::get($id)['locked']);

        $this->expectException(\moodle_exception::class);
        preset_library::save(preset_library::KIND_POOL, 'Locked pool', $payload, '', $id);
    }

    /**
     * A locked block cannot be deleted either.
     *
     * @return void
     */
    public function test_locked_block_cannot_be_deleted(): void {
        $this->resetAfterTest();

        $id = preset_library::save(preset_library::KIND_POOL, 'Locked', ['scales' => []]);
        preset_library::record_use($id, true);

        $this->expectException(\moodle_exception::class);
        preset_library::delete($id);
    }

    /**
     * An unused block can be deleted.
     *
     * @return void
     */
    public function test_unused_block_can_be_deleted(): void {
        $this->resetAfterTest();

        $id = preset_library::save(preset_library::KIND_POOL, 'Scratch', ['scales' => []]);
        preset_library::delete($id);

        $this->assertNull(preset_library::get($id));
    }

    /**
     * Creating a sweep locks the blocks the definition cited.
     *
     * @return void
     */
    public function test_creating_a_sweep_locks_the_cited_blocks(): void {
        $this->resetAfterTest();

        $payload = preset_library::extract($this->definition(), preset_library::KIND_POOL);
        $presetid = preset_library::save(preset_library::KIND_POOL, 'Cited pool', $payload);

        $definition = experiment_definition::example_baseline();
        $definition['name'] = 'Cites a block';
        $definition['poolpreset'] = $presetid;
        $experimentid = (int) experiment_service::save($definition)['id'];

        $this->assertFalse(preset_library::get($presetid)['locked']);

        experiment_service::create_sweep($experimentid);

        $this->assertTrue(preset_library::get($presetid)['locked']);
    }

    /**
     * The picker lists blocks of the requested kind only.
     *
     * @return void
     */
    public function test_menu_filters_by_kind(): void {
        $this->resetAfterTest();

        preset_library::save(preset_library::KIND_POOL, 'A pool', ['scales' => ['categories' => 2]]);
        preset_library::save(preset_library::KIND_PERSONS, 'A cohort', ['stratum' => 'chaotic']);

        $this->assertCount(1, preset_library::menu(preset_library::KIND_POOL));
        $this->assertCount(1, preset_library::menu(preset_library::KIND_PERSONS));
        $this->assertCount(2, preset_library::listing());
    }

    /**
     * An unknown kind is refused.
     *
     * @return void
     */
    public function test_unknown_kind_is_refused(): void {
        $this->resetAfterTest();

        $this->expectException(\moodle_exception::class);
        preset_library::save('astrology', 'Nope', []);
    }
}
