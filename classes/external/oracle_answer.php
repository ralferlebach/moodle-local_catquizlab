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
 * External function: response oracle for the Puppeteer worker.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_catquizlab\local\environment;
use local_catquizlab\local\item_repository;
use local_catquizlab\local\materialiser;
use local_catquizlab\local\seed_domains;
use local_catquizlab\local\response_oracle;
use local_catquizlab\local\scale_provisioner;
use local_catquizlab\local\test_binder;

/**
 * Returns the answer a simulated person gives to a presented item.
 *
 * The worker is a dumb executor: for every question it reads from the real
 * attempt UI it calls this function, which is where all simulation logic will
 * live — the ground-truth ability profile, the IRT likelihood of the model
 * under test, seeded randomness, and (for the DPF sensitivity conditions)
 * deliberate local deviations. Keeping it server-side means the worker never
 * embeds any of the experiment logic.
 *
 * Stub scope (round E0): the endpoint authenticates, validates its parameters
 * and returns a well-formed "not ready" response. The actual likelihood
 * computation against the catmodel_* subplugins lands in E3.4.
 */
class oracle_answer extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'runid'      => new external_value(PARAM_INT, 'Lab run id the attempt belongs to.'),
            'questionid' => new external_value(PARAM_INT, 'Moodle question id of the presented item.'),
            'attemptid'  => new external_value(
                PARAM_INT,
                'Lab attempt the question belongs to. The worker calls this with its own token, '
                    . 'so the person cannot be inferred from the logged-in user.',
                VALUE_DEFAULT,
                0
            ),
            'qubaid'     => new external_value(
                PARAM_INT,
                'Question usage id, as rendered in the page markup. With the slot it identifies the '
                    . 'presented question; the browser never sees a question id.',
                VALUE_DEFAULT,
                0
            ),
            'slot'       => new external_value(PARAM_INT, 'Slot within the question usage.', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Return the simulated answer for one presented item.
     *
     * @param int $runid Lab run id.
     * @param int $questionid Moodle question id, or 0 to resolve from the usage.
     * @param int $attemptid The lab attempt, naming the person the worker is playing.
     * @param int $qubaid Question usage id from the page markup.
     * @param int $slot Slot within that usage.
     * @return array The oracle response.
     */
    public static function execute(
        int $runid,
        int $questionid,
        int $attemptid = 0,
        int $qubaid = 0,
        int $slot = 0
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'runid'      => $runid,
            'questionid' => $questionid,
            'attemptid'  => $attemptid,
            'qubaid'     => $qubaid,
            'slot'       => $slot,
        ]);
        unset($params);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/catquizlab:worker', $context);

        if (!environment::engine_available() || !environment::adaptivequiz_available()) {
            return self::not_ready(get_string('oracle:notready', 'local_catquizlab'));
        }

        // The caller is the simulated test-taker driving their own attempt through
        // the UI. Resolve the run's bound test, the person and the presented item;
        // then answer with the seed-deterministic, subscale-aware response oracle.
        // The page markup identifies a question by usage and slot, not by
        // question id: Moodle renders question-{qubaid}-{slot}. The worker used
        // to read the first number out of that and send it as a question id,
        // which is the usage id — so the oracle looked up an item that could
        // not exist and reported itself as not ready at every single question.
        if ($questionid <= 0 && $qubaid > 0 && $slot > 0) {
            $questionid = self::question_id_from_usage($qubaid, $slot);
        }

        $resolved = self::resolve($runid, $questionid, $attemptid);
        if ($resolved === null) {
            return self::not_ready(get_string('oracle:notready', 'local_catquizlab'));
        }

        $correct = self::compute($runid, $questionid, $resolved);

        return [
            'ready'    => true,
            'fraction' => $correct['fraction'],
            'choice'   => $correct['choice'],
            'message'  => get_string('oracle:computed', 'local_catquizlab'),
        ];
    }

    /**
     * The question id behind a usage and slot.
     *
     * @param int $qubaid The question usage id.
     * @param int $slot The slot within it.
     * @return int The question id, or 0 when it cannot be resolved.
     */
    protected static function question_id_from_usage(int $qubaid, int $slot): int {
        global $CFG;
        require_once($CFG->dirroot . '/question/engine/lib.php');

        try {
            $usage = \question_engine::load_questions_usage_by_activity($qubaid);
            $question = $usage->get_question($slot);
        } catch (\Throwable $e) {
            return 0;
        }

        return (int) ($question->id ?? 0);
    }

    /**
     * Resolve the run's test config, the person and the presented item.
     *
     * @param int $runid The lab run id.
     * @param int $questionid The presented question id.
     * @param int $attemptid The lab attempt naming the person, or 0 to use the logged-in user.
     * @return array|null ['item' => ..., 'person' => ...], or null when not answerable.
     */
    protected static function resolve(int $runid, int $questionid, int $attemptid = 0): ?array {
        global $DB, $USER;

        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
        if (!$run || empty($run->testcmid)) {
            return null;
        }
        $config = test_binder::read_test_config((int) $run->testcmid);

        // The person comes from the attempt when the caller names one. The
        // worker drives the browser as the simulated user but calls this web
        // service with its own token, so $USER is the worker account and never
        // matches a person — the oracle then reported itself as not ready and
        // every attempt died at its first question. $USER stays as the fallback
        // for a call that genuinely comes from the test-taker.
        $person = null;
        if ($attemptid > 0) {
            $attempt = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid, 'runid' => $runid]);
            if ($attempt) {
                $person = $DB->get_record('local_catquizlab_person', ['id' => $attempt->personid]);
            }
        }
        if (!$person) {
            $person = $DB->get_record('local_catquizlab_person', [
                'runid' => $runid, 'moodleuserid' => $USER->id,
            ]);
        }
        if ($config === null || !$person) {
            return null;
        }
        $item = item_repository::for_question((int) $config['contextid'], $questionid);
        if ($item === null) {
            return null;
        }

        return ['item' => $item, 'person' => $person];
    }

    /**
     * Compute the seed-deterministic response for a resolved item.
     *
     * @param int $runid The run id.
     * @param int $questionid The question id.
     * @param array $resolved The resolved item and person.
     * @return array{fraction: float, choice: int} The score fraction and chosen category.
     */
    protected static function compute(int $runid, int $questionid, array $resolved): array {
        global $DB;

        $item = $resolved['item'];
        $person = $resolved['person'];
        $truth = materialiser::ground_truth_for_question($questionid, $runid);

        // Which ability governs this item is a question about the item's true
        // content, not about the tag it was imported with. Reading the engine's
        // catscaleid here — as this did before — would make a deliberately
        // mistagged item genuinely belong to the wrong subscale, and the
        // tagging-error condition would cancel itself out.
        $categoryindex = null;
        $subscaleindex = null;
        if ($truth !== null && (int) $truth->truecategory > 0) {
            $categoryindex = (int) $truth->truecategory;
            $subscaleindex = (int) $truth->truesubscale;
        } else {
            $mapping = scale_provisioner::mapping_for($runid, (int) $item['catscaleid']);
            $categoryindex = $mapping['categoryindex'] ?? null;
            $subscaleindex = $mapping['subscaleindex'] ?? null;
        }

        $profile = json_decode((string) $person->profilejson, true) ?: [];
        $ability = response_oracle::ability_for($profile, $categoryindex, $subscaleindex);
        $ability = response_oracle::deviant_ability(
            $ability,
            $profile['deviance'] ?? null,
            $categoryindex,
            $subscaleindex
        );

        // Likewise for the parameters: the oracle answers against the true
        // a/b/c, while the engine works from the stored ones. Under a
        // calibration error the two differ, and that difference is the
        // condition being tested.
        if ($truth !== null) {
            $item['difficulty'] = (float) $truth->truedifficulty;
            $item['discrimination'] = (float) $truth->discrimination;
            $item['guessing'] = (float) $truth->guessing;
            $item['model'] = (string) $truth->model;
            if (!empty($truth->stepsjson)) {
                $item['steps'] = json_decode((string) $truth->stepsjson, true) ?: [];
            }
        }

        $masterseed = (int) ($DB->get_field('local_catquizlab_run', 'masterseed', ['id' => $runid]) ?: 0);
        $seed = seed_domains::response($masterseed, $runid, (int) $person->id, $questionid);

        return response_oracle::respond_item($ability, $item, $seed);
    }

    /**
     * Build a well-formed "not ready" response.
     *
     * @param string $message The status message.
     * @return array The response.
     */
    protected static function not_ready(string $message): array {
        return [
            'ready'    => false,
            'fraction' => 0.0,
            'choice'   => -1,
            'message'  => $message,
        ];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ready'    => new external_value(PARAM_BOOL, 'True when a real answer was computed; false while the oracle is a stub.'),
            'fraction' => new external_value(PARAM_FLOAT, 'Score fraction in [0,1] for dichotomous items (0 when not ready).'),
            'choice'   => new external_value(PARAM_INT, 'Chosen answer category for polytomous items, or -1 when not applicable.'),
            'message'  => new external_value(PARAM_TEXT, 'Human-readable status or explanation.'),
        ]);
    }
}
