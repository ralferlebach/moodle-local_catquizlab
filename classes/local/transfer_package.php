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
 * Transfer package: bundle a run for hub submission and ingest (E5).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Bundles a run into a portable package and ingests it on the hub (E5).
 *
 * A node packages a run — its metadata, persons (by index), attempts (with
 * traces) and results — as a JSON payload with a SHA-256 integrity hash
 * ({@see self::build()}). The hub verifies the hash ({@see self::verify()}) and
 * recreates the run locally under a dedicated ingest experiment
 * ({@see self::ingest()}), re-mapping person references by index. Packaging,
 * verification and ingest read/write only the lab store and are testable;
 * {@see self::submit_to_hub()} performs the network call and is guarded by the hub
 * configuration.
 */
class transfer_package {
    /** @var int The package format version. */
    public const VERSION = 1;

    /**
     * Build the transfer package for a run.
     *
     * @param int $runid The run.
     * @return array{payload: string, hash: string}
     */
    public static function build(int $runid): array {
        $payload = json_encode(self::assemble($runid), JSON_UNESCAPED_SLASHES);
        return ['payload' => $payload, 'hash' => hash('sha256', $payload)];
    }

    /**
     * Verify a payload against its hash.
     *
     * @param string $payload The JSON payload.
     * @param string $hash The expected SHA-256 hash.
     * @return bool
     */
    public static function verify(string $payload, string $hash): bool {
        return hash_equals(hash('sha256', $payload), $hash);
    }

    /**
     * Ingest a package payload as a new local run (hub side).
     *
     * @param string $payload The JSON payload.
     * @param array $options 'experimentid' to attach to (defaults to the ingest experiment).
     * @return int|null The new local run id, or null on a malformed payload.
     */
    public static function ingest(string $payload, array $options = []): ?int {
        global $DB;

        $data = json_decode($payload, true);
        if (!is_array($data) || !isset($data['run'])) {
            return null;
        }

        $now = time();
        $experimentid = (int) ($options['experimentid'] ?? self::ingest_experiment());
        $run = $data['run'];
        $runid = $DB->insert_record('local_catquizlab_run', (object) [
            'experimentid' => $experimentid,
            'cellkey'      => $run['cellkey'] ?? 'ingested',
            'seed'         => (int) ($run['seed'] ?? 0),
            'replication'  => (int) ($run['replication'] ?? 1),
            'status'       => (int) ($run['status'] ?? 20),
            'manifestjson' => $run['manifestjson'] ?? null,
            'usermodified' => 0,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);

        $personids = self::ingest_persons($runid, $data['persons'] ?? [], $now);
        self::ingest_attempts($runid, $data['attempts'] ?? [], $personids, $now);
        self::ingest_results($runid, $data['results'] ?? [], $now);

        return $runid;
    }

    /**
     * Submit a run package to the configured hub (network; guarded).
     *
     * @param int $runid The run to submit.
     * @return array{submitted: bool, verified: bool, message: string}
     */
    public static function submit_to_hub(int $runid): array {
        global $DB, $USER;

        $huburl = trim((string) get_config('local_catquizlab', 'hub_url'));
        $hubtoken = trim((string) get_config('local_catquizlab', 'hub_token'));
        if ($huburl === '' || $hubtoken === '') {
            return ['submitted' => false, 'verified' => false, 'message' => 'hub not configured'];
        }

        $package = self::build($runid);
        $transferid = $DB->insert_record('local_catquizlab_transfer', (object) [
            'runid'       => $runid,
            'direction'   => 'submit',
            'remotehost'  => $huburl,
            'payloadhash' => $package['hash'],
            'status'      => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $response = self::post($huburl, $hubtoken, 'local_catquizlab_hub_submit_run', [
            'payload' => $package['payload'],
            'hash'    => $package['hash'],
        ]);
        $accepted = is_array($response) && !empty($response['accepted']);

        $DB->update_record('local_catquizlab_transfer', (object) [
            'id'           => $transferid,
            'status'       => $accepted ? 20 : 40,
            'timemodified' => time(),
        ]);

        return [
            'submitted' => $accepted,
            'verified'  => is_array($response) && !empty($response['verified']),
            'message'   => is_array($response) ? (string) ($response['message'] ?? '') : 'no response',
        ];
    }

    /**
     * Assemble the package data structure for a run.
     *
     * @param int $runid The run.
     * @return array
     */
    protected static function assemble(int $runid): array {
        global $DB;

        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid], '*', MUST_EXIST);

        $index = [];
        $persons = [];
        foreach ($DB->get_records('local_catquizlab_person', ['runid' => $runid], 'id') as $offset => $person) {
            $index[$person->id] = count($persons);
            $persons[] = [
                'stratum'       => (string) $person->stratum,
                'abilityglobal' => $person->abilityglobal === null ? null : (float) $person->abilityglobal,
                'profilejson'   => $person->profilejson,
            ];
        }

        $attempts = [];
        foreach ($DB->get_records('local_catquizlab_attempt', ['runid' => $runid], 'id') as $attempt) {
            $attempts[] = [
                'personindex'     => $index[$attempt->personid] ?? null,
                'status'          => (int) $attempt->status,
                'tracejson'       => $attempt->tracejson,
                'runtimems'       => $attempt->runtimems === null ? null : (int) $attempt->runtimems,
                'engineattemptid' => $attempt->engineattemptid === null ? null : (int) $attempt->engineattemptid,
            ];
        }

        $results = [];
        foreach ($DB->get_records('local_catquizlab_result', ['runid' => $runid], 'id') as $result) {
            $results[] = [
                'metric'     => (string) $result->metric,
                'scope'      => (string) $result->scope,
                'value'      => $result->value === null ? null : (float) $result->value,
                'detailjson' => $result->detailjson,
            ];
        }

        return [
            'version' => self::VERSION,
            'run'     => [
                'cellkey'      => (string) $run->cellkey,
                'seed'         => (int) $run->seed,
                'replication'  => (int) $run->replication,
                'status'       => (int) $run->status,
                'manifestjson' => $run->manifestjson,
            ],
            'persons'  => $persons,
            'attempts' => $attempts,
            'results'  => $results,
        ];
    }

    /**
     * Insert the ingested persons and return index => new id.
     *
     * @param int $runid The new run id.
     * @param array $persons The package persons.
     * @param int $now Timestamp.
     * @return array<int, int>
     */
    protected static function ingest_persons(int $runid, array $persons, int $now): array {
        global $DB;

        $ids = [];
        foreach ($persons as $offset => $person) {
            $ids[$offset] = $DB->insert_record('local_catquizlab_person', (object) [
                'runid'         => $runid,
                'stratum'       => $person['stratum'] ?? '',
                'abilityglobal' => $person['abilityglobal'] ?? 0,
                'profilejson'   => $person['profilejson'] ?? null,
                'timecreated'   => $now,
                'timemodified'  => $now,
            ]);
        }
        return $ids;
    }

    /**
     * Insert the ingested attempts, mapping person references by index.
     *
     * @param int $runid The new run id.
     * @param array $attempts The package attempts.
     * @param array $personids The index => new person id map.
     * @param int $now Timestamp.
     * @return void
     */
    protected static function ingest_attempts(int $runid, array $attempts, array $personids, int $now): void {
        global $DB;

        foreach ($attempts as $attempt) {
            $personid = $personids[$attempt['personindex']] ?? null;
            if ($personid === null) {
                continue;
            }
            $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid'           => $runid,
                'personid'        => $personid,
                'status'          => (int) ($attempt['status'] ?? 20),
                'tracejson'       => $attempt['tracejson'] ?? null,
                'runtimems'       => $attempt['runtimems'] ?? null,
                'engineattemptid' => $attempt['engineattemptid'] ?? null,
                'timecreated'     => $now,
                'timemodified'    => $now,
            ]);
        }
    }

    /**
     * Insert the ingested result rows.
     *
     * @param int $runid The new run id.
     * @param array $results The package results.
     * @param int $now Timestamp.
     * @return void
     */
    protected static function ingest_results(int $runid, array $results, int $now): void {
        global $DB;

        foreach ($results as $result) {
            $DB->insert_record('local_catquizlab_result', (object) [
                'runid'       => $runid,
                'metric'      => (string) ($result['metric'] ?? ''),
                'scope'       => (string) ($result['scope'] ?? 'run'),
                'value'       => $result['value'] ?? null,
                'detailjson'  => $result['detailjson'] ?? null,
                'timecreated' => $now,
            ]);
        }
    }

    /**
     * Get or create the experiment that ingested runs are attached to.
     *
     * @return int
     */
    protected static function ingest_experiment(): int {
        global $DB;

        $existing = $DB->get_record('local_catquizlab_experiment', ['name' => 'Hub ingest']);
        if ($existing) {
            return (int) $existing->id;
        }

        $now = time();
        return (int) $DB->insert_record('local_catquizlab_experiment', (object) [
            'name'         => 'Hub ingest',
            'tier'         => 'ingested',
            'configjson'   => null,
            'status'       => 0,
            'usermodified' => 0,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * POST a web-service call to the hub.
     *
     * @param string $huburl The hub wwwroot.
     * @param string $token The web-service token.
     * @param string $function The web-service function name.
     * @param array $params The function parameters.
     * @return array|null The decoded response, or null on failure.
     */
    protected static function post(string $huburl, string $token, string $function, array $params): ?array {
        $url = rtrim($huburl, '/') . '/webservice/rest/server.php';
        $postdata = array_merge([
            'wstoken'            => $token,
            'wsfunction'         => $function,
            'moodlewsrestformat' => 'json',
        ], $params);

        $curl = new \curl();
        $response = $curl->post($url, $postdata);
        if ($curl->get_errno() !== 0) {
            return null;
        }
        $decoded = json_decode((string) $response, true);
        return is_array($decoded) ? $decoded : null;
    }
}
