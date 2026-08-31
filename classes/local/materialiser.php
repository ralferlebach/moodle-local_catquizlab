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
    /** @var string The plan contained no items at all. */
    public const REASON_EMPTY_PLAN = 'empty-materialisation-plan';

    /** @var string A Moodle question could not be created. */
    public const REASON_NO_QUESTION = 'question-creation-failed';

    /** @var string Some items did not complete for mixed reasons. */
    public const REASON_INCOMPLETE = 'materialisation-incomplete';

    /** @var int How many individual item errors are kept for diagnosis. */
    public const MAX_RECORDED_ERRORS = 20;

    /**
     * Plan the item specs from a blueprint and a run's scale map.
     *
     * @param array $blueprint The pool blueprint (from {@see pool_planner::plan()}).
     * @param array $scalemap The run's scale map rows (from {@see scale_provisioner}).
     * @param array $options 'model': the public model key driving the item parameters.
     * @return array[] Item specs ready for the template and the registrar.
     */
    public static function plan_items(array $blueprint, array $scalemap, array $options = []): array {
        $index = self::index_scalemap($scalemap);
        $model = model_catalog::normalise((string) ($options['model'] ?? '2pl')) ?? '2pl';
        $enginemodel = model_catalog::engine_key($model);
        $polytomous = model_catalog::is_polytomous($model);

        $specs = [];
        foreach ($blueprint['categories'] ?? [] as $category) {
            foreach ($category['subscales'] ?? [] as $subscale) {
                foreach ($subscale['items'] ?? [] as $item) {
                    // Two placements, deliberately kept apart. The assigned one
                    // is what the engine is told and is what a tagging error
                    // corrupts; the true one stays with the item so the oracle
                    // can still answer against the ability that really governs
                    // it. Collapsing them would neutralise the tagging-error
                    // condition, because a mistagged item would simply become
                    // an item of the other subscale.
                    $truekey = ($item['truecategory'] ?? $category['index'])
                        . ':' . ($item['truesubscale'] ?? $subscale['index']);
                    $assignedkey = ($item['assignedcategory'] ?? $category['index'])
                        . ':' . ($item['assignedsubscale'] ?? $subscale['index']);
                    if (!isset($index[$assignedkey])) {
                        continue;
                    }
                    $mapping = $index[$assignedkey];
                    $truemapping = $index[$truekey] ?? $mapping;

                    $spec = [
                        'catscaleid'         => $mapping['catscaleid'],
                        'contextid'          => $mapping['contextid'],
                        'scalename'          => $mapping['name'],
                        'scalenumber'        => $item['assignedsubscale'] ?? $subscale['index'],
                        'truecatscaleid'     => $truemapping['catscaleid'],
                        'truecategory'       => $item['truecategory'] ?? $category['index'],
                        'truesubscale'       => $item['truesubscale'] ?? $subscale['index'],
                        'mistagged'          => !empty($item['mistagged']),
                        'itemname'           => $item['name'],
                        'itemnumber'         => $item['index'],
                        'model'              => $enginemodel,
                        'publicmodel'        => $model,
                        // The difficulty is the ground truth; storeddifficulty is
                        // what reaches local_catquiz_itemparams. They differ
                        // exactly when a calibration error was injected.
                        'difficulty'         => $item['storeddifficulty'] ?? $item['difficulty'],
                        'truedifficulty'     => $item['difficulty'],
                        'miscalibrated'      => !empty($item['miscalibrated']),
                        'discrimination'     => (float) ($item['discrimination'] ?? 1.0),
                        'guessing'           => (float) ($item['guessing'] ?? 0.0),
                    ];
                    if ($polytomous) {
                        $spec['steps'] = $item['steps'] ?? self::polytomous_steps((float) $spec['difficulty']);
                        $spec['categories'] = (int) ($item['categories'] ?? (count($spec['steps']) + 1));
                    }
                    $specs[] = $spec;
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
     * @param array $options 'questioncategoryid' (required), 'seed', 'poolseed', 'mutationseed', 'template'.
     * @return array|null planned and created counts, or null when unavailable.
     */
    public static function materialise(int $runid, array $definition, array $options = []): ?array {
        global $DB;

        if (!environment::engine_available()) {
            return null;
        }

        $categoryid = (int) ($options['questioncategoryid'] ?? 0);
        $scalemap = self::run_scalemap($runid);
        if ($categoryid <= 0 || $scalemap === []) {
            return null;
        }

        $model = model_catalog::normalise((string) ($definition['model'] ?? '2pl')) ?? '2pl';
        $polytomous = model_catalog::is_polytomous($model);
        $variant = (string) ($definition['pool']['variant'] ?? 'ideal');
        $recipe = (array) ($definition['pool']['recipe'] ?? []);
        $poolseed = (int) ($options['poolseed'] ?? $options['seed'] ?? 42);
        $mutationseed = (int) ($options['mutationseed'] ?? $poolseed);

        // Plan the ideal pool, then mutate it. Before this, mutate() was never
        // called at runtime, so a robustness cell ran on the ideal pool and
        // still reported success — the condition existed only in the cell key.
        $blueprint = pool_planner::plan($definition, $poolseed);
        $blueprint = pool_mutator::mutate($blueprint, $variant, $recipe, $mutationseed);

        $specs = self::plan_items($blueprint, $scalemap, ['model' => $model]);

        // An empty plan is a failure whatever the variant. The ideal pool used
        // to be exempt, which meant a run whose blueprint or scale map produced
        // nothing at all was reported as a good run with zero items.
        if ($specs === []) {
            return [
                'planned'              => 0,
                'questionscreated'     => 0,
                'itemsregistered'      => 0,
                'parametersregistered' => 0,
                'enginevisible'        => 0,
                'faileditems'          => 0,
                'created'              => 0,
                'variant'              => $variant,
                'model'                => $model,
                'failed'               => true,
                'reason'               => self::REASON_EMPTY_PLAN,
                'errors'               => [],
            ];
        }

        // A run that is already fully materialised keeps its pool. Without this
        // a second provisioning built the whole pool again: six more questions,
        // six more engine items, and a ground truth describing twice as many
        // items as the run actually used.
        $existing = self::existing_pool($runid, count($specs), $options['verify'] ?? true);
        if ($existing !== null) {
            return $existing;
        }

        $poolid = self::record_pool($runid, $variant, $recipe, $poolseed, $mutationseed);
        $template = $options['template'] ?? null;
        $verify = $options['verify'] ?? true;

        $counts = [
            'planned'              => count($specs),
            'questionscreated'     => 0,
            'itemsregistered'      => 0,
            'parametersregistered' => 0,
            'enginevisible'        => 0,
            'faileditems'          => 0,
        ];
        $errors = [];

        foreach ($specs as $spec) {
            $rendered = question_template::render($spec + ['polytomous' => $polytomous], $template);
            $questionid = self::create_question($categoryid, $rendered);
            if ($questionid <= 0) {
                $counts['faileditems']++;
                $errors = self::record_error($errors, [
                    'itemname' => $spec['itemname'] ?? '',
                    'reason'   => self::REASON_NO_QUESTION,
                ]);
                continue;
            }
            $counts['questionscreated']++;

            // The engine's verdict is binding. Counting an item before it is
            // registered — as this did — turned "a question row exists" into
            // "the CAT engine can play this item", which are different claims.
            $outcome = cat_item_provisioner::provision(
                $questionid,
                (int) $spec['catscaleid'],
                (int) $spec['contextid'],
                $spec,
                ['verify' => $verify]
            );

            if ($outcome['itemid'] !== null) {
                $counts['itemsregistered']++;
            }
            if ($outcome['paramid'] !== null) {
                $counts['parametersregistered']++;
            }

            if (!$outcome['ok']) {
                $counts['faileditems']++;
                $errors = self::record_error($errors, [
                    'itemname'    => $spec['itemname'] ?? '',
                    'questionid'  => $questionid,
                    'catscaleid'  => (int) $spec['catscaleid'],
                    'reason'      => (string) $outcome['reason'],
                    'engineerror' => $outcome['engineerror'],
                ]);
                continue;
            }

            $counts['enginevisible']++;
            // Ground truth is written only for an item the engine can actually
            // retrieve, so an audit row never describes an item that is not
            // part of the realised pool.
            self::record_ground_truth($runid, $poolid, $questionid, $spec);
        }

        if ($poolid > 0) {
            $DB->set_field('local_catquizlab_pool', 'itemcount', $counts['enginevisible'], ['id' => $poolid]);
            $DB->set_field('local_catquizlab_pool', 'questioncategoryid', $categoryid, ['id' => $poolid]);
        }

        $failed = $counts['faileditems'] > 0 || $counts['enginevisible'] !== $counts['planned'];

        return $counts + [
            // Kept so existing readers of the old key keep working; it now
            // means "items the engine can retrieve", which is what a caller
            // asking "how many were created" actually wants to know.
            'created' => $counts['enginevisible'],
            'variant' => $variant,
            'poolid'  => $poolid,
            'model'   => $model,
            'failed'  => $failed,
            'reason'  => $failed ? self::failure_reason($counts, $errors) : null,
            'errors'  => $errors,
        ];
    }

    /**
     * The result of a run whose pool is already complete, if it is.
     *
     * Completeness is judged the same way a fresh materialisation is: every
     * recorded item must still be retrievable through the engine's own path.
     * A pool that only half survived is rebuilt rather than trusted.
     *
     * @param int $runid The run.
     * @param int $planned How many items the plan calls for.
     * @param bool $verify Whether to check engine visibility.
     * @return array|null The stage result, or null when there is nothing to reuse.
     */
    protected static function existing_pool(int $runid, int $planned, bool $verify): ?array {
        global $DB;

        $items = $DB->get_records('local_catquizlab_item', ['runid' => $runid]);
        if (count($items) !== $planned || $planned === 0) {
            return null;
        }

        $visible = 0;
        foreach ($items as $item) {
            if (
                !$verify || cat_item_provisioner::is_visible(
                    (int) $item->questionid,
                    (int) $item->assignedcatscaleid,
                    self::context_of_item($item)
                )
            ) {
                $visible++;
            }
        }

        if ($visible !== $planned) {
            return null;
        }

        $pool = $DB->get_records('local_catquizlab_pool', ['runid' => $runid], 'id DESC', '*', 0, 1);
        $pool = $pool ? reset($pool) : null;

        return [
            'planned'              => $planned,
            'questionscreated'     => $planned,
            'itemsregistered'      => $planned,
            'parametersregistered' => $planned,
            'enginevisible'        => $planned,
            'faileditems'          => 0,
            'created'              => $planned,
            'variant'              => $pool ? (string) $pool->variant : 'ideal',
            'poolid'               => $pool ? (int) $pool->id : 0,
            'model'                => (string) ($items ? reset($items)->model : ''),
            'failed'               => false,
            'reason'               => null,
            'errors'               => [],
            'reused'               => true,
        ];
    }

    /**
     * The CAT context an already-materialised item lives in.
     *
     * @param \stdClass $item The ground-truth row.
     * @return int
     */
    protected static function context_of_item(\stdClass $item): int {
        global $DB;

        $row = $DB->get_record('local_catquiz_items', [
            'componentid'   => (int) $item->questionid,
            'componentname' => 'question',
            'catscaleid'    => (int) $item->assignedcatscaleid,
        ]);

        return $row ? (int) $row->contextid : 0;
    }

    /**
     * The reason that best describes a failed materialisation.
     *
     * The first broken link in the chain is the useful one: knowing that the
     * questions were created but nothing was registered points at the engine
     * boundary, while "0 visible" alone does not.
     *
     * @param array $counts The stage counters.
     * @param array $errors The recorded item errors.
     * @return string
     */
    protected static function failure_reason(array $counts, array $errors): string {
        if ($counts['questionscreated'] < $counts['planned']) {
            return self::REASON_NO_QUESTION;
        }
        if ($counts['itemsregistered'] < $counts['planned']) {
            return cat_item_provisioner::REASON_ASSIGNMENT;
        }
        if ($counts['parametersregistered'] < $counts['planned']) {
            return cat_item_provisioner::REASON_PARAMETERS;
        }
        if ($counts['enginevisible'] < $counts['planned']) {
            return cat_item_provisioner::REASON_NOT_VISIBLE;
        }

        return $errors[0]['reason'] ?? self::REASON_INCOMPLETE;
    }

    /**
     * Append an item error, keeping the list bounded.
     *
     * A run of 2500 items can fail 2500 times for the same reason; the first
     * few plus the total tell a reader everything the full list would.
     *
     * @param array $errors The errors so far.
     * @param array $error The error to add.
     * @return array
     */
    protected static function record_error(array $errors, array $error): array {
        if (count($errors) < self::MAX_RECORDED_ERRORS) {
            $errors[] = $error;
        }

        return $errors;
    }

    /**
     * Record the realised pool of a run.
     *
     * The pool table used to be schema-only. Writing the variant, its recipe
     * and both seeds here is what makes a robustness condition auditable after
     * the fact: without it, "this cell ran on a gappy pool" is a claim about
     * the cell key rather than about the items that were played.
     *
     * @param int $runid The run.
     * @param string $variant The pool variant.
     * @param array $recipe The variant recipe, defaults already applied.
     * @param int $poolseed Seed of the ideal blueprint.
     * @param int $mutationseed Seed of the mutation.
     * @return int The pool row id.
     */
    protected static function record_pool(
        int $runid,
        string $variant,
        array $recipe,
        int $poolseed,
        int $mutationseed
    ): int {
        global $DB;

        $experimentid = (int) $DB->get_field('local_catquizlab_run', 'experimentid', ['id' => $runid]);
        $now = time();

        return (int) $DB->insert_record('local_catquizlab_pool', (object) [
            'experimentid'       => $experimentid,
            'runid'              => $runid,
            'variant'            => $variant,
            'recipejson'         => json_encode(
                pool_mutator::apply_recipe_defaults($variant, $recipe),
                JSON_UNESCAPED_SLASHES
            ),
            'poolseed'           => $poolseed,
            'mutationseed'       => $mutationseed,
            'itemcount'          => 0,
            'scaleid'            => null,
            'questioncategoryid' => null,
            'timecreated'        => $now,
            'timemodified'       => $now,
        ]);
    }

    /**
     * Record one item's ground truth alongside the engine's view of it.
     *
     * @param int $runid The run.
     * @param int $poolid The realised pool.
     * @param int $questionid The generated question.
     * @param array $spec The item spec from {@see self::plan_items()}.
     * @return void
     */
    protected static function record_ground_truth(int $runid, int $poolid, int $questionid, array $spec): void {
        global $DB;

        $DB->insert_record('local_catquizlab_item', (object) [
            'runid'              => $runid,
            'poolid'             => $poolid,
            'questionid'         => $questionid,
            'itemname'           => (string) $spec['itemname'],
            'model'              => (string) $spec['model'],
            'truedifficulty'     => (float) $spec['truedifficulty'],
            'storeddifficulty'   => (float) $spec['difficulty'],
            'discrimination'     => (float) $spec['discrimination'],
            'guessing'           => (float) $spec['guessing'],
            'stepsjson'          => isset($spec['steps']) ? json_encode($spec['steps']) : null,
            'truecatscaleid'     => (int) $spec['truecatscaleid'],
            'assignedcatscaleid' => (int) $spec['catscaleid'],
            'truecategory'       => (int) $spec['truecategory'],
            'truesubscale'       => (int) $spec['truesubscale'],
            'miscalibrated'      => !empty($spec['miscalibrated']) ? 1 : 0,
            'mistagged'          => !empty($spec['mistagged']) ? 1 : 0,
            'timecreated'        => time(),
        ]);
    }

    /**
     * The recorded ground truth of a question, or null when it is not a lab item.
     *
     * @param int $questionid The question.
     * @param int|null $runid Restrict to one run when known.
     * @return \stdClass|null
     */
    public static function ground_truth_for_question(int $questionid, ?int $runid = null): ?\stdClass {
        global $DB;

        $conditions = ['questionid' => $questionid];
        if ($runid !== null) {
            $conditions['runid'] = $runid;
        }
        $record = $DB->get_records('local_catquizlab_item', $conditions, 'id DESC', '*', 0, 1);

        return $record ? reset($record) : null;
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
        global $USER, $DB, $CFG;

        // Moodle autoloads question_bank only where something has already
        // pulled the question library in. A web request usually has; a CLI run,
        // a scheduled task and a web service have not, so materialising from
        // the command line died on a missing class rather than on anything to
        // do with the pool.
        require_once($CFG->libdir . '/questionlib.php');

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
