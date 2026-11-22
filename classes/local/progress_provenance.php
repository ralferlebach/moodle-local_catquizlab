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
 * Whose engine progress a stored sitting carries (#168, #136 AK 5).
 *
 * Until 0.7.74 the collector could store another person's engine progress with a sitting (attempt_collector::
 * read_progress(), fixed in 0.7.75). A stored trace holds two independent records of what was asked: the items, read
 * from the sitting's own question usage (Moodle's record, never the engine's), and the engine progress' played
 * questions. They are the same list, in the same order, for a sitting's own progress — on the development site
 * 17 394 of 17 397 traces with a progress — and the three that are not share no question with their items.
 *
 * Verdicts:
 *   verified            the played questions are the items, in order;
 *   mismatch            they share no question, or most questions differ: another sitting's progress;
 *   suspect             the same questions in another order, or one list a part of the other: not provably own;
 *   notreconstructable  a progress without the items to check it against, or the trace reduced to its digest;
 *   notassessed         no engine progress stored (nothing that could be another's), or no trace at all.
 *
 * Nothing here writes but record() (recorded or excluded), rederive() and revert(), each adding a
 * row to local_catquizlab_provenance and none overwriting what was there without keeping it.
 *
 * @package    local_catquizlab
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class progress_provenance {
    /** @var string The played questions are the sitting's own items. */
    public const VERIFIED = 'verified';

    /** @var string Another sitting's progress. */
    public const MISMATCH = 'mismatch';

    /** @var string Not provably the sitting's own. */
    public const SUSPECT = 'suspect';

    /** @var string Nothing to check the progress against. */
    public const UNRECONSTRUCTABLE = 'notreconstructable';

    /** @var string No progress stored. */
    public const NOTASSESSED = 'notassessed';

    /** @var string[] Every verdict, in the order reports list them. */
    public const VERDICTS = [self::MISMATCH, self::SUSPECT, self::UNRECONSTRUCTABLE, self::VERIFIED, self::NOTASSESSED];

    /** @var string Decision: the verdict recorded, nothing else done. */
    public const RECORDED = 'recorded';

    /** @var string Decision: the sitting is left out of every analysis and export until it is re-derived. */
    public const EXCLUDED = 'excluded';

    /** @var string Decision: the progress replaced by the sitting's own, the stored trace kept in the row. */
    public const REDERIVED = 'rederived';

    /** @var string Decision: a re-derivation undone, the kept trace back in place. */
    public const REVERTED = 'reverted';

    /** @var string The table of decisions. */
    public const TABLE = 'local_catquizlab_provenance';

    /** @var int Version of the rules above; stored with every row. */
    public const RULES = 1;

    /**
     * The verdict for one stored trace.
     *
     * @param array|null $trace The decoded trace; null when there is none.
     * @param bool $digested The trace was reduced to its digest (the progress gone with it).
     * @return array{verdict: string, progress: int, items: int, overlap: int}
     */
    public static function assess_trace(?array $trace, bool $digested = false): array {
        if ($trace === null) {
            return ['verdict' => $digested ? self::UNRECONSTRUCTABLE : self::NOTASSESSED, 'progress' => 0, 'items' => 0,
                'overlap' => 0];
        }
        $played = self::played($trace);
        $items = array_map('intval', array_values((array) ($trace['items'] ?? [])));
        $out = ['progress' => count($played), 'items' => count($items),
            'overlap' => count(array_intersect(array_unique($played), array_unique($items)))];
        if ($played === []) {
            return ['verdict' => self::NOTASSESSED] + $out;
        }
        if ($items === []) {
            return ['verdict' => self::UNRECONSTRUCTABLE] + $out;
        }
        if ($played === $items) {
            return ['verdict' => self::VERIFIED] + $out;
        }
        $sameset = array_diff($played, $items) === [] && array_diff($items, $played) === [];
        $within = array_diff($played, $items) === [] || array_diff($items, $played) === [];
        if ($sameset || ($within && $out['overlap'] * 2 > max(count($played), count($items)))) {
            return ['verdict' => self::SUSPECT] + $out;
        }

        return ['verdict' => self::MISMATCH] + $out;
    }

    /**
     * The question ids of a trace's engine progress, in the order played.
     *
     * @param array $trace The decoded trace.
     * @return int[]
     */
    public static function played(array $trace): array {
        return array_map(
            static fn($q): int => (int) (is_array($q) ? ($q['id'] ?? 0) : $q),
            array_values((array) ($trace['progress']['playedquestions'] ?? []))
        );
    }

    /**
     * The verdict for one sitting as stored.
     *
     * @param int $attemptid The lab sitting.
     * @return array{verdict: string, progress: int, items: int, overlap: int}
     */
    public static function assess(int $attemptid): array {
        global $DB;
        $row = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid], 'id, tracejson, digestjson');
        if (!$row) {
            return self::assess_trace(null);
        }
        $trace = $row->tracejson === null ? null : json_decode((string) $row->tracejson, true);
        return self::assess_trace(is_array($trace) ? $trace : null, $row->tracejson === null && $row->digestjson !== null);
    }

    /**
     * Every stored sitting assessed, read only: the counts per verdict, the ids of all that are not verified or not
     * assessed, and a fingerprint of the sittings' table taken before and after, which must be the same (#168 AK1,
     * DoD2). Ids and counts only: no question, answer or estimate leaves the database.
     *
     * @param int[]|null $runids Only these runs; null for every one.
     * @param int $before Count separately the sittings last changed before this time (0: none).
     * @return array{counts: array<string,int>, before: array<string,int>, ids: array<string,int[]>, total: int,
     *     fingerprint: array{before: string, after: string, unchanged: bool}, decided: array<string,int>}
     */
    public static function inventory(?array $runids = null, int $before = 0): array {
        global $DB;
        $where = 'a.tracejson IS NOT NULL OR a.digestjson IS NOT NULL';
        $params = [];
        if ($runids !== null) {
            [$insql, $params] = $DB->get_in_or_equal($runids ?: [0], SQL_PARAMS_NAMED, 'run');
            $where = 'a.runid ' . $insql . ' AND (' . $where . ')';
        }
        $fingerprint = self::fingerprint();
        $counts = array_fill_keys(self::VERDICTS, 0);
        $beforecounts = array_fill_keys(self::VERDICTS, 0);
        $ids = [self::MISMATCH => [], self::SUSPECT => [], self::UNRECONSTRUCTABLE => []];
        $total = 0;
        $rs = $DB->get_recordset_sql(
            'SELECT a.id, a.timemodified, a.tracejson, CASE WHEN a.digestjson IS NULL THEN 0 ELSE 1 END AS digested
               FROM {local_catquizlab_attempt} a
              WHERE ' . $where . '
           ORDER BY a.id',
            $params
        );
        foreach ($rs as $row) {
            $trace = $row->tracejson === null ? null : json_decode((string) $row->tracejson, true);
            $verdict = self::assess_trace(is_array($trace) ? $trace : null, $row->tracejson === null
                && (int) $row->digested === 1)['verdict'];
            $row->tracejson = null;
            $counts[$verdict]++;
            $total++;
            if ($before > 0 && (int) $row->timemodified < $before) {
                $beforecounts[$verdict]++;
            }
            if (isset($ids[$verdict])) {
                $ids[$verdict][] = (int) $row->id;
            }
        }
        $rs->close();
        $decided = [];
        if ($DB->get_manager()->table_exists(self::TABLE)) {
            foreach (self::latest() as $decision) {
                $decided[$decision->decision] = ($decided[$decision->decision] ?? 0) + 1;
            }
        }
        $after = self::fingerprint();

        return ['counts' => $counts, 'before' => $beforecounts, 'ids' => $ids, 'total' => $total,
            'fingerprint' => ['before' => $fingerprint, 'after' => $after, 'unchanged' => $fingerprint === $after],
            'decided' => $decided];
    }

    /**
     * Whether sittings found went out before they were found (#168 AK4, re-audit 22.11.2026): per experiment, the
     * exports the plugin recorded (local_catquizlab_exportlog, for one of its runs or the whole experiment) and the
     * research packages it stored (files of the component, area "export"), and of those how many were made after a
     * found sitting was collected — they carry it. Downloads straight from the results page (CSV, JSON) are not
     * recorded by the plugin: said so, not counted as none. Ids and counts only. Reads only.
     *
     * @param int[] $attemptids The sittings found.
     * @return array{sittings: int, experiments: array, carried: int, unrecorded: string}
     */
    public static function exposure(array $attemptids): array {
        global $DB;
        $byexperiment = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $attemptids))), 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk);
            $rows = $DB->get_records_sql(
                "SELECT a.id, a.runid, r.experimentid,
                        (SELECT MAX(l.timecreated) FROM {local_catquizlab_attemptlog} l
                          WHERE l.attemptid = a.id AND l.outcome = 'collected') AS timecollected
                   FROM {local_catquizlab_attempt} a
                   JOIN {local_catquizlab_run} r ON r.id = a.runid
                  WHERE a.id {$insql}",
                $params
            );
            foreach ($rows as $row) {
                $byexperiment[(int) $row->experimentid][] = $row;
            }
        }
        $fs = get_file_storage();
        $context = \context_system::instance();
        $files = $fs->get_area_files($context->id, 'local_catquizlab', 'export', 0, 'timecreated', false);
        $experiments = [];
        $carried = 0;
        foreach ($byexperiment as $experimentid => $sittings) {
            $runs = array_values(array_unique(array_map(static fn($r): int => (int) $r->runid, $sittings)));
            [$insql, $params] = $DB->get_in_or_equal($runs, SQL_PARAMS_NAMED, 'run');
            $exports = array_map('intval', $DB->get_fieldset_select(
                'local_catquizlab_exportlog',
                'timecreated',
                "experimentid = :experimentid OR runid {$insql}",
                $params + ['experimentid' => $experimentid]
            ));
            $packages = [];
            foreach ($files as $file) {
                if (str_starts_with($file->get_filename(), research_package::FILEPREFIX . $experimentid . '-')) {
                    $packages[] = (int) $file->get_timecreated();
                }
            }
            $out = array_merge($exports, $packages);
            $after = 0;
            foreach ($sittings as $sitting) {
                $collected = (int) $sitting->timecollected;
                if ($collected > 0 && array_filter($out, static fn(int $t): bool => $t >= $collected) !== []) {
                    $after++;
                }
            }
            $carried += $after;
            $experiments[$experimentid] = ['sittings' => count($sittings), 'exports' => count($exports),
                'packages' => count($packages), 'carriedbyalaterexport' => $after,
                'lastexport' => $out === [] ? null : gmdate('Y-m-d\TH:i:s\Z', max($out))];
        }
        ksort($experiments);
        return ['sittings' => count($attemptids), 'experiments' => $experiments, 'carried' => $carried,
            'unrecorded' => 'downloads straight from the results page (CSV, JSON, step table) are not recorded by '
                . 'the plugin: whether those went out cannot be read here'];
    }

    /**
     * A fingerprint of the sittings' table and of the decisions: rows, their latest change, the stored traces' bytes.
     * Taken before and after a read, equal when nothing was written.
     *
     * @return string
     */
    public static function fingerprint(): string {
        global $DB;
        $a = $DB->get_record_sql(
            'SELECT COUNT(1) AS n, MAX(timemodified) AS changed, MAX(id) AS lastid,
                    SUM(CASE WHEN tracebytes IS NULL THEN 0 ELSE tracebytes END) AS bytes,
                    SUM(CASE WHEN tracejson IS NULL THEN 0 ELSE 1 END) AS traces
               FROM {local_catquizlab_attempt}'
        );
        $p = $DB->get_manager()->table_exists(self::TABLE)
            ? $DB->get_record_sql('SELECT COUNT(1) AS n, MAX(id) AS lastid FROM {' . self::TABLE . '}') : null;
        return hash('sha256', json_encode([(array) $a, $p ? (array) $p : null]));
    }

    /**
     * The latest decision per sitting.
     *
     * @param int[]|null $attemptids Only these sittings; null for all.
     * @return \stdClass[] Keyed by sitting: attemptid, verdict, decision, id.
     */
    public static function latest(?array $attemptids = null): array {
        global $DB;
        $where = '';
        $params = [];
        if ($attemptids !== null) {
            [$insql, $params] = $DB->get_in_or_equal($attemptids ?: [0], SQL_PARAMS_NAMED, 'att');
            $where = ' WHERE p.attemptid ' . $insql;
        }
        $rows = $DB->get_records_sql(
            'SELECT p.id, p.attemptid, p.verdict, p.decision
               FROM {' . self::TABLE . '} p
               JOIN (SELECT attemptid, MAX(id) AS lastid FROM {' . self::TABLE . '} GROUP BY attemptid) l
                 ON l.lastid = p.id' . $where,
            $params
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->attemptid] = $row;
        }
        return $out;
    }

    /**
     * SQL that leaves out the sittings whose latest decision is "excluded" — for every analysis and export (#168 AK3).
     *
     * @param string $alias The alias of local_catquizlab_attempt in the query.
     * @return string A condition to AND into the WHERE clause; "1 = 1" before the table exists.
     */
    public static function included_sql(string $alias = 'a'): string {
        global $DB;
        static $exists = null;
        if ($exists === null || (defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            $exists = $DB->get_manager()->table_exists(self::TABLE);
        }
        if (!$exists) {
            return '1 = 1';
        }
        return 'NOT EXISTS (SELECT 1 FROM {' . self::TABLE . '} pv
                             WHERE pv.attemptid = ' . $alias . '.id AND pv.decision = \'' . self::EXCLUDED . '\'
                               AND pv.id = (SELECT MAX(pv2.id) FROM {' . self::TABLE . '} pv2
                                             WHERE pv2.attemptid = ' . $alias . '.id))';
    }

    /**
     * How many sittings of these runs the analyses leave out for their provenance.
     *
     * @param int[] $runids The runs.
     * @return int
     */
    public static function excluded_count(array $runids): int {
        global $DB;
        if ($runids === [] || !$DB->get_manager()->table_exists(self::TABLE)) {
            return 0;
        }
        [$insql, $params] = $DB->get_in_or_equal($runids, SQL_PARAMS_NAMED, 'run');
        return (int) $DB->count_records_sql(
            'SELECT COUNT(1) FROM {local_catquizlab_attempt} a WHERE a.runid ' . $insql . ' AND NOT ('
                . self::included_sql('a') . ')',
            $params
        );
    }

    /**
     * Record a sitting's verdict, and a decision: recorded (nothing else), or excluded (left out of the analyses).
     * A row added, never one changed: the history of decisions is the table (#168 AK3).
     *
     * @param int $attemptid The sitting.
     * @param string $decision self::RECORDED or self::EXCLUDED.
     * @param string $note Why, in a few words (no person's data).
     * @return int The row.
     */
    public static function record(int $attemptid, string $decision = self::RECORDED, string $note = ''): int {
        if (!in_array($decision, [self::RECORDED, self::EXCLUDED], true)) {
            throw new \coding_exception('Unknown provenance decision ' . $decision);
        }
        return self::add($attemptid, self::assess($attemptid), $decision, null, $note);
    }

    /**
     * The sitting's own engine progress, where the engine still has it and it is provably the sitting's: its played
     * questions are the sitting's items. Null otherwise.
     *
     * @param int $attemptid The sitting.
     * @return array|null The progress, as the collector stores it.
     */
    public static function own_progress(int $attemptid): ?array {
        global $DB;
        $row = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid], 'id, engineattemptid, tracejson');
        if (!$row || $row->tracejson === null || (int) $row->engineattemptid <= 0) {
            return null;
        }
        $trace = json_decode((string) $row->tracejson, true);
        if (!is_array($trace)) {
            return null;
        }
        $progress = attempt_collector::progress_of((int) $row->engineattemptid);
        if ($progress === []) {
            return null;
        }
        $check = self::assess_trace(['items' => $trace['items'] ?? [], 'progress' => $progress]);
        return $check['verdict'] === self::VERIFIED ? $progress : null;
    }

    /**
     * Re-derive a sitting whose stored progress is another's from its own (#168 AK3): only where the engine still holds
     * the sitting's own progress and it is provably its own. The progress is replaced and what was taken from it — the
     * ability path where it came from the progress, the step count — derived again; the trace as it was is kept in
     * the decision row, with its hash, and revert() puts it back. Nothing else of the trace changes.
     *
     * @param int $attemptid The sitting.
     * @param string $note Why.
     * @return array{done: bool, reason: string, row: int|null}
     */
    public static function rederive(int $attemptid, string $note = ''): array {
        global $DB;
        $verdict = self::assess($attemptid);
        if (!in_array($verdict['verdict'], [self::MISMATCH, self::SUSPECT], true)) {
            return ['done' => false, 'reason' => 'verdict ' . $verdict['verdict'] . ': nothing to re-derive', 'row' => null];
        }
        $own = self::own_progress($attemptid);
        if ($own === null) {
            return ['done' => false, 'reason' => 'the engine has no provably own progress of the sitting', 'row' => null];
        }
        $row = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid], 'id, tracejson', MUST_EXIST);
        $stored = json_decode((string) $row->tracejson, true);
        $trace = self::with_progress($stored, $own);
        $json = json_encode($trace, JSON_UNESCAPED_SLASHES);
        $transaction = $DB->start_delegated_transaction();
        $id = self::add($attemptid, $verdict, self::REDERIVED, (string) $row->tracejson, $note);
        $DB->update_record('local_catquizlab_attempt', (object) (trace_digest::fields($json) + ['id' => $attemptid,
            'tracejson' => $json, 'tracebytes' => strlen($json), 'timemodified' => time()]));
        $transaction->allow_commit();
        return ['done' => true, 'reason' => 'progress replaced by the sitting\'s own', 'row' => $id];
    }

    /**
     * Undo the latest re-derivation of a sitting: the trace kept with it back in place, a row saying so.
     *
     * @param int $attemptid The sitting.
     * @return bool Whether there was one to undo.
     */
    public static function revert(int $attemptid): bool {
        global $DB;
        $latest = $DB->get_records(self::TABLE, ['attemptid' => $attemptid], 'id DESC', '*', 0, 1);
        $latest = reset($latest);
        if (!$latest || $latest->decision !== self::REDERIVED || $latest->previoustrace === null) {
            return false;
        }
        $transaction = $DB->start_delegated_transaction();
        $current = (string) $DB->get_field('local_catquizlab_attempt', 'tracejson', ['id' => $attemptid]);
        self::add(
            $attemptid,
            self::assess_trace(json_decode((string) $latest->previoustrace, true)),
            self::REVERTED,
            $current,
            'revert of row ' . $latest->id
        );
        $json = (string) $latest->previoustrace;
        $DB->update_record('local_catquizlab_attempt', (object) (trace_digest::fields($json) + ['id' => $attemptid,
            'tracejson' => $json, 'tracebytes' => strlen($json), 'timemodified' => time()]));
        $transaction->allow_commit();
        return true;
    }

    /**
     * A trace with another progress, and what the collector derives from the progress derived again.
     *
     * @param array $trace The stored trace.
     * @param array $progress The progress to put in.
     * @return array
     */
    public static function with_progress(array $trace, array $progress): array {
        $oldpath = attempt_collector::path_of((array) ($trace['progress']['abilitytrace'] ?? []));
        $trace['progress'] = $progress;
        // The ability path was taken from the progress where the engine had no debug path: then it is the old
        // progress' path, and the own progress gives the right one.
        if ($oldpath !== [] && ($trace['abilitypath'] ?? null) === $oldpath) {
            $trace['abilitypath'] = attempt_collector::path_of((array) ($progress['abilitytrace'] ?? []));
        }
        $trace['steps'] = count((array) ($progress['playedquestions'] ?? [])) ?: (int) ($trace['steps'] ?? 0);
        $trace['provenance'] = ['rederived' => true, 'rules' => self::RULES];
        return $trace;
    }

    /**
     * Add a decision row.
     *
     * @param int $attemptid The sitting.
     * @param array $verdict assess() of it.
     * @param string $decision The decision.
     * @param string|null $previous The trace as it was, where it is replaced.
     * @param string $note Why.
     * @return int The row.
     */
    protected static function add(int $attemptid, array $verdict, string $decision, ?string $previous, string $note): int {
        global $DB, $USER;
        return (int) $DB->insert_record(self::TABLE, (object) [
            'attemptid' => $attemptid, 'verdict' => $verdict['verdict'], 'progresscount' => $verdict['progress'],
            'itemcount' => $verdict['items'], 'overlap' => $verdict['overlap'], 'decision' => $decision,
            'previoustrace' => $previous, 'previoushash' => $previous === null ? null : hash('sha256', $previous),
            'rules' => self::RULES, 'note' => \core_text::substr($note, 0, 255),
            'userid' => (int) ($USER->id ?? 0), 'timecreated' => time(),
        ]);
    }
}
