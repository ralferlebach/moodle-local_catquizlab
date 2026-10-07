<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Whether a sitting's results are valid, by the CAT engine's own definitions.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * The engine's validity, measured with the engine's functions and judged two ways (#112).
 *
 * The definitions are local_catquiz's, not this plugin's:
 *
 * - SE: a scale is not valid with a standard error above the test's
 *   catquiz_standarderror_max (feedbacksettings::filter_semax);
 * - items per scale: fewer answered productive items than the test's
 *   catquiz_minquestionspersubscale (filter_nminscale);
 * - items per test: fewer than catquiz_minquestions in all (filter_nmintest);
 * - response pattern: only 0 < share of points < 1 — all correct or all wrong
 *   leaves the ability undetermined (the strategies' "fraction" rule);
 * - measured: at least one answered item on the scale in this sitting
 *   (attempt_result_validator, issue #128).
 *
 * The measures are the engine's too: N and the share of points per scale from
 * its progress (answered, pilots excluded, a question counted for its scale and
 * every ancestor), the standard error from its stored result, the thresholds
 * from the settings of the test as it was provisioned.
 *
 * The engine applies these rules per strategy, and not every strategy applies
 * every rule: the classical test, allsubs and relsubs never check the response
 * pattern, fastest never checks the standard error or the items per scale.
 * Two verdicts therefore:
 *
 * - uniform: every rule for every strategy — the default of every figure;
 * - engine: attempt_result_validator's verdict, word for word, beside it.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine_validity {
    /** @var string Standard error above the bound. */
    public const SE_MAX = 'se_max';

    /** @var string No standard error to check against the bound. */
    public const SE_MISSING = 'se_missing';

    /** @var string Fewer items than the minimum, per scale or per test. */
    public const N_MIN = 'n_min';

    /** @var string All answers correct or all wrong. */
    public const FRACTION = 'fraction';

    /** @var string No answered item on the scale in this sitting. */
    public const NOT_MEASURED = 'not_measured';

    /** @var string The engine's data of the sitting could not be read. */
    public const NO_ENGINE_DATA = 'no_engine_data';

    /** @var string The uniform verdict: every rule for every strategy. */
    public const RULE_UNIFORM = 'uniform';

    /** @var string The engine's verdict, word for word. */
    public const RULE_ENGINE = 'engine';

    /**
     * Keep a sitting's measure for its subscales, which are read right after it.
     *
     * @param int $attemptid The sitting.
     * @param array|null $measure Its measure.
     * @return void
     */
    public static function remember(int $attemptid, ?array $measure): void {
        $memo = &self::memo();
        $memo['last'] = [$attemptid, $measure];
    }

    /**
     * A sitting's measure kept by remember(), or false when it is not the one kept.
     *
     * @param int $attemptid The sitting.
     * @return array|null|false
     */
    public static function recall(int $attemptid) {
        $memo = &self::memo();
        $last = $memo['last'] ?? null;

        return $attemptid > 0 && is_array($last) && $last[0] === $attemptid ? $last[1] : false;
    }

    /** @var array Thresholds, question scales and the last measure, for this request only — see memo(). */
    protected static array $memo = [];

    /**
     * The memo: static for speed, emptied by begin().
     *
     * @return array The memo, by reference.
     */
    protected static function &memo(): array {
        return self::$memo;
    }

    /**
     * Start afresh: every evaluation (results_query) calls this when it is made.
     *
     * A static map outlived the evaluation it belonged to: between tests run
     * and sitting ids repeat after the database is reset, and the scales of an
     * earlier run with the same id were measured. Moodle's request cache
     * avoided that, but asking it for every sitting cost a fifth more time at
     * fifty thousand sittings; a new evaluation is the moment that matters.
     *
     * @return void
     */
    public static function begin(): void {
        self::$memo = [];
    }

    /**
     * Whether the engine's classes are there to measure with.
     *
     * @return bool
     */
    public static function available(): bool {
        return class_exists(\local_catquiz\teststrategy\progress::class)
            && class_exists(\local_catquiz\local\result\attempt_result_validator::class)
            && method_exists(\local_catquiz\teststrategy\progress::class, 'load_for_reading');
    }

    /**
     * What the engine knows about a sitting: N, share of points and SE per scale, and its own verdict.
     *
     * Kept in the trace at collection, so that results do not ask the engine again.
     *
     * @param int $engineattemptid The adaptivequiz attempt.
     * @return array|null scales (catscaleid => n, fraction, se, ability), n, fraction, engine (valid, scales); null without data.
     */
    public static function measure(int $engineattemptid): ?array {
        global $DB;

        if ($engineattemptid <= 0 || !self::available()) {
            return null;
        }
        // Both spellings of the component: the engine writes 'mod_adaptivequiz'
        // when a sitting starts and 'adaptivequiz' once its result page saved it
        // (catquiz::component_names()).
        [$insql, $params] = $DB->get_in_or_equal(['adaptivequiz', 'mod_adaptivequiz'], SQL_PARAMS_NAMED, 'comp');
        $catattempt = $DB->get_record_select(
            'local_catquiz_attempts',
            "attemptid = :attemptid AND component {$insql}",
            ['attemptid' => $engineattemptid] + $params,
            'id, json',
            IGNORE_MULTIPLE
        );
        if (!$catattempt) {
            return null;
        }
        $data = json_decode((string) $catattempt->json, true);
        $data = is_array($data) ? $data : [];
        $se = is_array($data['se'] ?? null) ? $data['se'] : [];
        $abilities = $data['personabilities_abilities'] ?? $data['customscalefeedback_abilities'] ?? [];
        $abilities = is_array($abilities) ? $abilities : [];

        try {
            $progress = \local_catquiz\teststrategy\progress::load_for_reading((int) $catattempt->id);
        } catch (\Throwable $e) {
            $progress = null;
        }
        if ($progress === null) {
            return null;
        }
        $scaleids = array_map('intval', array_unique(array_merge(
            array_keys((array) $progress->get_playedquestions(true)),
            array_keys($abilities),
            array_keys($se)
        )));
        $scales = [];
        foreach ($scaleids as $scaleid) {
            if ($scaleid <= 0) {
                continue;
            }
            $value = $abilities[$scaleid]['value'] ?? ($abilities[$scaleid] ?? null);
            $scales[$scaleid] = [
                'n' => $progress->get_num_answered_productive_questions($scaleid),
                'fraction' => $progress->get_fraction_for_scale($scaleid),
                'se' => isset($se[$scaleid]) && is_numeric($se[$scaleid]) ? (float) $se[$scaleid] : null,
                'ability' => is_numeric($value) ? (float) $value : null,
            ];
        }

        // The engine's own verdict, word for word.
        $engine = ['valid' => null, 'scales' => []];
        try {
            $result = \local_catquiz\local\result\attempt_result_validator::validate($engineattemptid);
            $engine['valid'] = $result->get_scale_results() === [] ? null : $result->is_valid();
            foreach ($result->get_scale_results() as $scaleid => $scaleresult) {
                $engine['scales'][(int) $scaleid] = [
                    'valid' => $scaleresult->statisticallyvalid && $scaleresult->measuredincurrentattempt,
                    'reasons' => array_values(array_diff((array) $scaleresult->rejectionreasons, [
                        \local_catquiz\local\result\scale_result::REASON_NOT_PRIMARY,
                        \local_catquiz\local\result\scale_result::REASON_HIDDEN,
                        \local_catquiz\local\result\scale_result::REASON_REPORTING_DISABLED,
                    ])),
                ];
            }
        } catch (\Throwable $e) {
            $engine = ['valid' => null, 'scales' => []];
        }

        return [
            'scales' => $scales,
            'n' => $progress->get_num_answered_productive_questions(),
            'fraction' => $progress->get_fraction_for_scale(),
            'engine' => $engine,
        ];
    }

    /**
     * The same measures from the sitting's own trace, where the engine has none to read (#112).
     *
     * Same definitions as the engine's: an item counts for the scale it is filed
     * under and every ancestor; the share of points is the mean of the answered
     * items' fractions, clamped to [0, 1]; the SE is the one the trace kept. Pilot
     * items are not known here and count — the engine's measure does not. No
     * engine verdict: there is no engine data to give one.
     *
     * @param array $trace The sitting's trace.
     * @param int $runid The run.
     * @return array|null As measure(), with engine verdict null; null without responses.
     */
    public static function measure_from_trace(array $trace, int $runid): ?array {
        // Plain loops: this runs once for every sitting of a view, fifty
        // thousand times for the largest experiments.
        $map = self::question_scales($runid);
        $sums = [];
        $counts = [];
        $sum = 0.0;
        $n = 0;
        // The responses as the collector keeps them (question id => fraction),
        // or as the engine's progress does (question id => questionid, fraction).
        $responses = (array) ($trace['responses'] ?? []);
        if ($responses === []) {
            foreach ((array) ($trace['progress']['responses'] ?? []) as $questionid => $response) {
                $responses[$questionid] = is_array($response) ? ($response['fraction'] ?? null) : null;
            }
        }
        foreach ($responses as $questionid => $fraction) {
            if ($fraction === null || !is_numeric($fraction)) {
                continue;
            }
            $f = (float) $fraction;
            $f = $f < 0.0 ? 0.0 : ($f > 1.0 ? 1.0 : $f);
            $sum += $f;
            $n++;
            foreach ($map[(int) $questionid] ?? [] as $scaleid) {
                $sums[$scaleid] = ($sums[$scaleid] ?? 0.0) + $f;
                $counts[$scaleid] = ($counts[$scaleid] ?? 0) + 1;
            }
        }
        if ($n === 0) {
            return null;
        }
        $ses = (array) ($trace['scalestandarderrors'] ?? []);
        $scales = [];
        foreach ($counts as $scaleid => $count) {
            $se = $ses[$scaleid] ?? null;
            $scales[$scaleid] = [
                'n' => $count,
                'fraction' => $sums[$scaleid] / $count,
                'se' => is_numeric($se) ? (float) $se : null,
                'ability' => null,
            ];
        }

        return [
            'scales' => $scales,
            'n' => $n,
            'fraction' => $sum / $n,
            'engine' => ['valid' => null, 'scales' => []],
            'source' => 'trace',
        ];
    }

    /**
     * Question id => the scale it is filed under and its ancestors, for a run.
     *
     * @param int $runid The run.
     * @return array<int, int[]>
     */
    protected static function question_scales(int $runid): array {
        global $DB;

        $memo = &self::memo();
        if (isset($memo['questionscales'][$runid])) {
            return $memo['questionscales'][$runid];
        }
        $parents = $DB->get_records_menu(
            'local_catquizlab_scalemap',
            ['runid' => $runid],
            '',
            'catscaleid, parentcatscaleid'
        );
        $map = [];
        foreach (
            $DB->get_records(
                'local_catquizlab_item',
                ['runid' => $runid],
                '',
                'id, questionid, assignedcatscaleid, truecatscaleid'
            ) as $item
        ) {
            $scaleid = (int) ($item->assignedcatscaleid ?: $item->truecatscaleid);
            $chain = [];
            for ($guard = 0; $scaleid > 0 && $guard < 20; $guard++) {
                $chain[] = $scaleid;
                $scaleid = (int) ($parents[$scaleid] ?? 0);
            }
            if ((int) $item->questionid > 0 && $chain !== []) {
                $map[(int) $item->questionid] = $chain;
            }
        }
        $memo['questionscales'][$runid] = $map;

        return $map;
    }

    /**
     * The thresholds the engine ran the test with: from the settings of the run's test.
     *
     * @param int $runid The run.
     * @return array{nmintest: int, nminscale: int, semax: ?float, rootscaleid: int}
     */
    public static function thresholds(int $runid): array {
        $memo = &self::memo();
        if (isset($memo['thresholds'][$runid])) {
            return $memo['thresholds'][$runid];
        }
        $settings = provisioning_check::engine_settings($runid) ?? [];
        $semax = $settings['catquiz_standarderrorgroup']['catquiz_standarderror_max'] ?? null;
        $thresholds = [
            'nmintest' => (int) ($settings['maxquestionsgroup']['catquiz_minquestions'] ?? 0),
            'nminscale' => (int) ($settings['maxquestionsscalegroup']['catquiz_minquestionspersubscale'] ?? 0),
            'semax' => is_numeric($semax) && (float) $semax > 0 ? (float) $semax : null,
            'rootscaleid' => (int) ($settings['catquiz_catscales'] ?? 0),
        ];
        $memo['thresholds'][$runid] = $thresholds;

        return $thresholds;
    }

    /**
     * The uniform verdict on one scale: every rule of the engine.
     *
     * @param array|null $scale The scale's measures (n, fraction, se); null when the engine has none.
     * @param array $thresholds From thresholds().
     * @param bool $root Whether it is the test's root scale: then the minimum is the test's.
     * @return string[] Reasons it is not valid; empty when it is.
     */
    public static function judge_scale(?array $scale, array $thresholds, bool $root = false): array {
        if ($scale === null || (int) ($scale['n'] ?? 0) <= 0) {
            return [self::NOT_MEASURED];
        }
        $reasons = [];
        $minimum = $root ? (int) $thresholds['nmintest'] : (int) $thresholds['nminscale'];
        if ($minimum > 0 && (int) $scale['n'] < $minimum) {
            $reasons[] = self::N_MIN;
        }
        $fraction = $scale['fraction'] ?? null;
        if ($fraction !== null && ((float) $fraction <= 0.0 || (float) $fraction >= 1.0)) {
            $reasons[] = self::FRACTION;
        }
        if ($thresholds['semax'] !== null) {
            if (!isset($scale['se']) || $scale['se'] === null || !is_finite((float) $scale['se'])) {
                $reasons[] = self::SE_MISSING;
            } else if ((float) $scale['se'] > (float) $thresholds['semax']) {
                $reasons[] = self::SE_MAX;
            }
        }

        return $reasons;
    }

    /**
     * The uniform verdict on a sitting: its global result — the root scale — by every rule.
     *
     * @param array|null $measure From measure().
     * @param array $thresholds From thresholds().
     * @param float|null $finalse The trace's final standard error, where the engine stored none for the root.
     * @return string[] Reasons it is not valid; empty when it is.
     */
    public static function judge_attempt(?array $measure, array $thresholds, ?float $finalse = null): array {
        if ($measure === null) {
            return [self::NO_ENGINE_DATA];
        }
        $root = (int) $thresholds['rootscaleid'];
        $scale = $measure['scales'][$root] ?? null;
        // The test as a whole: the engine's own N and share over every answer.
        $global = [
            'n' => (int) ($measure['n'] ?? 0),
            'fraction' => $measure['fraction'] ?? null,
            'se' => $scale['se'] ?? $finalse,
        ];

        return self::judge_scale($global, $thresholds, true);
    }

    /**
     * The engine's own verdict on a sitting, word for word; null where it gave none.
     *
     * @param array|null $measure From measure().
     * @return bool|null
     */
    public static function engine_attempt(?array $measure): ?bool {
        $valid = $measure['engine']['valid'] ?? null;

        return $valid === null ? null : (bool) $valid;
    }

    /**
     * A reason's label.
     *
     * @param string $reason A reason code.
     * @return string
     */
    public static function label(string $reason): string {
        $key = 'validity:reason_' . $reason;

        return get_string_manager()->string_exists($key, 'local_catquizlab') ? get_string($key, 'local_catquizlab') : $reason;
    }

    /**
     * Forget cached thresholds — for tests.
     *
     * @return void
     */
    public static function reset(): void {
        self::$memo = [];
    }
}
