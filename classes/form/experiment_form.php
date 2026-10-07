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
use local_catquizlab\local\budget_feasibility;
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
     * Make the whole form read-only, for an experiment that has runs.
     *
     * An experiment with runs documents what those runs did, and saving was
     * refused — but only after somebody had edited it, believing the runs
     * would follow (#104). Frozen, the form cannot be mistaken for one that
     * changes anything, and the page offers the copy that can.
     *
     * @return void
     */
    public function freeze_for_runs(): void {
        $this->_form->hardFreezeAllVisibleExcept([]);
        if ($this->_form->elementExists('buttonar')) {
            $this->_form->removeElement('buttonar');
        }
    }

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
        $mform->setType('discriminationa', PARAM_LOCALISEDFLOAT);
        $mform->setDefault('discriminationa', 1.0);
        $mform->hideIf('discriminationa', 'model', 'eq', '1pl');

        $mform->addElement('text', 'discriminationb', get_string('form:paramtwo', $component), ['size' => 10]);
        $mform->setType('discriminationb', PARAM_LOCALISEDFLOAT);
        $mform->setDefault('discriminationb', 0.3);
        $mform->hideIf('discriminationb', 'discriminationdist', 'eq', 'constant');

        $mform->addElement('text', 'guessingmin', get_string('form:guessingmin', $component), ['size' => 10]);
        $mform->setType('guessingmin', PARAM_LOCALISEDFLOAT);
        $mform->setDefault('guessingmin', 0.1);
        $mform->hideIf('guessingmin', 'model', 'noteq', '3pl');

        $mform->addElement('text', 'guessingmax', get_string('form:guessingmax', $component), ['size' => 10]);
        $mform->setType('guessingmax', PARAM_LOCALISEDFLOAT);
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
        $mform->setType('recipeshift', PARAM_LOCALISEDFLOAT);
        $mform->setDefault('recipeshift', pool_mutator::DEFAULT_SHIFT);
        $mform->hideIf('recipeshift', 'variant', 'noteq', 'shifted');

        $mform->addElement('text', 'recipefactor', get_string('form:stretch', $component), ['size' => 8]);
        $mform->setType('recipefactor', PARAM_LOCALISEDFLOAT);
        $mform->setDefault('recipefactor', pool_mutator::DEFAULT_STRETCH);
        $mform->hideIf('recipefactor', 'variant', 'noteq', 'stretched');

        $mform->addElement('text', 'recipefraction', get_string('form:fraction', $component), ['size' => 8]);
        $mform->setType('recipefraction', PARAM_LOCALISEDFLOAT);
        $mform->setDefault('recipefraction', 0.1);
        $mform->addHelpButton('recipefraction', 'form:fraction', $component);

        $mform->addElement('text', 'recipesd', get_string('form:errorsd', $component), ['size' => 8]);
        $mform->setType('recipesd', PARAM_LOCALISEDFLOAT);
        $mform->setDefault('recipesd', 0.5);
        $mform->hideIf('recipesd', 'variant', 'noteq', 'calibrationerror');

        $mform->addElement('text', 'recipegapmin', get_string('form:gapmin', $component), ['size' => 8]);
        $mform->setType('recipegapmin', PARAM_LOCALISEDFLOAT);
        $mform->setDefault('recipegapmin', -0.5);
        $mform->hideIf('recipegapmin', 'variant', 'noteq', 'gappy');

        $mform->addElement('text', 'recipegapmax', get_string('form:gapmax', $component), ['size' => 8]);
        $mform->setType('recipegapmax', PARAM_LOCALISEDFLOAT);
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
        $mform->addHelpButton('personcount', 'form:personcount', $component);

        // The simulated abilities: distribution, parameters and range, named
        // explicitly (#102, #105) — "N(0, 2)" left open whether 2 was a
        // variance or a standard deviation. The range is also the range of
        // every provisioned CAT scale.
        $recommended = \local_catquizlab\local\ability_distribution::RECOMMENDED;
        $mform->addElement('select', 'abilitydistribution', get_string('form:abilitydistribution', $component), [
            'truncated_normal' => get_string('distribution:truncated_normal', $component),
            'normal'           => get_string('distribution:normal', $component),
            'uniform'          => get_string('distribution:uniform', $component),
        ]);
        $mform->setDefault('abilitydistribution', $recommended['distribution']);
        $mform->addHelpButton('abilitydistribution', 'form:abilitydistribution', $component);
        $mform->addElement('text', 'abilitymean', get_string('form:abilitymean', $component), ['size' => 6]);
        $mform->setType('abilitymean', PARAM_LOCALISEDFLOAT);
        $mform->setDefault('abilitymean', $recommended['mean']);
        $mform->addElement('text', 'abilitysd', get_string('form:abilitysd', $component), ['size' => 6]);
        $mform->setType('abilitysd', PARAM_LOCALISEDFLOAT);
        $mform->setDefault('abilitysd', $recommended['sd']);
        $mform->hideIf('abilitysd', 'abilitydistribution', 'eq', 'uniform');
        $mform->addElement('text', 'abilitymin', get_string('form:abilitymin', $component), ['size' => 6]);
        $mform->setType('abilitymin', PARAM_LOCALISEDFLOAT);
        $mform->setDefault('abilitymin', $recommended['min']);
        $mform->addElement('text', 'abilitymax', get_string('form:abilitymax', $component), ['size' => 6]);
        $mform->setType('abilitymax', PARAM_LOCALISEDFLOAT);
        $mform->setDefault('abilitymax', $recommended['max']);
        $mform->addHelpButton('abilitymin', 'form:abilitymin', $component);

        // Local deviations (#102): the SD of each category around the global
        // ability and of each subscale around its category. Empty follows
        // the stratum and the severity; a number sets it outright.
        foreach (['catsd', 'subsd'] as $field) {
            $mform->addElement('text', $field, get_string('form:' . $field, $component), ['size' => 6]);
            $mform->setType($field, PARAM_RAW_TRIMMED);
            $mform->addHelpButton($field, 'form:' . $field, $component);
        }
        // What mild, medium and strong multiply the stratum's deviation by.
        foreach (['mild' => 0.5, 'medium' => 1.0, 'strong' => 2.0] as $level => $default) {
            $mform->addElement('text', 'severity' . $level, get_string(
                'form:severityfactor',
                $component,
                get_string('severity:' . $level, $component)
            ), ['size' => 5]);
            $mform->setType('severity' . $level, PARAM_LOCALISEDFLOAT);
            $mform->setDefault('severity' . $level, $default);
        }
        $mform->addHelpButton('severitymild', 'form:severityfactor', $component);

        $mform->addElement('advcheckbox', 'twins', get_string('form:twins', $component));
        $mform->setDefault('twins', 1);
        $mform->addHelpButton('twins', 'form:twins', $component);

        // Strategy and budgets.
        $mform->addElement('header', 'catheader', get_string('form:cat', $component));
        $mform->setExpanded('catheader', true);

        $mform->addElement('select', 'strategy', get_string('form:strategy', $component), self::strategy_menu());
        $mform->setDefault('strategy', 'fastest');
        $mform->addHelpButton('strategy', 'form:strategy', $component);
        // Said where the choice is when the sweep's strategies replace it.
        $mform->addElement('static', 'na_strategy', '', \html_writer::span(
            '',
            'text-muted',
            ['data-catquizlab-na' => 'strategy', 'hidden' => 'hidden']
        ));

        $mform->addElement('text', 'globalmin', get_string('form:globalmin', $component), ['size' => 8]);
        $mform->setType('globalmin', PARAM_INT);
        $mform->setDefault('globalmin', 20);

        $mform->addElement('text', 'globalmax', get_string('form:globalmax', $component), ['size' => 8]);
        $mform->setType('globalmax', PARAM_INT);
        $mform->setDefault('globalmax', 25);
        // Classic only: it plays every item, the question budget is not its (#104).
        $mform->addElement('static', 'na_globalmax', '', \html_writer::span(
            get_string('form:allitems', $component),
            'text-muted',
            ['data-catquizlab-na' => 'globalmax', 'hidden' => 'hidden']
        ));

        $mform->addElement('text', 'subscalemin', get_string('form:subscalemin', $component), ['size' => 8]);
        $mform->setType('subscalemin', PARAM_INT);
        $mform->setDefault('subscalemin', 3);

        $mform->addElement('text', 'subscalemax', get_string('form:subscalemax', $component), ['size' => 8]);
        $mform->setType('subscalemax', PARAM_INT);
        $mform->setDefault('subscalemax', 5);
        // Said in words where it does not apply (#101), not just greyed out.
        $mform->addElement('static', 'na_subscalemax', '', \html_writer::span(
            get_string('form:na_subscale', $component),
            'text-muted',
            ['data-catquizlab-na' => 'subscalemax', 'hidden' => 'hidden']
        ));
        // What the budgets cannot do with this pool, as they are typed.
        $mform->addElement('static', 'feasibility', '', \html_writer::div(
            '',
            'alert alert-danger mb-0',
            ['data-catquizlab-feasibility' => '1', 'role' => 'alert', 'hidden' => 'hidden']
        ));

        // Budgets that belong to one strategy. Left empty, a strategy uses the
        // budgets above; filled, it overrides only what is filled. This is how
        // "classic without a ceiling, allsubs at eighty" is said without a
        // sweep multiplying every budget across every strategy.
        $mform->addElement('header', 'perstrategy', get_string('form:perstrategy', $component));
        $mform->setExpanded('perstrategy', false);
        $mform->addElement('static', 'perstrategyhelp', '', get_string('form:perstrategyhelp', $component));

        foreach (strategy_catalog::keys() as $key) {
            if (!strategy_catalog::runnable($key)) {
                continue;
            }

            // Every field says what it is: "per sitting" and "per subscale",
            // each from – to. A row of four unlabelled boxes asked the reader
            // to remember an order given in a paragraph above.
            $group = [];
            $group[] = $mform->createElement(
                'static',
                'perstrategy_' . $key . '_testlabel',
                '',
                \html_writer::span(get_string('form:persitting', $component), 'mr-1 text-muted')
            );
            foreach (['globalmin', 'globalmax', 'subscalemin', 'subscalemax'] as $field) {
                if ($field === 'subscalemin') {
                    $group[] = $mform->createElement(
                        'static',
                        'perstrategy_' . $key . '_sublabel',
                        '',
                        \html_writer::span(get_string('form:persubscale', $component), 'ml-3 mr-1 text-muted')
                    );
                }
                $name = 'perstrategy_' . $key . '_' . $field;
                $attributes = [
                    'size' => 5,
                    'aria-label' => strategy_catalog::label($key) . ': ' . get_string('form:' . $field, $component),
                    'title' => get_string('form:' . $field, $component),
                    'data-catquizlab-perstrategy' => $key,
                    'data-catquizlab-field' => $field,
                ];
                // A strategy without subscales has no subscale budget to set,
                // and the field says so rather than sitting there empty (#101).
                if (str_starts_with($field, 'subscale') && !strategy_catalog::uses_subscales($key)) {
                    $attributes['disabled'] = 'disabled';
                    $attributes['placeholder'] = get_string('form:na_short', $component);
                    $attributes['title'] = get_string('form:na_forstrategy', $component, strategy_catalog::label($key));
                }
                // The classical test plays every item of the scale: there is
                // no number of questions to set for it.
                if (str_starts_with($field, 'global') && !strategy_catalog::uses($key, 'globalmax')) {
                    $attributes['disabled'] = 'disabled';
                    $attributes['placeholder'] = get_string('form:allitems_short', $component);
                    $attributes['title'] = get_string('form:allitems', $component);
                }
                $group[] = $mform->createElement('text', $name, '', $attributes);
                // Text, not integer: a maximum may be the word "unlimited".
                $mform->setType($name, PARAM_ALPHANUMEXT);
                if (str_ends_with($field, 'min')) {
                    $group[] = $mform->createElement('static', $name . '_dash', '', '–');
                }
            }

            $mform->addGroup($group, 'perstrategygroup_' . $key, strategy_catalog::label($key), ' ', false);
            $mform->addHelpButton('perstrategygroup_' . $key, 'form:perstrategy', $component);
        }

        // Which fields apply follows what is chosen, as it is chosen. A field
        // that applies to none of the strategies in play is hidden and
        // disabled, with a sentence where it was; a strategy's own row is shown
        // only while that strategy is in play; the classical test's row has no
        // question budget to set. The strategies in play are the sweep's where
        // it has any — they replace the one chosen above — that one otherwise.
        // And the budgets are checked against the pool as they are typed, by
        // the same rules the server applies on submit (budget_feasibility).
        global $PAGE;
        $config = budget_feasibility::client_rules() + [
            'na' => get_string('form:na_short', $component),
            'allitems' => get_string('form:allitems_short', $component),
            'replaced' => get_string('form:strategyreplaced', $component),
        ];
        $PAGE->requires->js_amd_inline('require([], function() {
            var cfg = ' . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';
            ' . self::form_script() . '
        });');

        // Pilot questions: an option of their own, not a strategy (#103). The
        // engine mixes not-yet-calibrated questions into the sitting at the
        // given share; a strategy that cannot include them ignores this.
        $mform->addElement('advcheckbox', 'pilotinclude', get_string('form:pilotinclude', $component));
        $mform->addHelpButton('pilotinclude', 'form:pilotinclude', $component);
        $mform->addElement('text', 'pilotratio', get_string('form:pilotratio', $component), ['size' => 5]);
        $mform->setType('pilotratio', PARAM_LOCALISEDFLOAT);
        $mform->setDefault('pilotratio', 20);
        $mform->hideIf('pilotratio', 'pilotinclude', 'notchecked');
        // Said in words where it does not apply (#101), not just greyed out.
        $mform->addElement('static', 'na_pilot', '', \html_writer::span(
            get_string('form:na_pilot', $component),
            'text-muted',
            ['data-catquizlab-na' => 'pilot', 'hidden' => 'hidden']
        ));

        $mform->addElement('text', 'semin', get_string('form:semin', $component), ['size' => 8]);
        $mform->setType('semin', PARAM_LOCALISEDFLOAT);
        $mform->setDefault('semin', 0.35);
        $mform->addHelpButton('semin', 'form:semin', $component);

        $mform->addElement('text', 'semax', get_string('form:semax', $component), ['size' => 8]);
        $mform->setType('semax', PARAM_LOCALISEDFLOAT);
        $mform->setDefault('semax', 0.75);
        // Said in words where it does not apply (#101), not just greyed out.
        $mform->addElement('static', 'na_standarderror', '', \html_writer::span(
            get_string('form:na_standarderror', $component),
            'text-muted',
            ['data-catquizlab-na' => 'standarderror', 'hidden' => 'hidden']
        ));

        // Sweep.
        // Budgets of single cells (#96): for an experiment already saved, one
        // row per concrete cell of its sweep, empty fields following the
        // strategy's budgets, a number setting it for that cell alone.
        $this->add_cell_budgets($mform);

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

        // A number that could not be read is false, not zero. Storing it as
        // zero — which the old float type did for "0,3" on a German site — made
        // a standard-error floor of 0 and a discrimination of 0 out of typing
        // mistakes, and the experiment ran with them without a word.
        $floats = [
            'discriminationa', 'discriminationb', 'guessingmin', 'guessingmax',
            'recipeshift', 'recipefactor', 'recipefraction', 'recipesd',
            'recipegapmin', 'recipegapmax', 'semin', 'semax',
        ];
        foreach ($floats as $field) {
            if (array_key_exists($field, $data) && $data[$field] === false) {
                $errors[$field] = get_string('form:notanumber', 'local_catquizlab');
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        // A budget a strategy cannot use, caught while it is being typed. The
        // readiness check catches it too, but only once the run is being
        // provisioned: by then a course, two and a half thousand questions and
        // a thousand accounts exist for a run that was never going to start.
        foreach (self::impossible_budgets((array) $data) as $field => $message) {
            $errors[$field] = $message;
        }
        if ($errors !== []) {
            return $errors;
        }

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
                'distribution' => (string) ($data['abilitydistribution']
                    ?? \local_catquizlab\local\ability_distribution::RECOMMENDED['distribution']),
                'abilitymean'  => (float) unformat_float((string) ($data['abilitymean'] ?? 0)),
                'abilitysd'    => (float) unformat_float((string) ($data['abilitysd'] ?? 1)),
                'abilityrange' => [
                    'min' => (float) unformat_float((string) ($data['abilitymin'] ?? -3)),
                    'max' => (float) unformat_float((string) ($data['abilitymax'] ?? 3)),
                ],
                'variation'    => array_filter([
                    'category' => trim((string) ($data['catsd'] ?? '')) === '' ? null
                        : unformat_float((string) $data['catsd']),
                    'subscale' => trim((string) ($data['subsd'] ?? '')) === '' ? null
                        : unformat_float((string) $data['subsd']),
                ], static fn($v): bool => $v !== null),
                'severityscale' => [
                    'mild'   => (float) unformat_float((string) ($data['severitymild'] ?? 0.5)),
                    'medium' => (float) unformat_float((string) ($data['severitymedium'] ?? 1.0)),
                    'strong' => (float) unformat_float((string) ($data['severitystrong'] ?? 2.0)),
                ],
                'twins'    => ['enabled' => !empty($data['twins'])],
                'naming'   => ['pattern' => 'P-{stratum}-{index:04d}'],
            ],
            'budgetsbystrategy' => self::per_strategy_budgets($data),
            'budgetsbycell' => self::per_cell_budgets($data),
            'pilot'         => [
                'include' => !empty($data['pilotinclude']),
                'ratio'   => (float) unformat_float((string) ($data['pilotratio'] ?? 0)),
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
     * Budgets that the chosen strategies cannot satisfy with the chosen pool.
     *
     * The arithmetic is budget_feasibility's — the same the browser applies
     * while the form is filled in — for every strategy this experiment will
     * run, with whichever budget each of them will actually use: its own where
     * it has one, the shared one otherwise. The pool's shape is the pool's:
     * domains × subscales per domain. It used to be read from the model's
     * response categories and a field that does not exist, the product was
     * zero, and the check never ran.
     *
     * @param array $data The submitted form data.
     * @return array<string, string> Field name => message.
     */
    protected static function impossible_budgets(array $data): array {
        $leaves = max(0, (int) ($data['poolcategories'] ?? 0)) * max(0, (int) ($data['poolsubcategories'] ?? 0));
        $items = max(0, (int) ($data['poolitems'] ?? 0));
        $overrides = self::per_strategy_budgets($data);
        $errors = [];

        foreach (self::strategies_in_play($data) as $key) {
            $own = (array) ($overrides[$key] ?? []);
            $budgets = [
                'globalmin' => $own['global']['minitems'] ?? ($data['globalmin'] ?? null),
                'globalmax' => $own['global']['maxitems'] ?? ($data['globalmax'] ?? null),
                'subscalemin' => $own['subscale']['minitems'] ?? ($data['subscalemin'] ?? null),
                'subscalemax' => $own['subscale']['maxitems'] ?? ($data['subscalemax'] ?? null),
            ];
            foreach (budget_feasibility::check($key, $budgets, $leaves, $items) as $problem) {
                // A minimum above the maximum the definition's own validation
                // reports on the field, with the level it is in; said twice it
                // would only hide that message behind this one.
                if ($problem['code'] === 'minabovemax') {
                    continue;
                }
                // On the field somebody can change: the strategy's own row where
                // it set this budget itself, the shared field otherwise.
                [$level, $target] = [
                    'globalmin' => ['global', 'minitems'], 'globalmax' => ['global', 'maxitems'],
                    'subscalemin' => ['subscale', 'minitems'], 'subscalemax' => ['subscale', 'maxitems'],
                ][$problem['field']];
                $field = isset($own[$level][$target]) ? 'perstrategygroup_' . $key : $problem['field'];
                $message = budget_feasibility::message($problem);
                $errors[$field] = isset($errors[$field]) ? $errors[$field] . ' ' . $message : $message;
            }
        }

        return $errors;
    }

    /**
     * The browser's half of the form logic; cfg is defined before it.
     *
     * @return string
     */
    protected static function form_script(): string {
        return <<<'JS'
var byid = function(id) { return document.getElementById(id); };
var row = function(id) {
    var el = byid(id);
    return el ? (el.closest('.fitem') || el.closest('.form-group') || el) : null;
};
var style = document.createElement('style');
style.textContent = '.catquizlab-hidden{display:none!important}';
document.head.appendChild(style);
var show = function(node, visible) {
    if (node) { node.classList.toggle('catquizlab-hidden', !visible); }
};
var num = function(value) {
    var v = String(value === undefined || value === null ? '' : value).trim();
    return /^[0-9]+$/.test(v) ? parseInt(v, 10) : null;
};
var inplay = function() {
    var sweep = byid('id_sweepstrategies');
    var keys = [];
    if (sweep) {
        Array.prototype.forEach.call(sweep.options, function(o) {
            if (o.selected && cfg.strategies[o.value]) { keys.push(o.value); }
        });
    }
    var main = byid('id_strategy');
    return {keys: keys.length ? keys : (main && cfg.strategies[main.value] ? [main.value] : []), swept: keys.length > 0};
};
var uses = function(keys, what) {
    return keys.some(function(k) { return cfg.strategies[k][what]; });
};
var fill = function(template, a) {
    return template.replace(/\{\$a->([a-z]+)\}/g, function(m, k) { return a[k] !== undefined ? a[k] : m; });
};
var check = function(key, b, leaves, items) {
    var s = cfg.strategies[key];
    var out = [];
    if (!s || !s.global) { return out; }
    var add = function(code, a) { a.strategy = s.label; out.push(fill(cfg.messages[code], a)); };
    var smin = s.subscale ? b.subscalemin : null;
    var smax = s.subscale ? b.subscalemax : null;
    if (b.globalmin !== null && b.globalmax !== null && b.globalmax > 0 && b.globalmin > b.globalmax) {
        add('minabovemax', {minimum: b.globalmin, maximum: b.globalmax});
    }
    if (leaves > 0 && s.floor && smin !== null && smin > 0) {
        if (b.globalmax !== null && b.globalmax > 0 && smin * leaves > b.globalmax) {
            add('floorabovemaximum', {leaves: leaves, submin: smin, floor: smin * leaves, maximum: b.globalmax});
        }
        if (items > 0 && smin > items) { add('floorabovepool', {submin: smin, items: items}); }
    }
    if (leaves > 0 && smax !== null && smax > 0 && b.globalmin !== null && smax * leaves < b.globalmin) {
        add('ceilingbelowminimum', {leaves: leaves, submax: smax, ceiling: smax * leaves, minimum: b.globalmin});
    }
    if (leaves > 0 && items > 0 && b.globalmin !== null && leaves * items < b.globalmin) {
        add('poolbelowminimum', {pool: leaves * items, minimum: b.globalmin});
    }
    return out;
};
var shared = {
    global: ['id_globalmin', 'id_globalmax'],
    subscale: ['id_subscalemin', 'id_subscalemax'],
    se: ['id_semin', 'id_semax'],
    pilot: ['id_pilotinclude']
};
var notes = {global: 'globalmax', subscale: 'subscalemax', se: 'standarderror', pilot: 'pilot'};
var update = function() {
    var play = inplay();
    var keys = play.keys;

    // The choice above, replaced by the sweep's strategies.
    var main = byid('id_strategy');
    if (main) { main.disabled = play.swept; }
    document.querySelectorAll('[data-catquizlab-na="strategy"]').forEach(function(note) {
        note.textContent = play.swept ? fill(cfg.replaced, {strategies: keys.map(function(k) {
            return cfg.strategies[k].label;
        }).join(', ')}) : '';
        note.hidden = !play.swept;
        show(note.closest('.fitem') || note.parentNode, play.swept);
    });

    // Shared fields: hidden and disabled where no strategy in play uses them.
    Object.keys(shared).forEach(function(what) {
        var applies = uses(keys, what);
        shared[what].forEach(function(id) {
            var el = byid(id);
            if (el) { el.disabled = !applies; }
            show(row(id), applies);
        });
        if (what === 'pilot') {
            var ratio = byid('id_pilotratio');
            if (ratio) { ratio.disabled = !applies; }
            show(row('id_pilotratio'), applies);
        }
        document.querySelectorAll('[data-catquizlab-na="' + notes[what] + '"]').forEach(function(note) {
            note.hidden = applies;
            show(note.closest('.fitem') || note.parentNode, !applies);
        });
    });

    // A strategy's own row: only while it is in play, only what it uses.
    Object.keys(cfg.strategies).forEach(function(key) {
        show(row('fgroup_id_perstrategygroup_' + key) || byid('fgroup_id_perstrategygroup_' + key),
            keys.indexOf(key) !== -1);
    });
    document.querySelectorAll('[data-catquizlab-perstrategy]').forEach(function(el) {
        var key = el.getAttribute('data-catquizlab-perstrategy');
        var field = el.getAttribute('data-catquizlab-field');
        var s = cfg.strategies[key] || {};
        var level = field.indexOf('subscale') === 0 ? 'subscale' : 'global';
        var applies = keys.indexOf(key) !== -1 && !!s[level];
        el.disabled = !applies;
        if (!s[level]) {
            el.value = '';
            el.placeholder = level === 'global' ? cfg.allitems : cfg.na;
            return;
        }
        var base = byid('id_' + field);
        el.placeholder = base && !base.disabled ? base.value : '';
    });

    // What the budgets cannot do with this pool.
    var leaves = (num(byid('id_poolcategories') && byid('id_poolcategories').value) || 0)
        * (num(byid('id_poolsubcategories') && byid('id_poolsubcategories').value) || 0);
    var items = num(byid('id_poolitems') && byid('id_poolitems').value) || 0;
    var problems = [];
    keys.forEach(function(key) {
        var b = {};
        ['globalmin', 'globalmax', 'subscalemin', 'subscalemax'].forEach(function(field) {
            var own = byid('id_perstrategy_' + key + '_' + field);
            var base = byid('id_' + field);
            var value = own && !own.disabled && String(own.value).trim() !== '' ? own.value
                : (base && !base.disabled ? base.value : '');
            b[field] = num(value);
        });
        problems = problems.concat(check(key, b, leaves, items));
    });
    document.querySelectorAll('[data-catquizlab-feasibility]').forEach(function(box) {
        box.innerHTML = '';
        problems.forEach(function(text) {
            var p = document.createElement('p');
            p.className = 'mb-1';
            p.textContent = text;
            box.appendChild(p);
        });
        box.hidden = problems.length === 0;
        show(box.closest('.fitem') || box.parentNode, problems.length > 0);
    });
};
document.addEventListener('change', update);
document.addEventListener('input', update);
update();
JS;
    }

    /**
     * Every strategy this experiment would run.
     *
     * @param array $data The submitted form data.
     * @return string[]
     */
    protected static function strategies_in_play(array $data): array {
        // The strategies of the sweep where it has any — they replace the one
        // chosen above, as the sweep's help says — that one otherwise. Both
        // used to count: a classical test chosen above and four other
        // strategies in the sweep was checked as five strategies.
        $keys = array_map('strval', array_values(array_filter(
            (array) ($data['sweepstrategies'] ?? []),
            static fn($v): bool => (string) $v !== ''
        )));
        if ($keys === [] && !empty($data['strategy'])) {
            $keys[] = (string) $data['strategy'];
        }

        return array_values(array_unique(array_filter($keys, static function (string $key): bool {
            return strategy_catalog::has($key);
        })));
    }

    /** @var string[] The fields of a cell's budget, with their level and key in the definition. */
    protected const CELL_FIELDS = [
        'globalmin' => ['global', 'minitems'], 'globalmax' => ['global', 'maxitems'],
        'subscalemin' => ['subscale', 'minitems'], 'subscalemax' => ['subscale', 'maxitems'],
        'semin' => ['se', 'min'], 'semax' => ['se', 'max'],
    ];

    /**
     * The form field prefix of one cell: a short hash of its key.
     *
     * @param string $cellkey The sweep cell key.
     * @return string
     */
    public static function cell_prefix(string $cellkey): string {
        return 'cb_' . substr(sha1($cellkey), 0, 10) . '_';
    }

    /**
     * One row of fields per cell of the saved experiment's sweep (#96).
     *
     * @param \MoodleQuickForm $mform The form.
     * @return void
     */
    protected function add_cell_budgets(\MoodleQuickForm $mform): void {
        $component = 'local_catquizlab';
        $existing = $this->_customdata['existing'] ?? null;
        if (!$existing || empty($existing->configjson)) {
            return;
        }
        try {
            $definition = \local_catquizlab\local\experiment_definition::from_json((string) $existing->configjson)
                ->get_normalised();
            $expansion = \local_catquizlab\local\sweep::expand(
                \local_catquizlab\local\experiment_service::sweep_spec($definition)
            );
        } catch (\Throwable $e) {
            return;
        }
        $cells = [];
        foreach ((array) ($expansion['runs'] ?? []) as $run) {
            $cells[(string) $run['cellkey']] = (array) $run['definition'];
        }
        if (count($cells) < 1 || count($cells) > 60) {
            return;
        }
        $factors = [];
        foreach ((array) ($expansion['cells'] ?? []) as $cell) {
            $factors[(string) $cell['cellkey']] = (array) ($cell['factors'] ?? []);
        }

        $mform->addElement('header', 'cellbudgets', get_string('form:cellbudgets', $component));
        $mform->addElement('static', 'cellbudgetsexplain', '', get_string('form:cellbudgets_help', $component));
        $na = get_string('form:na_short', $component);
        foreach ($cells as $cellkey => $applied) {
            $prefix = self::cell_prefix($cellkey);
            $strategy = (string) ($applied['strategy'] ?? '');
            $mform->addElement('hidden', $prefix . 'key', $cellkey);
            $mform->setType($prefix . 'key', PARAM_RAW);
            $group = [];
            foreach (self::CELL_FIELDS as $field => [$level, $key]) {
                $applies = ($level === 'global' && \local_catquizlab\local\strategy_catalog::uses($strategy, 'globalmax'))
                    || ($level === 'subscale' && \local_catquizlab\local\strategy_catalog::uses_subscales($strategy))
                    || ($level === 'se' && \local_catquizlab\local\strategy_catalog::uses_standard_error($strategy));
                $current = $applied['budgets'][$level][$key] ?? null;
                $attributes = ['size' => 5, 'title' => get_string('form:cell_' . $field, $component),
                    'placeholder' => $applies
                        ? ($current === null ? '' : (\local_catquizlab\local\experiment_definition::is_unlimited($current)
                            ? get_string('budget:unlimited', $component) : (string) $current))
                        : ($level === 'global' ? get_string('form:allitems_short', $component) : $na)];
                if (!$applies) {
                    $attributes['disabled'] = 'disabled';
                }
                $group[] = $mform->createElement(
                    'text',
                    $prefix . $field,
                    get_string('form:cell_' . $field, $component),
                    $attributes
                );
            }
            $mform->addGroup(
                $group,
                $prefix . 'group',
                s(\local_catquizlab\local\experiment_service::factor_text($factors[$cellkey] ?? [])),
                ' ',
                false
            );
            foreach (array_keys(self::CELL_FIELDS) as $field) {
                $mform->setType($prefix . $field, PARAM_RAW_TRIMMED);
            }
        }
    }

    /**
     * The per-cell budgets from the submitted data (#96).
     *
     * @param array $data Submitted data.
     * @return array cellkey => budgets
     */
    protected static function per_cell_budgets(array $data): array {
        $budgets = [];
        foreach ($data as $name => $cellkey) {
            if (!preg_match('/^(cb_[0-9a-f]{10}_)key$/', (string) $name, $m)) {
                continue;
            }
            foreach (self::CELL_FIELDS as $field => [$level, $key]) {
                $raw = trim((string) ($data[$m[1] . $field] ?? ''));
                if ($raw === '') {
                    continue;
                }
                $budgets[(string) $cellkey][$level][$key] = \local_catquizlab\local\experiment_definition::is_unlimited($raw)
                    || \core_text::strtolower($raw) === \core_text::strtolower(get_string('budget:unlimited', 'local_catquizlab'))
                    ? \local_catquizlab\local\experiment_definition::UNLIMITED
                    : (is_numeric(unformat_float($raw)) ? unformat_float($raw) : $raw);
            }
        }

        return $budgets;
    }

    /**
     * The form fields of the per-cell budgets of a definition.
     *
     * @param array $normalised The definition.
     * @return array
     */
    protected static function per_cell_fields(array $normalised): array {
        $fields = [];
        foreach ((array) ($normalised['budgetsbycell'] ?? []) as $cellkey => $levels) {
            $prefix = self::cell_prefix((string) $cellkey);
            foreach (self::CELL_FIELDS as $field => [$level, $key]) {
                if (isset($levels[$level][$key])) {
                    $value = $levels[$level][$key];
                    $fields[$prefix . $field] = \local_catquizlab\local\experiment_definition::is_unlimited($value)
                        ? get_string('budget:unlimited', 'local_catquizlab')
                        : self::localised((float) $value);
                }
            }
        }

        return $fields;
    }

    /**
     * The per-strategy budget block, from the form's fields.
     *
     * Only what somebody filled in: an empty field means "use the budgets
     * above", and a strategy with four empty fields does not appear at all.
     *
     * @param array $data The submitted form data.
     * @return array<string, array>
     */
    protected static function per_strategy_budgets(array $data): array {
        $bystrategy = [];

        foreach (strategy_catalog::keys() as $key) {
            $levels = [];

            $levelfields = [
                'global'   => ['minitems' => 'globalmin', 'maxitems' => 'globalmax'],
                'subscale' => ['minitems' => 'subscalemin', 'maxitems' => 'subscalemax'],
            ];
            foreach ($levelfields as $level => $fields) {
                // No subscale budget for a strategy without subscales, whatever
                // was sent: the field is disabled in the browser, and a request
                // that fills it anyway describes nothing the engine would use.
                if ($level === 'subscale' && !strategy_catalog::uses_subscales($key)) {
                    continue;
                }
                if ($level === 'global' && !strategy_catalog::uses($key, 'globalmax')) {
                    continue;
                }
                foreach ($fields as $target => $field) {
                    $value = trim((string) ($data['perstrategy_' . $key . '_' . $field] ?? ''));
                    if ($value === '') {
                        continue;
                    }

                    $levels[$level][$target] = experiment_definition::is_unlimited($value)
                        ? experiment_definition::UNLIMITED
                        : (int) $value;
                }
            }

            if ($levels !== []) {
                $bystrategy[$key] = $levels;
            }
        }

        return $bystrategy;
    }

    /**
     * The per-strategy budget fields, from a stored definition.
     *
     * @param array $normalised The stored definition.
     * @return array<string, string>
     */
    protected static function per_strategy_fields(array $normalised): array {
        $fields = [];

        foreach ((array) ($normalised['budgetsbystrategy'] ?? []) as $key => $levels) {
            $levelfields = [
                'global'   => ['minitems' => 'globalmin', 'maxitems' => 'globalmax'],
                'subscale' => ['minitems' => 'subscalemin', 'maxitems' => 'subscalemax'],
            ];
            foreach ($levelfields as $level => $map) {
                foreach ($map as $source => $field) {
                    if (isset($levels[$level][$source])) {
                        $fields['perstrategy_' . $key . '_' . $field] = (string) $levels[$level][$source];
                    }
                }
            }
        }

        return $fields;
    }

    /**
     * A float, written the way the person's language writes it.
     *
     * @param float $value The number.
     * @return string
     */
    protected static function localised(float $value): string {
        return format_float($value, -1, true, true);
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
            'discriminationa'    => self::localised((float) ($discrimination['value']
                ?? $discrimination['meanlog'] ?? $discrimination['min'] ?? 1.0)),
            'discriminationb'    => self::localised((float) ($discrimination['sdlog'] ?? $discrimination['max'] ?? 0.3)),
            'guessingmin'        => self::localised((float) ($guessing['min'] ?? $guessing['value'] ?? 0.1)),
            'guessingmax'        => self::localised((float) ($guessing['max'] ?? 0.25)),
            'categories'         => (int) ($params['categories'] ?? 4),
            'poolcategories'     => (int) ($normalised['pool']['scales']['categories'] ?? 10),
            'poolsubcategories'  => (int) ($normalised['pool']['scales']['subcategories'] ?? 10),
            'poolitems'          => (int) ($normalised['pool']['scales']['itemspersubscale'] ?? 25),
            'variant'            => (string) ($normalised['pool']['variant'] ?? 'ideal'),
            'recipeshift'        => self::localised((float) ($recipe['shift'] ?? pool_mutator::DEFAULT_SHIFT)),
            'recipefactor'       => self::localised((float) ($recipe['factor'] ?? pool_mutator::DEFAULT_STRETCH)),
            'recipefraction'     => self::localised((float) ($recipe['fraction'] ?? 0.1)),
            'recipesd'           => self::localised((float) ($recipe['sd'] ?? 0.5)),
            'recipegapmin'       => self::localised((float) ($recipe['gapmin'] ?? -0.5)),
            'recipegapmax'       => self::localised((float) ($recipe['gapmax'] ?? 0.5)),
            'stratum'            => (string) ($normalised['persons']['stratum'] ?? 'conforming'),
            'severity'           => (string) ($normalised['persons']['severity'] ?? 'none'),
            'personcount'        => (int) ($normalised['persons']['count'] ?? 50),
            // What the experiment really does — for one saved before these
            // fields existed, the unbounded N(μ = 0, σ = 2) within ±3.
            'abilitydistribution' => \local_catquizlab\local\ability_distribution::of($normalised)['distribution'],
            'abilitymean'        => self::localised(\local_catquizlab\local\ability_distribution::of($normalised)['mean']),
            'abilitysd'          => self::localised(\local_catquizlab\local\ability_distribution::of($normalised)['sd']),
            'abilitymin'         => self::localised(\local_catquizlab\local\ability_distribution::of($normalised)['min']),
            'abilitymax'         => self::localised(\local_catquizlab\local\ability_distribution::of($normalised)['max']),
            'catsd'              => isset($normalised['persons']['variation']['category'])
                ? self::localised((float) $normalised['persons']['variation']['category']) : '',
            'subsd'              => isset($normalised['persons']['variation']['subscale'])
                ? self::localised((float) $normalised['persons']['variation']['subscale']) : '',
            'severitymild'       => self::localised((float) ($normalised['persons']['severityscale']['mild'] ?? 0.5)),
            'severitymedium'     => self::localised((float) ($normalised['persons']['severityscale']['medium'] ?? 1.0)),
            'severitystrong'     => self::localised((float) ($normalised['persons']['severityscale']['strong'] ?? 2.0)),
            'twins'              => !empty($normalised['persons']['twins']['enabled']) ? 1 : 0,
            'strategy'           => (string) ($normalised['strategy'] ?? 'fastest'),
            'globalmin'          => (int) ($normalised['budgets']['global']['minitems'] ?? 20),
            'globalmax'          => (int) ($normalised['budgets']['global']['maxitems'] ?? 25),
            'subscalemin'        => (int) ($normalised['budgets']['subscale']['minitems'] ?? 3),
            'subscalemax'        => (int) ($normalised['budgets']['subscale']['maxitems'] ?? 5),
            'semin'              => self::localised((float) ($normalised['budgets']['se']['min'] ?? 0.35)),
            'semax'              => self::localised((float) ($normalised['budgets']['se']['max'] ?? 0.75)),
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
            'pilotinclude'       => !empty($normalised['pilot']['include']) ? 1 : 0,
            'pilotratio'         => self::localised((float) ($normalised['pilot']['ratio'] ?? 20)),
        ] + self::per_strategy_fields($normalised) + self::per_cell_fields($normalised);
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
        // Only what the installed engine can play — for the strategy and for
        // the sweep alike. 0.6.94 filtered the catalogue's menu and missed
        // this one, which both selects use: "balanced" and "pilot" stayed on
        // offer and validation then refused them.
        $menu = [];
        foreach (strategy_catalog::keys() as $key) {
            if (strategy_catalog::runnable($key)) {
                $menu[$key] = strategy_catalog::label($key) . ' (' . $key . ')';
            }
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
