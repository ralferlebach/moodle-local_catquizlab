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
 * Axis profiles: saved axis settings to put plots on one scale.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Saved axis settings, per user, and the shared scale (#108).
 *
 * A setting is: mode (auto, manual, shared), whether to force symmetry around
 * 0, x and y ranges, and tick spacing. "Shared" means the one scale saved as
 * shared: every plot set to it uses the same axes, so plots of different
 * strategies, runs or experiments can be compared by eye.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class axis_profiles {
    /** @var string Axes by the plot conventions. */
    public const AUTO = 'auto';

    /** @var string Axes set by hand for this plot. */
    public const MANUAL = 'manual';

    /** @var string The shared scale. */
    public const SHARED = 'shared';

    /** @var string The user preference holding the profiles. */
    protected const PREFERENCE = 'local_catquizlab_axisprofiles';

    /** @var string The name under which the shared scale is kept. */
    public const SHARED_NAME = '__shared';

    /** @var string[] The fields of a setting. */
    public const FIELDS = ['mode', 'sym', 'robust', 'xmin', 'xmax', 'ymin', 'ymax', 'xtick', 'ytick'];

    /**
     * The built-in profiles named in the issue.
     *
     * @return array<string, array> Name => settings.
     */
    public static function builtin(): array {
        return [
            'Ability symmetric [-4, 4]' => ['mode' => self::MANUAL, 'sym' => 1,
                'xmin' => -4, 'xmax' => 4, 'ymin' => -4, 'ymax' => 4, 'xtick' => 1, 'ytick' => 1],
            'Error symmetric [-2, 2]' => ['mode' => self::MANUAL, 'sym' => 1,
                'ymin' => -2, 'ymax' => 2, 'ytick' => 0.5],
            'Integer test length' => ['mode' => self::MANUAL, 'sym' => 0, 'xmin' => 0, 'xtick' => 5],
            'Shared comparison axes' => ['mode' => self::SHARED],
        ];
    }

    /**
     * The user's own profiles.
     *
     * @return array<string, array> Name => settings.
     */
    public static function mine(): array {
        $stored = json_decode((string) get_user_preferences(self::PREFERENCE, '{}'), true);

        return is_array($stored) ? $stored : [];
    }

    /**
     * The profiles saved for an experiment, for everyone working on it (#108).
     *
     * @param int $experimentid The experiment.
     * @return array<string, array>
     */
    public static function of_experiment(int $experimentid): array {
        if ($experimentid <= 0) {
            return [];
        }
        $stored = json_decode((string) get_config('local_catquizlab', 'axisprofiles_exp' . $experimentid), true);

        return is_array($stored) ? $stored : [];
    }

    /**
     * Every profile the user can load: built in, the experiment's, their own.
     *
     * @param int $experimentid The experiment in view, 0 for none.
     * @return array<string, array>
     */
    public static function all(int $experimentid = 0): array {
        $mine = self::mine();
        unset($mine[self::SHARED_NAME]);

        return self::builtin() + self::of_experiment($experimentid) + $mine;
    }

    /**
     * Save a profile for an experiment.
     *
     * @param int $experimentid The experiment.
     * @param string $name The name.
     * @param array $settings The settings.
     * @return void
     */
    public static function save_for_experiment(int $experimentid, string $name, array $settings): void {
        $name = trim(\core_text::substr(clean_param($name, PARAM_TEXT), 0, 60));
        if ($experimentid <= 0 || $name === '' || isset(self::builtin()[$name])) {
            return;
        }
        $profiles = self::of_experiment($experimentid);
        $profiles[$name] = self::clean($settings);
        set_config('axisprofiles_exp' . $experimentid, json_encode($profiles), 'local_catquizlab');
    }

    /**
     * Save a profile, or the shared scale.
     *
     * @param string $name The name; SHARED_NAME for the shared scale.
     * @param array $settings The settings.
     * @return void
     */
    public static function save(string $name, array $settings): void {
        $name = trim(\core_text::substr(clean_param($name, PARAM_TEXT), 0, 60));
        if ($name === '' || isset(self::builtin()[$name])) {
            return;
        }
        $mine = self::mine();
        $mine[$name] = self::clean($settings);
        // The shared scale itself cannot point at the shared scale.
        if ($name === self::SHARED_NAME && ($mine[$name]['mode'] ?? '') === self::SHARED) {
            $mine[$name]['mode'] = self::MANUAL;
        }
        set_user_preference(self::PREFERENCE, json_encode($mine));
    }

    /**
     * The settings that apply to a plot: from a profile, the shared scale, or the request.
     *
     * @param array $request What the plot's form sent.
     * @param int $experimentid The experiment in view, for its profiles.
     * @return array{settings: array, profile: string}
     */
    public static function resolve(array $request, int $experimentid = 0): array {
        $profile = (string) ($request['profile'] ?? '');
        $settings = self::clean($request);
        $profiles = self::all($experimentid);
        if ($profile !== '' && isset($profiles[$profile])) {
            $settings = self::clean($profiles[$profile]);
        }
        if ($settings['mode'] === self::SHARED) {
            $shared = self::mine()[self::SHARED_NAME] ?? null;
            $settings = $shared ? self::clean($shared) + ['shared' => true] : ['mode' => self::AUTO, 'sym' => 0];
            $settings['shared'] = $shared !== null;
            $settings['mode'] = $shared ? self::SHARED : self::AUTO;
        }

        return ['settings' => $settings, 'profile' => $profile];
    }

    /**
     * Only the known fields, as numbers where they are numbers.
     *
     * @param array $settings Raw settings.
     * @return array
     */
    public static function clean(array $settings): array {
        $mode = (string) ($settings['mode'] ?? self::AUTO);
        $clean = [
            'mode' => in_array($mode, [self::AUTO, self::MANUAL, self::SHARED], true) ? $mode : self::AUTO,
            'sym'  => !empty($settings['sym']) ? 1 : 0,
            'robust' => !empty($settings['robust']) ? 1 : 0,
        ];
        foreach (['xmin', 'xmax', 'ymin', 'ymax', 'xtick', 'ytick'] as $field) {
            $value = $settings[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value) === '' ? null : unformat_float($value);
            }
            $clean[$field] = is_numeric($value) ? (float) $value : null;
        }

        return $clean;
    }
}
