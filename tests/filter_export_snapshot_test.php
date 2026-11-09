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

namespace local_catquizlab;

use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\results_export;
use local_catquizlab\local\results_query;

/**
 * The download of a selection is the selection, for each of the six factor filters (#140, #122).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\results_export
 * @covers     \local_catquizlab\local\results_query
 */
final class filter_export_snapshot_test extends \advanced_testcase {
    /**
     * Two values per factor: how each is written into a run.
     *
     * @return array<string, array{0: string, 1: string, 2: callable}>
     */
    protected static function factors(): array {
        return [
            'tier' => ['core', 'extended', static function (array &$m, array &$d, string $v): void {
                $m['config']['experiment']['tier'] = $v;
            }],
            'model' => ['2pl', '1pl', static function (array &$m, array &$d, string $v): void {
                $d['model'] = $v;
            }],
            'strategy' => ['fastest', 'classic', static function (array &$m, array &$d, string $v): void {
                $d['strategy'] = $v;
            }],
            'variant' => ['ideal', 'miscalibrated', static function (array &$m, array &$d, string $v): void {
                $d['pool']['variant'] = $v;
            }],
            'stratum' => ['conforming', 'deficit', static function (array &$m, array &$d, string $v): void {
                $d['persons']['stratum'] = $v;
            }],
            'severity' => ['none', 'mild', static function (array &$m, array &$d, string $v): void {
                $d['persons']['severity'] = $v;
            }],
        ];
    }

    /**
     * Runs with value a (two runs) and value b (one run), three collected sittings each.
     *
     * @param callable $apply How a value is written into a run.
     * @param string $a The chosen value.
     * @param string $b The other value.
     * @return array{experimentid: int, chosen: int[], other: int[]}
     */
    protected function experiment(callable $apply, string $a, string $b): array {
        global $DB;

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $experiment = $generator->create_experiment();
        $out = ['experimentid' => (int) $experiment->id, 'chosen' => [], 'other' => []];
        foreach ([$a, $a, $b] as $value) {
            $definition = experiment_definition::example_baseline();
            $definition['budgets']['global']['minitems'] = 1;
            $manifest = [];
            $apply($manifest, $definition, $value);
            $manifest['config']['definition'] = $definition;
            $run = $generator->create_run(['experimentid' => $experiment->id, 'manifestjson' => json_encode($manifest)]);
            $out[$value === $a ? 'chosen' : 'other'][] = (int) $run->id;
            for ($i = 0; $i < 3; $i++) {
                $person = $generator->create_person(['runid' => $run->id, 'twinid' => sprintf('t%05d', $i + 1),
                    'abilityglobal' => 0.5 * $i]);
                $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $run->id, 'personid' => $person->id,
                    'status' => 30, 'tries' => 1, 'runtimems' => 1000, 'timecreated' => time(), 'timemodified' => time(),
                    'tracejson' => json_encode(['finaltheta' => 0.5 * $i, 'finalse' => 0.3, 'items' => [101, 102],
                        'responses' => [101 => 1.0, 102 => 0.0], 'nitems' => 2, 'stopreason' => 'standarderror'])]);
            }
        }

        return $out;
    }

    /**
     * The six filters.
     *
     * @return array
     */
    public static function filters(): array {
        return array_combine(array_keys(self::factors()), array_map(
            static fn(string $f): array => [$f],
            array_keys(self::factors())
        ));
    }

    /**
     * Filtered by one factor: every level of the download holds the selected runs and only them, in CSV and JSON,
     * and says which filter made it.
     *
     * @dataProvider filters
     * @param string $factor The factor filtered on.
     * @return void
     */
    public function test_the_download_is_the_selection(string $factor): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$a, $b, $apply] = self::factors()[$factor];
        $experiment = $this->experiment($apply, $a, $b);
        $filter = ['experimentid' => $experiment['experimentid'], $factor => $a, 'validity' => 'all'];
        $query = new results_query($filter);

        // The selection on screen.
        $this->assertEqualsCanonicalizing($experiment['chosen'], array_map('intval', array_keys($query->runs())));

        // Run level: the two runs of value a.
        $runs = results_export::dataset(new results_query($filter), results_export::LEVEL_RUN);
        $this->assertEqualsCanonicalizing($experiment['chosen'], array_map('intval', array_column($runs['rows'], 'runid')));

        // Sitting level: their six sittings, none of the other run, each labelled with the chosen value.
        $sittings = results_export::dataset(new results_query($filter), results_export::LEVEL_ATTEMPT);
        $this->assertCount(6, $sittings['rows']);
        $this->assertSame([], array_values(array_diff(
            array_map('intval', array_column($sittings['rows'], 'runid')),
            $experiment['chosen']
        )), $factor . ': no sitting of another value');
        if ($factor !== 'model') {
            // The model column carries the engine's key, not the definition's.
            $this->assertSame([$a], array_values(array_unique(array_column($sittings['rows'], $factor))));
        }

        // The CSV download: the same rows, a header.
        $csv = results_export::to_csv(results_export::dataset(new results_query($filter), results_export::LEVEL_ATTEMPT));
        $this->assertCount(7, array_filter(explode("\n", $csv)), $factor . ': CSV header and six rows');

        // The JSON download: the same rows, and its metadata names the filter.
        $json = json_decode(results_export::to_json(new results_query($filter), results_export::LEVEL_ATTEMPT), true);
        $this->assertCount(6, $json['data']);
        $this->assertSame($a, $json['metadata']['filter'][$factor]);
        $this->assertSame($experiment['experimentid'], (int) $json['metadata']['filter']['experimentid']);

        // Without the filter, all nine: the filter is what removed the other three.
        $all = results_export::dataset(new results_query(['experimentid' => $experiment['experimentid'],
            'validity' => 'all']), results_export::LEVEL_ATTEMPT);
        $this->assertCount(9, $all['rows']);
    }
}
