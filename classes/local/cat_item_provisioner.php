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
 * The boundary between the lab and the CAT engine.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Registers questions as usable CAT items, and proves that they are.
 *
 * The lab used to define "materialised" as "a Moodle question row exists and we
 * wrote something into the engine's tables". That is not the same claim. An
 * insert succeeding says nothing about whether local_catquiz can retrieve the
 * item afterwards, and the engine can refuse an assignment outright — it does
 * so, for instance, when the item already belongs to a related parent or
 * subscale. A run could therefore report success while the CAT manager showed
 * no questions at all for its scales.
 *
 * So this class draws the boundary in one place and holds it to the engine's
 * own contract:
 *
 * - assignment goes through catscale::add_or_update_testitem_to_scale() and its
 *   result is binding; an err() is a failure, not a hint,
 * - the item parameters are written and activated,
 * - and the item is then looked up again through the same retrieval path the
 *   CAT manager and the test strategy use.
 *
 * Only an item that survives all three counts as materialised. Everything else
 * is reported with the step it failed at, so a broken installation can be
 * diagnosed without comparing tables by hand.
 */
class cat_item_provisioner {
    /** @var string The engine is not installed. */
    public const REASON_NO_ENGINE = 'engine-not-available';

    /** @var string The engine refused to assign the question to the scale. */
    public const REASON_ASSIGNMENT = 'engine-item-registration-failed';

    /** @var string The item row could not be located after assignment. */
    public const REASON_NO_ITEM_ROW = 'engine-item-row-missing';

    /** @var string The item parameters could not be stored or activated. */
    public const REASON_PARAMETERS = 'engine-itemparams-failed';

    /** @var string The item is not retrievable through the engine's own path. */
    public const REASON_NOT_VISIBLE = 'engine-item-not-visible';

    /**
     * Register one question as a fully usable CAT item.
     *
     * @param int $questionid The Moodle question id.
     * @param int $catscaleid The engine scale the item belongs to.
     * @param int $contextid The engine CAT context.
     * @param array $params The item parameters (see {@see item_registrar::build_itemparam()}).
     * @param array $options 'verify' (default true) runs the retrieval check.
     * @return array{ok: bool, itemid: ?int, paramid: ?int, reason: ?string,
     *               questionid: int, catscaleid: int, engineerror: ?string}
     */
    public static function provision(
        int $questionid,
        int $catscaleid,
        int $contextid,
        array $params,
        array $options = []
    ): array {
        global $DB;

        $result = [
            'ok'          => false,
            'itemid'      => null,
            'paramid'     => null,
            'reason'      => null,
            'questionid'  => $questionid,
            'catscaleid'  => $catscaleid,
            'engineerror' => null,
        ];

        if (!environment::engine_available() || !class_exists('\local_catquiz\catscale')) {
            return ['reason' => self::REASON_NO_ENGINE] + $result;
        }

        // Step 1: the assignment, and its verdict.
        $assignment = self::assign($catscaleid, $questionid);
        if (!$assignment['ok']) {
            return [
                'reason'      => self::REASON_ASSIGNMENT,
                'engineerror' => $assignment['error'],
            ] + $result;
        }

        // Step 2: the item row the parameters attach to. The engine's API
        // returns its id, which is preferred over looking it up again.
        $itemid = $assignment['itemid'] ?? self::find_item($questionid, $catscaleid);
        if ($itemid === null) {
            return ['reason' => self::REASON_NO_ITEM_ROW] + $result;
        }
        $result['itemid'] = $itemid;

        // Step 3: the parameters, written once per item and context so a retry
        // cannot leave several competing sets behind with the active one
        // decided by whichever run went last.
        try {
            $paramid = self::write_parameters($itemid, $questionid, $contextid, $params);
        } catch (\Throwable $e) {
            return ['reason' => self::REASON_PARAMETERS, 'engineerror' => $e->getMessage()] + $result;
        }
        if ($paramid === null) {
            return ['reason' => self::REASON_PARAMETERS] + $result;
        }
        $result['paramid'] = $paramid;

        $DB->update_record('local_catquiz_items', (object) [
            'id'            => $itemid,
            'activeparamid' => $paramid,
            'contextid'     => $contextid,
        ]);

        // Step 4: the proof. Counting rows in local_catquiz_items would miss
        // exactly the inconsistencies this check exists to catch.
        if (($options['verify'] ?? true) && !self::is_visible($questionid, $catscaleid, $contextid)) {
            return ['reason' => self::REASON_NOT_VISIBLE] + $result;
        }

        $result['ok'] = true;

        return $result;
    }

    /**
     * Ask the engine to assign a question to a scale.
     *
     * @param int $catscaleid The scale.
     * @param int $questionid The question.
     * @return array{ok: bool, itemid: ?int, error: ?string}
     */
    protected static function assign(int $catscaleid, int $questionid): array {
        try {
            $outcome = \local_catquiz\catscale::add_or_update_testitem_to_scale($catscaleid, $questionid);
        } catch (\Throwable $e) {
            return ['ok' => false, 'itemid' => null, 'error' => $e->getMessage()];
        }

        // The engine returns a result object. Older builds returned nothing at
        // all, which is treated as "no verdict" rather than as a refusal.
        if (is_object($outcome) && method_exists($outcome, 'iserr')) {
            if ($outcome->iserr()) {
                $message = method_exists($outcome, 'geterrormessage')
                    ? (string) $outcome->geterrormessage()
                    : (string) $outcome->get_status();

                return ['ok' => false, 'itemid' => null, 'error' => $message];
            }
            $value = method_exists($outcome, 'unwrap') ? $outcome->unwrap() : null;

            return ['ok' => true, 'itemid' => is_numeric($value) ? (int) $value : null, 'error' => null];
        }

        return ['ok' => true, 'itemid' => is_numeric($outcome) ? (int) $outcome : null, 'error' => null];
    }

    /**
     * Locate the engine item row of a question in a scale.
     *
     * @param int $questionid The question.
     * @param int $catscaleid The scale.
     * @return int|null
     */
    protected static function find_item(int $questionid, int $catscaleid): ?int {
        global $DB;

        $id = $DB->get_field('local_catquiz_items', 'id', [
            'componentid'   => $questionid,
            'componentname' => 'question',
            'catscaleid'    => $catscaleid,
        ]);

        return $id ? (int) $id : null;
    }

    /**
     * Write or refresh the item parameters of an item in a context.
     *
     * @param int $itemid The engine item row.
     * @param int $questionid The question.
     * @param int $contextid The CAT context.
     * @param array $params The item parameters.
     * @return int|null The parameter row id.
     */
    protected static function write_parameters(int $itemid, int $questionid, int $contextid, array $params): ?int {
        global $DB;

        $now = time();
        $fields = item_registrar::build_itemparam($questionid, $contextid, $params);

        // One parameter set per item and context: re-running a materialisation
        // updates it rather than adding a competitor.
        $existing = $DB->get_record('local_catquiz_itemparams', [
            'componentid'   => $questionid,
            'componentname' => 'question',
            'contextid'     => $contextid,
            'itemid'        => $itemid,
        ]);

        if ($existing) {
            $DB->update_record('local_catquiz_itemparams', (object) ($fields + [
                'id'           => $existing->id,
                'itemid'       => $itemid,
                'timemodified' => $now,
            ]));

            return (int) $existing->id;
        }

        $id = $DB->insert_record('local_catquiz_itemparams', (object) ($fields + [
            'itemid'       => $itemid,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]));

        return $id ? (int) $id : null;
    }

    /**
     * Whether the engine can retrieve a question as an item of a scale.
     *
     * Uses catscale::get_testitems(), which is what the CAT manager and the
     * test strategy use. An item that is not found here is not usable in a
     * test, whatever the tables say.
     *
     * @param int $questionid The question.
     * @param int $catscaleid The scale.
     * @param int $contextid The CAT context.
     * @return bool
     */
    public static function is_visible(int $questionid, int $catscaleid, int $contextid): bool {
        foreach (self::visible_items($catscaleid, $contextid) as $item) {
            $componentid = $item->componentid ?? $item->id ?? null;
            if ((int) $componentid === $questionid) {
                return true;
            }
        }

        return false;
    }

    /**
     * The items the engine reports for a scale in a context.
     *
     * @param int $catscaleid The scale.
     * @param int $contextid The CAT context.
     * @param bool $includesubscales Whether to include items of subscales.
     * @return array The engine's item records, empty when the engine is absent.
     */
    public static function visible_items(int $catscaleid, int $contextid, bool $includesubscales = false): array {
        if (!environment::engine_available() || !class_exists('\local_catquiz\catscale')) {
            return [];
        }

        try {
            $scale = new \local_catquiz\catscale($catscaleid);
            $items = $scale->get_testitems($contextid, $includesubscales);
        } catch (\Throwable $e) {
            return [];
        }

        return is_array($items) ? $items : [];
    }

    /**
     * How many items the engine reports for a scale.
     *
     * @param int $catscaleid The scale.
     * @param int $contextid The CAT context.
     * @param bool $includesubscales Whether to include items of subscales.
     * @return int
     */
    public static function visible_count(int $catscaleid, int $contextid, bool $includesubscales = false): int {
        return count(self::visible_items($catscaleid, $contextid, $includesubscales));
    }

    /**
     * A readable explanation of a failure reason.
     *
     * @param string $reason One of the REASON_* constants.
     * @return string
     */
    public static function reason_label(string $reason): string {
        $keys = [
            self::REASON_NO_ENGINE   => 'provision:noengine',
            self::REASON_ASSIGNMENT  => 'provision:assignmentfailed',
            self::REASON_NO_ITEM_ROW => 'provision:noitemrow',
            self::REASON_PARAMETERS  => 'provision:parametersfailed',
            self::REASON_NOT_VISIBLE => 'provision:notvisible',
        ];

        return isset($keys[$reason])
            ? get_string($keys[$reason], 'local_catquizlab')
            : $reason;
    }
}
