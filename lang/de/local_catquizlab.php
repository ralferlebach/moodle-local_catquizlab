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

$string['catquizlab:hubtransfer'] = 'Run-Pakete zwischen Node und zentralem Hub übertragen';
$string['catquizlab:manage'] = 'CAT-Experimenten-Suite verwalten (Experimente anlegen, ausführen und löschen)';
$string['catquizlab:view'] = 'Ergebnisse der CAT-Experimente ansehen';
$string['catquizlab:worker'] = 'Oracle-Anfragen beantworten und Attempt-Jobs übernehmen (Puppeteer-Worker)';
$string['event:run_aborted'] = 'Run-Attempts abgebrochen';
$string['event:run_aggregated'] = 'Run-Ergebnisse aggregiert';
$string['event:run_scheduled'] = 'Run-Attempts geplant';
$string['def:enum'] = 'Ungültiger oder fehlender Wert für {$a}.';
$string['def:integer'] = 'Feld „{$a}" muss eine Ganzzahl sein.';
$string['def:mingtmax'] = 'In {$a} darf minitems nicht größer als maxitems sein.';
$string['def:missingblock'] = 'Erforderlicher Block „{$a}" fehlt oder ist fehlerhaft.';
$string['def:nonemptylist'] = 'Feld „{$a}" muss eine nicht-leere Liste sein.';
$string['def:notjson'] = 'Die Experiment-Definition ist kein gültiges JSON.';
$string['def:positiveint'] = 'Feld „{$a}" muss eine positive Ganzzahl sein.';
$string['def:required'] = 'Feld „{$a}" ist erforderlich.';
$string['env:adaptivequizfound'] = 'Trägeraktivität mod_adaptivequiz: installiert.';
$string['env:adaptivequizmissing'] = 'Trägeraktivität mod_adaptivequiz: NICHT installiert — Experimente können auf dieser Instanz nicht laufen.';
$string['env:catquizfound'] = 'CAT-Engine local_catquiz: installiert.';
$string['env:catquizmissing'] = 'CAT-Engine local_catquiz: NICHT installiert — Experimente können auf dieser Instanz nicht laufen.';
$string['hub:hashmismatch'] = 'Payload-Hash stimmt nicht überein; das Paket wurde abgelehnt.';
$string['hub:ingested'] = 'Run-Paket als Run {$a} aufgenommen.';
$string['hub:malformed'] = 'Das Run-Paket konnte nicht gelesen werden.';
$string['hub:noresults'] = 'Für diesen Run liegen noch keine nachberechneten Ergebnisse vor.';
$string['hub:resultsfound'] = 'Ergebnisse für Run {$a} gefunden.';
$string['hub:verifiednotstored'] = 'Payload-Integrität bestätigt. Die Hub-Speicherung ist noch nicht implementiert (Stub).';
$string['instancerole:hub'] = 'Hub (zentrale Nachberechnung)';
$string['instancerole:node'] = 'Node (führt Experimente aus)';
$string['job:acknowledged'] = 'Attempt-Meldung angenommen.';
$string['job:claimed'] = 'Ein wartender Attempt wurde ausgegeben.';
$string['job:none'] = 'Derzeit ist kein Attempt-Job verfügbar.';
$string['manage:col_cell'] = 'Zelle';
$string['manage:col_experiment'] = 'Experiment';
$string['manage:col_name'] = 'Experiment';
$string['manage:col_replication'] = 'Rep.';
$string['manage:col_runs'] = 'Runs';
$string['manage:col_seed'] = 'Seed';
$string['manage:col_status'] = 'Status';
$string['manage:col_tier'] = 'Tier';
$string['manage:createhint'] = 'Experimente und Sweeps werden derzeit über das CLI (cli/sweep.php) und die Experiment-API definiert; ein Editor auf dieser Seite ist geplant. Registry per CLI füllen, Runs dann hier verwalten.';
$string['manage:disabled'] = 'Experimentläufe sind derzeit deaktiviert (Hauptschalter aus). Definitionen lassen sich ansehen, es finden aber weder Provisionierung noch Task-Planung oder Worker-Aktivität statt.';
$string['manage:environment'] = 'Umgebung';
$string['manage:experiments'] = 'Experimente';
$string['manage:heading'] = 'CAT-Experimenten-Suite';
$string['manage:intro'] = 'DPF-basierte computerisierte adaptive Tests gegen die CAT-Engine vorbereiten, ausführen und auswerten. Dies ist die Verwaltungs-Startseite der Suite.';
$string['manage:noexperiments'] = 'Noch keine Experimente definiert.';
$string['manage:noruns'] = 'Noch keine Runs definiert. Mit dem CLI (cli/sweep.php) einen Sweep expandieren, um die Registry zu füllen.';
$string['manage:pagetitle'] = 'CAT-Experimenten-Suite';
$string['manage:runs'] = 'Runs';
$string['mutator:unknownvariant'] = 'Unbekannte Pool-Variante: {$a}.';
$string['job:unknownattempt'] = 'Unbekannte Attempt-Id.';
$string['naming:unknownplaceholder'] = 'Namensmuster verweist auf einen unbekannten Platzhalter: {$a}.';
$string['navbarbutton'] = 'CATQUIZ-Lab';
$string['oracle:computed'] = 'Das Orakel hat eine modellkonforme Antwort berechnet.';
$string['oracle:notready'] = 'Das Response-Oracle ist noch nicht implementiert (Stub); es wurde keine Antwort berechnet.';
$string['pluginname'] = 'CAT-Experimenten-Suite';
$string['privacy:metadata:local_catquizlab_person'] = 'Simulierte Personen der CAT-Experimenten-Suite: jede Zeile verknüpft ein Ground-Truth-Fähigkeitsprofil mit dem dafür angelegten Moodle-Nutzer.';
$string['privacy:metadata:local_catquizlab_person:abilityglobal'] = 'Die globale Ground-Truth-Fähigkeit der simulierten Person.';
$string['privacy:metadata:local_catquizlab_person:moodleuserid'] = 'Die Id des für die simulierte Person angelegten Moodle-Nutzers.';
$string['privacy:metadata:local_catquizlab_person:profilejson'] = 'Das hierarchische Ground-Truth-Fähigkeitsprofil (je Kategorie und Subskala).';
$string['privacy:metadata:local_catquizlab_person:runid'] = 'Der Experiment-Run, zu dem die simulierte Person gehört.';
$string['privacy:metadata:local_catquizlab_person:stratum'] = 'Das Stratum, für das das Profil erzeugt wurde.';
$string['setting:enabled'] = 'Experimentläufe aktivieren';
$string['setting:enabled_desc'] = 'Hauptschalter. Solange deaktiviert, finden weder Provisionierung noch Task-Planung oder Worker-Ansteuerung statt. Auf allen Instanzen, die kein dediziertes Testsystem sind, deaktiviert lassen.';
$string['setting:environment'] = 'Umgebungsstatus';
$string['setting:hub'] = 'Hub-Verbindung';
$string['setting:hub_desc'] = 'Fertige Run-Pakete zur instanzübergreifenden Aggregation an einen zentralen Hub senden.';
$string['setting:hub_token'] = 'Hub-Token';
$string['setting:hub_token_desc'] = 'Der Webservice-Token für den Hub.';
$string['setting:hub_url'] = 'Hub-URL';
$string['setting:hub_url_desc'] = 'Der wwwroot der Hub-Instanz.';
$string['setting:instancerole'] = 'Instanz-Rolle';
$string['setting:instancerole_desc'] = 'Node: Diese Instanz provisioniert Experimente, führt sie aus und sammelt die Daten. Hub: Diese Instanz nimmt Run-Pakete von Nodes entgegen und rechnet die Auswertung mit identischer Metrik-Bibliothek zentral nach.';
$string['report:cv'] = 'VK';
$string['report:experimentchart'] = 'Metrik-Verlauf über die Runs';
$string['report:experimenttitle'] = 'Experiment-Report';
$string['report:heading'] = 'CAT-Experiment-Report';
$string['report:mean'] = 'Mittel';
$string['report:metric'] = 'Metrik';
$string['report:nodata'] = 'Für diese Auswahl wurden noch keine Ergebnisse aggregiert.';
$string['report:noselection'] = 'Wähle einen Run oder ein Experiment, um den Report anzuzeigen.';
$string['report:runchart'] = 'Run-Metriken';
$string['report:runs'] = 'Runs';
$string['report:runtitle'] = 'Run-Report — {$a}';
$string['report:sd'] = 'SD';
$string['report:value'] = 'Wert';
$string['report:viewreport'] = 'Report ansehen';
$string['status:draft'] = 'Entwurf';
$string['status:failed'] = 'Fehlgeschlagen';
$string['status:finished'] = 'Abgeschlossen';
$string['status:running'] = 'Läuft';
$string['status:scheduled'] = 'Geplant';
$string['sweep:emptyfactor'] = 'Sweep-Faktor „{$a}" hat keine Stufen.';
$string['sweep:unknownfactor'] = 'Unbekannter Sweep-Faktor „{$a}".';
$string['setting:worker'] = 'Lokaler Worker (Exec-Variante)';
$string['setting:worker_base_url'] = 'Basis-URL der Seite';
$string['setting:worker_base_url_desc'] = 'Der wwwroot, den der Worker aufruft.';
$string['setting:worker_concurrency'] = 'Worker-Parallelität';
$string['setting:worker_concurrency_desc'] = 'Wie viele Attempts parallel laufen dürfen (Kapazitätsplanung).';
$string['setting:worker_desc'] = 'Der Dispatch-Task startet den Puppeteer-Worker auf diesem Host, um die Attempt-Warteschlange abzuarbeiten.';
$string['setting:worker_exec_enabled'] = 'Exec-Worker aktivieren';
$string['setting:worker_exec_enabled_desc'] = 'Wenn aktiv, führt der Dispatch-Task den Worker auf diesem Host aus.';
$string['setting:worker_login_mode'] = 'Worker-Login-Modus';
$string['setting:worker_login_mode_desc'] = 'Wie sich der Worker als jeweilige simulierte Person anmeldet.';
$string['setting:worker_login_mode_password'] = 'Benutzername und Passwort (Konvention)';
$string['setting:worker_login_mode_urltemplate'] = 'Vorauthentifizierte URL-Vorlage';
$string['setting:worker_login_suffix'] = 'Login-Passwort-Suffix';
$string['setting:worker_login_suffix_desc'] = 'Wird im Benutzername/Passwort-Modus an die Nutzer-ID angehängt, um das Passwort zu bilden.';
$string['setting:worker_login_url_template'] = 'Login-URL-Vorlage';
$string['setting:worker_login_url_template_desc'] = 'Eine URL, die die simulierte Person anmeldet; {userid} wird durch die Moodle-Nutzer-ID ersetzt. Wird im URL-Vorlagen-Modus verwendet.';
$string['setting:worker_max_jobs'] = 'Maximale Jobs pro Lauf';
$string['setting:worker_max_jobs_desc'] = 'Wie viele Attempts der Worker je Start spielt (0 = bis die Warteschlange leer ist).';
$string['setting:worker_node_path'] = 'Node.js-Pfad';
$string['setting:worker_node_path_desc'] = 'Pfad zur Node.js-Programmdatei, die den Worker ausführt.';
$string['setting:worker_token'] = 'Webservice-Token';
$string['setting:worker_token_desc'] = 'Der Token, den der Worker für die Plugin-Webservices nutzt.';
$string['task:aggregateresults'] = 'Ergebnisse eines CAT-Experimentlaufs aggregieren';
$string['task:collectattempts'] = 'Attempt-Traces eines Laufs aus der CAT-Engine einsammeln';
$string['task:dispatchworker'] = 'Lokalen Puppeteer-Worker starten';
$string['task:exportrun'] = 'Antwortmatrix eines Runs exportieren';
$string['task:orchestraterun'] = 'Einen Run vollständig aufsetzen';
$string['task:pipelinetick'] = 'Attempt-Pipeline am Laufen halten (Wiederfreigabe und Dispatch)';
$string['task:scheduleattempts'] = 'Attempts eines CAT-Experimentlaufs planen';
