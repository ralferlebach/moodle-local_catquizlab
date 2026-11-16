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
 * The claim under a backlog like the live installation's, bound to the execution line (#163 AK8, AK9, DoD2, DoD6).
 *
 * Builds, in its own rows only: --old experiments started outside the line, each with four running runs and --per
 * queued sittings (due time 0, queued a day ago) and a few in play; and one experiment holding the line with
 * --holderruns ready runs of --holdersittings sittings each, queued later. Then:
 * 1. the claim of 0.7.64 (nextruntime, timecreated, id over every ready run): how many of its first fifty belong to
 *    the holder — on the live installation none;
 * 2. --claims claims one after another: every one must be the holder's, in the order they were queued; time per claim;
 * 3. the counts (queue_breakdown) and the forecast (run_figures, eta) on the same backlog: time;
 * 4. for each of --processes (e.g. 11,25): that many processes claiming at once until the holder has nothing left:
 *    every sitting the holder's, none twice, the experiments outside the line untouched;
 * 5. the line afterwards: the holder through, the others adopted at its end, in the order their sittings were queued;
 * 6. the query plan of the claim's candidate query (PostgreSQL: EXPLAIN ANALYZE; MariaDB/MySQL: EXPLAIN).
 * Everything it created is deleted at the end. It refuses to run beside other queued work unless --force.
 *
 * Exit 0 on PASS, 1 on FAIL.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\execution_queue;
use local_catquizlab\local\registry;

[$options] = cli_get_params([
    'old' => 20, 'per' => 3000, 'holderruns' => 6, 'holdersittings' => 50, 'claims' => 120, 'processes' => '11,25',
    'force' => false, 'keep' => false, 'help' => false,
    'child' => false, 'workerid' => '', 'start' => 0, 'max' => 0,
]);
if (!empty($options['help'])) {
    cli_writeln('php cli/claim_bench.php [--old=20] [--per=3000] [--holderruns=6] [--holdersittings=50] [--claims=120]'
        . ' [--processes=11,25] [--force] [--keep]');
    exit(0);
}

\core\session\manager::set_user(get_admin());

if (!empty($options['child'])) {
    // A claimer: from the common start, claim until nothing is handed out; print each sitting and its time.
    while (microtime(true) < (float) $options['start']) {
        usleep(1000);
    }
    for ($i = 0; $i < (int) $options['max']; $i++) {
        $t = microtime(true);
        $claim = \local_catquizlab\external\job_claim::execute((string) $options['workerid']);
        $ms = (int) round(1000 * (microtime(true) - $t));
        if (!empty($claim['hasjob'])) {
            echo 'job ', (int) $claim['attemptid'], ' ', $ms, "\n";
        } else if (!empty($claim['retry'])) {
            usleep(20000);
        } else {
            echo 'none ', $ms, "\n";
            break;
        }
    }
    exit(0);
}

$tag = 'claim-bench-' . time();
$created = ['experiments' => [], 'runs' => []];
$fail = [];
$step = static function (string $text): void {
    cli_writeln($text);
};

// Nothing else queued: the measurement would mix with real work, and the line it moves is real.
$others = $DB->count_records('local_catquizlab_attempt', ['status' => attempt_scheduler::STATUS_QUEUED]);
if ($others > 0 && empty($options['force'])) {
    cli_error("{$others} queued sitting(s) exist: this would move the real execution line. "
        . 'Run it on an installation without queued work, or with --force.');
}

register_shutdown_function(static function () use (&$created, $options): void {
    if (empty($options['keep'])) {
        claim_bench_cleanup($created);
    }
});

$old = max(1, (int) $options['old']);
$per = max(4, (int) $options['per']);
$day = time() - DAYSECS;
$person = 1000000000;

// 1. The backlog: experiments started outside the line, earlier.
$t = microtime(true);
for ($e = 0; $e < $old; $e++) {
    $experimentid = claim_bench_experiment($tag . '-old-' . $e, $day);
    $created['experiments'][] = $experimentid;
    for ($r = 0; $r < 4; $r++) {
        $runid = claim_bench_run($experimentid, registry::STATUS_RUNNING, $day + $e * 60 + $r);
        $created['runs'][] = $runid;
        $rows = [];
        $n = intdiv($per, 4);
        for ($i = 0; $i < $n; $i++) {
            $inplay = $i < 2 && $r === 0;
            $rows[] = ['runid' => $runid, 'personid' => $person++, 'nextruntime' => 0, 'tries' => $inplay ? 1 : 0,
                'status' => $inplay ? attempt_scheduler::STATUS_RUNNING : attempt_scheduler::STATUS_QUEUED,
                'leaseowner' => $inplay ? 'claim-bench-old' : null, 'leaseexpires' => $inplay ? time() + HOURSECS : 0,
                'timecreated' => $day + $e * 60 + $r, 'timemodified' => $day];
        }
        $DB->insert_records('local_catquizlab_attempt', $rows);
    }
}
// The holder: queued later, holding the line.
$holder = claim_bench_experiment($tag . '-holder', time() - HOURSECS);
$created['experiments'][] = $holder;
$holdersittings = [];
for ($r = 0; $r < (int) $options['holderruns']; $r++) {
    $runid = claim_bench_run($holder, registry::STATUS_READY, time() - HOURSECS + $r);
    $created['runs'][] = $runid;
    $rows = [];
    for ($i = 0; $i < (int) $options['holdersittings']; $i++) {
        $rows[] = ['runid' => $runid, 'personid' => $person++, 'nextruntime' => 0, 'tries' => 0,
            'status' => attempt_scheduler::STATUS_QUEUED, 'leaseowner' => null, 'leaseexpires' => 0,
            'timecreated' => time() - HOURSECS + $r, 'timemodified' => time()];
    }
    $DB->insert_records('local_catquizlab_attempt', $rows);
}
// It holds the line the way an operator tool does: openly, for a limited time (whatever held it before waits at the front).
if (empty(execution_queue::take_front($holder, 'cli/claim_bench.php', HOURSECS)['ok'])) {
    cli_error('The execution line could not be taken.');
}
[$hin, $hparams] = $DB->get_in_or_equal(claim_bench_runs($holder));
$holdersittings = array_map('intval', $DB->get_fieldset_select(
    'local_catquizlab_attempt',
    'id',
    "runid {$hin} AND status = ?",
    array_merge($hparams, [attempt_scheduler::STATUS_QUEUED])
));
sort($holdersittings);
$backlog = $DB->count_records_select('local_catquizlab_attempt', 'status = ?', [attempt_scheduler::STATUS_QUEUED]);
$step(sprintf(
    'Backlog: %d experiments outside the line with %d queued sitting(s) and %d in play; holder %d with %d runs × %d '
    . '(built in %.1f s, %s).',
    $old,
    $backlog - count($holdersittings),
    2 * $old,
    $holder,
    (int) $options['holderruns'],
    (int) $options['holdersittings'],
    microtime(true) - $t,
    $DB->get_dbfamily()
));

// 2. The claim of 0.7.64, for comparison.
$t = microtime(true);
$first = $DB->get_records_sql(
    'SELECT a.id, a.runid FROM {local_catquizlab_attempt} a
      WHERE a.status = :queued AND a.nextruntime <= :now
        AND a.runid IN (SELECT id FROM {local_catquizlab_run} WHERE status IN (:ready, :running))
   ORDER BY a.nextruntime ASC, a.timecreated ASC, a.id ASC',
    ['queued' => attempt_scheduler::STATUS_QUEUED, 'now' => time(), 'ready' => registry::STATUS_READY,
        'running' => registry::STATUS_RUNNING],
    0,
    50
);
$ofholder = count(array_intersect(array_map('intval', array_column($first, 'runid')), claim_bench_runs($holder)));
$step(sprintf(
    'Claim order of 0.7.64: %d of its first 50 candidates belong to the holder (%.1f ms).',
    $ofholder,
    1000 * (microtime(true) - $t)
));

// 3. Claims one after another.
$times = [];
$handed = [];
$n = min((int) $options['claims'], count($holdersittings));
for ($i = 0; $i < $n; $i++) {
    $t = microtime(true);
    $claim = \local_catquizlab\external\job_claim::execute('claim-bench-seq');
    $times[] = 1000 * (microtime(true) - $t);
    if (empty($claim['hasjob']) || (int) $claim['experimentid'] !== $holder) {
        $fail[] = 'claim ' . ($i + 1) . ' did not hand out a sitting of the holder';
        break;
    }
    $handed[] = (int) $claim['attemptid'];
}
if ($handed !== array_slice($holdersittings, 0, count($handed))) {
    $fail[] = 'the claims did not follow the order the sittings were queued in';
}
$step(sprintf(
    '%d claims one after another: all of the holder, in queued order: %s; ms per claim p50 %.1f, p95 %.1f, max %.1f.',
    count($handed),
    $fail === [] ? 'yes' : 'NO',
    claim_bench_q($times, 0.5),
    claim_bench_q($times, 0.95),
    $times === [] ? 0 : max($times)
));

// 4. Counts and forecast on the same backlog.
$t = microtime(true);
$breakdown = attempt_scheduler::queue_breakdown();
$tb = 1000 * (microtime(true) - $t);
$t = microtime(true);
$forecast = \local_catquizlab\local\eta::forecast([], time());
$tf = 1000 * (microtime(true) - $t);
$step(sprintf(
    'Counts: claimable %d, waiting for their turn %d (%.0f ms). Forecast over %d run(s): %.0f ms.',
    $breakdown['claimable'],
    $breakdown['waitingturn'],
    $tb,
    count($forecast['figures']['runs']),
    $tf
));
if ($breakdown['claimable'] !== count($holdersittings) - count($handed)) {
    $fail[] = 'claimable is not the holder\'s queued sittings';
}

// 5. Many claimers at once, as many processes as given, each until nothing is handed out.
foreach (array_filter(array_map('intval', explode(',', (string) $options['processes']))) as $processes) {
    $left = $DB->count_records_select(
        'local_catquizlab_attempt',
        "runid {$hin} AND status = ?",
        array_merge($hparams, [attempt_scheduler::STATUS_QUEUED])
    );
    if ($left < $processes) {
        // Put the holder's sittings back in the queue for the next round.
        $DB->execute(
            "UPDATE {local_catquizlab_attempt} SET status = ?, leaseowner = NULL, leaseexpires = 0 WHERE runid {$hin}",
            array_merge([attempt_scheduler::STATUS_QUEUED], $hparams)
        );
        $left = count($holdersittings);
    }
    $start = microtime(true) + 2.0;
    $children = [];
    for ($p = 1; $p <= $processes; $p++) {
        $handle = proc_open([PHP_BINARY, __FILE__, '--child', '--workerid=claim-bench-' . $processes . '-' . $p,
            '--start=' . $start, '--max=' . ($left + 5)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $children[] = [$handle, $pipes];
    }
    $got = [];
    $ms = [];
    $errors = '';
    foreach ($children as [$handle, $pipes]) {
        foreach (explode("\n", trim((string) stream_get_contents($pipes[1]))) as $line) {
            $parts = explode(' ', $line);
            if ($parts[0] === 'job') {
                $got[] = (int) $parts[1];
                $ms[] = (float) $parts[2];
            }
        }
        $errors .= trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($handle);
    }
    $twice = count($got) - count(array_unique($got));
    [$gin, $gparams] = $got === [] ? ['= 0', []] : $DB->get_in_or_equal($got);
    $foreign = $got === [] ? 0 : $DB->count_records_select(
        'local_catquizlab_attempt',
        "id {$gin} AND NOT runid {$hin}",
        array_merge($gparams, $hparams)
    );
    $ok = $twice === 0 && $foreign === 0 && count($got) === $left && $errors === '';
    $step(sprintf(
        '%d claimers at once: %d of %d queued sittings of the holder handed out, %d twice, %d of another '
        . 'experiment; ms per claim p50 %.1f, p95 %.1f, max %.1f%s.',
        $processes,
        count($got),
        $left,
        $twice,
        $foreign,
        claim_bench_q($ms, 0.5),
        claim_bench_q($ms, 0.95),
        $ms === [] ? 0 : max($ms),
        $ok ? '' : ' — FAIL'
    ));
    if ($errors !== '') {
        cli_writeln('  ' . substr($errors, 0, 500));
    }
    if (!$ok) {
        $fail[] = $processes . ' claimers at once';
    }
}

// 6. The holder through: the next claim moves the line on; the others wait at its end in the order they were queued.
$DB->execute(
    "UPDATE {local_catquizlab_attempt} SET status = ? WHERE runid {$hin}",
    array_merge([attempt_scheduler::STATUS_COLLECTED], $hparams)
);
$DB->execute(
    'UPDATE {local_catquizlab_attempt} SET status = ? WHERE leaseowner = ?',
    [attempt_scheduler::STATUS_COLLECTED, 'claim-bench-old']
);
$t = microtime(true);
$next = \local_catquizlab\external\job_claim::execute('claim-bench-seq');
$tn = 1000 * (microtime(true) - $t);
$expected = array_slice($created['experiments'], 0, $old);
// The line as far as this measurement's experiments go: entries of others, if any, are not its business.
$line = array_values(array_intersect(array_column(execution_queue::entries(), 'experimentid'), $expected));
$holderentry = $DB->get_record('local_catquizlab_execqueue', ['experimentid' => $holder]);
$moved = !empty($next['hasjob']) && (int) $next['experimentid'] === $expected[0] && $line === $expected
    && $holderentry->state === execution_queue::FINISHED;
$step(sprintf(
    'Holder through: the line moved on to experiment %d (%s), %d adopted in queued order: %s (%.0f ms for the claim '
    . 'that moved it).',
    (int) ($next['experimentid'] ?? 0),
    $holderentry->reason,
    count($line),
    $line === $expected ? 'yes' : 'NO',
    $tn
));
if (!$moved) {
    $fail[] = 'the line did not move on as it should';
}

// 7. The query plan of the claim's candidate query for the new holder.
$runs = claim_bench_runs($expected[0]);
[$pin, $pparams] = $DB->get_in_or_equal($runs, SQL_PARAMS_QM);
$sql = "SELECT * FROM {local_catquizlab_attempt} WHERE status = ? AND nextruntime <= ? AND runid {$pin}
          AND personid NOT IN (SELECT DISTINCT busy.personid FROM {local_catquizlab_attempt} busy
                                WHERE busy.status = ? AND busy.leaseexpires > ? AND busy.personid > 0)
        ORDER BY timecreated ASC, id ASC LIMIT 50";
$params = array_merge([attempt_scheduler::STATUS_QUEUED, time()], $pparams, [attempt_scheduler::STATUS_RUNNING, time()]);
cli_writeln('Plan of the candidate query (' . $DB->get_dbfamily() . '):');
if ($DB->get_dbfamily() === 'postgres') {
    // One column, one line each; not a recordset — PostgreSQL does not run EXPLAIN inside a cursor.
    foreach ($DB->get_fieldset_sql('EXPLAIN ANALYZE ' . $sql, $params) as $line) {
        cli_writeln('  ' . $line);
    }
} else {
    foreach ($DB->get_records_sql('EXPLAIN ' . $sql, $params) as $row) {
        cli_writeln('  ' . implode(' | ', array_map('strval', (array) $row)));
    }
}

cli_writeln($fail === [] ? 'PASS' : 'FAIL: ' . implode('; ', $fail));
exit($fail === [] ? 0 : 1);

/**
 * An experiment of this measurement.
 *
 * @param string $name Its name.
 * @param int $created When.
 * @return int
 */
function claim_bench_experiment(string $name, int $created): int {
    global $DB;

    return (int) $DB->insert_record('local_catquizlab_experiment', (object) ['name' => $name, 'tier' => 'baseline',
        'configjson' => '{}', 'status' => 0, 'usermodified' => 2, 'timecreated' => $created, 'timemodified' => $created]);
}

/**
 * A run of this measurement.
 *
 * @param int $experimentid The experiment.
 * @param int $status Its status.
 * @param int $created When.
 * @return int
 */
function claim_bench_run(int $experimentid, int $status, int $created): int {
    global $DB;

    return (int) $DB->insert_record('local_catquizlab_run', (object) ['experimentid' => $experimentid,
        'cellkey' => 'bench-' . $experimentid . '-' . $created, 'status' => $status, 'usermodified' => 2,
        'timecreated' => $created, 'timemodified' => $created]);
}

/**
 * The runs of an experiment.
 *
 * @param int $experimentid The experiment.
 * @return int[]
 */
function claim_bench_runs(int $experimentid): array {
    global $DB;

    return array_map('intval', $DB->get_fieldset_select('local_catquizlab_run', 'id', 'experimentid = ?', [$experimentid]));
}

/**
 * A quantile.
 *
 * @param float[] $values The values.
 * @param float $q The quantile, 0..1.
 * @return float
 */
function claim_bench_q(array $values, float $q): float {
    if ($values === []) {
        return 0.0;
    }
    sort($values);

    return (float) $values[(int) min(count($values) - 1, floor($q * count($values)))];
}

/**
 * Delete everything this measurement made: sittings and their history, runs and their log, experiments, the line's
 * entries, the workers its claimers registered.
 *
 * @param array $created What was created.
 * @return void
 */
function claim_bench_cleanup(array $created): void {
    global $DB;

    if ($created['runs'] !== []) {
        [$in, $params] = $DB->get_in_or_equal($created['runs']);
        $DB->delete_records_select('local_catquizlab_attemptlog', "runid {$in}", $params);
        $DB->delete_records_select('local_catquizlab_runlog', "runid {$in}", $params);
        $DB->delete_records_select('local_catquizlab_attempt', "runid {$in}", $params);
        $DB->delete_records_select('local_catquizlab_run', "id {$in}", $params);
    }
    if ($created['experiments'] !== []) {
        [$in, $params] = $DB->get_in_or_equal($created['experiments']);
        $DB->delete_records_select('local_catquizlab_execqueue', "experimentid {$in}", $params);
        $DB->delete_records_select('local_catquizlab_experiment', "id {$in}", $params);
    }
    $DB->delete_records_select('local_catquizlab_worker', $DB->sql_like('workerid', '?'), ['claim-bench-%']);
}
