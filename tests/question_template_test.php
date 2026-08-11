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
 * Tests for the question template renderer.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\question_template;

/**
 * Question template tests.
 *
 * @covers \local_catquizlab\local\question_template
 */
final class question_template_test extends \advanced_testcase {
    /** @var array A representative item spec. */
    private const ITEM = [
        'scalename'      => 'Algebra',
        'scalenumber'    => 2,
        'itemname'       => 'AlgItem',
        'itemnumber'     => 7,
        'itemid'         => 3397,
        'difficulty'     => 0.7500001,
        'discrimination' => 1.3,
        'guessing'       => 0.2,
    ];

    /**
     * The dichotomous default fills placeholders and grades one correct of four.
     *
     * @return void
     */
    public function test_dichotomous(): void {
        $q = question_template::render(self::ITEM);

        $this->assertTrue($q['single']);
        $this->assertStringContainsString('Algebra', $q['questiontext']);
        $this->assertStringContainsString('b=0.75', $q['questiontext']);
        $this->assertStringContainsString('a=1.3', $q['questiontext']);
        $this->assertCount(4, $q['answers']);

        $correct = array_filter($q['answers'], static fn($a) => $a['fraction'] > 0);
        $this->assertCount(1, $correct);
        $this->assertEqualsWithDelta(1.0, reset($correct)['fraction'], 1e-9);
    }

    /**
     * The polytomous default is multi-select with balanced credit and malus.
     *
     * @return void
     */
    public function test_polytomous(): void {
        $q = question_template::render(self::ITEM + ['polytomous' => true]);

        // A polytomous item is single-select with one option per ordered category.
        $this->assertTrue($q['single']);
        $this->assertCount(4, $q['answers']);

        $fractions = array_column($q['answers'], 'fraction');
        // Categories ascend from no credit to full credit (0, 1/3, 2/3, 1).
        $this->assertEqualsWithDelta(0.0, $fractions[0], 1e-6);
        $this->assertEqualsWithDelta(1.0, $fractions[3], 1e-6);
        for ($k = 1; $k < count($fractions); $k++) {
            $this->assertGreaterThan($fractions[$k - 1], $fractions[$k]);
        }
    }

    /**
     * A custom template overrides text, options and grading.
     *
     * @return void
     */
    public function test_custom_template(): void {
        $q = question_template::render(self::ITEM, [
            'name'         => 'X',
            'questiontext' => 'b={difficulty} a={discrimination} id={itemid}',
            'single'       => true,
            'options'      => [
                ['text' => 'ok', 'fraction' => 1.0],
                ['text' => 'no {scalename}', 'fraction' => -0.5],
            ],
        ]);

        $this->assertSame('b=0.75 a=1.3 id=3397', $q['questiontext']);
        $this->assertCount(2, $q['answers']);
        $this->assertSame('no Algebra', $q['answers'][1]['text']);
        $this->assertEqualsWithDelta(-0.5, $q['answers'][1]['fraction'], 1e-9);
    }
}
