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
 * Every place that shows a sitting says the same of it, under every validity filter (#136 AK 5).
 *
 * Compared, per filter valid | invalid | unknown | all: the filtered sample (the same sitting ids in the analysis the
 * tables read, the CSV and the JSON export); per sitting strategy, validity state and verdicts, SE, TI@n, the engine's
 * end (finished, stop text, end code, terminal code, verdict), the limits it broke (max_questions_exceeded among them)
 * and the engine's productive count against its own record; per scale result the items administered against the
 * steps; the plots of the overview and the global tab as drawn against the analysis.
 *
 * Across the filters: valid, invalid and unknown are disjoint, together they are "all", and every sitting's state is
 * the filter it is listed under. And per sitting of "all": the engine progress stored with it is its own
 * (progress_provenance), and no two sittings carry the same progress unless they were asked the same items (#168).
 *
 * smoke.php runs this on a played experiment; tests/output_agreement_test.php on a deterministic one.
 *
 * @package    local_catquizlab
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class output_agreement {
    /** @var string[] The filters, in the order checked. */
    public const FILTERS = [result_validity::VALID, result_validity::INVALID, result_validity::UNKNOWN, result_validity::ALL];

    /** @var string[] Per sitting, analysis = CSV = JSON. */
    public const FIELDS = ['strategy', 'validitystate', 'valid', 'uniformvalid', 'enginevalid', 'statisticalvalid', 'se',
        'nitems', 'stopreason', 'enginefinished', 'designstopreached', 'endreasoncode', 'contractviolations',
        'productiveitems', 'esttheta', 'truetheta'];

    /** @var string[] Per scale result, CSV = JSON: the steps' count, SE, the verdicts and the engine's own reasons. */
    public const SCALE_FIELDS = ['items', 'localse', 'estdelta', 'scalevalid', 'scalereasons', 'enginescalevalid',
        'enginescalereasons', 'validitystate'];

    /** @var string[] Per sitting, CSV = JSON (the analysis holds them under other names or not at all). */
    public const EXPORT_FIELDS = ['finaltiatn', 'finalti', 'engineverdict', 'terminalreasoncode'];

    /**
     * Compare every output under every filter.
     *
     * @param int $experimentid The experiment.
     * @param string[] $filters The filters; all four for the partition to be checked.
     * @param bool $plots Render the tabs and compare the plots (needs a page; off where nothing can render).
     * @return array{problems: string[], facts: string, ids: array<string,int[]>, bystrategy: array<string,array<string,int>>,
     *     provenance: array<string,int>}
     */
    public static function check(int $experimentid, array $filters = self::FILTERS, bool $plots = true): array {
        $problems = [];
        $facts = [];
        $ids = [];
        $states = [];
        $bystrategy = [];
        $engineitems = self::engine_counts($experimentid);
        foreach ($filters as $validity) {
            $one = self::one_filter($experimentid, $validity, $engineitems, $plots);
            $problems = [...$problems, ...$one['problems']];
            $facts[] = $one['facts'];
            $ids[$validity] = $one['ids'];
            $states += $one['states'];
            $bystrategy[$validity] = $one['bystrategy'];
        }
        if (count(array_intersect(self::FILTERS, $filters)) === count(self::FILTERS)) {
            $problems = [...$problems, ...self::partition($ids, $states)];
            $facts[] = 'valid + invalid + unknown = all';
        }
        $provenance = ['checked' => 0];
        if (isset($ids[result_validity::ALL])) {
            $found = self::provenance($ids[result_validity::ALL]);
            $problems = [...$problems, ...$found['problems']];
            $provenance = $found['counts'];
            $facts[] = 'provenance ' . json_encode($provenance);
        }

        return ['problems' => $problems, 'facts' => implode('; ', $facts), 'ids' => $ids, 'bystrategy' => $bystrategy,
            'provenance' => $provenance];
    }

    /**
     * The three filters are a partition of "all", and every sitting stands under its own state.
     *
     * @param array $ids Sitting ids per filter.
     * @param array $states Each sitting's validity state as the analysis says it.
     * @return string[] Problems.
     */
    public static function partition(array $ids, array $states): array {
        $problems = [];
        $parts = [result_validity::VALID, result_validity::INVALID, result_validity::UNKNOWN];
        $union = [];
        foreach ($parts as $i => $a) {
            $union = [...$union, ...($ids[$a] ?? [])];
            foreach (array_slice($parts, $i + 1) as $b) {
                $both = array_intersect($ids[$a] ?? [], $ids[$b] ?? []);
                if ($both !== []) {
                    $problems[] = "sitting(s) " . implode(', ', array_slice($both, 0, 20)) . " under both {$a} and {$b}";
                }
            }
            foreach ($ids[$a] ?? [] as $id) {
                if (($states[$id] ?? null) !== $a) {
                    $problems[] = "sitting {$id} listed under {$a}, its state is " . json_encode($states[$id] ?? null);
                }
            }
        }
        $union = array_values(array_unique($union));
        sort($union);
        $all = $ids[result_validity::ALL] ?? [];
        sort($all);
        if ($union !== $all) {
            $problems[] = 'valid + invalid + unknown hold ' . count($union) . ' sitting(s), all ' . count($all)
                . '; missing from the three: ' . json_encode(array_slice(array_values(array_diff($all, $union)), 0, 20))
                . ', only in the three: ' . json_encode(array_slice(array_values(array_diff($union, $all)), 0, 20));
        }
        return $problems;
    }

    /**
     * Each sitting carries its own engine progress, and none another's (#168, #136 AK 5).
     *
     * @param int[] $attemptids The sittings.
     * @return array{problems: string[], counts: array<string,int>}
     */
    public static function provenance(array $attemptids): array {
        global $DB;
        $problems = [];
        $counts = ['checked' => 0] + array_fill_keys(progress_provenance::VERDICTS, 0);
        $byprogress = [];
        $byitems = [];
        foreach (array_chunk($attemptids, 500) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            [$insql, $params] = $DB->get_in_or_equal($chunk);
            $rows = $DB->get_recordset_select('local_catquizlab_attempt', "id {$insql}", $params, 'id ASC', 'id, tracejson');
            foreach ($rows as $row) {
                $trace = $row->tracejson === null ? null : json_decode((string) $row->tracejson, true);
                $trace = is_array($trace) ? $trace : null;
                $verdict = progress_provenance::assess_trace($trace);
                $counts['checked']++;
                $counts[$verdict['verdict']]++;
                if (in_array($verdict['verdict'], [progress_provenance::MISMATCH, progress_provenance::SUSPECT], true)) {
                    $problems[] = "sitting {$row->id}: its engine progress is {$verdict['verdict']} — {$verdict['progress']} "
                        . "played, {$verdict['items']} item(s), {$verdict['overlap']} in common";
                }
                if ($trace !== null) {
                    $played = progress_provenance::played($trace);
                    if ($played !== []) {
                        $byprogress[implode(',', $played)][] = (int) $row->id;
                    }
                    $items = array_map('intval', array_values((array) ($trace['items'] ?? [])));
                    $byitems[(int) $row->id] = implode(',', $items);
                }
            }
            $rows->close();
        }
        // The same progress in two sittings is one copied, unless both were asked exactly those items.
        foreach ($byprogress as $sequence => $holders) {
            if (count($holders) < 2) {
                continue;
            }
            $foreign = array_values(array_filter($holders, static fn(int $id): bool => ($byitems[$id] ?? '') !== $sequence));
            if ($foreign !== []) {
                $owners = array_values(array_diff($holders, $foreign));
                $problems[] = 'sitting(s) ' . implode(', ', $foreign) . ' carry the engine progress of '
                    . ($owners === [] ? 'another sitting' : 'sitting(s) ' . implode(', ', $owners));
            }
        }
        return ['problems' => $problems, 'counts' => $counts];
    }

    /**
     * The engine's own count of items per sitting, where its record is installed.
     *
     * @param int $experimentid The experiment.
     * @return array<int,int>
     */
    private static function engine_counts(int $experimentid): array {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_catquiz_attempts')) {
            return [];
        }
        return array_map('intval', $DB->get_records_sql_menu(
            "SELECT a.id, e.number_of_testitems_used
               FROM {local_catquizlab_attempt} a
               JOIN {local_catquizlab_run} r ON r.id = a.runid
               JOIN {local_catquiz_attempts} e ON e.attemptid = a.engineattemptid
                    AND e.component IN ('adaptivequiz', 'mod_adaptivequiz')
              WHERE r.experimentid = ?",
            [$experimentid]
        ));
    }

    /**
     * One filter: analysis, CSV, JSON and plots against each other.
     *
     * @param int $experimentid The experiment.
     * @param string $validity The filter.
     * @param array $engineitems The engine's counts.
     * @param bool $plots Compare the plots.
     * @return array{problems: string[], facts: string, ids: int[], states: array<int,string>, bystrategy: array<string,int>}
     */
    private static function one_filter(int $experimentid, string $validity, array $engineitems, bool $plots): array {
        $problems = [];
        $same = static fn($a, $b): bool => ($a === null || $a === '') ? ($b === null || $b === '')
            : (is_numeric($a) && is_numeric($b) ? abs((float) $a - (float) $b) < 1e-9 : (string) $a === (string) $b);
        $filter = ['experimentid' => $experimentid, 'validity' => $validity];
        $query = new results_query($filter);
        $analysis = [];
        $states = [];
        $bystrategy = [];
        foreach ($query->each_observation() as $o) {
            $analysis[(int) $o['attemptid']] = $o;
            $states[(int) $o['attemptid']] = (string) $o['validitystate'];
            $bystrategy[(string) $o['strategy']] = ($bystrategy[(string) $o['strategy']] ?? 0) + 1;
        }
        ksort($bystrategy);
        $export = results_export::class;
        $csvrows = self::parse_csv($export::to_csv($export::dataset($query, $export::LEVEL_ATTEMPT)));
        $csv = array_combine(array_map(static fn(array $r): int => (int) $r['attemptid'], $csvrows), $csvrows) ?: [];
        $jsonrows = json_decode($export::to_json($query, $export::LEVEL_ATTEMPT), true)['data'] ?? [];
        $json = array_combine(array_map(static fn(array $r): int => (int) $r['attemptid'], $jsonrows), $jsonrows) ?: [];
        $ids = array_keys($analysis);
        sort($ids);
        foreach (['CSV' => $csv, 'JSON' => $json] as $name => $rows) {
            $theirs = array_keys($rows);
            sort($theirs);
            if ($theirs !== $ids) {
                $problems[] = "{$validity}: the analysis holds " . count($ids) . " sitting(s), the {$name} export "
                    . count($theirs) . ' — not the same sample';
            }
            if (count($rows) !== count($name === 'CSV' ? $csvrows : $jsonrows)) {
                $problems[] = "{$validity}: the {$name} export lists a sitting more than once";
            }
        }
        foreach ($analysis as $id => $o) {
            foreach (self::FIELDS as $field) {
                $values = ['analysis' => is_bool($o[$field] ?? null) ? (int) $o[$field] : ($o[$field] ?? null),
                    'csv' => $csv[$id][$field] ?? null, 'json' => is_bool($json[$id][$field] ?? null)
                    ? (int) $json[$id][$field] : ($json[$id][$field] ?? null)];
                if ($field === 'contractviolations') {
                    // The analysis holds the sitting; the limits it broke are judged from it as the exports judge them.
                    $values['analysis'] = implode(' ', run_contract::check_observation($o));
                }
                if (is_array($values['analysis'])) {
                    $values['analysis'] = implode(';', $values['analysis']);
                }
                if (is_array($values['json'])) {
                    $values['json'] = implode(';', $values['json']);
                }
                if (!$same($values['analysis'], $values['csv']) || !$same($values['analysis'], $values['json'])) {
                    $problems[] = "{$validity}: sitting {$id} {$field}: " . json_encode($values);
                }
            }
            foreach (self::EXPORT_FIELDS as $field) {
                if (!$same($csv[$id][$field] ?? null, $json[$id][$field] ?? null)) {
                    $problems[] = "{$validity}: sitting {$id} {$field}: CSV " . json_encode($csv[$id][$field] ?? null)
                        . ', JSON ' . json_encode($json[$id][$field] ?? null);
                }
            }
            if (
                isset($engineitems[$id]) && $o['productiveitems'] !== null
                    && $engineitems[$id] !== (int) $o['productiveitems']
            ) {
                $problems[] = "{$validity}: sitting {$id}: the engine's record counts {$engineitems[$id]} item(s), "
                    . "the analysis {$o['productiveitems']}";
            }
        }
        $subscales = self::scale_results($query, $validity, $same);
        $problems = [...$problems, ...$subscales['problems']];
        // The step table holds every question of every sitting the analysis holds, and of no other.
        foreach ($analysis as $id => $o) {
            $n = $subscales['steps'][$id] ?? 0;
            if ($n > 0 && $o['nitems'] !== null && $n !== (int) $o['nitems']) {
                $problems[] = "{$validity}: sitting {$id}: {$n} step(s) in the step table, {$o['nitems']} item(s) in the analysis";
            }
        }
        $strays = array_diff(array_keys($subscales['steps']), $ids);
        if ($strays !== []) {
            $problems[] = "{$validity}: the step table holds sitting(s) the analysis does not: "
                . implode(', ', array_slice($strays, 0, 20));
        }
        $facts = "{$validity}: " . count($ids) . ' sitting(s) ' . json_encode((object) $bystrategy) . ', '
            . $subscales['n'] . ' scale result(s)';

        if ($plots) {
            $drawn = self::plots($query, $filter, $analysis);
            $problems = [...$problems, ...array_map(static fn(string $p): string => "{$validity}: {$p}", $drawn['problems'])];
            $facts .= ', plots ' . $drawn['points'] . ' point(s)';
        }
        return ['problems' => $problems, 'facts' => $facts, 'ids' => $ids, 'states' => $states, 'bystrategy' => $bystrategy];
    }

    /**
     * Scale results: CSV and JSON alike, and the items given per scale as the steps count them.
     *
     * @param results_query $query The filtered query.
     * @param string $validity The filter.
     * @param callable $same Equality of two exported values.
     * @return array{problems: string[], n: int, steps: array<int,int>}
     */
    private static function scale_results(results_query $query, string $validity, callable $same): array {
        global $DB;
        $problems = [];
        $export = results_export::class;
        $subscsv = self::parse_csv($export::to_csv($export::dataset($query, $export::LEVEL_SUBSCALE)));
        $subsjson = json_decode($export::to_json($query, $export::LEVEL_SUBSCALE), true)['data'] ?? [];
        if (count($subscsv) !== count($subsjson)) {
            $problems[] = "{$validity}: " . count($subscsv) . ' scale result(s) in the CSV, ' . count($subsjson) . ' in the JSON';
        }
        $key = static fn(array $r): string => $r['attemptid'] . ':' . $r['category'] . ':' . $r['subscale'];
        $byjson = [];
        foreach ($subsjson as $r) {
            $byjson[$key($r)] = $r;
        }
        $given = [];
        $givenby = [];
        foreach ($subscsv as $r) {
            foreach (self::SCALE_FIELDS as $field) {
                if (array_key_exists($field, $r) && !$same($r[$field], $byjson[$key($r)][$field] ?? null)) {
                    $problems[] = "{$validity}: scale result {$key($r)} {$field}: CSV " . json_encode($r[$field])
                        . ', JSON ' . json_encode($byjson[$key($r)][$field] ?? null);
                }
            }
            if ((int) $r['items'] > 0) {
                $given[(int) $r['attemptid']][] = (int) $r['items'];
                $givenby[(int) $r['attemptid']][(string) ($r['subscale'] ?? '?')] = (int) $r['items'];
            }
        }
        $steps = [];
        $stepsof = [];
        foreach ($export::dataset($query, $export::LEVEL_STEP)['rows'] as $step) {
            $stepsof[(int) $step['attemptid']] = ($stepsof[(int) $step['attemptid']] ?? 0) + 1;
            if (!empty($step['subscaleid'])) {
                $steps[(int) $step['attemptid']][(int) $step['subscaleid']] =
                    ($steps[(int) $step['attemptid']][(int) $step['subscaleid']] ?? 0) + 1;
            }
        }
        foreach ($steps as $id => $byscale) {
            $fromsteps = array_values($byscale);
            sort($fromsteps);
            $exported = $given[$id] ?? [];
            sort($exported);
            // With every scale result ("all") the two are the same; under another filter a scale result of another
            // state is not exported, so what is exported is a part of what the steps count.
            $part = $exported;
            foreach ($fromsteps as $n) {
                $at = array_search($n, $part, true);
                if ($at !== false) {
                    unset($part[$at]);
                }
            }
            $differs = $validity === result_validity::ALL ? $fromsteps !== $exported : $part !== [];
            if (isset($given[$id]) && $differs) {
                $history = [];
                $rows = $DB->get_records(
                    'local_catquizlab_attemptlog',
                    ['attemptid' => $id],
                    'id ASC',
                    'id, tryno, outcome, reasoncode'
                );
                foreach ($rows as $row) {
                    $history[] = $row->tryno . ':' . $row->outcome . ($row->reasoncode ? ':' . $row->reasoncode : '');
                }
                $problems[] = "{$validity}: sitting {$id}: items per scale from the steps " . json_encode($fromsteps)
                    . ', in the scale results ' . json_encode($exported) . '; by scale, steps '
                    . json_encode((object) $byscale) . ', results ' . json_encode((object) ($givenby[$id] ?? []))
                    . '; its executions ' . json_encode($history);
            }
        }
        return ['problems' => $problems, 'n' => count($subscsv), 'steps' => $stepsof];
    }

    /**
     * The first plot of the overview tab (SE over items) and of the global tab (estimate over true ability) as drawn,
     * against the analysis.
     *
     * @param results_query $query The filtered query.
     * @param array $filter The filter.
     * @param array $analysis The observations.
     * @return array{problems: string[], points: int}
     */
    private static function plots(results_query $query, array $filter, array $analysis): array {
        global $PAGE;
        if (!$PAGE->has_set_url()) {
            $PAGE->set_context(\context_system::instance());
            $PAGE->set_url('/local/catquizlab/results.php');
        }
        $problems = [];
        $withse = array_filter($analysis, static fn(array $o): bool => $o['se'] !== null && $o['nitems'] !== null);
        $withestimate = array_filter($analysis, static fn(array $o): bool => $o['esttheta'] !== null
            && $o['truetheta'] !== null);
        $expected = [
            'overview' => array_map(static fn(array $o): array => [(float) $o['nitems'], (float) $o['se']], $withse),
            'global' => array_map(
                static fn(array $o): array => [(float) $o['truetheta'], (float) $o['esttheta']],
                $withestimate
            ),
        ];
        $total = 0;
        foreach ($expected as $tab => $points) {
            $html = (new \local_catquizlab\output\results_page($query, $tab, $filter))->render_tab();
            $at = strpos($html, '<svg ');
            $drawn = [];
            if ($at !== false) {
                $svg = explode('</svg>', substr($html, $at))[0];
                preg_match_all('/data-x="([^"]*)" data-y="([^"]*)" data-count="(\d+)"/', $svg, $m, PREG_SET_ORDER);
                foreach ($m as [, $x, $y, $n]) {
                    for ($i = 0; $i < (int) $n; $i++) {
                        $drawn[] = [(float) $x, (float) $y];
                    }
                }
            }
            $points = array_values($points);
            usort($points, static fn($a, $b) => $a <=> $b);
            usort($drawn, static fn($a, $b) => $a <=> $b);
            $ok = count($points) === count($drawn);
            foreach ($points as $i => $p) {
                $ok = $ok && abs($p[0] - $drawn[$i][0]) < 5e-3 && abs($p[1] - $drawn[$i][1]) < 5e-3;
            }
            if (!$ok) {
                $problems[] = "the first plot of the {$tab} tab draws " . count($drawn) . ' point(s), the analysis has '
                    . count($points) . ' — or they lie elsewhere';
            }
            $total += count($points);
        }
        return ['problems' => $problems, 'points' => $total];
    }

    /**
     * Rows of an exported CSV, keyed by its header.
     *
     * @param string $csv The CSV.
     * @return array[]
     */
    private static function parse_csv(string $csv): array {
        $lines = array_values(array_filter(explode("\n", $csv), static fn(string $l): bool => $l !== ''));
        if ($lines === []) {
            return [];
        }
        $head = str_getcsv(array_shift($lines), ',', '"', '');
        return array_map(static fn(string $l): array => array_combine($head, str_getcsv($l, ',', '"', '')), $lines);
    }
}
