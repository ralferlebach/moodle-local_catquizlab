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
 * Question template: render a multiple-choice item from a spec.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Renders a templated multiple-choice question for a materialised item (E2.1).
 *
 * A template is a plain array with a question-text template, a 'single' flag
 * (single-choice = dichotomous 1-of-4, multi = polytomous 1..4-of-6) and a list
 * of option templates each carrying a grading fraction (1.0 correct, 0 or a
 * negative malus for distractors, partial fractions for graded options). Both the
 * question text and each option text may use placeholders — {scalename},
 * {scalenumber}, {itemname}, {itemnumber}, {itemid}, {difficulty},
 * {discrimination}, {guessing} — which are filled from the item spec. Rendering
 * is pure and testable; the engine-side question and item creation consume its
 * output.
 */
class question_template {
    /**
     * Default single-choice (dichotomous) template: 1 correct of 4.
     *
     * @return array
     */
    public static function default_dichotomous(): array {
        return [
            'name'         => 'CATLab {scalename} #{itemnumber}',
            'questiontext' => 'Skala: {scalename} (#{scalenumber}) — Item {itemname} '
                . '(#{itemnumber}, ID {itemid}). Parameter: Schwierigkeit b={difficulty}, '
                . 'Trennschärfe a={discrimination}. Wählen Sie die korrekte Option.',
            'single'       => true,
            'options'      => [
                ['text' => 'Korrekte Antwort ({scalename} #{itemnumber})', 'fraction' => 1.0],
                ['text' => 'Distraktor A ({scalename} #{itemnumber})', 'fraction' => 0.0],
                ['text' => 'Distraktor B ({scalename} #{itemnumber})', 'fraction' => 0.0],
                ['text' => 'Distraktor C ({scalename} #{itemnumber})', 'fraction' => 0.0],
            ],
        ];
    }

    /**
     * Default multi-choice (polytomous) template: 3 correct of 6, malus on wrong.
     *
     * The three correct options share full credit (1/3 each) and the three
     * distractors carry an equal negative malus, matching Moodle's multi-answer
     * grading.
     *
     * @return array
     */
    public static function default_polytomous(): array {
        $correct = round(1.0 / 3.0, 7);
        $malus = round(-1.0 / 3.0, 7);
        return [
            'name'         => 'CATLab {scalename} #{itemnumber} (poly)',
            'questiontext' => 'Skala: {scalename} (#{scalenumber}) — Item {itemname} '
                . '(#{itemnumber}, ID {itemid}). Parameter: Schwierigkeit b={difficulty}, '
                . 'Trennschärfe a={discrimination}. Wählen Sie alle zutreffenden Optionen.',
            'single'       => false,
            'options'      => [
                ['text' => 'Korrekt 1 ({scalename} #{itemnumber})', 'fraction' => $correct],
                ['text' => 'Korrekt 2 ({scalename} #{itemnumber})', 'fraction' => $correct],
                ['text' => 'Korrekt 3 ({scalename} #{itemnumber})', 'fraction' => $correct],
                ['text' => 'Falsch 1 ({scalename} #{itemnumber})', 'fraction' => $malus],
                ['text' => 'Falsch 2 ({scalename} #{itemnumber})', 'fraction' => $malus],
                ['text' => 'Falsch 3 ({scalename} #{itemnumber})', 'fraction' => $malus],
            ],
        ];
    }

    /**
     * Render a question from an item spec and a template.
     *
     * @param array $item The item spec (scalename, scalenumber, itemname, itemnumber,
     *                    itemid, difficulty, discrimination, guessing).
     * @param array|null $template The template; defaults to dichotomous, or polytomous
     *                    when $item['polytomous'] is truthy.
     * @return array{name: string, questiontext: string, single: bool, answers: array}
     */
    public static function render(array $item, ?array $template = null): array {
        if ($template === null) {
            $template = !empty($item['polytomous']) ? self::default_polytomous() : self::default_dichotomous();
        }

        $answers = [];
        foreach ($template['options'] ?? [] as $option) {
            $answers[] = [
                'text'     => self::substitute((string) ($option['text'] ?? ''), $item),
                'fraction' => (float) ($option['fraction'] ?? 0.0),
            ];
        }

        return [
            'name'         => self::substitute((string) ($template['name'] ?? '{itemname}'), $item),
            'questiontext' => self::substitute((string) ($template['questiontext'] ?? ''), $item),
            'single'       => (bool) ($template['single'] ?? true),
            'answers'      => $answers,
        ];
    }

    /**
     * Replace {placeholder} tokens in a string from the item spec.
     *
     * @param string $text The template text.
     * @param array $item The item spec.
     * @return string
     */
    protected static function substitute(string $text, array $item): string {
        $keys = ['scalename', 'scalenumber', 'itemname', 'itemnumber', 'itemid',
            'difficulty', 'discrimination', 'guessing'];

        $replacements = [];
        foreach ($keys as $key) {
            $replacements['{' . $key . '}'] = self::stringify($item[$key] ?? '');
        }

        return strtr($text, $replacements);
    }

    /**
     * Render a spec value as a string, trimming float noise to 4 decimals.
     *
     * @param mixed $value The value.
     * @return string
     */
    protected static function stringify($value): string {
        if (is_float($value)) {
            return (string) round($value, 4);
        }
        return (string) $value;
    }
}
