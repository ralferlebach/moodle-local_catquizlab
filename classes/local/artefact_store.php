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
 * Debug artefacts of executions, in moodledata and as a ZIP.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Where an execution's artefacts live, what they say, and how they leave (#107).
 *
 * The worker writes screenshots, DOM and browser events of a failed execution.
 * This class adds what only the server knows — the run's design, the normalised
 * reason, the history, a log excerpt — keeps a line per execution for every
 * run, packs it all into a ZIP a person can read, and removes it again when
 * the configured time is up.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class artefact_store {
    /** @var string A download of one sitting. */
    public const SCOPE_ATTEMPT = 'attempt';

    /** @var string A download of a whole run. */
    public const SCOPE_RUN = 'run';

    /** @var string A download of a run's failed sittings. */
    public const SCOPE_FAILED = 'failed';

    /**
     * The root of all artefacts.
     *
     * @return string
     */
    public static function root(): string {
        global $CFG;

        return $CFG->dataroot . '/local_catquizlab/artefacts';
    }

    /**
     * A run's directory.
     *
     * @param int $experimentid The experiment.
     * @param int $runid The run.
     * @return string
     */
    public static function run_dir(int $experimentid, int $runid): string {
        return self::root() . '/experiment-' . $experimentid . '/run-' . $runid;
    }

    /**
     * One execution's directory.
     *
     * @param int $experimentid The experiment.
     * @param int $runid The run.
     * @param int $attemptid The sitting.
     * @param int $execution Which try.
     * @return string
     */
    public static function execution_dir(int $experimentid, int $runid, int $attemptid, int $execution): string {
        return self::run_dir($experimentid, $runid) . '/attempt-' . $attemptid . '/execution-' . $execution;
    }

    /**
     * Document how an execution ended — every execution, not only failures.
     *
     * A failure gets a reason.json beside the worker's artefacts, with the run's
     * design, the reason, the raw message and a log excerpt. Every execution,
     * failed or finished, adds one line to its run's reasons.jsonl: the ends a
     * test was designed to reach are results as much as failures are faults.
     *
     * @param int $attemptid The sitting.
     * @param string $outcome attempt_history::FAILED or ::COLLECTED.
     * @param string $reasoncode The normalised reason.
     * @param string $message The raw message, if any.
     * @return void
     */
    public static function document(int $attemptid, string $outcome, string $reasoncode, string $message = ''): void {
        global $DB;

        $attempt = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid]);
        if (!$attempt) {
            return;
        }
        $run = $DB->get_record('local_catquizlab_run', ['id' => $attempt->runid]);
        if (!$run) {
            return;
        }
        $experimentid = (int) $run->experimentid;
        $execution = (int) $attempt->tries;
        $line = [
            'time'          => time(),
            'attemptid'     => $attemptid,
            'execution'     => $execution,
            'outcome'       => $outcome,
            'reason_code'   => $reasoncode,
            'reason_label'  => reason_catalog::label($reasoncode),
            'correlationid' => debug_trace::correlation_id(),
        ];

        try {
            $rundir = self::run_dir($experimentid, (int) $run->id);
            check_dir_exists($rundir, true, true);
            $jsonline = json_encode($line, JSON_UNESCAPED_SLASHES) . "\n";
            file_put_contents($rundir . '/reasons.jsonl', $jsonline, FILE_APPEND | LOCK_EX);

            if ($outcome === attempt_history::FAILED) {
                $dir = self::execution_dir($experimentid, (int) $run->id, $attemptid, $execution);
                check_dir_exists($dir, true, true);
                $definition = run_registry::definition_for($run);
                file_put_contents($dir . '/reason.json', json_encode([
                    'attempt' => [
                        'id' => $attemptid, 'personid' => (int) $attempt->personid, 'tries' => (int) $attempt->tries,
                        'engineattemptid' => (int) ($attempt->engineattemptid ?? 0),
                    ],
                    'run' => [
                        'id' => (int) $run->id, 'experimentid' => $experimentid, 'cellkey' => (string) $run->cellkey,
                        'strategy' => (string) ($definition['strategy'] ?? ''),
                        'model' => (string) ($definition['model'] ?? ''),
                        'variant' => (string) ($definition['pool']['variant'] ?? ''),
                        'stratum' => (string) ($definition['persons']['stratum'] ?? ''),
                    ],
                    'reason_code'   => $reasoncode,
                    'reason_label'  => reason_catalog::label($reasoncode),
                    'raw_message'   => attempt_history::redact($message),
                    'correlationid' => debug_trace::correlation_id(),
                    'log_excerpt'   => self::log_excerpt((int) $run->id),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        } catch (\Throwable $e) {
            // Documentation must not make an execution fail.
            debugging('Artefact documentation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * The last lifecycle entries of a run.
     *
     * @param int $runid The run.
     * @return array[]
     */
    protected static function log_excerpt(int $runid): array {
        global $DB;

        $fields = 'id, event, stage, detail, timecreated';
        $rows = $DB->get_records('local_catquizlab_runlog', ['runid' => $runid], 'id DESC', $fields, 0, 15);
        $out = [];
        foreach (array_reverse($rows) as $row) {
            $out[] = [
                'time' => userdate((int) $row->timecreated, '%Y-%m-%d %H:%M:%S'),
                'event' => (string) $row->event,
                'stage' => (string) ($row->stage ?? ''),
                'detail' => attempt_history::redact((string) ($row->detail ?? '')),
            ];
        }

        return $out;
    }

    /**
     * The artefacts of one sitting, per execution, with when they were written.
     *
     * @param int $attemptid The sitting.
     * @return array[] execution, path, files [{name, size, time}].
     */
    public static function list_for_attempt(int $attemptid): array {
        global $DB;

        $attempt = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid], 'id, runid');
        $run = $attempt ? $DB->get_record('local_catquizlab_run', ['id' => $attempt->runid], 'id, experimentid') : null;
        if (!$run) {
            return [];
        }
        $base = self::run_dir((int) $run->experimentid, (int) $run->id) . '/attempt-' . $attemptid;
        $out = [];
        foreach (glob($base . '/execution-*', GLOB_ONLYDIR) ?: [] as $dir) {
            $files = [];
            foreach (glob($dir . '/*') ?: [] as $file) {
                $files[] = ['name' => basename($file), 'size' => filesize($file), 'time' => filemtime($file)];
            }
            $out[] = [
                'execution' => (int) substr(basename($dir), strlen('execution-')),
                'path'      => $dir,
                'files'     => $files,
            ];
        }
        usort($out, static fn(array $a, array $b): int => $a['execution'] <=> $b['execution']);

        return $out;
    }

    /**
     * Pack artefacts into a ZIP with a structure a person can read.
     *
     *     manifest.json
     *     attempt-<id>/attempt.json      metadata and history
     *     attempt-<id>/logs.json         history and run log excerpt
     *     attempt-<id>/screenshots/      execution-<n>-screenshot-*.jpg
     *     attempt-<id>/html/             execution-<n>-dom.html (if allowed)
     *     attempt-<id>/network/          execution-<n>-events.json, -error.json, -reason.json
     *
     * Everything textual passes through redaction on the way in.
     *
     * @param string $scope One of the SCOPE_ constants.
     * @param int $runid The run.
     * @param int $attemptid The sitting, for SCOPE_ATTEMPT.
     * @return string|null The path of the ZIP, or null when there is nothing to pack.
     */
    public static function zip(string $scope, int $runid, int $attemptid = 0): ?string {
        global $DB, $CFG;

        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid], '*', MUST_EXIST);
        $conditions = 'runid = :runid';
        $params = ['runid' => $runid];
        if ($scope === self::SCOPE_ATTEMPT) {
            $conditions .= ' AND id = :attemptid';
            $params['attemptid'] = $attemptid;
        } else if ($scope === self::SCOPE_FAILED) {
            $conditions .= ' AND (status = :failed OR id IN (SELECT attemptid FROM {local_catquizlab_attemptlog}'
                . ' WHERE runid = :logrunid AND outcome = :logfailed))';
            $params += [
                'failed' => attempt_scheduler::STATUS_FAILED,
                'logrunid' => $runid,
                'logfailed' => attempt_history::FAILED,
            ];
        }
        $attempts = $DB->get_records_select('local_catquizlab_attempt', $conditions, $params, 'id ASC');

        $includehtml = (bool) get_config('local_catquizlab', 'artefacts_include_html');
        $files = [];
        $manifest = [
            'generated'    => date('c'),
            'scope'        => $scope,
            'run'          => [
                'id' => (int) $run->id,
                'experimentid' => (int) $run->experimentid,
                'cellkey' => (string) $run->cellkey,
            ],
            'include_html' => $includehtml,
            'attempts'     => [],
        ];

        foreach ($attempts as $attempt) {
            $prefix = 'attempt-' . $attempt->id . '/';
            $history = attempt_history::of_attempt((int) $attempt->id);
            $files[$prefix . 'attempt.json'] = [self::redacted_json([
                'id' => (int) $attempt->id, 'status' => (int) $attempt->status, 'tries' => (int) $attempt->tries,
                'engineattemptid' => (int) ($attempt->engineattemptid ?? 0), 'history' => $history,
            ])];
            $files[$prefix . 'logs.json'] = [self::redacted_json([
                'history' => $history,
                'run_log' => self::log_excerpt((int) $run->id),
            ])];

            $executions = self::list_for_attempt((int) $attempt->id);
            foreach ($executions as $execution) {
                foreach ($execution['files'] as $file) {
                    $source = $execution['path'] . '/' . $file['name'];
                    $name = 'execution-' . $execution['execution'] . '-' . $file['name'];
                    if (substr($file['name'], -4) === '.jpg') {
                        $files[$prefix . 'screenshots/' . $name] = $source;
                    } else if ($file['name'] === 'dom.html') {
                        if ($includehtml) {
                            $files[$prefix . 'html/' . $name] = [self::redact_html((string) file_get_contents($source))];
                        }
                    } else {
                        $files[$prefix . 'network/' . $name] = [attempt_history::redact((string) file_get_contents($source))];
                    }
                }
            }
            $manifest['attempts'][] = [
                'id' => (int) $attempt->id,
                'executions' => array_map(static fn(array $e): array => [
                    'execution' => $e['execution'],
                    'files' => array_map(static fn(array $f): array => [
                        'name' => $f['name'], 'size' => $f['size'], 'written' => date('c', $f['time']),
                    ], $e['files']),
                ], $executions),
            ];
        }
        if ($attempts === []) {
            return null;
        }
        $files['manifest.json'] = [self::redacted_json($manifest)];

        $zipfile = make_request_directory() . '/catquizlab-run-' . $runid . '-' . $scope . '.zip';
        $packer = get_file_packer('application/zip');
        $packer->archive_to_pathname($files, $zipfile);

        return is_file($zipfile) ? $zipfile : null;
    }

    /**
     * A ZIP for the current user, if they may have it.
     *
     * The one way a download is built for a person: screenshots and page
     * snapshots show what a simulated person saw and how a run is designed, so
     * they are for the debug capability only — checked here, where the ZIP is
     * made, not only where the button is drawn.
     *
     * @param string $scope One of the SCOPE_ constants.
     * @param int $runid The run.
     * @param int $attemptid The sitting, for SCOPE_ATTEMPT.
     * @param \context $context Where the capability is checked.
     * @return string|null The path of the ZIP, or null when there is nothing to pack.
     * @throws \required_capability_exception Without the debug capability.
     */
    public static function zip_for_download(string $scope, int $runid, int $attemptid, \context $context): ?string {
        require_capability('local/catquizlab:debug', $context);

        return self::zip($scope, $runid, $attemptid);
    }

    /**
     * JSON of a structure, redacted.
     *
     * @param array $data The data.
     * @return string
     */
    protected static function redacted_json(array $data): string {
        $json = (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return attempt_history::redact($json);
    }

    /**
     * A DOM snapshot without the session key Moodle embeds in every page.
     *
     * @param string $html The snapshot.
     * @return string
     */
    public static function redact_html(string $html): string {
        $html = attempt_history::redact($html);
        // In M.cfg, in hidden form fields, and in logout links.
        $html = (string) preg_replace('/("sesskey"\s*:\s*")[^"]*(")/i', '$1[redacted]$2', $html);
        $html = (string) preg_replace('/(name=["\']sesskey["\'][^>]*value=["\'])[^"\']*(["\'])/i', '$1[redacted]$2', $html);
        $html = (string) preg_replace('/(value=["\'])[^"\']*(["\'][^>]*name=["\']sesskey["\'])/i', '$1[redacted]$2', $html);
        $secretfields = '/(name=["\'](?:password|logintoken)["\'][^>]*value=["\'])[^"\']*(["\'])/i';
        $html = (string) preg_replace($secretfields, '$1[redacted]$2', $html);

        return $html;
    }

    /**
     * Remove execution directories older than the configured retention.
     *
     * @param int|null $now For tests.
     * @return int How many execution directories were removed.
     */
    public static function cleanup(?int $now = null): int {
        $days = (int) get_config('local_catquizlab', 'artefact_retention_days');
        if ($days <= 0 || !is_dir(self::root())) {
            return 0;
        }
        $limit = ($now ?? time()) - $days * DAYSECS;
        $removed = 0;
        foreach (glob(self::root() . '/experiment-*/run-*/attempt-*/execution-*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) < $limit) {
                remove_dir($dir);
                $removed++;
            }
        }

        return $removed;
    }
}
