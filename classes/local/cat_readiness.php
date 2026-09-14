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

namespace local_catquizlab\local;

/**
 * Can this run's CAT configuration actually deliver a first question?
 *
 * A structural preflight — scales exist, person parameters exist — is not
 * enough, and the reported installation proves it: everything checked out, and
 * 1600 attempts were queued against a test that could never select item one.
 * Every one of those jobs was going to fail the same way before the first
 * question, which costs a worker hour per few hundred and leaves a queue that
 * says nothing about why.
 *
 * What makes a configuration unable to start is arithmetic, and arithmetic can
 * be checked before anything is played:
 *
 * - A test cannot ask more questions than its pool can answer.
 * - Per-subscale minima multiply: twenty subscales at three questions each is
 *   sixty questions, whatever the global maximum says.
 * - Per-subscale maxima cap the total the same way, so a global minimum above
 *   that sum cannot be reached.
 * - An item the engine treats as a pilot question contributes nothing to the
 *   estimate, so a pool of pilots is an empty pool as far as selection goes.
 *
 * The item counts come from the engine's own retrieval path — the one the CAT
 * manager uses — rather than from the lab's record of what it created, because
 * the question is what the selection will see, not what was intended.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cat_readiness {
    /** @var int Parameter status from which the engine stops treating an item as a pilot. */
    public const STATUS_KNOWN = 4;

    /**
     * Check a run's CAT configuration against what its pool can deliver.
     *
     * @param int $runid The run to check.
     * @return array{ok: bool, reasons: string[], facts: array}
     */
    public static function check(int $runid): array {
        global $DB;

        $component = 'local_catquizlab';
        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
        if (!$run) {
            return self::verdict(false, [get_string('readiness:norun', $component)]);
        }

        if (!environment::catquiz_available()) {
            // Nothing to check against, and nothing to run either — the
            // preflight already refuses this, so it is not reported twice.
            return self::verdict(true, [], ['skipped' => 'engine-absent']);
        }

        $definition = json_decode((string) ($run->manifestjson ?? ''), true)['config']['definition'] ?? null;
        if (!is_array($definition)) {
            // Falling back to the experiment, and saying so if that is gone
            // too. A readiness check that throws is worse than one that fails:
            // the caller gets an exception where it expected a verdict, and the
            // provisioning stage around it turns into a stack trace rather than
            // a run marked as not ready.
            $configjson = (string) $DB->get_field(
                'local_catquizlab_experiment',
                'configjson',
                ['id' => $run->experimentid]
            );

            try {
                $definition = experiment_definition::from_json($configjson)->get_normalised();
            } catch (\Throwable $e) {
                return self::verdict(false, [
                    get_string('readiness:nodefinition', 'local_catquizlab'),
                ], ['leaves' => 0, 'items' => 0, 'usable' => 0, 'perleaf' => []]);
            }
        }

        $facts = self::pool_facts($runid);
        $facts['strategy'] = (string) ($definition['strategy'] ?? '');
        $facts['enforcespersubscale'] = $facts['strategy'] !== ''
            && strategy_catalog::has($facts['strategy'])
            && strategy_catalog::enforces_per_subscale_minimum($facts['strategy']);

        $reasons = array_merge(
            self::check_pool_exists($facts, $component),
            self::check_budgets($definition, $facts, $component)
        );

        return self::verdict($reasons === [], $reasons, $facts);
    }

    /**
     * What the engine can actually retrieve for this run.
     *
     * @param int $runid The run.
     * @return array{leaves: int, items: int, usable: int, perleaf: array<int, int>}
     */
    public static function pool_facts(int $runid): array {
        global $DB;

        $facts = ['leaves' => 0, 'items' => 0, 'usable' => 0, 'perleaf' => []];

        $scales = $DB->get_records_select(
            'local_catquizlab_scalemap',
            'runid = :runid AND level = :level',
            ['runid' => $runid, 'level' => scale_provisioner::LEVEL_SUBSCALE],
            'id ASC',
            'id, catscaleid'
        );

        foreach ($scales as $scale) {
            $scaleid = (int) $scale->catscaleid;
            $facts['leaves']++;

            $total = $DB->count_records('local_catquiz_items', ['catscaleid' => $scaleid]);

            // Items whose parameters the engine treats as known. A pilot
            // question is administered but contributes nothing to the estimate,
            // so a pool of pilots cannot carry a test however large it is.
            $usable = $DB->count_records_sql(
                'SELECT COUNT(1)
                   FROM {local_catquiz_items} ci
                   JOIN {local_catquiz_itemparams} ip ON ip.id = ci.activeparamid
                  WHERE ci.catscaleid = :scaleid AND ip.status >= :status',
                ['scaleid' => $scaleid, 'status' => self::STATUS_KNOWN]
            );

            $facts['items'] += $total;
            $facts['usable'] += $usable;
            $facts['perleaf'][$scaleid] = $usable;
        }

        return $facts;
    }

    /**
     * Is there anything to select from at all?
     *
     * @param array $facts The pool facts.
     * @param string $component For the strings.
     * @return string[]
     */
    protected static function check_pool_exists(array $facts, string $component): array {
        if ($facts['leaves'] === 0) {
            return [get_string('readiness:noscales', $component)];
        }

        if ($facts['usable'] === 0) {
            // The distinction matters: "no items" and "items the engine will
            // not learn from" need different fixes.
            return [$facts['items'] > 0
                ? get_string('readiness:nousableitems', $component, $facts['items'])
                : get_string('readiness:noitems', $component)];
        }

        $empty = [];
        foreach ($facts['perleaf'] as $scaleid => $usable) {
            if ($usable === 0) {
                $empty[] = $scaleid;
            }
        }

        // A subscale with no items only stops a test that has to visit every
        // subscale. Under a strategy that picks where it likes, an empty scale
        // among ninety-nine full ones is a scale it will not pick.
        if ($empty !== [] && !empty($facts['enforcespersubscale'])) {
            return [get_string('readiness:emptysubscale', $component, implode(', ', $empty))];
        }

        return [];
    }

    /**
     * Do the budgets add up against the pool and against each other?
     *
     * @param array $definition The run's normalised definition.
     * @param array $facts The pool facts.
     * @param string $component For the strings.
     * @return string[]
     */
    protected static function check_budgets(array $definition, array $facts, string $component): array {
        $reasons = [];

        $globalmin = (int) ($definition['budgets']['global']['minitems'] ?? 0);
        $submin = (int) ($definition['budgets']['subscale']['minitems'] ?? 0);
        $submax = (int) ($definition['budgets']['subscale']['maxitems'] ?? 0);
        $leaves = (int) $facts['leaves'];
        $usable = (int) $facts['usable'];

        if ($globalmin > 0 && $usable < $globalmin) {
            $reasons[] = get_string('readiness:poolbelowminimum', $component, (object) [
                'usable'  => $usable,
                'minimum' => $globalmin,
            ]);
        }

        // Per-subscale maxima cap the whole test: with a maximum of five on
        // each of two subscales, ten is all the test can ever ask.
        if (
            !empty($facts['enforcespersubscale'])
                && $globalmin > 0 && $submax > 0 && $leaves > 0 && $submax * $leaves < $globalmin
        ) {
            $reasons[] = get_string('readiness:subscalecapbelowminimum', $component, (object) [
                'cap'     => $submax * $leaves,
                'minimum' => $globalmin,
                'leaves'  => $leaves,
                'submax'  => $submax,
            ]);
        }

        // Per-subscale minima multiply only where the strategy makes them
        // binding on every subscale. Under `fastest` the engine's base
        // implementation of filterbyquestionsperscale() returns the candidates
        // unchanged, so 100 subscales at 3 questions each is not a demand for
        // 300 questions — it is a bound on what may be taken from whichever
        // scales the selection actually visits. Applying the multiplication
        // there refused valid configurations before a worker ever ran.
        $globalmax = (int) ($definition['budgets']['global']['maxitems'] ?? 0);
        if (
            !empty($facts['enforcespersubscale'])
                && $submin > 0 && $leaves > 0 && $globalmax > 0 && $submin * $leaves > $globalmax
        ) {
            $reasons[] = get_string('readiness:subscalefloorabovemaximum', $component, (object) [
                'floor'    => $submin * $leaves,
                'maximum'  => $globalmax,
                'leaves'   => $leaves,
                'submin'   => $submin,
                'strategy' => strategy_catalog::label((string) $facts['strategy']),
            ]);
        }

        // The same for per-subscale caps: they only bound the whole test when
        // every subscale has to be served.
        if (
            !empty($facts['enforcespersubscale'])
                && $submin > 0
        ) {
            foreach ($facts['perleaf'] as $scaleid => $available) {
                if ($available < $submin) {
                    $reasons[] = get_string('readiness:subscalebelowminimum', $component, (object) [
                        'scale'     => $scaleid,
                        'available' => $available,
                        'minimum'   => $submin,
                    ]);
                    break;
                }
            }
        }

        return $reasons;
    }

    /**
     * Wrap a result.
     *
     * @param bool $ok Whether the run can start.
     * @param string[] $reasons Why not, when it cannot.
     * @param array $facts What was counted.
     * @return array{ok: bool, reasons: string[], facts: array}
     */
    protected static function verdict(bool $ok, array $reasons, array $facts = []): array {
        return ['ok' => $ok, 'reasons' => $reasons, 'facts' => $facts];
    }

    /**
     * The reasons as one sentence.
     *
     * @param array $result The result of {@see self::check()}.
     * @return string
     */
    public static function summary(array $result): string {
        return implode(' ', $result['reasons']);
    }
}
