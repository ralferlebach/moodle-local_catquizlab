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
 * Developer tool: batch-fix package docblock annotations in PHP files.
 *
 * Scans all *.php files under the given directory and ensures every file
 * docblock contains the correct package tag for local_catquizlab.
 *
 * Run from the command line:
 *   php tools/fix_phpdoc.php [<plugin_dir>]
 *
 * If <plugin_dir> is omitted, the parent directory of this script is used.
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

$plugindir = $argv[1] ?? dirname(__DIR__);

if (!is_dir($plugindir)) {
    fwrite(STDERR, "ERROR: directory not found: {$plugindir}\n");
    exit(1);
}

$component = 'local_catquizlab';
$iterator  = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($plugindir, FilesystemIterator::SKIP_DOTS)
);

$fixed   = 0;
$skipped = 0;

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    if (
        strpos($path, DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR) !== false
        || strpos($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) !== false
    ) {
        continue;
    }

    $content = file_get_contents($path);

    if (strpos($content, "@package    {$component}") !== false) {
        ++$skipped;
        continue;
    }

    $updated = preg_replace(
        '/@package\s+\S+/',
        "@package    {$component}",
        $content
    );

    if ($updated !== null && $updated !== $content) {
        file_put_contents($path, $updated);
        echo "FIXED: {$path}\n";
        ++$fixed;
    } else {
        echo "WARN:  no @package tag found in {$path}\n";
        ++$skipped;
    }
}

echo "\nDone. Fixed: {$fixed}  Skipped/unchanged: {$skipped}\n";
