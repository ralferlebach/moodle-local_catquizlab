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
 * Export a run's item pool for inspection on another site.
 *
 * Writes the questions as Moodle XML, importable into any question bank, and
 * the item parameters as CSV. The two together are what someone needs to
 * reproduce a pool elsewhere: the questions alone are unusable for CAT, and the
 * parameters alone describe items that do not exist.
 *
 * The CSV carries the lab's ground truth beside the values the engine was
 * given, so a disturbed pool can be told from an ideal one without consulting
 * the experiment definition.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/format/xml/format.php');

[$options, $unrecognised] = cli_get_params(
    [
        'help'  => false,
        'runid' => 0,
        'dir'   => '',
    ],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(', ', $unrecognised)));
}

if ($options['help'] || !$options['runid']) {
    cli_writeln(<<<'TXT'
Export a run's item pool.

Writes two files:
  pool-run<N>-questions.xml   Moodle question XML, importable into a question bank
  pool-run<N>-items.csv       item parameters, with the lab's ground truth beside
                              the values the engine was given
  pool-run<N>-scales.csv      the scale tree, with the item count per scale

Options:
  --runid=N   The run whose pool is exported (required).
  --dir=PATH  Where to write (default: the Moodle temp directory).
  -h, --help  Show this help.
TXT);
    exit(0);
}

$runid = (int) $options['runid'];
$run = $DB->get_record('local_catquizlab_run', ['id' => $runid], '*', MUST_EXIST);

$items = $DB->get_records('local_catquizlab_item', ['runid' => $runid], 'id ASC');
if (!$items) {
    cli_error('Run ' . $runid . ' has no items.');
}

$directory = $options['dir'] !== '' ? $options['dir'] : $CFG->tempdir;
if (!is_dir($directory)) {
    mkdir($directory, 0777, true);
}

// The questions, as Moodle XML. writequestion() produces one <question> block
// per question; the surrounding document is assembled here so the file imports
// as a whole.
$qformat = new qformat_xml();
$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<quiz>\n";
$exported = 0;

foreach ($items as $item) {
    $questiondata = question_bank::load_question_data((int) $item->questionid);
    if (!$questiondata) {
        cli_writeln('  skipped question ' . $item->questionid . ' (not found)');
        continue;
    }
    $block = $qformat->writequestion($questiondata);
    if ($block !== false && $block !== null) {
        $xml .= $block;
        $exported++;
    }
}
$xml .= "</quiz>\n";

$xmlpath = $directory . '/pool-run' . $runid . '-questions.xml';
file_put_contents($xmlpath, $xml);

// The parameters. The engine's stored values come from its own tables rather
// than from the lab's record, so the file shows what the engine actually holds.
$columns = [
    'itemname', 'questionid', 'engineitemid', 'model',
    'true_difficulty', 'stored_difficulty', 'engine_difficulty',
    'discrimination', 'guessing', 'engine_status', 'is_known_parameter',
    'true_catscaleid', 'assigned_catscaleid', 'catscale_name',
    'category_index', 'subscale_index', 'miscalibrated', 'mistagged',
];
$rows = [];

foreach ($items as $item) {
    $engineitem = $DB->get_record('local_catquiz_items', [
        'componentid'   => (int) $item->questionid,
        'componentname' => 'question',
        'catscaleid'    => (int) $item->assignedcatscaleid,
    ]);
    $param = $engineitem && !empty($engineitem->activeparamid)
        ? $DB->get_record('local_catquiz_itemparams', ['id' => (int) $engineitem->activeparamid])
        : null;

    $rows[] = [
        'itemname'            => (string) $item->itemname,
        'questionid'          => (int) $item->questionid,
        'engineitemid'        => $engineitem ? (int) $engineitem->id : '',
        'model'               => (string) $item->model,
        'true_difficulty'     => $item->truedifficulty,
        'stored_difficulty'   => $item->storeddifficulty,
        'engine_difficulty'   => $param ? $param->difficulty : '',
        'discrimination'      => $item->discrimination,
        'guessing'            => $item->guessing,
        'engine_status'       => $param ? $param->status : '',
        // The distinction that decides whether the engine learns from an item:
        // below UPDATED_MANUALLY it is treated as a pilot question.
        'is_known_parameter'  => $param && (int) $param->status >= 4 ? '1' : '0',
        'true_catscaleid'     => (int) $item->truecatscaleid,
        'assigned_catscaleid' => (int) $item->assignedcatscaleid,
        'catscale_name'       => (string) $DB->get_field(
            'local_catquiz_catscales',
            'name',
            ['id' => (int) $item->assignedcatscaleid]
        ),
        'category_index'      => (int) $item->truecategory,
        'subscale_index'      => (int) $item->truesubscale,
        'miscalibrated'       => (int) $item->miscalibrated,
        'mistagged'           => (int) $item->mistagged,
    ];
}

$csv = implode(',', $columns) . "\n";
foreach ($rows as $row) {
    $cells = [];
    foreach ($columns as $column) {
        $value = $row[$column];
        $cells[] = is_numeric($value) || $value === ''
            ? (string) $value
            : '"' . str_replace('"', '""', (string) $value) . '"';
    }
    $csv .= implode(',', $cells) . "\n";
}

$csvpath = $directory . '/pool-run' . $runid . '-items.csv';
file_put_contents($csvpath, $csv);

// The scale tree. Without it the catscaleid columns above mean nothing on
// another site, and the shape of the tree is exactly what one wants to look at.
$scalecolumns = ['level', 'catscaleid', 'parentid', 'name', 'category_index', 'subscale_index', 'direct_items'];
$scalecsv = implode(',', $scalecolumns) . "\n";
foreach ($DB->get_records('local_catquizlab_scalemap', ['runid' => $runid], 'level ASC, id ASC') as $map) {
    $scale = $DB->get_record('local_catquiz_catscales', ['id' => (int) $map->catscaleid]);
    $scalecsv .= implode(',', [
        (int) $map->level,
        (int) $map->catscaleid,
        $scale ? (int) $scale->parentid : '',
        '"' . str_replace('"', '""', (string) ($scale->name ?? '')) . '"',
        $map->categoryindex ?? '',
        $map->subscaleindex ?? '',
        $DB->count_records('local_catquiz_items', ['catscaleid' => (int) $map->catscaleid]),
    ]) . "\n";
}
$scalepath = $directory . '/pool-run' . $runid . '-scales.csv';
file_put_contents($scalepath, $scalecsv);

cli_writeln('Run ' . $runid . ', ' . count($items) . ' item(s)');
cli_writeln('  questions: ' . $xmlpath . ' (' . $exported . ' exported)');
cli_writeln('  parameters: ' . $csvpath);
cli_writeln('  scales: ' . $scalepath);

// A short summary of what the engine holds, since that is the point of looking.
$known = 0;
foreach ($rows as $row) {
    $known += (int) $row['is_known_parameter'];
}
cli_writeln('  parameters the engine treats as known: ' . $known . ' of ' . count($rows));

exit(0);
