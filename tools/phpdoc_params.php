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
 * Check that every documented function lists its parameters exactly: each once, in order.
 *
 * The rule local_moodlecheck reports as "Phpdocs for function ... has incomplete parameters
 * list". It failed the release CI on 16.10.2026 (a parameter documented twice, one missing) while the
 * local `make check` skipped the PHPDoc step for want of moodle-plugin-ci. This script needs
 * nothing but PHP, so the local gate catches it too.
 *
 * Usage: php tools/phpdoc_params.php [dir]   (default: the plugin directory)
 * Exit code 1 when a docblock does not match its signature.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Standalone developer tool, not a file included by Moodle, so a MOODLE_INTERNAL check would be wrong.
// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This developer tool can only be run from the command line.');
}

$root = $argv[1] ?? dirname(__DIR__);
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$skip = [T_WHITESPACE, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL];
$mismatches = 0;

foreach ($files as $file) {
    $path = $file->getPathname();
    if (substr($path, -4) !== '.php' || preg_match('#/(node_modules|vendor|tests/playwright)/#', $path)) {
        continue;
    }
    $tokens = token_get_all(file_get_contents($path));
    $count = count($tokens);
    $doc = null;
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (is_array($token) && $token[0] === T_DOC_COMMENT) {
            $doc = $token;
            continue;
        }
        if (is_array($token) && in_array($token[0], $skip, true)) {
            continue;
        }
        if (!is_array($token) || $token[0] !== T_FUNCTION || $doc === null) {
            $doc = null;
            continue;
        }
        // A named function or method; closures are not documented this way.
        $j = $i + 1;
        while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($tokens[$j] === '&') {
            $j++;
        }
        if (!is_array($tokens[$j])) {
            $doc = null;
            continue;
        }
        $name = $tokens[$j][1];
        while ($tokens[$j] !== '(') {
            $j++;
        }
        $depth = 0;
        $params = [];
        for (; $j < $count; $j++) {
            $t = $tokens[$j];
            if ($t === '(') {
                $depth++;
            } else if ($t === ')') {
                if (--$depth === 0) {
                    break;
                }
            } else if (is_array($t) && $t[0] === T_VARIABLE && $depth === 1) {
                $params[] = $t[1];
            }
        }
        if (strpos($doc[1], '@param') !== false) {
            preg_match_all('/@param\s+(?:[^\s$]+\s+)?(?:\.\.\.)?(\$\w+)/', $doc[1], $m);
            if ($m[1] !== $params) {
                $mismatches++;
                printf(
                    "%s:%d %s(): @param [%s], signature [%s]\n",
                    $path,
                    $doc[2],
                    $name,
                    implode(', ', $m[1]),
                    implode(', ', $params)
                );
            }
        }
        $doc = null;
    }
}

echo $mismatches === 0 ? "phpdoc parameters: OK\n" : "phpdoc parameters: {$mismatches} mismatch(es)\n";
exit($mismatches === 0 ? 0 : 1);
