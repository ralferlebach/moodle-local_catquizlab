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

use local_catquizlab\local\debug_trace;
use local_catquizlab\local\progress_view;
use local_catquizlab\local\registry;
use local_catquizlab\local\run_labels;

/**
 * Experiment, run and strategy on every run row (#150).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\run_labels
 * @covers     \local_catquizlab\local\progress_view
 */
final class run_labels_test extends \advanced_testcase {
    /**
     * Two experiments with the same strategies.
     *
     * @param int $runs Runs each.
     * @return array<string, int[]> Experiment name => run ids.
     */
    protected function twins(int $runs = 2): array {
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $out = [];
        foreach (['Pilot <b>A</b>', 'Pilot Ü ' . str_repeat('x', 60)] as $name) {
            $id = (int) $generator->create_experiment(['name' => $name])->id;
            for ($r = 0; $r < $runs; $r++) {
                $out[$name][] = (int) $generator->create_run(['experimentid' => $id, 'cellkey' => 'strategy=classic',
                    'status' => registry::STATUS_RUNNING,
                    'manifestjson' => json_encode(['config' => ['strategy' => ['key' => 'classic']]])])->id;
            }
        }

        return $out;
    }

    /**
     * AK1, AK3, AK4: the same cell in two experiments is told apart by name, escaped, long names shortened with the
     * full name kept; links to both.
     *
     * @return void
     */
    public function test_runs_of_two_experiments_are_told_apart(): void {
        $this->resetAfterTest();
        $runs = $this->twins();
        $labels = run_labels::for_runs(array_merge(...array_values($runs)));
        [$a, $b] = array_keys($runs);
        $la = $labels[$runs[$a][0]];
        $lb = $labels[$runs[$b][0]];
        $this->assertSame($la['cellkey'], $lb['cellkey'], 'the same design cell');
        $this->assertNotSame($la['experimentid'], $lb['experimentid']);
        $this->assertStringNotContainsString('<b>', $la['experimentname'], 'escaped');
        $this->assertTrue($lb['shortened']);
        $this->assertSame(run_labels::SHORT, \core_text::strlen($lb['experimentname']));
        $this->assertStringContainsString(str_repeat('x', 60), $lb['experimentfull']);
        $this->assertStringContainsString('runid=' . $runs[$a][0], $la['runurl']);
        $this->assertStringContainsString('experimentid=' . $la['experimentid'], $la['experimenturl']);
        $this->assertSame(\local_catquizlab\local\strategy_catalog::display_label('classic'), $la['strategylabel']);
    }

    /**
     * AK6: a rename shows at once; a run whose experiment is gone says so.
     *
     * @return void
     */
    public function test_rename_and_orphan(): void {
        global $DB;

        $this->resetAfterTest();
        $runs = $this->twins(1);
        $runid = reset($runs)[0];
        $experimentid = (int) $DB->get_field('local_catquizlab_run', 'experimentid', ['id' => $runid]);
        $DB->set_field('local_catquizlab_experiment', 'name', 'Renamed', ['id' => $experimentid]);
        $this->assertSame('Renamed', run_labels::for_runs([$runid])[$runid]['experimentname']);
        // Plain text for the template to escape once: "&" stays "&" here, not "&amp;" (shown as "&amp;" before).
        $DB->set_field('local_catquizlab_experiment', 'name', 'Ärger & „Öl“', ['id' => $experimentid]);
        $this->assertSame('Ärger & „Öl“', run_labels::for_runs([$runid])[$runid]['experimentfull']);
        $selector = \local_catquizlab\output\shell::context('plan', $experimentid)['experiments'];
        $this->assertContains('Ärger & „Öl“', array_column($selector, 'name'));
        $DB->delete_records('local_catquizlab_experiment', ['id' => $experimentid]);
        $this->assertStringContainsString((string) $experimentid, run_labels::for_runs([$runid])[$runid]['experimentname']);
    }

    /**
     * AK5: one query for ten runs or three hundred.
     *
     * @return void
     */
    public function test_one_query_however_many_runs(): void {
        global $DB;

        $this->resetAfterTest();
        $few = array_merge(...array_values($this->twins(5)));
        $before = $DB->perf_get_reads();
        run_labels::for_runs($few);
        $one = $DB->perf_get_reads() - $before;
        $many = array_merge(...array_values($this->twins(150)));
        $before = $DB->perf_get_reads();
        run_labels::for_runs($many);
        $this->assertSame($one, $DB->perf_get_reads() - $before);
        $this->assertSame(1, $one);
    }

    /**
     * AK1, AK2: step 3's run list carries the label on every row.
     *
     * @return void
     */
    public function test_the_step_3_list_names_the_experiment(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $runs = $this->twins();
        $rows = progress_view::context(0)['runs']['rows'];
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertNotNull($row['label']);
            $this->assertSame($row['id'], $row['label']['runid']);
            $this->assertNotSame('', $row['label']['experimentname']);
        }
    }

    /**
     * AK2, AK5: the remaining run short lists. The debug log on the progress page holds entries of every experiment:
     * each with experiment and strategy, one query for all of them; a run deleted since keeps its number. The setup
     * tab lists no single runs at all: the lists it read and never showed are gone.
     *
     * @return void
     */
    public function test_the_debug_log_names_the_experiment_and_setup_lists_no_runs(): void {
        global $CFG, $DB, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('debuglevel', debug_trace::LEVEL_VERBOSE, 'local_catquizlab');
        $runs = $this->twins(1);
        [$a, $b] = array_values($runs);
        debug_trace::record(debug_trace::UI, 'pauserun', [], 'ok', [], $a[0]);
        debug_trace::record(debug_trace::UI, 'pauserun', [], 'ok', [], $b[0]);
        debug_trace::record(debug_trace::UI, 'deleterun', [], 'ok', [], 999999);

        // Renamed after the entries were written: the log shows the name of now, escaped once.
        $DB->set_field(
            'local_catquizlab_experiment',
            'name',
            'Ärger & Öl',
            ['id' => $DB->get_field('local_catquizlab_run', 'experimentid', ['id' => $a[0]])]
        );
        $rows = progress_view::context(0)['debug']['rows'];
        $byrun = array_column($rows, null, 'runid');
        $classic = \local_catquizlab\local\strategy_catalog::display_label('classic');
        $this->assertSame([$a[0], 'Ärger & Öl', $classic], [$byrun[$a[0]]['runlabel']['runid'],
            $byrun[$a[0]]['runlabel']['experimentfull'], $byrun[$a[0]]['runlabel']['strategylabel']]);
        $this->assertNotSame($byrun[$a[0]]['runlabel']['experimentid'], $byrun[$b[0]]['runlabel']['experimentid']);
        $this->assertTrue($byrun[$b[0]]['runlabel']['shortened'], 'the long name shortened, the whole kept');
        $this->assertNull($byrun[999999]['runlabel'], 'a run deleted since: its number, no label');

        // Rendered: the run linked, the experiment linked, the whole name for screen readers, escaped once.
        $html = $PAGE->get_renderer('core')->render_from_template('local_catquizlab/progress', progress_view::context(0));
        $this->assertMatchesRegularExpression('/data-region="catquizlab-debug-run-' . $a[0] . '">\(<a href="[^"]*runid='
            . $a[0] . '"/', $html);
        $this->assertStringContainsString('>Ärger &amp; Öl</a>', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringContainsString('<span class="sr-only">' . $byrun[$b[0]]['runlabel']['experimentfull'], $html);
        $this->assertStringContainsString('#999999', $html);

        // One query for the labels, whatever the number of entries.
        for ($i = 0; $i < 40; $i++) {
            debug_trace::record(
                debug_trace::UI,
                'tick',
                [],
                'ok',
                [],
                $i % 2 ? $a[0] : $b[0]
            );
        }
        $entries = debug_trace::entries([], 60);
        $before = $DB->perf_get_reads();
        $labels = run_labels::for_runs(array_column($entries, 'runid'));
        $this->assertSame(1, $DB->perf_get_reads() - $before, 'one read for the labels of all entries');
        $this->assertCount(2, $labels);

        $context = \local_catquizlab\local\operations_view::context();
        $this->assertArrayNotHasKey('inflight', $context);
        $this->assertArrayNotHasKey('stuck', $context);
        $source = file_get_contents($CFG->dirroot . '/local/catquizlab/templates/operations.mustache');
        $this->assertDoesNotMatchRegularExpression('/\{\{[#^]?\s*runid\b/', $source, 'no run is listed on the setup tab');
    }
}
