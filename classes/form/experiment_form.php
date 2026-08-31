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
 * The experiment editor form.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\form;

use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\model_catalog;
use local_catquizlab\local\pool_mutator;
use local_catquizlab\local\preset_library;
use local_catquizlab\local\strategy_catalog;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * Edits an experiment definition.
 *
 * The form is a view onto {@see experiment_definition} and nothing more. It
 * offers the fields the definition has, converts them to and from the
 * definition array, and leaves every judgement about what is valid to the
 * definition itself — the same check the CLI and the API run. A form that did
 * its own validation would eventually disagree with them, and then a sweep
 * started from the UI would not be the sweep the CLI would have started.
 *
 * Labels are the publication ones, taken from the catalogues, with the internal
 * key shown beside them so a run remains debuggable.
 */
class experiment_form extends \moodleform {
    /**
     * Build the form.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;
        $component = 'local_catquizlab';

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        // Basics.
        $mform->addElement('header', 'basics', get_string('form:basics', $component));
        $mform->setExpanded('basics', true);

        $mform->addElement('text', 'name', get_string('form:name', $component), ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $mform->addElement('textarea', 'description', get_string('form:description', $component), [
            'rows' => 3, 'cols' => 60,
        ]);
        $mform->setType('description', PARAM_TEXT);
        $mform->addHelpButton('description', 'form:description', $component);

        $mform->addElement('text', 'experimentkey', get_string('form:experimentkey', $component), ['size' => 40]);
        $mform->setType('experimentkey', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('experimentkey', 'form:experimentkey', $component);

        $mform->addElement('text', 'version', get_string('form:version', $component), ['size' => 12]);
        $mform->setType('version', PARAM_TEXT);
        $mform->setDefault('version', '1.0.0');
        $mform->addHelpButton('version', 'form:version', $component);

        $mform->addElement('text', 'tags', get_string('form:tags', $component), ['size' => 40]);
        $mform->setType('tags', PARAM_TEXT);
        $mform->addHelpButton('tags', 'form:tags', $component);

        $mform->addElement('advcheckbox', 'enabled', get_string('form:enabled', $component));
        $mform->setDefault('enabled', 1);
        $mform->addHelpButton('enabled', 'form:enabled', $component);

        $mform->addElement('select', 'tier', get_string('form:tier', $component), self::tier_menu());
        $mform->addHelpButton('tier', 'form:tier', $component);

        $mform->addElement('text', 'seed', get_string('form:seed', $component), ['size' => 12]);
        $mform->setType('seed', PARAM_INT);
        $mform->setDefault('seed', 42);
        $mform->addHelpButton('seed', 'form:seed', $component);

        $mform->addElement('text', 'replications', get_string('form:replications', $component), ['size' => 8]);
        $mform->setType('replications', PARAM_INT);
        $mform->setDefault('replications', 1);

        // Model.
        $mform->addElement('header', 'modelheader', get_string('form:model', $component));
        $mform->setExpanded('modelheader', true);

        $mform->addElement('select', 'model', get_string('form:model', $component), self::model_menu());
        $mform->setDefault('model', '2pl');
        $mform->addHelpButton('model', 'form:model', $component);

        $mform->addElement('select', 'discriminationdist', get_string('form:discriminationdist', $component), [
            'constant'  => get_string('dist:constant', $component),
            'lognormal' => get_string('dist:lognormal', $component),
            'uniform'   => get_string('dist:uniform', $component),
        ]);
        // Log-normal rather than constant: a model called 2PL should describe
        // a 2PL by default, and a Rasch parametrisation should be something an
        // author chooses rather than something they fail to notice.
        $mform->setDefault('discriminationdist', 'lognormal');
        $mform->addHelpButton('discriminationdist', 'form:discriminationdist', $component);
        $mform->hideIf('discriminationdist', 'model', 'eq', '1pl');

        $mform->addElement('advcheckbox', 'allowdegenerate', get_string('form:allowdegenerate', $component));
        $mform->addHelpButton('allowdegenerate', 'form:allowdegenerate', $component);
        $mform->hideIf('allowdegenerate', 'discriminationdist', 'noteq', 'constant');
        $mform->hideIf('allowdegenerate', 'model', 'eq', '1pl');

        $mform->addElement('text', 'discriminationa', get_string('form:paramone', $component), ['size' => 10]);
        $mform->setType('discriminationa', PARAM_FLOAT);
        $mform->setDefault('discriminationa', 1.0);
        $mform->hideIf('discriminationa', 'model', 'eq', '1pl');

        $mform->addElement('text', 'discriminationb', get_string('form:paramtwo', $component), ['size' => 10]);
        $mform->setType('discriminationb', PARAM_FLOAT);
        $mform->setDefault('discriminationb', 0.3);
        $mform->hideIf('discriminationb', 'discriminationdist', 'eq', 'constant');

        $mform->addElement('text', 'guessingmin', get_string('form:guessingmin', $component), ['size' => 10]);
        $mform->setType('guessingmin', PARAM_FLOAT);
        $mform->setDefault('guessingmin', 0.1);
        $mform->hideIf('guessingmin', 'model', 'noteq', '3pl');

        $mform->addElement('text', 'guessingmax', get_string('form:guessingmax', $component), ['size' => 10]);
        $mform->setType('guessingmax', PARAM_FLOAT);
        $mform->setDefault('guessingmax', 0.25);
        $mform->hideIf('guessingmax', 'model', 'noteq', '3pl');

        $mform->addElement('text', 'categories', get_string('form:categories', $component), ['size' => 6]);
        $mform->setType('categories', PARAM_INT);
        $mform->setDefault('categories', 4);
        $mform->addHelpButton('categories', 'form:categories', $component);

        // Item pool.
        $mform->addElement('header', 'poolheader', get_string('form:pool', $component));

        $poolpresets = preset_library::menu(preset_library::KIND_POOL);
        if ($poolpresets !== []) {
            $mform->addElement(
                'select',
                'poolpreset',
                get_string('form:poolpreset', $component),
                [0 => get_string('form:nopreset', $component)] + $poolpresets
            );
            $mform->setType('poolpreset', PARAM_INT);
            $mform->addHelpButton('poolpreset', 'form:poolpreset', $component);
        }

        $mform->addElement('text', 'poolcategories', get_string('form:domains', $component), ['size' => 6]);
        $mform->setType('poolcategories', PARAM_INT);
        $mform->setDefault('poolcategories', 10);

        $mform->addElement('text', 'poolsubcategories', get_string('form:subscales', $component), ['size' => 6]);
        $mform->setType('poolsubcategories', PARAM_INT);
        $mform->setDefault('poolsubcategories', 10);

        $mform->addElement('text', 'poolitems', get_string('form:itemspersubscale', $component), ['size' => 6]);
        $mform->setType('poolitems', PARAM_INT);
        $mform->setDefault('poolitems', 25);

        $mform->addElement('select', 'variant', get_string('form:variant', $component), self::variant_menu());
        $mform->setDefault('variant', 'ideal');
        $mform->addHelpButton('variant', 'form:variant', $component);

        // Variant parameters appear only for the variant they belong to, so the
        // form never asks for a shift on a pool that is not shifted.
        $mform->addElement('text', 'recipeshift', get_string('form:shift', $component), ['size' => 8]);
        $mform->setType('recipeshift', PARAM_FLOAT);
        $mform->setDefault('recipeshift', pool_mutator::DEFAULT_SHIFT);
        $mform->hideIf('recipeshift', 'variant', 'noteq', 'shifted');

        $mform->addElement('text', 'recipefactor', get_string('form:stretch', $component), ['size' => 8]);
        $mform->setType('recipefactor', PARAM_FLOAT);
        $mform->setDefault('recipefactor', pool_mutator::DEFAULT_STRETCH);
        $mform->hideIf('recipefactor', 'variant', 'noteq', 'stretched');

        $mform->addElement('text', 'recipefraction', get_string('form:fraction', $component), ['size' => 8]);
        $mform->setType('recipefraction', PARAM_FLOAT);
        $mform->setDefault('recipefraction', 0.1);
        $mform->addHelpButton('recipefraction', 'form:fraction', $component);

        $mform->addElement('text', 'recipesd', get_string('form:errorsd', $component), ['size' => 8]);
        $mform->setType('recipesd', PARAM_FLOAT);
        $mform->setDefault('recipesd', 0.5);
        $mform->hideIf('recipesd', 'variant', 'noteq', 'calibrationerror');

        $mform->addElement('text', 'recipegapmin', get_string('form:gapmin', $component), ['size' => 8]);
        $mform->setType('recipegapmin', PARAM_FLOAT);
        $mform->setDefault('recipegapmin', -0.5);
        $mform->hideIf('recipegapmin', 'variant', 'noteq', 'gappy');

        $mform->addElement('text', 'recipegapmax', get_string('form:gapmax', $component), ['size' => 8]);
        $mform->setType('recipegapmax', PARAM_FLOAT);
        $mform->setDefault('recipegapmax', 0.5);
        $mform->hideIf('recipegapmax', 'variant', 'noteq', 'gappy');

        // Persons.
        $mform->addElement('header', 'personsheader', get_string('form:persons', $component));

        $personpresets = preset_library::menu(preset_library::KIND_PERSONS);
        if ($personpresets !== []) {
            $mform->addElement(
                'select',
                'personspreset',
                get_string('form:personspreset', $component),
                [0 => get_string('form:nopreset', $component)] + $personpresets
            );
            $mform->setType('personspreset', PARAM_INT);
            $mform->addHelpButton('personspreset', 'form:personspreset', $component);
        }

        $mform->addElement('select', 'stratum', get_string('form:stratum', $component), self::stratum_menu());
        $mform->setDefault('stratum', 'conforming');
        $mform->addHelpButton('stratum', 'form:stratum', $component);

        $mform->addElement('select', 'severity', get_string('form:severity', $component), self::severity_menu());
        $mform->setDefault('severity', 'none');
        $mform->hideIf('severity', 'stratum', 'eq', 'conforming');

        $mform->addElement('text', 'personcount', get_string('form:personcount', $component), ['size' => 8]);
        $mform->setType('personcount', PARAM_INT);
        $mform->setDefault('personcount', 50);

        $mform->addElement('advcheckbox', 'twins', get_string('form:twins', $component));
        $mform->setDefault('twins', 1);
        $mform->addHelpButton('twins', 'form:twins', $component);

        // Strategy and budgets.
        $mform->addElement('header', 'catheader', get_string('form:cat', $component));
        $mform->setExpanded('catheader', true);

        $mform->addElement('select', 'strategy', get_string('form:strategy', $component), self::strategy_menu());
        $mform->setDefault('strategy', 'fastest');
        $mform->addHelpButton('strategy', 'form:strategy', $component);

        $mform->addElement('text', 'globalmin', get_string('form:globalmin', $component), ['size' => 8]);
        $mform->setType('globalmin', PARAM_INT);
        $mform->setDefault('globalmin', 20);

        $mform->addElement('text', 'globalmax', get_string('form:globalmax', $component), ['size' => 8]);
        $mform->setType('globalmax', PARAM_INT);
        $mform->setDefault('globalmax', 25);

        $mform->addElement('text', 'subscalemin', get_string('form:subscalemin', $component), ['size' => 8]);
        $mform->setType('subscalemin', PARAM_INT);
        $mform->setDefault('subscalemin', 3);

        $mform->addElement('text', 'subscalemax', get_string('form:subscalemax', $component), ['size' => 8]);
        $mform->setType('subscalemax', PARAM_INT);
        $mform->setDefault('subscalemax', 5);

        $mform->addElement('text', 'semin', get_string('form:semin', $component), ['size' => 8]);
        $mform->setType('semin', PARAM_FLOAT);
        $mform->setDefault('semin', 0.35);
        $mform->addHelpButton('semin', 'form:semin', $component);

        $mform->addElement('text', 'semax', get_string('form:semax', $component), ['size' => 8]);
        $mform->setType('semax', PARAM_FLOAT);
        $mform->setDefault('semax', 0.75);

        // Sweep.
        $mform->addElement('header', 'sweepheader', get_string('form:sweep', $component));

        $strategies = $mform->addElement(
            'select',
            'sweepstrategies',
            get_string('form:sweepstrategies', $component),
            self::strategy_menu()
        );
        $strategies->setMultiple(true);
        $mform->addHelpButton('sweepstrategies', 'form:sweepstrategies', $component);

        $variants = $mform->addElement(
            'select',
            'sweepvariants',
            get_string('form:sweepvariants', $component),
            self::variant_menu()
        );
        $variants->setMultiple(true);

        $strata = $mform->addElement(
            'select',
            'sweepstrata',
            get_string('form:sweepstrata', $component),
            self::stratum_menu()
        );
        $strata->setMultiple(true);

        $severities = $mform->addElement(
            'select',
            'sweepseverities',
            get_string('form:sweepseverities', $component),
            self::severity_menu()
        );
        $severities->setMultiple(true);

        $models = $mform->addElement(
            'select',
            'sweepmodels',
            get_string('form:sweepmodels', $component),
            self::model_menu()
        );
        $models->setMultiple(true);

        // Budgets and SE windows are pairs: "10 to 15 items" is one condition,
        // and varying the ends independently would produce cells like 40/15.
        // Each line is therefore one level.
        $mform->addElement('textarea', 'sweepglobalbudgets', get_string('form:sweepglobalbudgets', $component), [
            'rows' => 4, 'cols' => 30,
        ]);
        $mform->setType('sweepglobalbudgets', PARAM_TEXT);
        $mform->addHelpButton('sweepglobalbudgets', 'form:sweepglobalbudgets', $component);

        $mform->addElement('textarea', 'sweepsubscalebudgets', get_string('form:sweepsubscalebudgets', $component), [
            'rows' => 4, 'cols' => 30,
        ]);
        $mform->setType('sweepsubscalebudgets', PARAM_TEXT);
        $mform->addHelpButton('sweepsubscalebudgets', 'form:sweepsubscalebudgets', $component);

        $mform->addElement('textarea', 'sweepse', get_string('form:sweepse', $component), [
            'rows' => 4, 'cols' => 30,
        ]);
        $mform->setType('sweepse', PARAM_TEXT);
        $mform->addHelpButton('sweepse', 'form:sweepse', $component);

        $mform->addElement('textarea', 'sweepstrengths', get_string('form:sweepstrengths', $component), [
            'rows' => 4, 'cols' => 30,
        ]);
        $mform->setType('sweepstrengths', PARAM_TEXT);
        $mform->addHelpButton('sweepstrengths', 'form:sweepstrengths', $component);

        $this->add_action_buttons(true, get_string('form:save', $component));
    }

    /**
     * Server-side validation, delegated to the experiment definition.
     *
     * @param array $data The submitted data.
     * @param array $files The submitted files.
     * @return array Field name => error message.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $definition = self::to_definition((array) $data);
        $result = (new experiment_definition($definition))->validate();

        // Map each message back onto the field it belongs to, so the author
        // sees the problem where they can fix it rather than in a list at the
        // top of a long form.
        foreach ($result['errors'] as $message) {
            $field = self::field_for($message);
            $errors[$field] = isset($errors[$field]) ? $errors[$field] . ' ' . $message : $message;
        }

        return $errors;
    }

    /**
     * Guess which form field a validation message belongs to.
     *
     * @param string $message The validation message.
     * @return string A form field name; 'name' is the catch-all.
     */
    protected static function field_for(string $message): string {
        $map = [
            'budgets.global.minitems'   => 'globalmin',
            'budgets.global.maxitems'   => 'globalmax',
            'budgets.subscale.minitems' => 'subscalemin',
            'budgets.subscale.maxitems' => 'subscalemax',
            'budgets.se'                => 'semin',
            'budgets.global'            => 'globalmin',
            'budgets.subscale'          => 'subscalemin',
            'modelparams.discrimination' => 'discriminationa',
            'modelparams.guessing'      => 'guessingmin',
            'modelparams.categories'    => 'categories',
            'pool.recipe'               => 'recipefraction',
            'pool.scales'               => 'poolcategories',
            'pool.variant'              => 'variant',
            'persons.severity'          => 'severity',
            'persons.stratum'           => 'stratum',
            'persons.count'             => 'personcount',
            'strategy'                  => 'strategy',
            'model'                     => 'model',
            'replications'              => 'replications',
            'seed'                      => 'seed',
        ];

        foreach ($map as $needle => $field) {
            if (strpos($message, $needle) !== false) {
                return $field;
            }
        }

        return 'name';
    }

    /**
     * Convert submitted form data into an experiment definition.
     *
     * @param array $data The submitted data.
     * @return array The definition, ready for validation or saving.
     */
    public static function to_definition(array $data): array {
        $model = (string) ($data['model'] ?? '2pl');
        $variant = (string) ($data['variant'] ?? 'ideal');

        $definition = [
            'schema'        => experiment_definition::SCHEMA,
            'schemaversion' => experiment_definition::SCHEMAVERSION,
            'name'          => (string) ($data['name'] ?? ''),
            'description'   => (string) ($data['description'] ?? ''),
            'experimentkey' => (string) ($data['experimentkey'] ?? ''),
            'version'       => (string) ($data['version'] ?? '1.0.0'),
            'tags'          => self::split_tags((string) ($data['tags'] ?? '')),
            'enabled'       => !empty($data['enabled']),
            'tier'          => (string) ($data['tier'] ?? 'baseline'),
            'model'         => $model,
            'modelparams'   => self::model_params($data, $model),
            'strategy'      => (string) ($data['strategy'] ?? 'fastest'),
            'replications'  => (int) ($data['replications'] ?? 1),
            'seed'          => (int) ($data['seed'] ?? 42),
            'pool'          => [
                'variant'          => $variant,
                'recipe'           => self::recipe($data, $variant),
                'scales'           => [
                    'categories'       => (int) ($data['poolcategories'] ?? 10),
                    'subcategories'    => (int) ($data['poolsubcategories'] ?? 10),
                    'itemspersubscale' => (int) ($data['poolitems'] ?? 25),
                ],
                'questiontemplate' => [
                    'type'   => 'multichoice',
                    'blanks' => ['stem' => '{category}/{subscale} item {index}'],
                ],
                'itemnaming'       => ['pattern' => 'Q-{category}-{subscale}-{index:03d}'],
            ],
            'persons'       => [
                'stratum'  => (string) ($data['stratum'] ?? 'conforming'),
                'severity' => (string) ($data['severity'] ?? 'none'),
                'count'    => (int) ($data['personcount'] ?? 50),
                'twins'    => ['enabled' => !empty($data['twins'])],
                'naming'   => ['pattern' => 'P-{stratum}-{index:04d}'],
            ],
            'budgets'       => [
                'global'   => [
                    'minitems' => (int) ($data['globalmin'] ?? 20),
                    'maxitems' => (int) ($data['globalmax'] ?? 25),
                ],
                'subscale' => [
                    'minitems' => (int) ($data['subscalemin'] ?? 3),
                    'maxitems' => (int) ($data['subscalemax'] ?? 5),
                ],
                'se'       => [
                    'min' => (float) ($data['semin'] ?? 0.35),
                    'max' => (float) ($data['semax'] ?? 0.75),
                ],
            ],
            'courses'       => [['shortname' => 'catlab-' . time(), 'reference' => null]],
            'tests'         => [['name' => 'CATLab test', 'reference' => null]],
        ];

        // A conforming stratum has no deviation to scale, so a severity would
        // only be misleading.
        if ($definition['persons']['stratum'] === 'conforming') {
            $definition['persons']['severity'] = 'none';
        }

        $factors = array_filter([
            'strategy'       => array_values((array) ($data['sweepstrategies'] ?? [])),
            'variant'        => array_values((array) ($data['sweepvariants'] ?? [])),
            'stratum'        => array_values((array) ($data['sweepstrata'] ?? [])),
            'severity'       => array_values((array) ($data['sweepseverities'] ?? [])),
            'model'          => array_values((array) ($data['sweepmodels'] ?? [])),
            'globalbudget'   => self::parse_pairs((string) ($data['sweepglobalbudgets'] ?? ''), 'items'),
            'subscalebudget' => self::parse_pairs((string) ($data['sweepsubscalebudgets'] ?? ''), 'items'),
            'se'             => self::parse_pairs((string) ($data['sweepse'] ?? ''), 'se'),
            'recipe'         => self::parse_strengths((string) ($data['sweepstrengths'] ?? '')),
        ]);
        if ($factors !== []) {
            $definition['sweep'] = ['factors' => $factors];
        }

        // A cited block is recorded by id and fingerprint, so the manifest can
        // later show that two experiments really shared a blueprint. The form
        // values still win, because the author saw and could change them.
        foreach (['poolpreset' => 'pool', 'personspreset' => 'persons'] as $field => $kind) {
            $presetid = (int) ($data[$field] ?? 0);
            if ($presetid <= 0) {
                continue;
            }
            $preset = preset_library::get($presetid);
            if ($preset === null) {
                continue;
            }
            $definition[$field] = $presetid;
            $definition[$field . 'fingerprint'] = $preset['fingerprint'];
        }

        return $definition;
    }

    /**
     * Convert a stored definition back into form data.
     *
     * @param array $definition The stored definition.
     * @param int $id The experiment id, or 0 for a new one.
     * @return array Form data.
     */
    public static function to_form_data(array $definition, int $id = 0): array {
        $normalised = (new experiment_definition($definition))->get_normalised();
        $params = (array) ($normalised['modelparams'] ?? []);
        $discrimination = (array) ($params['discrimination'] ?? []);
        $guessing = (array) ($params['guessing'] ?? []);
        $recipe = (array) ($normalised['pool']['recipe'] ?? []);
        $factors = (array) ($normalised['sweep']['factors'] ?? []);

        return [
            'id'                 => $id,
            'name'               => (string) ($normalised['name'] ?? ''),
            'description'        => (string) ($normalised['description'] ?? ''),
            'experimentkey'      => (string) ($normalised['experimentkey'] ?? ''),
            'version'            => (string) ($normalised['version'] ?? '1.0.0'),
            'tags'               => implode(', ', (array) ($normalised['tags'] ?? [])),
            'enabled'            => !empty($normalised['enabled']) ? 1 : 0,
            'tier'               => (string) ($normalised['tier'] ?? 'baseline'),
            'seed'               => (int) ($normalised['seed'] ?? 42),
            'replications'       => (int) ($normalised['replications'] ?? 1),
            'model'              => (string) ($normalised['model'] ?? '2pl'),
            'discriminationdist' => (string) ($discrimination['dist'] ?? 'constant'),
            'allowdegenerate'    => !empty($params['allowdegenerate']) ? 1 : 0,
            'discriminationa'    => (float) ($discrimination['value']
                ?? $discrimination['meanlog'] ?? $discrimination['min'] ?? 1.0),
            'discriminationb'    => (float) ($discrimination['sdlog'] ?? $discrimination['max'] ?? 0.3),
            'guessingmin'        => (float) ($guessing['min'] ?? $guessing['value'] ?? 0.1),
            'guessingmax'        => (float) ($guessing['max'] ?? 0.25),
            'categories'         => (int) ($params['categories'] ?? 4),
            'poolcategories'     => (int) ($normalised['pool']['scales']['categories'] ?? 10),
            'poolsubcategories'  => (int) ($normalised['pool']['scales']['subcategories'] ?? 10),
            'poolitems'          => (int) ($normalised['pool']['scales']['itemspersubscale'] ?? 25),
            'variant'            => (string) ($normalised['pool']['variant'] ?? 'ideal'),
            'recipeshift'        => (float) ($recipe['shift'] ?? pool_mutator::DEFAULT_SHIFT),
            'recipefactor'       => (float) ($recipe['factor'] ?? pool_mutator::DEFAULT_STRETCH),
            'recipefraction'     => (float) ($recipe['fraction'] ?? 0.1),
            'recipesd'           => (float) ($recipe['sd'] ?? 0.5),
            'recipegapmin'       => (float) ($recipe['gapmin'] ?? -0.5),
            'recipegapmax'       => (float) ($recipe['gapmax'] ?? 0.5),
            'stratum'            => (string) ($normalised['persons']['stratum'] ?? 'conforming'),
            'severity'           => (string) ($normalised['persons']['severity'] ?? 'none'),
            'personcount'        => (int) ($normalised['persons']['count'] ?? 50),
            'twins'              => !empty($normalised['persons']['twins']['enabled']) ? 1 : 0,
            'strategy'           => (string) ($normalised['strategy'] ?? 'fastest'),
            'globalmin'          => (int) ($normalised['budgets']['global']['minitems'] ?? 20),
            'globalmax'          => (int) ($normalised['budgets']['global']['maxitems'] ?? 25),
            'subscalemin'        => (int) ($normalised['budgets']['subscale']['minitems'] ?? 3),
            'subscalemax'        => (int) ($normalised['budgets']['subscale']['maxitems'] ?? 5),
            'semin'              => (float) ($normalised['budgets']['se']['min'] ?? 0.35),
            'semax'              => (float) ($normalised['budgets']['se']['max'] ?? 0.75),
            'sweepstrategies'    => (array) ($factors['strategy'] ?? []),
            'sweepvariants'      => (array) ($factors['variant'] ?? []),
            'sweepstrata'        => (array) ($factors['stratum'] ?? []),
            'sweepseverities'    => (array) ($factors['severity'] ?? []),
            'sweepmodels'        => (array) ($factors['model'] ?? []),
            'sweepglobalbudgets' => self::render_pairs(
                (array) ($factors['globalbudget'] ?? []),
                ['minitems', 'maxitems']
            ),
            'sweepsubscalebudgets' => self::render_pairs(
                (array) ($factors['subscalebudget'] ?? []),
                ['minitems', 'maxitems']
            ),
            'sweepse'            => self::render_pairs((array) ($factors['se'] ?? []), ['min', 'max']),
            'sweepstrengths'     => implode("\n", array_map(
                static function (array $level): string {
                    $key = array_key_first($level);

                    return $key === 'fraction'
                        ? (string) round(100 * (float) $level[$key], 4)
                        : $key . '=' . $level[$key];
                },
                (array) ($factors['recipe'] ?? [])
            )),
            'poolpreset'         => (int) ($normalised['poolpreset'] ?? 0),
            'personspreset'      => (int) ($normalised['personspreset'] ?? 0),
        ];
    }

    /**
     * Parse one level per line, each written as "min/max".
     *
     * @param string $value The raw field value.
     * @param string $kind 'items' for integer item budgets, 'se' for the SE window.
     * @return array[] One block per line; empty when nothing parses.
     */
    public static function parse_pairs(string $value, string $kind): array {
        $levels = [];
        foreach (preg_split('/\r?\n/', $value) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode('/', $line));
            if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
                continue;
            }
            if ($kind === 'se') {
                $levels[] = ['min' => (float) $parts[0], 'max' => (float) $parts[1]];
            } else {
                $levels[] = ['minitems' => (int) $parts[0], 'maxitems' => (int) $parts[1]];
            }
        }

        return $levels;
    }

    /**
     * Parse one disturbance strength per line.
     *
     * A bare number is read as the affected share for the error and depletion
     * variants; "shift=1.0" or "factor=1.25" name the key explicitly for the
     * variants that measure their strength differently. The recipe is filtered
     * against the variant of each cell during expansion, so a level that does
     * not apply is dropped rather than making the cell invalid.
     *
     * @param string $value The raw field value.
     * @return array[] One recipe block per line.
     */
    public static function parse_strengths(string $value): array {
        $levels = [];
        foreach (preg_split('/\r?\n/', $value) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (strpos($line, '=') !== false) {
                [$key, $raw] = array_map('trim', explode('=', $line, 2));
                if ($key !== '' && is_numeric($raw)) {
                    $levels[] = [$key => (float) $raw];
                }
                continue;
            }
            if (is_numeric($line)) {
                // A percentage is written as a percentage and stored as a share.
                $number = (float) $line;
                $levels[] = ['fraction' => $number > 1.0 ? $number / 100.0 : $number];
            }
        }

        return $levels;
    }

    /**
     * Render parsed levels back into the textarea format.
     *
     * @param array $levels The levels from the definition.
     * @param string[] $keys The two keys to join with a slash.
     * @return string
     */
    protected static function render_pairs(array $levels, array $keys): string {
        $lines = [];
        foreach ($levels as $level) {
            if (isset($level[$keys[0]], $level[$keys[1]])) {
                $lines[] = $level[$keys[0]] . '/' . $level[$keys[1]];
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Split a comma-separated tag field into a list.
     *
     * @param string $value The raw field value.
     * @return string[] Trimmed, non-empty tags.
     */
    protected static function split_tags(string $value): array {
        $tags = array_map('trim', explode(',', $value));

        return array_values(array_filter($tags, static fn(string $tag): bool => $tag !== ''));
    }

    /**
     * Assemble the model parameter block from the form fields.
     *
     * @param array $data The submitted data.
     * @param string $model The public model key.
     * @return array
     */
    protected static function model_params(array $data, string $model): array {
        $params = [];
        $key = model_catalog::normalise($model) ?? $model;

        if (model_catalog::has($key) && model_catalog::needs_discrimination($key)) {
            $dist = (string) ($data['discriminationdist'] ?? 'constant');
            $a = (float) ($data['discriminationa'] ?? 1.0);
            $b = (float) ($data['discriminationb'] ?? 0.3);

            if ($dist === 'lognormal') {
                $params['discrimination'] = ['dist' => 'lognormal', 'meanlog' => $a, 'sdlog' => $b];
            } else if ($dist === 'uniform') {
                $params['discrimination'] = ['dist' => 'uniform', 'min' => $a, 'max' => $b];
            } else {
                $params['discrimination'] = ['dist' => 'constant', 'value' => $a];
                // The degenerate flag is only set when the author ticked the
                // box that says so. Setting it here automatically, as this used
                // to, defeated the check it exists for: a run labelled 2PL
                // would quietly be a Rasch run, and the validator would have
                // said so had the form not answered the question on the
                // author's behalf.
                if (!empty($data['allowdegenerate'])) {
                    $params['allowdegenerate'] = true;
                }
            }
        }

        if (model_catalog::has($key) && model_catalog::needs_guessing($key)) {
            $params['guessing'] = [
                'dist' => 'uniform',
                'min'  => (float) ($data['guessingmin'] ?? 0.1),
                'max'  => (float) ($data['guessingmax'] ?? 0.25),
            ];
        }

        if (model_catalog::has($key) && model_catalog::is_polytomous($key)) {
            $params['categories'] = (int) ($data['categories'] ?? 4);
        }

        return $params;
    }

    /**
     * Assemble the variant recipe from the form fields.
     *
     * @param array $data The submitted data.
     * @param string $variant The pool variant.
     * @return array
     */
    protected static function recipe(array $data, string $variant): array {
        switch ($variant) {
            case 'shifted':
                return ['shift' => (float) ($data['recipeshift'] ?? pool_mutator::DEFAULT_SHIFT)];
            case 'stretched':
                return ['factor' => (float) ($data['recipefactor'] ?? pool_mutator::DEFAULT_STRETCH)];
            case 'gappy':
                return [
                    'gapmin' => (float) ($data['recipegapmin'] ?? -0.5),
                    'gapmax' => (float) ($data['recipegapmax'] ?? 0.5),
                    'mode'   => pool_mutator::GAP_MODE_FIXEDN,
                ];
            case 'depleted':
            case 'taggingerror':
                return ['fraction' => (float) ($data['recipefraction'] ?? 0.1)];
            case 'calibrationerror':
                return [
                    'fraction' => (float) ($data['recipefraction'] ?? 0.1),
                    'sd'       => (float) ($data['recipesd'] ?? 0.5),
                ];
            default:
                return [];
        }
    }

    /**
     * The editor sections, with a one-line summary of what each currently holds.
     *
     * The mockup shows the summary next to the collapsed section, so an author
     * can see the whole design without opening eight panels one after another.
     *
     * @param array $definition The normalised definition being edited.
     * @return array[] One entry per section: number, anchor, title, summary.
     */
    public static function sections(array $definition): array {
        $component = 'local_catquizlab';
        $model = (string) ($definition['model'] ?? '2pl');
        $strategy = (string) ($definition['strategy'] ?? 'fastest');
        $variant = (string) ($definition['pool']['variant'] ?? 'ideal');
        $scales = (array) ($definition['pool']['scales'] ?? []);
        $persons = (array) ($definition['persons'] ?? []);
        $budgets = (array) ($definition['budgets'] ?? []);
        $factors = (array) ($definition['sweep']['factors'] ?? []);

        $items = (int) ($scales['categories'] ?? 0)
            * (int) ($scales['subcategories'] ?? 0)
            * (int) ($scales['itemspersubscale'] ?? 0);

        return [
            [
                'number'  => 1,
                'anchor'  => 'id_basics',
                'title'   => get_string('form:basics', $component),
                'summary' => (string) ($definition['name'] ?? ''),
            ],
            [
                'number'  => 2,
                'anchor'  => 'id_modelheader',
                'title'   => get_string('form:model', $component),
                'summary' => get_string('editor:modelsummary', $component, (object) [
                    'model' => model_catalog::has($model) ? model_catalog::label($model) : $model,
                    'engine' => model_catalog::has($model) ? model_catalog::engine_key($model) : '—',
                ]),
            ],
            [
                'number'  => 3,
                'anchor'  => 'id_poolheader',
                'title'   => get_string('form:pool', $component),
                'summary' => get_string('editor:poolsummary', $component, (object) [
                    'variant' => get_string('variant:' . $variant, $component),
                    'items'   => $items,
                ]),
            ],
            [
                'number'  => 4,
                'anchor'  => 'id_personsheader',
                'title'   => get_string('form:persons', $component),
                'summary' => get_string('editor:personsummary', $component, (object) [
                    'stratum' => get_string('stratum:' . ($persons['stratum'] ?? 'conforming'), $component),
                    'count'   => (int) ($persons['count'] ?? 0),
                ]),
            ],
            [
                'number'  => 5,
                'anchor'  => 'id_catheader',
                'title'   => get_string('form:cat', $component),
                'summary' => get_string('editor:catsummary', $component, (object) [
                    'strategy' => strategy_catalog::has($strategy)
                        ? strategy_catalog::label($strategy)
                        : $strategy,
                    'min'      => (int) ($budgets['global']['minitems'] ?? 0),
                    'max'      => (int) ($budgets['global']['maxitems'] ?? 0),
                    'semin'    => format_float((float) ($budgets['se']['min'] ?? 0), 2),
                    'semax'    => format_float((float) ($budgets['se']['max'] ?? 0), 2),
                ]),
            ],
            [
                'number'  => 6,
                'anchor'  => 'id_sweepheader',
                'title'   => get_string('form:sweep', $component),
                'summary' => get_string('editor:sweepsummary', $component, (object) [
                    'factors'      => $factors === []
                        ? get_string('editor:nofactors', $component)
                        : implode(', ', array_keys($factors)),
                    'replications' => (int) ($definition['replications'] ?? 1),
                ]),
            ],
        ];
    }

    /**
     * Strategy options, labelled for publication with the internal key shown.
     *
     * @return array<string, string>
     */
    public static function strategy_menu(): array {
        $menu = [];
        foreach (strategy_catalog::keys() as $key) {
            $menu[$key] = strategy_catalog::label($key) . ' (' . $key . ')';
        }
        return $menu;
    }

    /**
     * Model options, labelled with the engine key they resolve to.
     *
     * @return array<string, string>
     */
    public static function model_menu(): array {
        $menu = [];
        foreach (model_catalog::keys() as $key) {
            $menu[$key] = model_catalog::label($key) . ' — ' . model_catalog::engine_key($key);
        }
        return $menu;
    }

    /**
     * Pool-variant options.
     *
     * @return array<string, string>
     */
    public static function variant_menu(): array {
        $menu = [];
        foreach (experiment_definition::VARIANTS as $variant) {
            $menu[$variant] = get_string('variant:' . $variant, 'local_catquizlab');
        }
        return $menu;
    }

    /**
     * Person-stratum options.
     *
     * @return array<string, string>
     */
    public static function stratum_menu(): array {
        $menu = [];
        foreach (experiment_definition::STRATA as $stratum) {
            $menu[$stratum] = get_string('stratum:' . $stratum, 'local_catquizlab');
        }
        return $menu;
    }

    /**
     * Severity options.
     *
     * @return array<string, string>
     */
    public static function severity_menu(): array {
        $menu = [];
        foreach (experiment_definition::SEVERITIES as $severity) {
            $menu[$severity] = get_string('severity:' . $severity, 'local_catquizlab');
        }
        return $menu;
    }

    /**
     * Tier options.
     *
     * @return array<string, string>
     */
    public static function tier_menu(): array {
        $menu = [];
        foreach (experiment_definition::TIERS as $tier) {
            $menu[$tier] = get_string('tier:' . $tier, 'local_catquizlab');
        }
        return $menu;
    }
}
