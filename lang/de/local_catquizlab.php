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
 * German language strings for local_catquizlab.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['catquizlab:manage'] = 'CAT-Experimenten-Suite verwalten (Experimente anlegen, ausführen und löschen)';
$string['catquizlab:view'] = 'Ergebnisse der CAT-Experimente ansehen';
$string['env:adaptivequizfound'] = 'Trägeraktivität mod_adaptivequiz: installiert.';
$string['env:adaptivequizmissing'] = 'Trägeraktivität mod_adaptivequiz: NICHT installiert — Experimente können auf dieser Instanz nicht laufen.';
$string['env:catquizfound'] = 'CAT-Engine local_catquiz: installiert.';
$string['env:catquizmissing'] = 'CAT-Engine local_catquiz: NICHT installiert — Experimente können auf dieser Instanz nicht laufen.';
$string['instancerole:hub'] = 'Hub (zentrale Nachberechnung)';
$string['instancerole:node'] = 'Node (führt Experimente aus)';
$string['pluginname'] = 'CAT-Experimenten-Suite';
$string['privacy:metadata'] = 'Der Stub der CAT-Experimenten-Suite speichert ausschließlich Experiment-Definitionen (Name, Tier, Konfiguration) und keine personenbezogenen Daten. Das ändert sich, sobald der Lab-Store Simulanten-Kohorten und Attempt-Traces erhält; der Privacy-Provider wird dann entsprechend erweitert.';
$string['setting:enabled'] = 'Experimentläufe aktivieren';
$string['setting:enabled_desc'] = 'Hauptschalter. Solange deaktiviert, finden weder Provisionierung noch Task-Planung oder Worker-Ansteuerung statt. Auf allen Instanzen, die kein dediziertes Testsystem sind, deaktiviert lassen.';
$string['setting:environment'] = 'Umgebungsstatus';
$string['setting:instancerole'] = 'Instanz-Rolle';
$string['setting:instancerole_desc'] = 'Node: Diese Instanz provisioniert Experimente, führt sie aus und sammelt die Daten. Hub: Diese Instanz nimmt Run-Pakete von Nodes entgegen und rechnet die Auswertung mit identischer Metrik-Bibliothek zentral nach.';
