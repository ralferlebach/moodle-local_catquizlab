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

namespace local_catquizlab\local;

/**
 * What a run was asked for, what it was given, what the engine holds, and what happened (#123).
 *
 * A run's definition passes through four hands before a sitting ends: the
 * experiment and its sweep cell (requested), the provisioning that derives
 * the engine's options from it, neutralising what the strategy does not use
 * (effective), the engine's own record of the test (provisioned), and the
 * sittings themselves (observed). Each step could change a value without a
 * word. Here every parameter is followed through all four, and every sitting
 * is checked against the limits it was given by rules a machine can read: a
 * limit the engine overstepped is a defect, a minimum the pool could not
 * supply is a design violation, and neither passes unremarked.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_contract {
    /** @var string The values agree along the chain. */
    public const OK = 'ok';

    /** @var string Not used by the strategy, and given the engine's neutral value — said, not hidden. */
    public const NEUTRALISED = 'neutralised';

    /** @var string Not requested: the plugin's default was used, and the chain says which. */
    public const DEFAULTED = 'defaulted';

    /** @var string Requested and effective differ without a neutralisation: replaced. */
    public const REPLACED = 'replaced';

    /** @var string The engine holds something other than the effective value. */
    public const DRIFT = 'drift';

    /** @var string Sittings broke the limit the engine held. */
    public const VIOLATED = 'violated';

    /** @var string No provisioned test, or no sitting yet: nothing to compare. */
    public const UNKNOWN = 'unknown';

    /** @var array<string, string> Rule => kind: a defect of the engine or the plugin, or a violation of the design. */
    public const RULES = [
        'global_max_exceeded' => 'defect',
        'global_min_undercut' => 'defect',
        'pool_exhausted_before_min' => 'design',
        'subscale_max_exceeded' => 'defect',
        'classic_not_all_items' => 'defect',
    ];

    /** @var string[] Engine stop codes that mean the pool had nothing left to give. */
    public const POOL_EXHAUSTED = ['noremainingquestions', 'noremainingquestionsbeforeminimum', 'no_eligible_item_before_minimum'];

    /** @var array<int, array> Per run, its effective options and pool size, read once. */
    protected static array $limits = [];

    /**
     * The chain of one run: every parameter from request to observation.
     *
     * @param int $runid The run.
     * @return array rows (param, requested, effective, provisioned, observed, status, note), violations (rule => n),
     *      sittings (checked), ok (bool).
     */
    public static function chain(int $runid): array {
        global $DB;

        $record = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
        $none = ['rows' => [], 'violations' => [], 'violatingsittings' => [], 'sittings' => 0, 'ok' => false];
        try {
            $definition = $record ? run_registry::definition_for($record) : [];
        } catch (\Throwable $e) {
            // A definition that no longer reads: said, not thrown at whoever asked.
            return $none + ['reason' => 'definition: ' . $e->getMessage()];
        }
        if ($definition === [] || !isset($definition['strategy'])) {
            return $none + ['reason' => 'no-definition'];
        }
        $options = test_provisioner::options_from_definition($definition);
        $check = provisioning_check::compare($runid);
        $provisioned = array_map(static fn(array $pair) => $pair['actual'], $check['checked']);
        $observed = self::observed($runid);
        $na = (array) ($options['na'] ?? []);
        $strategy = (string) $definition['strategy'];
        $usesglobal = strategy_catalog::uses($strategy, 'globalmax');

        $global = (array) ($definition['budgets']['global'] ?? []);
        $subscale = (array) ($definition['budgets']['subscale'] ?? []);
        $se = (array) ($definition['budgets']['se'] ?? []);
        $range = ability_distribution::of($definition);

        $rows = [];
        $rows[] = self::row(
            'strategy',
            $strategy,
            (int) $options['teststrategy'],
            $provisioned['strategy'] ?? null,
            null,
            false
        );
        $rows[] = self::row(
            'maxquestions',
            $global['maxitems'] ?? null,
            (int) $options['maxquestions'],
            $provisioned['maxquestions'] ?? null,
            $observed['maxitems'],
            !$usesglobal,
            $observed['violations']['global_max_exceeded']
            ?? 0
        );
        $rows[] = self::row(
            'minquestions',
            $global['minitems'] ?? null,
            (int) $options['minquestions'],
            $provisioned['minquestions'] ?? null,
            $observed['minitems'],
            !$usesglobal,
            $observed['violations']['global_min_undercut'] ?? 0
        );
        $rows[] = self::row(
            'maxquestionspersubscale',
            $subscale['maxitems'] ?? null,
            (int) $options['maxquestionspersubscale'],
            $provisioned['maxquestionspersubscale'] ?? null,
            $observed['maxpersubscale'],
            !empty($na['subscale']),
            $observed['violations']['subscale_max_exceeded'] ?? 0
        );
        $rows[] = self::row(
            'minquestionspersubscale',
            $subscale['minitems'] ?? null,
            (int) $options['minquestionspersubscale'],
            $provisioned['minquestionspersubscale'] ?? null,
            null,
            !empty($na['subscale'])
        );
        $rows[] = self::row(
            'se_min',
            $se['min'] ?? null,
            round((float) $options['se_min'], 4),
            $provisioned['se_min'] ?? null,
            $observed['semin'],
            !empty($na['standarderror'])
        );
        $rows[] = self::row(
            'se_max',
            $se['max'] ?? null,
            round((float) $options['se_max'], 4),
            $provisioned['se_max'] ?? null,
            $observed['semax'],
            !empty($na['standarderror'])
        );
        $rows[] = self::row(
            'includepilot',
            isset($definition['pilot']['include']) ? (int) !empty($definition['pilot']['include'])
            : null,
            !empty($options['includepilot']) ? 1 : 0,
            $provisioned['includepilot'] ?? null,
            null,
            !empty($options['pilotneutralised'])
        );
        $rows[] = self::row(
            'scale_min',
            $range['min'],
            round((float) $range['min'], 4),
            $provisioned['scale_min'] ?? null,
            null,
            false
        );
        $rows[] = self::row(
            'scale_max',
            $range['max'],
            round((float) $range['max'], 4),
            $provisioned['scale_max'] ?? null,
            null,
            false
        );
        if (!$usesglobal) {
            // The classical test: every item, whatever a number said.
            $row = self::row(
                'allitems',
                strategy_parameters::ALL_ITEMS,
                $observed['poolsize'],
                null,
                $observed['maxitems'],
                false,
                $observed['violations']['classic_not_all_items'] ?? 0
            );
            if ($row['status'] === self::UNKNOWN && $observed['sittings'] > 0) {
                $row['status'] = self::OK;
            }
            $rows[] = $row;
        }

        $ok = true;
        foreach ($rows as $row) {
            if (in_array($row['status'], [self::REPLACED, self::DRIFT, self::VIOLATED], true)) {
                $ok = false;
            }
        }

        return [
            'rows' => $rows,
            'violations' => $observed['violations'],
            'violatingsittings' => $observed['violating'],
            'sittings' => $observed['sittings'],
            'ok' => $ok,
            'reason' => '',
        ];
    }

    /**
     * One parameter along the chain, with its status.
     *
     * @param string $param The parameter.
     * @param mixed $requested As the cell defines it; null where it says nothing.
     * @param mixed $effective As provisioning derived it.
     * @param mixed $provisioned As the engine holds it; null without a test.
     * @param mixed $observed What the sittings showed, where it can be read.
     * @param bool $neutralised Whether the strategy does not use it.
     * @param int $violations Sittings that broke it.
     * @return array
     */
    protected static function row(
        string $param,
        $requested,
        $effective,
        $provisioned,
        $observed,
        bool $neutralised,
        int $violations = 0
    ): array {
        $note = '';
        if ($provisioned !== null && $provisioned !== $effective) {
            $status = self::DRIFT;
        } else if ($violations > 0) {
            $status = self::VIOLATED;
            $note = (string) $violations;
        } else if ($neutralised) {
            $status = self::NEUTRALISED;
        } else if ($requested === null) {
            $status = self::DEFAULTED;
        } else if (
            is_numeric($requested) && is_numeric($effective) && (float) $requested !== (float) $effective
                && !($param === 'maxquestions' && (int) $effective === -1 && (int) $requested <= 0)
        ) {
            $status = self::REPLACED;
        } else {
            $status = $provisioned === null ? self::UNKNOWN : self::OK;
        }

        return [
            'param' => $param,
            'requested' => $requested,
            'effective' => $effective,
            'provisioned' => $provisioned,
            'observed' => $observed,
            'status' => $status,
            'note' => $note,
        ];
    }

    /**
     * What the run's collected sittings showed, and which of them broke a rule.
     *
     * @param int $runid The run.
     * @return array sittings, minitems, maxitems, maxpersubscale, semin, semax, poolsize, violations (rule => n),
     *      violating (attempt id => rules).
     */
    protected static function observed(int $runid): array {
        global $DB;

        $out = ['sittings' => 0, 'minitems' => null, 'maxitems' => null, 'maxpersubscale' => null, 'semin' => null,
            'semax' => null, 'poolsize' => (int) $DB->count_records('local_catquizlab_item', ['runid' => $runid]),
            'violations' => [], 'violating' => []];
        $map = engine_validity::question_scales($runid);
        $records = $DB->get_recordset_select(
            'local_catquizlab_attempt',
            'runid = :runid AND status IN (:collected, :validated)',
            ['runid' => $runid, 'collected' => attempt_scheduler::STATUS_COLLECTED,
                'validated' => attempt_scheduler::STATUS_VALIDATED],
            'id ASC',
            'id, tracejson'
        );
        try {
            foreach ($records as $record) {
                $trace = json_decode((string) $record->tracejson, true) ?: [];
                $rules = self::check_sitting($runid, $trace, $map);
                $out['sittings']++;
                $n = (int) ($trace['nitems'] ?? count((array) ($trace['items'] ?? [])));
                $out['minitems'] = $out['minitems'] === null ? $n : min($out['minitems'], $n);
                $out['maxitems'] = $out['maxitems'] === null ? $n : max($out['maxitems'], $n);
                if (isset($trace['finalse']) && is_numeric($trace['finalse'])) {
                    $finalse = round((float) $trace['finalse'], 4);
                    $out['semin'] = $out['semin'] === null ? $finalse : min($out['semin'], $finalse);
                    $out['semax'] = $out['semax'] === null ? $finalse : max($out['semax'], $finalse);
                }
                $leaf = self::max_per_subscale($trace, $runid, $map);
                if ($leaf !== null) {
                    $out['maxpersubscale'] = max((int) $out['maxpersubscale'], $leaf);
                }
                foreach ($rules as $rule) {
                    $out['violations'][$rule] = ($out['violations'][$rule] ?? 0) + 1;
                }
                if ($rules !== []) {
                    $out['violating'][(int) $record->id] = $rules;
                }
            }
        } finally {
            $records->close();
        }

        return $out;
    }

    /**
     * The rules a sitting broke, against the limits its run's test was given.
     *
     * @param int $runid The run.
     * @param array $trace The sitting's trace.
     * @param array|null $map Question id => scale chain; the run's own by default.
     * @param string $endcode The engine's stop code of the sitting; '' reads it from the trace. A pool
     *     exhausted before the minimum (e.g. noremainingquestionsbeforeminimum, #141) is reported as
     *     pool_exhausted_before_min, not as an undercut.
     * @return string[] Keys of RULES.
     */
    public static function check_sitting(int $runid, array $trace, ?array $map = null, string $endcode = ''): array {
        $limits = self::limits($runid);
        if ($limits === null) {
            return [];
        }
        $n = (int) ($trace['nitems'] ?? count((array) ($trace['items'] ?? [])));
        $code = $endcode !== '' ? $endcode : (reason_catalog::engine_code(
            (string) ($trace['stopreason'] ?? ''),
            isset($trace['enginestatus']) ? (int) $trace['enginestatus'] : null,
            isset($trace['hoststopcode']) && $trace['hoststopcode'] !== '' ? (string) $trace['hoststopcode'] : null
        ) ?? '');
        $rules = [];
        if (!$limits['usesglobal']) {
            if ($limits['poolsize'] > 0 && $n < $limits['poolsize']) {
                $rules[] = 'classic_not_all_items';
            }

            return $rules;
        }
        if ($limits['maxquestions'] > 0 && $n > $limits['maxquestions']) {
            $rules[] = 'global_max_exceeded';
        }
        // The minimum counts answered productive questions, the maximum every
        // question asked, pilots included — as the engine counts them since
        // local_catquiz 2026100909 (#141). The engine's count where the trace
        // carries it; the played items otherwise.
        $answered = isset($trace['enginevalidity']['n']) && is_numeric($trace['enginevalidity']['n'])
            ? (int) $trace['enginevalidity']['n'] : $n;
        if ($answered < $limits['minquestions']) {
            $rules[] = in_array($code, self::POOL_EXHAUSTED, true) ? 'pool_exhausted_before_min' : 'global_min_undercut';
        }
        if ($limits['maxpersubscale'] > 0) {
            $leaf = self::max_per_subscale($trace, $runid, $map);
            if ($leaf !== null && $leaf > $limits['maxpersubscale']) {
                $rules[] = 'subscale_max_exceeded';
            }
        }

        return $rules;
    }

    /**
     * The rules an observation of the results broke (#123 AK 4): for the sitting-level export.
     *
     * @param array $observation runid, nitems, items, stopreason, endreasoncode.
     * @return string[] Keys of RULES.
     */
    public static function check_observation(array $observation): array {
        $runid = (int) ($observation['runid'] ?? 0);
        if ($runid <= 0) {
            return [];
        }
        self::$maps[$runid] ??= engine_validity::question_scales($runid);
        $endcode = (string) ($observation['endreasoncode'] ?? '');

        return self::check_sitting($runid, [
            'nitems' => (int) ($observation['nitems'] ?? 0),
            'items' => (array) ($observation['items'] ?? []),
            'stopreason' => (string) ($observation['stopreason'] ?? ''),
        ], self::$maps[$runid], in_array($endcode, self::POOL_EXHAUSTED, true) ? $endcode : '');
    }

    /** @var array<int, array> Per run, its question-to-scale map, read once. */
    protected static array $maps = [];

    /**
     * The most items any subscale of its own got in a sitting: leaves only, read from the steps.
     *
     * @param array $trace The trace.
     * @param int $runid The run.
     * @param array|null $map Question id => scale chain.
     * @return int|null Null where the steps cannot say.
     */
    protected static function max_per_subscale(array $trace, int $runid, ?array $map): ?int {
        $exposure = scale_exposure::reconstruct($trace, $runid, $map);
        if ($exposure['source'] !== scale_exposure::SOURCE_STEPS || $exposure['direct'] === []) {
            return null;
        }

        return max($exposure['direct']);
    }

    /**
     * A run's effective limits, read once.
     *
     * @param int $runid The run.
     * @return array|null usesglobal, minquestions, maxquestions, maxpersubscale, poolsize; null without a definition.
     */
    protected static function limits(int $runid): ?array {
        global $DB;

        if (array_key_exists($runid, self::$limits)) {
            return self::$limits[$runid];
        }
        $record = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
        try {
            $definition = $record ? run_registry::definition_for($record) : [];
        } catch (\Throwable $e) {
            $definition = [];
        }
        if ($definition === [] || !isset($definition['strategy'])) {
            return self::$limits[$runid] = null;
        }
        $options = test_provisioner::options_from_definition($definition);

        return self::$limits[$runid] = [
            'usesglobal' => strategy_catalog::uses((string) $definition['strategy'], 'globalmax'),
            'minquestions' => (int) $options['minquestions'],
            'maxquestions' => (int) $options['maxquestions'],
            'maxpersubscale' => empty($options['na']['subscale']) ? (int) $options['maxquestionspersubscale'] : 0,
            'poolsize' => (int) $DB->count_records('local_catquizlab_item', ['runid' => $runid]),
        ];
    }

    /**
     * Forget what was read: for tests that change a run between two reads.
     *
     * @return void
     */
    public static function reset_caches(): void {
        self::$limits = [];
        self::$maps = [];
        engine_validity::reset();
    }
}
