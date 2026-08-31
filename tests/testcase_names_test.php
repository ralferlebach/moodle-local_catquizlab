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
 * Guards the test suite against names PHPUnit reserves.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

/**
 * Checks that no test helper collides with a final PHPUnit method.
 *
 * PHPUnit 10 and 11 declare a large part of TestCase final. A helper named
 * result(), status() or size() is then not a failing test but a fatal error
 * while the file is being loaded, which takes down every test in the suite —
 * and it is invisible on PHPUnit 9, which Moodle 4.5 still uses. A helper
 * called result() therefore passed locally and killed the whole run on Moodle
 * 5.0 and above.
 *
 * The list below is taken from the PHPUnit 10.5 and 11.5 sources, so it holds
 * for every release Moodle currently supports.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class testcase_names_test extends \advanced_testcase {
    /**
     * Method names PHPUnit 10 and 11 declare final on TestCase.
     *
     * @return string[]
     */
    public static function reserved_names(): array {
        return [
        'addToAssertionCount', 'any', 'atLeast', 'atLeastOnce', 'atMost', 'count', 'createConfiguredMock',
        'createConfiguredStub', 'createMock', 'createMockForIntersectionOfInterfaces', 'createPartialMock',
        'createStub', 'createStubForIntersectionOfInterfaces', 'createTestProxy', 'dataName',
        'dataSetAsString', 'dataSetAsStringWithData', 'dependencyInput', 'doesNotPerformAssertions',
        'exactly', 'expectException', 'expectExceptionCode', 'expectExceptionMessage',
        'expectExceptionMessageMatches', 'expectExceptionObject', 'expectNotToPerformAssertions',
        'expectOutputRegex', 'expectOutputString', 'expectUserDeprecationMessage',
        'expectUserDeprecationMessageMatches', 'expectsOutput', 'getActualOutputForAssertion',
        'getMockBuilder', 'getMockForAbstractClass', 'getMockForTrait', 'getMockFromWsdl',
        'getObjectForTrait', 'groups', 'hasDependencyInput', 'hasUnexpectedOutput', 'iniSet', 'name',
        'nameWithDataSet', 'never', 'numberOfAssertionsPerformed', 'onConsecutiveCalls', 'once', 'output',
        'providedData', 'provides', 'registerComparator', 'registerFailureType', 'registerMockObject',
        'registerMockObjectsFromTestArgumentsRecursively', 'requires', 'result', 'returnArgument',
        'returnCallback', 'returnSelf', 'returnValue', 'returnValueMap', 'run', 'runBare', 'runTest',
        'setBackupGlobals', 'setBackupGlobalsExcludeList', 'setBackupStaticProperties',
        'setBackupStaticPropertiesExcludeList', 'setData', 'setDependencies', 'setDependencyInput',
        'setGroups', 'setInIsolation', 'setLocale', 'setName', 'setPreserveGlobalState', 'setResult',
        'setRunClassInSeparateProcess', 'setRunTestInSeparateProcess', 'size', 'sortId', 'status',
        'throwException', 'usesDataProvider', 'valueObjectForEvents', 'wasPrepared',
        ];
    }

    /**
     * No test file declares a method PHPUnit has made final.
     *
     * @return void
     */
    public function test_no_helper_uses_a_reserved_name(): void {
        global $CFG;
        $this->resetAfterTest();

        $reserved = array_flip(self::reserved_names());
        $directory = $CFG->dirroot . '/local/catquizlab/tests';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        $offenders = [];
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            preg_match_all(
                '/^\s+(?:public|protected|private)(?: static)? function ([a-zA-Z_]+)\s*\(/m',
                $source,
                $matches
            );
            foreach ($matches[1] as $name) {
                if (isset($reserved[$name])) {
                    $offenders[] = basename($file->getPathname()) . '::' . $name . '()';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These helpers collide with a final PHPUnit method and are fatal on PHPUnit 10 and 11: '
                . implode(', ', $offenders)
        );
    }
}
