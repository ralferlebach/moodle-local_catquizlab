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
 * (a dichotomous item is single-choice 1-of-4; a polytomous item is single-choice
 * with one option per ordered response category, ascending by credit) and a list
 * of option templates each carrying a grading fraction (1.0 correct, 0 for a
 * distractor, k/m for the k-th of m graded categories). Both the
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
     * Default ordered-category (polytomous) template.
     *
     * @param int $categories The number of response categories the model declares.
     * @return array
     */
    public static function default_polytomous(int $categories = 4): array {
        $categories = max(2, $categories);
        $top = $categories - 1;

        // One option per ordered response category, ascending by credit, so the
        // category k the oracle picks is the k-th option. The number of
        // categories follows the model, because a GPCM item with five
        // categories cannot be answered on a four-option question — the fifth
        // category would be unreachable and the item silently truncated.
        $labels = [
            0 => 'keine',
            1 => 'teilweise',
            2 => 'überwiegend',
            3 => 'weitgehend',
            4 => 'nahezu vollständig',
        ];

        $options = [];
        for ($k = 0; $k <= $top; $k++) {
            $label = $k === $top ? 'vollständig' : ($labels[$k] ?? ('Stufe ' . $k));
            $options[] = [
                'text'     => 'Stufe ' . $k . ' — ' . $label . ' ({scalename} #{itemnumber})',
                'fraction' => $top > 0 ? round($k / $top, 7) : 0.0,
            ];
        }

        return [
            'name'         => 'CATLab {scalename} #{itemnumber} (poly)',
            'questiontext' => 'Skala: {scalename} (#{scalenumber}) — Item {itemname} '
                . '(#{itemnumber}, ID {itemid}). Parameter: Schwierigkeit b={difficulty}, '
                . 'Trennschärfe a={discrimination}. Wählen Sie die zutreffende Antwortstufe.',
            // Shuffling is disabled on save, so the definition order is the
            // on-screen order and category k stays the k-th option.
            'single'       => true,
            'options'      => $options,
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
            $polytomous = !empty($item['polytomous'])
                || (isset($item['model']) && model_catalog::has((string) $item['model'])
                    && model_catalog::is_polytomous((string) $item['model']));
            // Categories come from the model when the item carries them, from
            // the threshold count when it carries steps, and otherwise from the
            // documented default of four.
            $steps = (array) ($item['steps'] ?? []);
            $categories = (int) ($item['categories'] ?? ($steps !== [] ? count($steps) + 1 : 4));
            $template = $polytomous
                ? self::default_polytomous($categories)
                : self::default_dichotomous();
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
