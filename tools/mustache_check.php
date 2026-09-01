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
 * Developer tool: basic Mustache template syntax checker.
 *
 * Run from the command line:
 *   php tools/mustache_check.php [<templates_dir>]
 *
 * Validates that every .mustache file under the given directory:
 *   1. Opens with the required file-level {{! ... }} docblock comment.
 *   2. Contains an "Example context (json)" block.
 *   3. Has balanced {{ / }} delimiters.
 *   4. Does not contain PHP short open tags (common copy-paste error).
 *
 * Exits with code 0 when all checks pass, code 1 on any error.
 *
 * NOT shipped with the plugin (excluded in .gitattributes export-ignore).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Developer tool: refuse to run anywhere but on a CLI. This file is excluded from release
// artefacts (.gitattributes export-ignore), but a hard guard means that even a stray copy on a
// web-reachable path cannot be invoked over HTTP.
//
// This is a standalone maintenance script, not a file included by Moodle, so a MOODLE_INTERNAL
// check would be wrong here - the SAPI guard is the correct protection.
// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This developer tool can only be run from the command line.');
}

// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState

$dir = $argv[1] ?? dirname(__DIR__) . '/templates';

if (!is_dir($dir)) {
    fwrite(STDERR, "ERROR: directory not found: {$dir}\n");
    exit(1);
}

$errors = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'mustache') {
        continue;
    }

    $path    = $file->getPathname();
    $content = file_get_contents($path);
    $name    = basename($path);
    $ok      = true;

    // 1. File-level docblock present.
    if (!preg_match('/^\{\{!/', ltrim($content))) {
        echo "ERROR [{$name}]: missing opening {{! docblock\n";
        $ok = false;
    }

    // 2. Example context JSON block present.
    if (strpos($content, 'Example context (json)') === false) {
        echo "ERROR [{$name}]: missing 'Example context (json)' block in docblock\n";
        $ok = false;
    }

    // 3. PHP short open tags.
    if (preg_match('/<\?(?!php|xml)/', $content)) {
        echo "ERROR [{$name}]: PHP short open tag '<?' found\n";
        $ok = false;
    }

    // 4. Balanced {{ / }} — rudimentary check.
    $opens  = preg_match_all('/\{\{/', $content);
    $closes = preg_match_all('/\}\}/', $content);
    if ($opens !== $closes) {
        echo "ERROR [{$name}]: unbalanced delimiters ({{ x {$opens}  }} x {$closes})\n";
        $ok = false;
    }

    if ($ok) {
        echo "OK:    {$name}\n";
    } else {
        ++$errors;
    }
}

echo "\nTotal errors: {$errors}\n";
exit($errors > 0 ? 1 : 0);
