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

namespace local_catquizlab;

/**
 * The mode comparison plot speaks German as it speaks English (#144, DE/EN accessibility, re-audit 18.11.2026).
 *
 * The browser tests run on installations with English only (no German language pack on the CI runners), so what a
 * screen reader is told in German is checked here, from the plugin's own language files: every string the plot puts
 * into its title, its axes, its description, its notes, its tooltips and its keyboard help exists in German, is not the
 * English text, keeps every placeholder of the English one, and says what the English one says in the words a German
 * reader expects (Subskala, Twin, Bearbeitung, gültig, Logit).
 *
 * @package    local_catquizlab
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class mode_plot_language_test extends \advanced_testcase {
    /** @var string[] Every string of the mode comparison plot, as results_page and scatter_chart use them. */
    private const KEYS = ['mode:plottitle', 'mode:plotx', 'mode:plotlabel', 'mode:plotpoint', 'mode:plotn',
        'mode:plotbelow', 'mode:meandifference', 'chart:identity', 'chart:densitymarkers', 'chart:pointkeys',
        'chart:roledescription', 'chart:quantity', 'chart:downloadmenu', 'chart:downloadsvg', 'chart:downloadpng',
        'chart:downloadpdf', 'chart:downloadcsv', 'chart:downloadsettings'];

    /**
     * The strings of one language file.
     *
     * @param string $lang en or de.
     * @return array<string, string>
     */
    private function strings(string $lang): array {
        $string = [];
        include(__DIR__ . '/../lang/' . $lang . '/local_catquizlab.php');

        return $string;
    }

    /**
     * Every key in both languages, German not English, the same placeholders.
     *
     * @return void
     */
    public function test_every_string_of_the_plot_is_german_with_the_same_placeholders(): void {
        $en = $this->strings('en');
        $de = $this->strings('de');
        foreach (self::KEYS as $key) {
            $this->assertArrayHasKey($key, $en, $key);
            $this->assertArrayHasKey($key, $de, $key . ' has no German text');
            // A formula is the same in every language ("y = x"); everything else must be German.
            if (!preg_match('/^[a-z] = [a-z]$/', $en[$key])) {
                $this->assertNotSame($en[$key], $de[$key], $key . ' is English in the German file');
            }
            preg_match_all('/\{\$a(->[a-z]+)?\}/', $en[$key], $m1);
            preg_match_all('/\{\$a(->[a-z]+)?\}/', $de[$key], $m2);
            $placeholders = array_unique($m1[0]);
            sort($placeholders);
            $german = array_unique($m2[0]);
            sort($german);
            $this->assertSame($placeholders, $german, $key . ': the German text lost or added a placeholder');
        }
    }

    /**
     * What a German screen reader hears for one point and for the plot says what the English says.
     *
     * @return void
     */
    public function test_the_german_point_and_description_name_the_same_things(): void {
        $de = $this->strings('de');
        $this->assertMatchesRegularExpression('/Subskala/', $de['mode:plotlabel']);
        $this->assertMatchesRegularExpression('/Twin/', $de['mode:plotlabel']);
        $this->assertMatchesRegularExpression('/Bearbeitung/', $de['mode:plotlabel']);
        $this->assertMatchesRegularExpression('/gültig/', $de['mode:plotlabel']);
        $this->assertMatchesRegularExpression('/Subskalenbeobachtung/', $de['mode:plotpoint']);
        $this->assertMatchesRegularExpression('/Logit/', $de['mode:plotpoint']);
        $this->assertMatchesRegularExpression('/Δ̂s − Δs,wahr/u', $de['mode:plotpoint']);
        $this->assertMatchesRegularExpression('/Punkte/', $de['mode:plotn']);
    }
}
