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
 * Tests for the engine-derived strategy catalogue (#103).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/catquizlab/tests/fixtures/stale_phantom_strategy.php');

use local_catquizlab\local\environment;
use local_catquizlab\local\strategy_catalog;
use local_catquizlab\local\test_provisioner;

/**
 * The strategy catalogue follows the engine, not a cache or a list of our own (#103).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\strategy_catalog
 */
final class strategy_engine_catalog_test extends \advanced_testcase {
    /**
     * Skip where there is no engine to compare against.
     *
     * @return void
     */
    protected function require_engine(): void {
        if (!environment::engine_available()) {
            $this->markTestSkipped('No CAT engine installed.');
        }
        strategy_catalog::reset_caches();
    }

    /**
     * A stale engine cache offering a strategy that no longer exists is not believed.
     *
     * @return void
     */
    public function test_a_stale_strategy_cache_offers_no_phantom(): void {
        $this->resetAfterTest();
        $this->require_engine();

        $cache = \cache::make('local_catquiz', 'teststrategies');
        $real = (array) \local_catquiz\teststrategy\info::return_available_strategies(true);

        // Case 1: the fingerprint differs — an engine update. The cache is
        // purged before it is read.
        $cache->set('all', array_merge($real, [new stale_phantom_strategy()]));
        set_config('enginestrategyfingerprint', 'from-an-older-engine', 'local_catquizlab');
        strategy_catalog::reset_caches();
        $this->assertNotContains(2, strategy_catalog::runnable_engine_ids());
        $this->assertSame(
            strategy_catalog::engine_fingerprint(),
            get_config('local_catquizlab', 'enginestrategyfingerprint')
        );

        // Case 2: the fingerprint matches and the cache is poisoned anyway.
        // The phantom's class is not among the engine's classes: dropped.
        $cache->set('all', array_merge($real, [new stale_phantom_strategy()]));
        strategy_catalog::reset_caches();
        $this->assertNotContains(2, strategy_catalog::runnable_engine_ids());
        $this->assertArrayNotHasKey('balanced', \local_catquizlab\form\experiment_form::strategy_menu());
        $this->assertTrue(strategy_catalog::catalogue_check()['ok']);
    }

    /**
     * The readiness check turns red when the engine and this plugin disagree.
     *
     * @return void
     */
    public function test_the_readiness_check_turns_red_on_a_difference(): void {
        $this->resetAfterTest();
        $this->require_engine();

        $this->assertTrue(strategy_catalog::catalogue_check()['ok']);

        // The engine plays a strategy this plugin has no key for.
        $strategies = strategy_catalog::engine_strategies();
        $strategies[99] = [
            'id' => 99, 'class' => 'local_catquiz\teststrategy\strategy\future',
            'description' => 'A strategy from a newer engine', 'active' => true, 'key' => null,
        ];
        $property = new \ReflectionProperty(strategy_catalog::class, 'enginestrategies');
        $property->setAccessible(true);
        $property->setValue(null, $strategies);

        $check = strategy_catalog::catalogue_check();
        $this->assertFalse($check['ok']);
        $this->assertSame([99], $check['unknown']);

        strategy_catalog::reset_caches();
    }

    /**
     * Names come from the engine; this plugin's descriptive name is an alias.
     *
     * @return void
     */
    public function test_labels_come_from_the_engine(): void {
        $this->resetAfterTest();
        $this->require_engine();

        foreach (strategy_catalog::engine_strategies() as $strategy) {
            if ($strategy['key'] !== null) {
                $this->assertSame($strategy['description'], strategy_catalog::label($strategy['key']));
            }
        }

        $this->assertSame('Fixed-form baseline', strategy_catalog::alias('classic'));
        $this->assertStringContainsString(
            get_string('strategy:notinengine', 'local_catquizlab'),
            strategy_catalog::display_label('balanced')
        );
    }

    /**
     * The capability matrix says what the engine's own form says.
     *
     * Read from the engine's source, so that a change there fails here rather
     * than quietly provisioning parameters the engine no longer uses.
     *
     * @return void
     */
    public function test_the_capability_matrix_matches_the_engine(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->require_engine();

        $source = file_get_contents($CFG->dirroot . '/local/catquiz/classes/teststrategy/info.php');
        $ids = static function (string $list): array {
            preg_match_all('/LOCAL_CATQUIZ_STRATEGY_[A-Z]+/', $list, $m);
            return array_map('constant', $m[0]);
        };

        $this->assertSame(1, preg_match('/\$strategieswithoutpilotquestions = \[([^\]]*)\]/', $source, $m));
        $nopilot = $ids($m[1]);
        $this->assertSame(1, preg_match('/\$strategieswithoutquestionsperscale = \[([^\]]*)\]/s', $source, $m));
        $noperscale = $ids($m[1]);
        $this->assertSame(1, preg_match('/!in_array\(\$ts->id, \[([^\]]*)\]/s', $source, $m));
        $withse = $ids($m[1]);

        foreach (strategy_catalog::keys() as $key) {
            if (!strategy_catalog::runnable($key)) {
                continue;
            }
            $id = strategy_catalog::engine_id($key);
            $this->assertSame(!in_array($id, $nopilot, true), strategy_catalog::uses($key, 'pilot'), $key . ': pilot');
            $this->assertSame(
                !in_array($id, $noperscale, true),
                strategy_catalog::uses($key, 'subscalemax'),
                $key . ': questions per scale'
            );
            $this->assertSame(
                in_array($id, $withse, true),
                strategy_catalog::uses($key, 'standarderror'),
                $key . ': standard error'
            );
        }
    }

    /**
     * Pilot questions are an option of their own, neutralised where they cannot apply.
     *
     * @return void
     */
    public function test_pilot_questions_are_separate_from_the_strategy(): void {
        $this->resetAfterTest();

        $definition = \local_catquizlab\local\experiment_definition::example_baseline();
        $definition['pilot'] = ['include' => true, 'ratio' => 25];

        $definition['strategy'] = 'allsubs';
        $options = test_provisioner::options_from_definition($definition);
        $this->assertTrue($options['includepilot']);
        $this->assertSame(25.0, $options['pilotratio']);
        $settings = test_provisioner::build_quizsettings('t', 1, [], $options);
        $this->assertSame('1', $settings['catquiz_includepilotquestions']);

        // The classical test cannot include pilot questions: off, and recorded
        // as neutralised rather than silently dropped.
        $definition['strategy'] = 'classic';
        $options = test_provisioner::options_from_definition($definition);
        $this->assertFalse($options['includepilot']);
        $this->assertTrue($options['pilotneutralised']);
        $this->assertSame('N/A (neutralised)', test_provisioner::effective_parameters($definition)['pilot']);

        // A share outside 0–100 is refused.
        $definition['pilot']['ratio'] = 140;
        $result = (new \local_catquizlab\local\experiment_definition($definition))->validate();
        $this->assertFalse($result['valid']);
    }

    /**
     * A historic definition naming a strategy the engine does not play is refused by name (#97).
     *
     * @return void
     */
    public function test_a_historic_strategy_is_refused_by_name(): void {
        $this->resetAfterTest();
        $this->require_engine();

        $definition = \local_catquizlab\local\experiment_definition::example_baseline();
        $definition['strategy'] = 'balanced';
        $result = (new \local_catquizlab\local\experiment_definition($definition))->validate();
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString(strategy_catalog::label('balanced'), implode(' ', $result['errors']));

        // In a sweep, too: every level is checked.
        $definition['strategy'] = 'fastest';
        $definition['sweep']['factors']['strategy'] = ['fastest', 'pilot'];
        $result = (new \local_catquizlab\local\experiment_definition($definition))->validate();
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString(strategy_catalog::label('pilot'), implode(' ', $result['errors']));

        // A playable sweep passes.
        $definition['sweep']['factors']['strategy'] = ['fastest', 'allsubs'];
        $this->assertTrue((new \local_catquizlab\local\experiment_definition($definition))->validate()['valid']);
    }
}
