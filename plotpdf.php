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
 * A plot as a PDF, with its drawing as vectors (#108).
 *
 * Renders only a drawing this plugin made for this user in this session and
 * kept under the given key — never SVG sent by a browser.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/pdflib.php');

require_login();
require_sesskey();
$context = context_system::instance();
require_capability('local/catquizlab:view', $context);

$key = required_param('key', PARAM_ALPHANUM);
$kept = (array) ($SESSION->local_catquizlab_plots ?? []);
if (!isset($kept[$key]['svg'])) {
    throw new moodle_exception('chart:pdfgone', 'local_catquizlab');
}
$svg = (string) $kept[$key]['svg'];
$name = clean_filename((string) ($kept[$key]['name'] ?? 'catquizlab-plot'));

// The drawing's own proportions on a landscape A4 page.
$width = 640;
$height = 380;
if (preg_match('/viewBox="0 0 (\d+) (\d+)"/', $svg, $m)) {
    [$width, $height] = [(int) $m[1], (int) $m[2]];
}
$pdf = new pdf('L', 'mm', 'A4');
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(10, 10, 10);
$pdf->AddPage();
$w = 277;
$h = $w * $height / max(1, $width);
if ($h > 190) {
    $h = 190;
    $w = $h * $width / max(1, $height);
}
// Metadata as text is not part of the drawing: removed for the PDF renderer.
$pdf->ImageSVG('@' . preg_replace('/<metadata>.*?<\/metadata>/s', '', $svg), 10, 10, $w, $h);
$pdf->Output($name . '.pdf', 'D');
