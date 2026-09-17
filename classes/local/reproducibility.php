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
 * Everything somebody would need to believe a result, in one file.
 *
 * A number from a simulation is worth what the reader can check about it. This
 * gathers the things that make that possible: what was asked for, what was
 * actually run, which versions of which plugins ran it, which seeds, and what
 * happened on the way — including the attempts that failed, because an average
 * over the attempts that happened to succeed is a different quantity from an
 * average over the design.
 *
 * It is a snapshot, not a re-run: it says what was done, so a reader can decide
 * whether to trust it and a future maintainer can reconstruct it.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reproducibility {
    /**
     * How complete the data behind an experiment's results actually is.
     *
     * Shown above the analyses rather than beside them: a mean over 40 of 120
     * planned attempts is not a worse version of the same number, it is a
     * different number, and the reader has to know before reading it.
     *
     * @param int $experimentid The experiment.
     * @return array{complete: bool, runs: array, attempts: array, summary: string}
     */
    public static function completeness(int $experimentid): array {
        global $DB;

        $component = 'local_catquizlab';

        $runs = $DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC');
        $bystatus = ['finished' => 0, 'running' => 0, 'failed' => 0, 'other' => 0];
        $unfinished = [];
        $inflight = [registry::STATUS_READY, registry::STATUS_RUNNING, registry::STATUS_AGGREGATING];

        foreach ($runs as $run) {
            $status = (int) $run->status;
            if ($status === registry::STATUS_FINISHED) {
                $bystatus['finished']++;
                continue;
            }

            if ($status === registry::STATUS_FAILED || $status === registry::STATUS_CANCELLED) {
                $bystatus['failed']++;
            } else if (in_array($status, $inflight, true)) {
                $bystatus['running']++;
            } else {
                $bystatus['other']++;
            }

            $unfinished[] = ['id' => (int) $run->id, 'cellkey' => (string) $run->cellkey];
        }

        $attempts = ['planned' => 0, 'collected' => 0, 'failed' => 0];
        if ($runs !== []) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($runs), SQL_PARAMS_NAMED, 'r');
            $attempts['planned'] = $DB->count_records_select('local_catquizlab_attempt', 'runid ' . $insql, $params);
            $attempts['collected'] = $DB->count_records_select(
                'local_catquizlab_attempt',
                'runid ' . $insql . ' AND status = :collected',
                $params + ['collected' => attempt_scheduler::STATUS_COLLECTED]
            );
            $attempts['failed'] = $DB->count_records_select(
                'local_catquizlab_attempt',
                'runid ' . $insql . ' AND status = :failed',
                $params + ['failed' => attempt_scheduler::STATUS_FAILED]
            );
        }

        $complete = $unfinished === [] && $attempts['failed'] === 0
            && $attempts['planned'] === $attempts['collected'];

        return [
            'complete'   => $complete,
            'runs'       => $bystatus + ['total' => count($runs)],
            'attempts'   => $attempts,
            'unfinished' => $unfinished,
            'hasunfinished' => $unfinished !== [],
            'summary'    => $complete
                ? get_string('repro:complete', $component, (object) [
                    'runs'     => count($runs),
                    'attempts' => $attempts['collected'],
                ])
                : get_string('repro:incomplete', $component, (object) [
                    'collected' => $attempts['collected'],
                    'planned'   => $attempts['planned'],
                    'unfinished' => count($unfinished),
                ]),
            'progressurl' => (new \moodle_url('/local/catquizlab/runs.php', [
                'experimentid' => $experimentid,
            ]))->out(false),
        ];
    }

    /**
     * The package itself.
     *
     * @param int $experimentid The experiment.
     * @param bool $includelog Whether to carry the execution log.
     * @return array
     */
    public static function package(int $experimentid, bool $includelog = true): array {
        global $DB, $CFG;

        $experiment = $DB->get_record('local_catquizlab_experiment', ['id' => $experimentid], '*', MUST_EXIST);
        $definition = json_decode((string) $experiment->configjson, true) ?: [];

        $runs = [];
        foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC') as $run) {
            $manifest = json_decode((string) $run->manifestjson, true) ?: [];

            $entry = [
                'id'          => (int) $run->id,
                'cellkey'     => (string) $run->cellkey,
                'status'      => run_registry::status_label((int) $run->status),
                // The seeds are the whole claim to reproducibility: without them
                // the design is a description and not an instruction.
                'seed'        => (int) $run->seed,
                'masterseed'  => (int) $run->masterseed,
                'replication' => (int) $run->replication,
                'manifest'    => $manifest,
            ];

            if ($includelog) {
                $entry['log'] = run_log::entries((int) $run->id);
            }

            $runs[] = $entry;
        }

        return [
            'generated'   => date('c'),
            'experiment'  => [
                'id'         => (int) $experiment->id,
                'name'       => (string) $experiment->name,
                'definition' => $definition,
                // What the plugin made of it, which is what actually ran. From
                // the stored text rather than the decoded array: a definition
                // that does not decode becomes an empty array on the way, and
                // an empty array normalises to defaults that never ran.
                'normalised' => self::normalised_definition((string) $experiment->configjson),
            ],
            // Which code produced this. A result from a version nobody can name
            // is a result nobody can reproduce.
            'versions'    => self::versions(),
            'moodle'      => ['release' => $CFG->release, 'version' => $CFG->version],
            'runs'        => $runs,
            'completeness' => self::completeness($experimentid),
        ];
    }

    /**
     * The definition as the plugin understands it.
     *
     * @param string $configjson The stored definition, as stored.
     * @return array
     */
    protected static function normalised_definition(string $configjson): array {
        try {
            return experiment_definition::from_json($configjson)->get_normalised();
        } catch (\Throwable $e) {
            // A definition the plugin can no longer read is worth saying so
            // about: it means this package cannot be replayed as it stands.
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * The versions of everything that took part.
     *
     * @return array<string, string>
     */
    public static function versions(): array {
        $components = [
            'local_catquizlab',
            'local_catquiz',
            'mod_adaptivequiz',
            'adaptivequizcatmodel_catquiz',
        ];

        $versions = [];
        foreach ($components as $component) {
            $version = get_config($component, 'version');
            $versions[$component] = $version === false ? 'absent' : (string) $version;
        }

        return $versions;
    }
}
