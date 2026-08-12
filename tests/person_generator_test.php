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
 * Tests for the person ground-truth generator.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\person_generator;

/**
 * Person generator tests.
 *
 * @covers \local_catquizlab\local\person_generator
 */
final class person_generator_test extends \advanced_testcase {
    /**
     * A small chaotic definition for reuse.
     *
     * @param array $overrides Overrides for the persons block.
     * @return array
     */
    protected function definition(array $overrides = []): array {
        return [
            'persons' => array_replace([
                'count'   => 4,
                'stratum' => 'chaotic',
                'naming'  => ['pattern' => 'P-{stratum}-{index:03d}'],
            ], $overrides),
            'pool'    => ['scales' => ['categories' => 2, 'subcategories' => 2]],
        ];
    }

    /**
     * Generation yields the requested count, labels and hierarchical structure.
     *
     * @return void
     */
    public function test_generate_structure(): void {
        $persons = person_generator::generate($this->definition(), 42);

        $this->assertCount(4, $persons);
        $this->assertSame(
            ['P-chaotic-001', 'P-chaotic-002', 'P-chaotic-003', 'P-chaotic-004'],
            array_column($persons, 'label')
        );
        $this->assertCount(2, $persons[0]['profile']['categories']);
        $this->assertCount(2, $persons[0]['profile']['categories'][0]['subscales']);
    }

    /**
     * The same seed reproduces identical profiles; a different seed changes them.
     *
     * @return void
     */
    public function test_determinism(): void {
        $a = person_generator::generate($this->definition(), 42);
        $b = person_generator::generate($this->definition(), 42);
        $c = person_generator::generate($this->definition(), 99);

        $this->assertSame($a, $b);
        $this->assertNotSame($a[0]['abilityglobal'], $c[0]['abilityglobal']);
    }

    /**
     * A conforming person has flat category/subscale θ equal to the global value.
     *
     * @return void
     */
    public function test_conforming_is_flat(): void {
        $persons = person_generator::generate($this->definition(['stratum' => 'conforming']), 7);
        $profile = $persons[0]['profile'];

        foreach ($profile['categories'] as $category) {
            $this->assertSame($profile['global'], $category['theta']);
            foreach ($category['subscales'] as $subscale) {
                $this->assertSame($profile['global'], $subscale['theta']);
            }
        }
    }

    /**
     * Persisting writes one ground-truth row per person, with profile JSON.
     *
     * @return void
     */
    public function test_persist(): void {
        global $DB;
        $this->resetAfterTest();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();

        $written = person_generator::generate_and_persist($run->id, $this->definition(), 42);

        $this->assertSame(4, $written);
        $rows = $DB->get_records('local_catquizlab_person', ['runid' => $run->id]);
        $this->assertCount(4, $rows);

        $row = reset($rows);
        $this->assertSame('chaotic', $row->stratum);
        $this->assertNull($row->moodleuserid);
        $decoded = json_decode($row->profilejson, true);
        $this->assertArrayHasKey('label', $decoded);
        $this->assertArrayHasKey('categories', $decoded);
    }

    /**
     * A deviance spec in the definition is carried into every profile.
     *
     * @return void
     */
    public function test_deviance_passthrough(): void {
        $definition = [
            'persons' => [
                'count' => 2, 'stratum' => 'deviant',
                'deviance' => ['magnitude' => 1.0, 'subscales' => [[1, 1]]],
            ],
            'pool' => ['scales' => ['categories' => 2, 'subcategories' => 2]],
        ];
        $people = person_generator::generate($definition, 42);
        $this->assertCount(2, $people);
        foreach ($people as $person) {
            $this->assertSame(['magnitude' => 1.0, 'subscales' => [[1, 1]]], $person['profile']['deviance']);
        }
    }
}
