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
 * Materialiser: turn the pool blueprint into real questions and CAT items.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Materialises the ideal-pool blueprint into questions and engine items (E2.1).
 *
 * {@see self::plan_items()} walks the blueprint (categories → subscales → items),
 * maps each item's subscale to the run's engine scale via the scale map, and
 * emits a flat list of item specs — pure and testable. {@see self::materialise()}
 * plans the pool, renders each item into a multiple-choice question
 * ({@see question_template}), creates it in a question category, and registers it
 * as a CAT item with its parameters ({@see item_registrar}). Creating questions
 * and engine rows needs the engine, so materialisation is a no-op without it.
 */
class materialiser {
    /**
     * Plan the item specs from a blueprint and a run's scale map.
     *
     * @param array $blueprint The pool blueprint (from {@see pool_planner::plan()}).
     * @param array $scalemap The run's scale map rows (from {@see scale_provisioner}).
     * @return array[] Item specs ready for the template and the registrar.
     */
    public static function plan_items(array $blueprint, array $scalemap): array {
        $index = self::index_scalemap($scalemap);

        $specs = [];
        foreach ($blueprint['categories'] ?? [] as $category) {
            foreach ($category['subscales'] ?? [] as $subscale) {
                $key = $category['index'] . ':' . $subscale['index'];
                if (!isset($index[$key])) {
                    continue;
                }
                $mapping = $index[$key];
                foreach ($subscale['items'] ?? [] as $item) {
                    $specs[] = [
                        'catscaleid'     => $mapping['catscaleid'],
                        'contextid'      => $mapping['contextid'],
                        'scalename'      => $mapping['name'],
                        'scalenumber'    => $subscale['index'],
                        'itemname'       => $item['name'],
                        'itemnumber'     => $item['index'],
                        'difficulty'     => $item['difficulty'],
                        'discrimination' => 1.0,
                        'guessing'       => 0.0,
                    ];
                }
            }
        }
        return $specs;
    }

    /**
     * Materialise a run's pool into questions and CAT items.
     *
     * @param int $runid The run.
     * @param array $definition The experiment definition (drives the blueprint).
     * @param array $options 'questioncategoryid' (required), 'seed', 'template', 'polytomous'.
     * @return array|null planned and created counts, or null when unavailable.
     */
    public static function materialise(int $runid, array $definition, array $options = []): ?array {
        if (!environment::engine_available()) {
            return null;
        }

        $categoryid = (int) ($options['questioncategoryid'] ?? 0);
        $scalemap = self::run_scalemap($runid);
        if ($categoryid <= 0 || $scalemap === []) {
            return null;
        }

        $blueprint = pool_planner::plan($definition, (int) ($options['seed'] ?? 42));
        $specs = self::plan_items($blueprint, $scalemap);
        $template = $options['template'] ?? null;
        $polytomous = !empty($options['polytomous']);

        $created = 0;
        foreach ($specs as $spec) {
            if ($polytomous) {
                $spec['steps'] = self::polytomous_steps((float) $spec['difficulty']);
                $spec['model'] = 'grmgeneralized';
            }
            $rendered = question_template::render($spec + ['polytomous' => $polytomous], $template);
            $questionid = self::create_question($categoryid, $rendered);
            if ($questionid > 0) {
                item_registrar::register_item($questionid, $spec['catscaleid'], $spec['contextid'], $spec);
                $created++;
            }
        }

        return ['planned' => count($specs), 'created' => $created];
    }

    /**
     * Ordered category thresholds around an item difficulty (a 4-category default).
     *
     * @param float $difficulty The item difficulty b.
     * @return float[] Ascending step thresholds [b-1, b, b+1].
     */
    public static function polytomous_steps(float $difficulty): array {
        return [
            round($difficulty - 1.0, 5),
            round($difficulty, 5),
            round($difficulty + 1.0, 5),
        ];
    }

    /**
     * Index the scale map by "categoryindex:subscaleindex" for subscale rows.
     *
     * @param array $scalemap The scale map rows.
     * @return array<string, array> The lookup.
     */
    protected static function index_scalemap(array $scalemap): array {
        $index = [];
        foreach ($scalemap as $row) {
            $isubscale = ($row['level'] ?? null) === scale_provisioner::LEVEL_SUBSCALE;
            if ($isubscale && $row['categoryindex'] !== null && $row['subscaleindex'] !== null) {
                $index[$row['categoryindex'] . ':' . $row['subscaleindex']] = $row;
            }
        }
        return $index;
    }

    /**
     * Read a run's scale map rows as arrays.
     *
     * @param int $runid The run.
     * @return array[]
     */
    protected static function run_scalemap(int $runid): array {
        global $DB;

        $rows = $DB->get_records('local_catquizlab_scalemap', ['runid' => $runid]);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'level'         => (int) $row->level,
                'categoryindex' => $row->categoryindex === null ? null : (int) $row->categoryindex,
                'subscaleindex' => $row->subscaleindex === null ? null : (int) $row->subscaleindex,
                'catscaleid'    => (int) $row->catscaleid,
                'contextid'     => (int) $row->contextid,
                'name'          => (string) $row->name,
            ];
        }
        return $out;
    }

    /**
     * Create a multiple-choice question from rendered template output.
     *
     * Uses the qtype_multichoice save path. The category's context is taken from
     * the question category; this is the step most likely to need tuning to a
     * specific instance's question-bank layout.
     *
     * @param int $categoryid The target question category id.
     * @param array $rendered The rendered question (name, questiontext, single, answers).
     * @return int The new question id, or 0 on failure.
     */
    protected static function create_question(int $categoryid, array $rendered): int {
        global $USER, $DB;

        $category = $DB->get_record('question_categories', ['id' => $categoryid]);
        if (!$category) {
            return 0;
        }

        $form = (object) [
            'category'                 => $categoryid,
            'name'                     => $rendered['name'],
            'questiontext'             => ['text' => $rendered['questiontext'], 'format' => FORMAT_HTML],
            'generalfeedback'          => ['text' => '', 'format' => FORMAT_HTML],
            'defaultmark'              => 1.0,
            'penalty'                  => 0.3333333,
            'single'                   => $rendered['single'] ? 1 : 0,
            'shuffleanswers'           => 0,
            'answernumbering'          => 'abc',
            'correctfeedback'          => ['text' => '', 'format' => FORMAT_HTML],
            'partiallycorrectfeedback' => ['text' => '', 'format' => FORMAT_HTML],
            'incorrectfeedback'        => ['text' => '', 'format' => FORMAT_HTML],
            'answer'                   => [],
            'fraction'                 => [],
            'feedback'                 => [],
        ];
        foreach ($rendered['answers'] as $answer) {
            $form->answer[] = ['text' => $answer['text'], 'format' => FORMAT_HTML];
            $form->fraction[] = (string) $answer['fraction'];
            $form->feedback[] = ['text' => '', 'format' => FORMAT_HTML];
        }

        $question = (object) [
            'category'  => $categoryid,
            'qtype'     => 'multichoice',
            'createdby' => $USER->id ?? 0,
            'contextid' => $category->contextid,
        ];

        $saved = \question_bank::get_qtype('multichoice')->save_question($question, $form);

        return (int) ($saved->id ?? 0);
    }
}
