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
 * Test provisioner: create an adaptivequiz CAT test for a run.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Creates a new adaptivequiz activity with catquiz settings for a run (E2.4).
 *
 * The engine stores the whole activity form as JSON and builds the
 * local_catquiz_tests row itself when the activity is saved (via the
 * catmodel_catquiz instance handler → catquiz_handler). So creating a CAT test
 * is a matter of assembling a valid module info — the adaptivequiz base fields
 * plus catmodel = catquiz and the catquiz_* settings — and calling
 * add_moduleinfo. The scale-selection settings are built by the pure, testable
 * {@see self::build_quizsettings()}; {@see self::create()} needs the engine and
 * host activity and is a no-op without them (CI and stand-alone stay green).
 */
class test_provisioner {
    /**
     * The fallback CAT selection strategy.
     *
     * @deprecated since 0.2.0. The strategy comes from the experiment
     * definition via {@see strategy_catalog}; a numeric default here is what
     * made every unconfigured run a weakest-subscale run. Kept so existing
     * callers do not fatal, and no longer consulted by build_quizsettings().
     * @var int
     */
    public const DEFAULT_STRATEGY = 4;

    /**
     * Translate a normalised experiment definition into provisioning options.
     *
     * This is the join the pipeline was missing: strategy, item budgets and SE
     * bounds are experimental factors, so they have to come from the definition
     * rather than from defaults in this class. Anything the definition does not
     * fix (the naming of the activity, the timing penalty) may still default.
     *
     * @param array $definition The normalised experiment definition.
     * @param array $extra Options that are not part of the definition, e.g. 'name'.
     * @return array Options for {@see self::build_quizsettings()} and {@see self::create()}.
     */
    public static function options_from_definition(array $definition, array $extra = []): array {
        $budgets = (array) ($definition['budgets'] ?? []);
        $global = (array) ($budgets['global'] ?? []);
        $subscale = (array) ($budgets['subscale'] ?? []);
        $se = (array) ($budgets['se'] ?? []);
        $strategy = (string) ($definition['strategy'] ?? 'fastest');

        return $extra + [
            'strategykey'             => $strategy,
            'teststrategy'            => strategy_catalog::engine_id($strategy),
            'minquestions'            => (int) ($global['minitems'] ?? 10),
            'maxquestions'            => (int) ($global['maxitems'] ?? 15),
            'minquestionspersubscale' => (int) ($subscale['minitems'] ?? 3),
            'maxquestionspersubscale' => (int) ($subscale['maxitems'] ?? 4),
            'se_min'                  => (float) ($se['min'] ?? 0.35),
            'se_max'                  => (float) ($se['max'] ?? 1.0),
        ];
    }

    /**
     * The effective CAT parameters of a run, for the manifest.
     *
     * The relation the article uses between a precision target and the
     * information needed to reach it is I_target = 1 / SE_target^2. The engine
     * applies it; the lab only has to hand over the right SE bounds and record
     * what it handed over, which is what this returns.
     *
     * @param array $definition The normalised experiment definition.
     * @return array The effective parameters, ready to be serialised.
     */
    public static function effective_parameters(array $definition): array {
        $options = self::options_from_definition($definition);
        $semin = (float) $options['se_min'];
        $semax = (float) $options['se_max'];

        return [
            'strategy'         => [
                'key'      => $options['strategykey'],
                'engineid' => $options['teststrategy'],
                'label'    => strategy_catalog::label($options['strategykey']),
            ],
            'budgets'          => [
                'global'   => [
                    'minitems' => $options['minquestions'],
                    'maxitems' => $options['maxquestions'],
                ],
                'subscale' => [
                    'minitems' => $options['minquestionspersubscale'],
                    'maxitems' => $options['maxquestionspersubscale'],
                ],
            ],
            'se'               => ['min' => $semin, 'max' => $semax],
            'targetinformation' => [
                'min' => $semax > 0 ? round(1.0 / ($semax * $semax), 5) : null,
                'max' => $semin > 0 ? round(1.0 / ($semin * $semin), 5) : null,
            ],
        ];
    }

    /**
     * Build the catquiz settings fields for the activity form.
     *
     * @param string $name The test name.
     * @param int $catscaleid The root CAT scale id.
     * @param int[] $subscaleids Subscale ids to activate.
     * @param array $options min/max questions, se bounds, per-subscale bounds, strategy.
     * @return array The catquiz_* settings (nested groups included).
     */
    public static function build_quizsettings(string $name, int $catscaleid, array $subscaleids, array $options = []): array {
        $settings = [
            'name'                                   => $name,
            'catmodel'                               => 'catquiz',
            'catquiz_catscales'                      => (string) $catscaleid,
            'catquiz_selectteststrategy'             => (string) ($options['teststrategy']
                ?? strategy_catalog::engine_id((string) ($options['strategykey'] ?? 'fastest'))),
            'catquiz_selectfirstquestion'            => (string) ($options['selectfirstquestion'] ?? '0'),
            'catquiz_includepilotquestions'          => '0',
            'catquiz_firstquestionreuseexistingdata' => '1',
            'catquiz_includetimelimit'               => '0',
            'catquiz_pp_min_inc'                     => $options['pp_min_inc'] ?? 0.01,
            'maxquestionsgroup'                      => [
                'catquiz_minquestions' => (int) ($options['minquestions'] ?? 10),
                'catquiz_maxquestions' => (int) ($options['maxquestions'] ?? 15),
            ],
            'maxquestionsscalegroup'                 => [
                'catquiz_minquestionspersubscale' => (int) ($options['minquestionspersubscale'] ?? 3),
                'catquiz_maxquestionspersubscale' => (int) ($options['maxquestionspersubscale'] ?? 4),
            ],
            'catquiz_standarderrorgroup'             => [
                'catquiz_standarderror_min' => (float) ($options['se_min'] ?? 0.35),
                'catquiz_standarderror_max' => (float) ($options['se_max'] ?? 1.0),
            ],
            // PF(t): the last-time-played penalty. Default 1 (active) per the design;
            // set 'timepenalty' => false to switch it off for a baseline/operative run.
            'catquiz_lasttimeplayedpenalty'          => (($options['timepenalty'] ?? true) ? '1' : '0'),
        ];

        // Activate the root scale and each requested subscale.
        $settings['catquiz_subscalecheckbox_' . $catscaleid] = '1';
        foreach ($subscaleids as $subscaleid) {
            $settings['catquiz_subscalecheckbox_' . (int) $subscaleid] = '1';
        }

        // The feedback configuration. The engine reads it whenever it builds an
        // attempt's feedback — including at the very first question — and a
        // missing number of ranges leaves it without the structure it needs.
        // A lab test shows no feedback to anyone, but the settings still have
        // to describe a valid one.
        $settings += self::feedback_settings(
            array_merge([$catscaleid], array_map('intval', $subscaleids)),
            $options
        );

        return $settings;
    }

    /**
     * The feedback ranges of every scale of a test.
     *
     * The engine divides each scale's ability range into a number of bands and
     * expects, per scale and band, a lower and an upper limit. It reads them
     * through `$quizsettings->numberoffeedbackoptionsselect` and the
     * `feedback_scaleid_limit_*` keys; without them there is nothing to build a
     * feedback structure from.
     *
     * The bands are spread evenly over the scale range, which is the neutral
     * choice for an experiment: the lab does not interpret abilities, it
     * measures them, and any other split would state a judgement the study has
     * not made.
     *
     * @param int[] $scaleids Every scale of the test, root first.
     * @param array $options Optional 'feedbackranges', 'scalemin', 'scalemax'.
     * @return array The feedback settings.
     */
    protected static function feedback_settings(array $scaleids, array $options = []): array {
        $ranges = max(2, (int) ($options['feedbackranges'] ?? 2));
        $min = (float) ($options['scalemin'] ?? -3.0);
        $max = (float) ($options['scalemax'] ?? 3.0);
        $step = ($max - $min) / $ranges;

        $settings = ['numberoffeedbackoptionsselect' => (string) $ranges];

        foreach ($scaleids as $scaleid) {
            $scaleid = (int) $scaleid;
            // Report every scale: a lab run wants the per-scale abilities the
            // engine only computes for scales it is asked to report on.
            $settings['catquiz_scalereportcheckbox_' . $scaleid] = '1';

            for ($i = 1; $i <= $ranges; $i++) {
                $lower = $min + ($i - 1) * $step;
                $upper = $min + $i * $step;

                $settings['feedback_scaleid_limit_lower_' . $scaleid . '_' . $i] = (string) $lower;
                $settings['feedback_scaleid_limit_upper_' . $scaleid . '_' . $i] = (string) $upper;
                $settings['feedbackeditor_scaleid_' . $scaleid . '_' . $i] = [
                    'text'   => '',
                    'format' => FORMAT_HTML,
                ];
                $settings['feedbacklegend_scaleid_' . $scaleid . '_' . $i] = '';
                $settings['wb_colourpicker_' . $scaleid . '_' . $i] = (string) $i;
                $settings['enrolment_message_checkbox_' . $scaleid . '_' . $i] = '0';
            }
        }

        return $settings;
    }

    /**
     * Create the adaptivequiz CAT test in the run's course and bind it to the run.
     *
     * @param int $runid The run to create the test for.
     * @param int $catscaleid The root CAT scale id.
     * @param int[] $subscaleids Subscale ids to activate.
     * @param array $options Optional settings (see build_quizsettings) plus 'name'.
     * @return int|null The new course-module id, or null when unavailable.
     */
    public static function create(int $runid, int $catscaleid, array $subscaleids, array $options = []): ?int {
        global $DB, $CFG;

        if (!environment::engine_available() || !environment::adaptivequiz_available()) {
            return null;
        }

        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid], '*', MUST_EXIST);
        if (empty($run->courseid)) {
            return null;
        }
        $moduleid = $DB->get_field('modules', 'id', ['name' => 'adaptivequiz']);
        if (!$moduleid) {
            return null;
        }

        require_once($CFG->dirroot . '/course/modlib.php');

        $name = $options['name'] ?? ('CATLab test ' . $runid);
        $course = get_course((int) $run->courseid);
        $moduleinfo = self::build_moduleinfo(
            $name,
            (int) $moduleid,
            $course,
            self::build_quizsettings($name, $catscaleid, $subscaleids, $options),
            $options
        );

        $created = add_moduleinfo($moduleinfo, $course);
        $DB->set_field('local_catquizlab_run', 'testcmid', $created->coursemodule, ['id' => $runid]);

        return (int) $created->coursemodule;
    }

    /**
     * Assemble the module info for add_moduleinfo (adaptivequiz base + catquiz).
     *
     * @param string $name The test name.
     * @param int $moduleid The adaptivequiz module id.
     * @param \stdClass $course The target course.
     * @param array $quizsettings The catquiz settings from build_quizsettings().
     * @param array $options Optional overrides (minquestions, maxquestions, ...).
     * @return \stdClass
     */
    protected static function build_moduleinfo(
        string $name,
        int $moduleid,
        \stdClass $course,
        array $quizsettings,
        array $options
    ): \stdClass {
        $base = (object) [
            'modulename'      => 'adaptivequiz',
            'module'          => $moduleid,
            'course'          => $course->id,
            // The experiment's own section, not section 0: every run activity
            // of an experiment belongs together, and a shared course with
            // everything in section 0 is unreadable after two sweeps.
            'section'         => (int) ($options['section'] ?? 0),
            'visible'         => 1,
            'cmidnumber'      => '',
            'name'            => $name,
            'intro'           => $options['intro'] ?? '',
            'introformat'     => FORMAT_HTML,
            // The adaptivequiz module declares these NOT NULL without a default, and
            // add_moduleinfo() passes the module info straight to the database.
            // Leaving them out failed the insert on the first real run; without
            // the host activity installed nothing had ever exercised this.
            'attemptfeedback'       => '',
            'attemptfeedbackformat' => FORMAT_HTML,
            'attempts'        => 0,
            'password'        => '',
            'browsersecurity' => 0,
            'highestlevel'    => (int) ($options['highestlevel'] ?? 100),
            'lowestlevel'     => (int) ($options['lowestlevel'] ?? 1),
            'startinglevel'   => (int) ($options['startinglevel'] ?? 50),
            'minimumquestions' => $quizsettings['maxquestionsgroup']['catquiz_minquestions'],
            'maximumquestions' => $quizsettings['maxquestionsgroup']['catquiz_maxquestions'],
            'standarderror'   => 0,
            'grademethod'     => 1,
        ];

        foreach ($quizsettings as $key => $value) {
            $base->$key = $value;
        }

        return $base;
    }
}
