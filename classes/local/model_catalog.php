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
 * The single source of truth for IRT models.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Maps a publication model name to the engine model key and to everything that
 * follows from the model choice: which item parameters the ground truth must
 * carry, which response family the oracle uses, and what shape the generated
 * question has.
 *
 * The article speaks of 1PL, 2PL and 3PL; the engine's catmodel subplugins are
 * called rasch, raschbirnbaum and mixedraschbirnbaum. Both names are correct in
 * their own context, and mixing them up is how a "2PL" run ends up materialised
 * with a=1 throughout. So the public name is what a definition is written in,
 * the engine key is what reaches local_catquiz_itemparams, and this table is
 * the only place the two meet.
 *
 * The legacy engine keys stay accepted as aliases, because existing definitions
 * were written with them; they are normalised on the way in rather than being
 * carried through the pipeline.
 */
class model_catalog {
    /** @var string Dichotomous response family. */
    public const FAMILY_DICHOTOMOUS = 'dichotomous';

    /** @var string Polytomous response family. */
    public const FAMILY_POLYTOMOUS = 'polytomous';

    /**
     * Every model: engine key, family, required item parameters, oracle family and label.
     *
     * @var array<string, array>
     */
    protected const CATALOG = [
        '1pl'  => [
            'enginekey' => 'rasch',
            'family'    => self::FAMILY_DICHOTOMOUS,
            'requires'  => ['difficulty'],
            'oracle'    => '1pl',
            'label'     => '1PL (Rasch)',
        ],
        '2pl'  => [
            'enginekey' => 'raschbirnbaum',
            'family'    => self::FAMILY_DICHOTOMOUS,
            'requires'  => ['difficulty', 'discrimination'],
            'oracle'    => '2pl',
            'label'     => '2PL',
        ],
        '3pl'  => [
            'enginekey' => 'mixedraschbirnbaum',
            'family'    => self::FAMILY_DICHOTOMOUS,
            'requires'  => ['difficulty', 'discrimination', 'guessing'],
            'oracle'    => '3pl',
            'label'     => '3PL',
        ],
        'pcm'  => [
            'enginekey' => 'pcm',
            'family'    => self::FAMILY_POLYTOMOUS,
            'requires'  => ['difficulty', 'steps'],
            'oracle'    => 'gpcm',
            'label'     => 'PCM (partial credit)',
        ],
        'gpcm' => [
            'enginekey' => 'pcmgeneralized',
            'family'    => self::FAMILY_POLYTOMOUS,
            'requires'  => ['difficulty', 'discrimination', 'steps'],
            'oracle'    => 'gpcm',
            'label'     => 'GPCM (generalised partial credit)',
        ],
        'grm'  => [
            'enginekey' => 'grm',
            'family'    => self::FAMILY_POLYTOMOUS,
            'requires'  => ['difficulty', 'steps'],
            'oracle'    => 'grm',
            'label'     => 'GRM (graded response)',
        ],
        'ggrm' => [
            'enginekey' => 'grmgeneralized',
            'family'    => self::FAMILY_POLYTOMOUS,
            'requires'  => ['difficulty', 'discrimination', 'steps'],
            'oracle'    => 'grm',
            'label'     => 'GGRM (generalised graded response)',
        ],
    ];

    /**
     * Legacy and engine-side names accepted on input, mapped to the public key.
     *
     * @var array<string, string>
     */
    protected const ALIASES = [
        'rasch'              => '1pl',
        'raschbirnbaum'      => '2pl',
        'mixedraschbirnbaum' => '3pl',
        'pcmgeneralized'     => 'gpcm',
        'grmgeneralized'     => 'ggrm',
        '1PL'                => '1pl',
        '2PL'                => '2pl',
        '3PL'                => '3pl',
        'GPCM'               => 'gpcm',
        'GRM'                => 'grm',
    ];

    /**
     * All public model keys.
     *
     * @return string[]
     */
    public static function keys(): array {
        return array_keys(self::CATALOG);
    }

    /**
     * All names accepted in a definition, public keys plus legacy aliases.
     *
     * @return string[]
     */
    public static function accepted(): array {
        return array_values(array_unique(array_merge(self::keys(), array_keys(self::ALIASES))));
    }

    /**
     * Normalise a model name to its public key.
     *
     * @param string $name A public key or a legacy alias.
     * @return string|null The public key, or null when the name is unknown.
     */
    public static function normalise(string $name): ?string {
        $name = trim($name);
        if (isset(self::CATALOG[$name])) {
            return $name;
        }
        $lower = strtolower($name);
        if (isset(self::CATALOG[$lower])) {
            return $lower;
        }
        if (isset(self::ALIASES[$name])) {
            return self::ALIASES[$name];
        }
        if (isset(self::ALIASES[$lower])) {
            return self::ALIASES[$lower];
        }
        return null;
    }

    /**
     * Whether a name resolves to a known model.
     *
     * @param string $name A public key or a legacy alias.
     * @return bool
     */
    public static function has(string $name): bool {
        return self::normalise($name) !== null;
    }

    /**
     * The full descriptor of a model.
     *
     * @param string $name A public key or a legacy alias.
     * @return array{key: string, enginekey: string, family: string, requires: string[], oracle: string, label: string}
     * @throws \coding_exception If the name is unknown.
     */
    public static function descriptor(string $name): array {
        $key = self::normalise($name);
        if ($key === null) {
            throw new \coding_exception('Unknown IRT model: ' . $name);
        }
        return ['key' => $key] + self::CATALOG[$key];
    }

    /**
     * The engine's catmodel key for a model.
     *
     * @param string $name A public key or a legacy alias.
     * @return string
     * @throws \coding_exception If the name is unknown.
     */
    public static function engine_key(string $name): string {
        return self::descriptor($name)['enginekey'];
    }

    /**
     * Whether the model is polytomous.
     *
     * @param string $name A public key or a legacy alias.
     * @return bool
     * @throws \coding_exception If the name is unknown.
     */
    public static function is_polytomous(string $name): bool {
        return self::descriptor($name)['family'] === self::FAMILY_POLYTOMOUS;
    }

    /**
     * The item parameters this model requires as ground truth.
     *
     * @param string $name A public key or a legacy alias.
     * @return string[]
     * @throws \coding_exception If the name is unknown.
     */
    public static function requires(string $name): array {
        return self::descriptor($name)['requires'];
    }

    /**
     * The response family the oracle answers with for this model.
     *
     * @param string $name A public key or a legacy alias.
     * @return string One of 1pl, 2pl, 3pl, gpcm, grm.
     * @throws \coding_exception If the name is unknown.
     */
    public static function oracle_family(string $name): string {
        return self::descriptor($name)['oracle'];
    }

    /**
     * The display label of a model.
     *
     * @param string $name A public key or a legacy alias.
     * @return string
     * @throws \coding_exception If the name is unknown.
     */
    public static function label(string $name): string {
        return self::descriptor($name)['label'];
    }

    /**
     * Public key => label, for form select elements.
     *
     * @return array<string, string>
     */
    public static function menu(): array {
        $menu = [];
        foreach (self::CATALOG as $key => $entry) {
            $menu[$key] = $entry['label'];
        }
        return $menu;
    }

    /**
     * Whether the model needs an explicit discrimination configuration to be
     * more than a degenerate control condition.
     *
     * @param string $name A public key or a legacy alias.
     * @return bool
     * @throws \coding_exception If the name is unknown.
     */
    public static function needs_discrimination(string $name): bool {
        return in_array('discrimination', self::requires($name), true);
    }

    /**
     * Whether the model needs an explicit guessing configuration.
     *
     * @param string $name A public key or a legacy alias.
     * @return bool
     * @throws \coding_exception If the name is unknown.
     */
    public static function needs_guessing(string $name): bool {
        return in_array('guessing', self::requires($name), true);
    }
}
