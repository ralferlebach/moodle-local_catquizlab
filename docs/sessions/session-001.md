# Session 001 — Aufbau der CAT-Experimenten-Suite (Stub → E1.1/E1.2)

Datum: 2026-08-10. Ziel dieses Chats: aus Vorlagen- und Forschungsmaterial ein
installierbares, CI-grünes Moodle-Plugin `local_catquizlab` aufbauen und das
Fundament der Experimenten-Suite legen.

> Konvention (siehe `README.md`): 1 Session = 1 Chat. Dieses Dokument ist das
> **einzige** Protokoll dieses Chats; die folgenden Phasen wurden nacheinander
> in derselben Session erarbeitet. (Zuvor als session-002…005 geführte
> Einzelprotokolle wurden hierher konsolidiert.)

## Phase 0 — Analyse & Architektur
Auswertung von Experimentellem Design, Artikelentwurf, Engine-Code
(`local_catquiz`, `mod_adaptivequiz`) sowie der Skripte `go-clara`/`getit-horst`.
Ergebnis: Zielbild Rev. 2 — **ein** lokales Plugin für Vorbereitung,
Durchführung (echte UI via Puppeteer, getriggert durch getimte Adhoc-Tasks,
Simulationslogik serverseitig im Oracle), Auswertung (PHP-Metriken),
Hub-Modus und Export. Kein externer Python/R-Workbench.

## Phase 1 — M0-Stub
Plugin-Gerüst: version.php, Settings (Hauptschalter, Node/Hub-Rolle,
Umgebungsstatus), Capabilities, erste Tabelle, Null-Privacy-Provider, en/de,
Tests, Worker-Stub, Makefile, CI-Workflows, README/CHANGELOG. Engine-Plugins
als Laufzeit-Erkennung (`classes/local/environment.php`), nicht als harte
Abhängigkeit, damit der Stub stand-alone in der CI installiert.

## Phase 2 — Rename
Komponente auf `local_catquizlab` vereinheitlicht (kein doppelter Unterstrich),
Tabelle `local_catquizlab_experiment` (27 Zeichen), Repository-Metadaten
(github.com/ralferlebach/moodle-local_catquizlab, Autor Ralf Erlebach,
Entwicklungsbranch `development`). Ohne Versionsbump (vor Erst-Release).

## Phase 3 — E0 Plugin-Fundament (Release 0.1.1)
Vollständiger Lab-Store (8 Tabellen) mit `db/upgrade.php`; fünf Webservices
(Oracle-Answer, Job-Queue claim/complete, Hub submit/fetch) in zwei
deaktivierten, nutzerbeschränkten Services; Run-Manifest-Builder
(`classes/local/manifest.php`); Generator und Tests. Versionsbump nötig
(Schema+Services, bereits installiert): 2026080900 → 2026081000.

## Phase 4 — UI-Einstieg (E1.3 Teil)
Bearbeitungs-/Verwaltungsseite `index.php` (Report-Layout) plus zwei
Einstiegswege: Navbar-Button **CATQUIZ-Lab** neben CATQUIZ (Callback
`local_catquizlab_render_navbar_output` in `lib.php`, gespiegelt von der Engine)
und **Website-Administration › Berichte** (`admin_externalpage` unter `reports`).
Sichtbar mit `local/catquizlab:manage`. Behat `navigation.feature`.

## Phase 5 — Anforderungen 2.6 + Schema-Korrektur (Release 0.1.2)
Vier normative Präzisierungen ins Lasten-/Pflichtenheft (architektur.md 2.6):
A) Item-Parametrisierungen über echte Items/Skalen, **nicht** über Kontexte;
B) Personen als eigene Nutzer; C) Kurse/CAT-Tests je Lauf spezifizierbar,
Personen kursweise einschreiben; D) systematische Namensregeln + Fragen-
Templates mit Blanks. Schema-Korrektur: `pool.contextid` → `scaleid` +
`questioncategoryid`. Versionsbump 2026081000 → 2026081001.

## Phase 6 — CI-Fixes + E1.1 (Release 0.1.3)
CI-Analyse (logs_85055861381): Alle PHPUnit-/Behat-Jobs brachen beim Install
ab, weil vier CHAR-NOT-NULL-Felder `DEFAULT=""` hatten (XMLDB verbietet
Leerstring-Defaults). Entfernt. PHPMD: ungenutzte `$params` in `oracle_answer`
bereinigt. E1.1: `classes/local/experiment_definition.php` (Parsen/Validieren/
Defaults, Anforderungen 2.6 abgedeckt, Sammel-Fehler, `example_baseline()`) mit
Tests und `docs/design/experiment-format.md`. Versionsbump → 2026081002 / 0.1.3.

## Phase 7 — E1.2 Sweep-Expansion (Release 0.1.4)
`classes/local/sweep.php`: expandiert eine Sweep-Spezifikation (Basis-Definition
+ Faktoren variant/stratum/strategy) zum kartesischen Produkt, wendet
Ausschlussregeln an, deckelt optional deterministisch die Zellzahl
(Fraktionierung), erzeugt je Zelle R Runs mit deterministischem Seed je
(Zelle, Replikation), validiert jede Zelle über `experiment_definition` und
liefert eine Kapazitätsschätzung (Attempts und erwartete Dauer). Rein logisch,
keine DB-Schreibzugriffe (Persistenz folgt in E1.3/E2). Tests
`sweep_test.php`. Versionsbump → 2026081003 / 0.1.4.

## Phase 8 — E1.3 Run-Registry (Release 0.1.5)
`classes/local/registry.php` persistiert eine Sweep-Expansion als ein
Experiment plus einen Run je Replikation (Status draft) und speichert die
Sweep-Spezifikation am Experiment (Reproduzierbarkeit); Lesehelfer für
Run-Zahl, globale Status-Zusammenfassung und jüngste Runs. Die
Verwaltungsseite zeigt nun einen **Runs**-Abschnitt (Run-Zahl-Spalte,
Status-Zusammenfassung, Run-Tabelle). Bewusst mit Core-Tabelle statt
`local_wunderbyte_table`, damit das Plugin ohne Engine installierbar und
CI-grün bleibt; wunderbyte_table ist die spätere Aufwertung bei vorhandener
Engine. CLI-Pendant `cli/sweep.php` (expandieren/persistieren/auflisten mit
Kapazitätsausgabe). Tests `registry_test.php`. Keine DB-Provisionierung
(Nutzer/Kurse/Fragen bleiben E2). Versionsbump → 2026081004 / 0.1.5.

## Phase 9 — CI-Fixes, Icon-Button, Naming-Engine (Release 0.1.6)
CI-Analyse (logs_85063334088): Install läuft nun, aber (a) ein PHPUnit-Fail
(`assertSame(int, $DB->get_field())` — DB liefert String; auf `(int)` gecastet)
und (b) PHPDoc-Fehler in sweep.php, weil der Moodle-PHPDoc-Checker generische
`array<K, V>`-Typen ablehnt — alle `array<...>` plugin-weit auf `array`
vereinfacht. Zusätzlich PHPMD-Hinweise (nicht blockierend) beseitigt: ungenutztes
`global $CFG` im Navbar-Callback entfernt, `validate()` und `expand()` per
Hilfsmethoden entzerrt (Komplexität unter Schwelle) — Verhalten unverändert,
durch Tests und Harness bestätigt.
Navbar-Button auf **reines Symbol** umgestellt (`fa-cat`); Label bleibt als
Accessible-Name (title/aria-label). Neu: Naming-Engine
`classes/local/naming.php` (Anforderung 2.6.D) — Muster mit `{key}` und
`{key:0Nd}` sowie `sequence()`; deterministisch, seiteneffektfrei; Tests
`naming_test.php`. Versionsbump → 2026081005 / 0.1.6.

## Phase 10 — Lang-Order-Fix + Personen-Ground-Truth (Release 0.1.7)
Lokaler `make check` meldete eine PHPCS-Warnung: `naming:unknownplaceholder`
stand hinter `navbarbutton`, der Moodle-Lang-Ordering-Sniff (moodle-cs ≥ 3.7)
verlangt es davor. In en/de korrigiert; der Container-Sniff war älter, daher
moodle-cs auf 3.7 aktualisiert, sodass die Prüfung nun dem lokalen Stand
entspricht. Neu: `classes/local/person_generator.php` (E2.3, Teil 1) — zieht je
Person eine globale Fähigkeit und, je Stratum, Kategorie-/Subskalen-Abweichungen
zu einem hierarchischen θ-Profil (Namensvergabe über die Naming-Engine),
seed-deterministisch und seiteneffektfrei; `persist()` schreibt in
`local_catquizlab_person` (moodleuserid vorerst null — echte Nutzer und
Einschreibung folgen mit Kursen/CAT-Test). Verteilungsparameter kommen aus der
Definition (dokumentierte First-Cut-Defaults), damit das statistische Design in
der Definition bleibt. Tests `person_generator_test.php`. Versionsbump →
2026081006 / 0.1.7.

## Phase 11 — Pool-Planner: Item-Ground-Truth (Release 0.1.8)
`classes/local/pool_planner.php` (E2.1, Teil 1) legt den Skalenbaum
(Kategorien × Subskalen × Items) an und zieht Item-Schwierigkeiten aus den
genesteten Verteilungen des Designs — Kategorie-Mittel ~ N(0, 2),
Subskalen-Mittel ~ N(Kategorie-Mittel, 0,75), Item-Schwierigkeit ~
N(Subskalen-Mittel, 0,5). Item-Namen aus der Naming-Engine (2.6.D),
seed-deterministisch, seiteneffektfrei; der volle Ideal-Pool ergibt
10 × 10 × 25 = 2500 Items. Das ist das Item-Gegenstück zum Personen-Generator
(fixiert die Item-Ground-Truth als reine Daten). Materialisierung als echte
Fragen über den Engine-Importer und die mutierten Varianten (2.6.A, E2.2)
sind engine-abhängige Folgeschritte und werden hier nicht berührt. Parameter
kommen aus der Definition (Design-Defaults). Tests `pool_planner_test.php`.
Versionsbump → 2026081007 / 0.1.8.

## Phase 12 — Prüf-Suite wiederhergestellt + Pool-Mutator (Release 0.1.9)
`make check` lief nur noch PHP-Lint + Worker. Abgleich mit dem vimipad-Original
und der CI (moodle-ci.yml): `makefile` neu aufgesetzt, `make check` spiegelt
jetzt die volle CI-Suite (Worker, PHPCS, PHPMD, Mustache, Grunt/Gherkin,
PHPDoc, validate, savepoints, PHPUnit); `make check-static` als schnelle
statische Teilmenge, `make ci` inkl. Behat. Die moodle-plugin-ci-Checks laufen
über `moodle-plugin-ci`, wenn vorhanden (exakte CI-Parität), sonst
Direkt-Tool-Fallback bzw. klarer Skip-Hinweis. Gegenüber dem vimipad-Ahnen
sind nur React/AMD- und jMeter/k6-Ziele entfernt; alle sonst zutreffenden
Prüfungen sind zurück, plus die von CI ergänzten (PHPMD, Gherkin, validate,
savepoints).
Weiter (E2.2): `classes/local/pool_mutator.php` leitet aus der Ideal-Blaupause
die Pool-Varianten ab — shifted, stretched, gappy, depleted, calibrationerror,
taggingerror, combined — als reine, seed-deterministische Transformationen
ohne Fragenbank-Zugriff. Gemäß 2.6.A ist jede Variante ein echt anderer
Item-Satz; die wahre Schwierigkeit bleibt Ground Truth (Set-/Schwierigkeits-
Varianten ändern Items, Import-Fehler-Varianten fügen nur Annotationen hinzu).
Tests `pool_mutator_test.php`. Versionsbump → 2026081008 / 0.1.9.

## Phase 13 — makefile-Fix (clear + PHPUnit) + Nutzer-Provisionierung (Release 0.1.10)
Zwei Meldungen aus dem lokalen `make check`: (a) Der Bildschirm wurde nicht
mehr geleert — `clear` war beim Neuaufbau nicht mehr erste Abhängigkeit von
`all/fix/check`; nach Original-Vorbild wiederhergestellt (mit Abschluss-Echo).
(b) PHPUnit brach ab, weil die Testumgebung für eine andere Moodle-Version
initialisiert war; die `phpunit`-Regel übernimmt jetzt die robuste Original-
Logik: Skip bei fehlendem `phpunit_dataroot` und **automatische
Reinitialisierung** (`admin/tool/phpunit/cli/init.php`) bei „initialised for
different version". Die vimipad-Warnungen stammen aus Restregistrierungen im
Moodle des Nutzers (fehlende qtype_vimipad/datafield_vimipad version.php), nicht
aus diesem Plugin.
Weiter (E2.3, Teil 2): `classes/local/user_provisioner.php` legt für die
Personen eines Runs echte Moodle-Nutzer über die Core-User-API an (2.6.B),
setzt `person.moodleuserid`, optional Kohorte; Namen aus dem Naming-Label,
idempotent, core-only (Einschreibung/CAT-Test-Bindung und Login-Zugangsdaten
bleiben spätere Schritte). Weil nun echte Nutzer verknüpft werden, wurde der
**Privacy-Provider vom Null- auf einen vollen Metadaten-/Request-Provider**
gehoben (Tabelle `local_catquizlab_person`, get_contexts/users_in_context/
export/delete im System-Kontext). Tests `user_provisioner_test.php`,
`privacy_test.php` neu. Versionsbump → 2026081009 / 0.1.10.

## Phase 14 — Verwaltungsseite als Mustache mit ausklappbaren Einheiten (Release 0.1.11)
Vorschlag des Nutzers aufgegriffen: Die Einzelabschnitte der Verwaltungsseite
sind jetzt **ausklappbare Einheiten** in **einer Mustache-Vorlage**
(`templates/manage.mustache`). `index.php` baut nur noch den Template-Kontext,
das Markup liegt in der Vorlage. Umsetzung mit nativem `<details>`/`<summary>`
(standardmäßig offen) — barrierefrei, ohne JS und ohne die Bootstrap-4-vs-5-
Unterschiede zwischen Moodle 4.5 und 5.x. Tabellenzellen werden von Mustache
escaped, Labels über `{{#str}}`. Die Vorlage wurde lokal per mustache.php mit
dem eingebetteten Beispiel-Kontext gerendert (3 Sektionen, 2 Tabellen, keine
offenen Tags). CI um einen `moodle-plugin-ci mustache`-Schritt ergänzt; lokal
deckt `make mustache` das ab. Versionsbump → 2026081010 / 0.1.11.

## Phase 15 — Kurs-Provisionierung + Einschreibung (Release 0.1.12)
E2.4, core-Hälfte: `classes/local/course_provisioner.php` löst je Run den Kurs
auf (vorhandenen referenzieren oder neuen, versteckten Kurs anlegen), schreibt
die provisionierten Nutzer als Teilnehmer ein (2.6.C) und vermerkt den Kurs am
Run. Nur Core-APIs (Kurs/Enrol), daher CI-tauglich und idempotent; das Anlegen
des adaptivequiz-CAT-Tests im Kurs braucht die Host-Aktivität und bleibt der
engine-seitige Folgeschritt (füllt später `run.testcmid`). Schema: `run` erhält
`courseid` (FK auf course) und `testcmid`; `db/upgrade.php` ergänzt beide mit
Savepoint 2026081011. Tests `course_provisioner_test.php`. Versionsbump →
2026081011 / 0.1.12 (wegen Schemaänderung erforderlich).

## Phase 16 — Attempt-Scheduling (Release 0.1.13)
E3.1: `classes/local/attempt_scheduler.php` legt je Run einen queued
`attempt`-Datensatz pro provisionierter Person an (Personen ohne Moodle-Nutzer
und bereits geplante werden übersprungen) und setzt den Run auf „geplant". Der
Adhoc-Task `classes/task/schedule_attempts.php` trägt die Run-ID in den
Custom-Data, respektiert den Hauptschalter (tut nichts, solange Läufe
deaktiviert sind) und ruft den Scheduler beim Cron-Lauf. Reine Lab-Store-/
Core-Task-Logik — keine Engine, kein Worker-Start; Collect/Execute setzen auf
den erzeugten Zeilen auf. Idempotent. Tests `attempt_scheduler_test.php` (inkl.
Master-Switch und Task-Enqueue). Versionsbump → 2026081012 / 0.1.13 (reiner
Code, kein Upgrade-Schritt).

## Verifikationsstand
Container: PHP-Syntax, install.xml, YAML, Worker-JS grün; PHPCS (Moodle) 0/0
**und PHPMD ohne Verstöße** über alle PHP-Dateien; höchster Upgrade-Savepoint ≤
Plugin-Version. PHPUnit/Behat laufen in der CI (kein Moodle im Container);
Validator-, Sweep- und Naming-Logik zusätzlich per CLI-Harness geprüft.

## Next
E2.1 Pool-Generator (Templates→Items, Skalenbaum, seed-deterministisch),
E2.3 Personen als Nutzer (+ Namensregeln), E2.4 Kurse/CAT-Tests + Einschreibung;
parallel E3.1 Adhoc-Task „schedule_attempt" — Richtung Meilenstein M1. Die
Run-Registry aus E1.3 liefert die Runs, auf denen Provisionierung und
Orchestrierung aufsetzen.
