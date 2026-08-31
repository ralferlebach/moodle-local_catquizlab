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
 * Run manifest builder for local_catquizlab.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Assembles the reproducibility manifest stored with every run.
 *
 * The manifest is the anchor that lets any result be reproduced and cited: it
 * pins down which code produced it (this plugin's and the engine's versions,
 * and the engine's git commit when the checkout exposes one), the environment
 * it ran in (Moodle, PHP, database family) and the seeds and configuration the
 * run was expanded from.
 */
class manifest {
    /**
     * Build the manifest for a run.
     *
     * @param array $config Experiment/run configuration to record (seeds live under the 'seeds' key by convention).
     * @return array The manifest as a nested associative array.
     */
    public static function build(array $config = []): array {
        global $CFG, $DB;

        return [
            'schema'      => 1,
            'generated'   => time(),
            'plugins'     => self::plugin_versions(),
            'engine'      => [
                'available' => environment::engine_available(),
                'githash'   => self::engine_git_hash(),
            ],
            'environment' => [
                'moodleversion' => $CFG->version ?? null,
                'moodlerelease' => $CFG->release ?? null,
                'moodlebranch'  => $CFG->branch ?? null,
                'phpversion'    => PHP_VERSION,
                'dbfamily'      => $DB->get_dbfamily(),
            ],
            'config'      => $config,
        ];
    }

    /**
     * Build the manifest of a run from its normalised definition.
     *
     * A run's manifest has to answer "what exactly was executed here" without
     * anyone re-deriving it from the code: the effective CAT configuration, the
     * model and its engine key, the pool variant with its recipe, the person
     * stratum and severity, and which factors each seed depends on.
     *
     * @param array $definition The normalised experiment definition.
     * @param array $extra Run-level facts: runid, cellkey, replication, seeds.
     * @return array The manifest.
     */
    public static function build_for_run(array $definition, array $extra = []): array {
        $model = (string) ($definition['model'] ?? '2pl');
        $strategy = (string) ($definition['strategy'] ?? 'fastest');
        $variant = (string) ($definition['pool']['variant'] ?? 'ideal');
        $persons = (array) ($definition['persons'] ?? []);

        $config = $extra + [
            'experiment' => [
                'name'          => $definition['name'] ?? null,
                // The key and version identify the study; the name is a label
                // and may change without the study becoming another one.
                'key'           => $definition['experimentkey'] ?? null,
                'version'       => $definition['version'] ?? null,
                'tier'          => $definition['tier'] ?? null,
                'schemaversion' => $definition['schemaversion'] ?? experiment_definition::SCHEMAVERSION,
                'publication'   => experiment_definition::is_publication($definition),
            ],
            'model'      => [
                'key'         => $model,
                'label'       => model_catalog::has($model) ? model_catalog::label($model) : $model,
                'enginekey'   => model_catalog::has($model) ? model_catalog::engine_key($model) : null,
                'polytomous'  => model_catalog::has($model) && model_catalog::is_polytomous($model),
                'parameters'  => $definition['modelparams'] ?? [],
            ],
            'strategy'   => [
                'key'      => $strategy,
                'label'    => strategy_catalog::has($strategy) ? strategy_catalog::label($strategy) : $strategy,
                'engineid' => strategy_catalog::has($strategy) ? strategy_catalog::engine_id($strategy) : null,
            ],
            'pool'       => [
                'variant' => $variant,
                'recipe'  => pool_mutator::apply_recipe_defaults($variant, (array) ($definition['pool']['recipe'] ?? [])),
                'scales'  => $definition['pool']['scales'] ?? [],
            ],
            'persons'    => [
                'stratum'       => $persons['stratum'] ?? null,
                'severity'      => $persons['severity'] ?? 'none',
                'count'         => $persons['count'] ?? null,
                'twins'         => $persons['twins'] ?? [],
                'severityscale' => $persons['severityscale'] ?? [],
            ],
            'cat'        => test_provisioner::effective_parameters($definition),
            // Recorded so a later reader can tell whether two experiments ran
            // on the same pool blueprint or person model, rather than on two
            // that merely look alike.
            'presets'    => [
                'pool'    => [
                    'id'          => $definition['poolpreset'] ?? null,
                    'fingerprint' => $definition['poolpresetfingerprint'] ?? null,
                ],
                'persons' => [
                    'id'          => $definition['personspreset'] ?? null,
                    'fingerprint' => $definition['personspresetfingerprint'] ?? null,
                ],
            ],
            'definition' => $definition,
        ];

        return self::build($config);
    }

    /**
     * Build the manifest and encode it as a JSON string for storage.
     *
     * @param array $config Experiment/run configuration to record.
     * @return string JSON-encoded manifest.
     */
    public static function build_json(array $config = []): string {
        return json_encode(self::build($config), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Collect the installed versions of this plugin and the engine plugins.
     *
     * @return array Map of component name to version string (or null when absent).
     */
    protected static function plugin_versions(): array {
        $components = ['local_catquizlab', 'local_catquiz', 'mod_adaptivequiz', 'local_wunderbyte_table'];
        $versions = [];
        foreach ($components as $component) {
            $version = get_config($component, 'version');
            $versions[$component] = $version === false ? null : $version;
        }
        return $versions;
    }

    /**
     * Best-effort read of the CAT engine's git commit hash.
     *
     * Only works when the engine was checked out with git (a plain zip install
     * has no .git directory); returns null otherwise. The reliable
     * reproducibility anchor is the engine version in plugin_versions(); the
     * git hash is a bonus when available.
     *
     * @return string|null The 40-character commit hash, or null if unavailable.
     */
    protected static function engine_git_hash(): ?string {
        $dir = \core_component::get_component_directory('local_catquiz');
        if ($dir === null) {
            return null;
        }

        $headfile = $dir . '/.git/HEAD';
        if (!is_readable($headfile)) {
            return null;
        }

        $head = trim((string) file_get_contents($headfile));
        if (strpos($head, 'ref:') === 0) {
            $ref = trim(substr($head, 4));
            $reffile = $dir . '/.git/' . $ref;
            if (!is_readable($reffile)) {
                return null;
            }
            $head = trim((string) file_get_contents($reffile));
        }

        return preg_match('/^[0-9a-f]{40}$/', $head) === 1 ? $head : null;
    }
}
