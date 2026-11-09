# Testen von `local_catquizlab`

Diese Anleitung beschreibt, wie das Plugin geprüft wird — sowohl die Prüfungen,
die ohne CAT-Engine laufen (CI-relevant), als auch der Rauchtest in einer echten
Instanz mit installierter Engine.

Stand: Release 0.1.50.

## 1. Was wie getestet wird — das Prinzip

Das Plugin trennt bewusst **reine Logik** von **engine-berührendem Code**:

- **Reine Logik** (Statistik/Metriken/Diagnostik, Planung/Sweep/Tiering,
  Templating, Paketbau, Response-Oracle, Antwortmatrix, Export-Formate) ist per
  PHPUnit vollständig abgedeckt und läuft ohne Engine.
- **Engine-berührender Code** (Materialisierung, Test anlegen/binden, Kontext-/
  Skalen-Inserts, Trace-Collect, Teardown) ist über `\local_catquizlab\local\environment`
  gekapselt: ohne Engine liefert er `null`/`[]`/„skipped", statt zu scheitern. So
  installiert das Plugin stand-alone und die CI bleibt grün; die Tiefe dieser
  Pfade wird in der Zielinstanz verifiziert.
- Der **Worker** (Node/Puppeteer) hat reine Hilfsfunktionen, die per Node-Test
  ohne Browser laufen; die DOM-Interaktion selbst wird in der Instanz erprobt.

## 2. Voraussetzungen im Prüf-Container

- PHP 8.2+ mit `php -l` (PHP 8.1 wird nicht mehr getestet).
- Moodle Coding Standard (moodle-cs) über Composer:
  `export PATH=$PATH:~/.config/composer/vendor/bin`.
- `phpmd` (informativ, siehe unten).
- Node 20+ für die Worker-Tests.
- Für PHPUnit/Behat: eine vollständige Moodle-Installation (im reinen
  Container nicht vorhanden — diese laufen in der CI bzw. der Instanz).

## 3. Statische Prüfungen (ohne Moodle)

Alle über das `makefile` (spiegelt die CI):

```
make phpcs        # Moodle Coding Standard — MUSS 0 Fehler und 0 Warnungen sein
make phpmd        # PHP Mess Detector — informativ (in CI mit `|| true`)
make mustache     # Mustache-Templates
make phpdoc       # PHPDoc-Prüfung
make validate     # Plugin-Struktur
make savepoints   # höchster Upgrade-Savepoint <= Plugin-Version
```

Einzeln, ohne makefile:

```
export PATH=$PATH:~/.config/composer/vendor/bin
phpcs --standard=phpcs.xml --extensions=php .        # erwartet Exit 0
find . -name '*.php' -exec php -l {} \;               # Syntax
```

### Hinweis zu PHPMD

PHPMD läuft in der CI als `moodle-plugin-ci phpmd plugin || true` und kann den
Build daher **nicht** rot machen. Die Textausgabe von `phpmd` enthält **nicht**
das Wort „VIOLATION" — eine zeilenbasierte Prüfung ist korrekt:

```
phpmd classes,lib.php,cli,db text codesize,unusedcode,cleancode \
  | grep -E "\.php:[0-9]"
```

Verbleibende, bewusst akzeptierte Nennungen: die mit jedem Schema-Schritt
wachsende `db/upgrade.php`-Funktion und die von Moodle vorgeschriebenen
`pluginfile`-Signaturparameter — beides Standardmuster, die moodle-plugin-ci
nicht flaggt. `@SuppressWarnings` ist **nicht** nutzbar, da Moodles phpcs es als
ungültigen Docblock-Tag ablehnt.

## 4. PHPUnit (in Moodle bzw. CI)

Init einmalig, dann die Suite des Plugins:

```
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --filter local_catquizlab
```

Abgedeckt sind u. a.: `experiment_definition`, `sweep`, `registry`, `naming`,
`person_generator` (inkl. Deviance-Pass-through), `pool_planner`, `pool_mutator`,
`user_/course_provisioner`, `attempt_scheduler` (inkl. Retry/Reclaim/Abort),
`response_oracle` (inkl. GPCM/GRM, `respond_item`, `deviant_ability`), `metrics`,
`diagnostics`, `se_diagnostics`, `trend_analysis`, `result_aggregator`,
`subscale_evaluator`, `exporter`, `answer_matrix`, `export_dataset`,
`run_exporter`, `attempt_collector` (inkl. `parse_debug_info`), `test_binder`,
`test_provisioner` (inkl. PF(t)-Toggle), `item_repository`, `item_registrar`,
`scale_provisioner`, `question_template`, `materialiser`, `worker_launcher`
(inkl. Pool-ids), `capacity`, `report_builder`, `transfer_package`,
`tier_planner`, `run_orchestrator`, `run_cleanup` (inkl. Teardown), `events`,
`privacy`, plus die externen Funktionen.

## 5. Behat (in Moodle bzw. CI)

```
php admin/tool/behat/cli/init.php
vendor/bin/behat --tags @local_catquizlab
```

Die Szenarien prüfen die UI-Präsenz (Navigation, Verwaltungsseite, Report,
Einstellungen). Die Pipeline selbst ist nicht Behat-getestet, da sie die Engine
braucht.

## 6. Worker-Tests (Node, ohne Browser)

```
cd worker
npm test          # node --test — reine Hilfsfunktionen
node --check run_attempt.js
```

Dazu `test/levels_parity.test.js` (#126): liest `amd/src/levels.js` ein und prüft
jeden Fall aus `tests/fixtures/levels_parity.json` — dieselbe Datei prüft
`sweep_levels_test::test_the_server_and_the_editor_agree` gegen
`experiment_form::parse_levels`. Eine Regel, zwei Orte.

Barrierefreiheit (#126, #128, #130, #131): `tests/playwright/a11y.spec.js` prüft
mit axe-core (WCAG 2.1 A/AA) den Hauptbereich jeder Seite des Plugins; schwere
und kritische Befunde lassen den Test scheitern, die übrigen stehen im Bericht.

Selbst säende Browser-Tests (0.7.47) — sie rufen die Seed-Skripte selbst auf und
werden übersprungen, wo `php` nicht läuft:

- `trace_progress.spec.js` (#138, #141, #142): `cli/seed_vanished_trace.php` meldet
  über `job_complete` (die Funktion des Workers) eine beendete Sitzung, deren
  Engine-Trace fehlt. Sie steht als „beendet, Trace fehlt“ im Filter `notrace` und
  wird nie als eingesammelt gezählt; mit `--giveup` nach allen Nachschauen als
  technisches Nicht-Ergebnis aufgegeben. `cli/seed_question_progress.php` spielt
  eine CLASSIC-Sitzung mit 625 Fragen: Fragenbalken 100/625 → 624/625 ohne Neuladen,
  Prognose „vorläufig“ aus dem Tempo der laufenden Sitzung.
- `plot_semantics.spec.js` (#143, #144): `cli/seed_results.php --length=625
  --identical` — ein Dichtemarker genau bei 625, Achse 624/625/626, Anzahl im
  Tooltip; `cli/seed_modes.php` — Moduswerte als signierte Punkte je Zwilling und
  gemeinsamer Subskala, z. B. (+0,6; +0,2) fünfmal.

PHPUnit dazu: `trace_integrity_test`, `question_progress_test` (Fake-Clock
0/1/100/624/625, Mischung 20/625, Retry, früher CAT-Stopp, unbestimmter Nenner,
Query-Budget), `density_marker_test` (n = 1/2/10/100/625), `mode_detection_test`
(Gegenbeispiel −0,4/+0,4), `preview_runs_test` (2×2×2-Sweep: Vorschau = angelegte
Runs; Save/Edit/Copy verlustfrei), `unit_consistency_test` (Plot = Tabelle = Export
für n = 1, n > 1, gepaart, Replikationen), `filter_export_snapshot_test` (alle sechs
Faktorfilter, CSV und JSON).

Abgedeckt: Argument-Parsing, URL-Bau, Frage-/Attempt-ID-Parsing, Login-
Konvention, die Login-URL-Vorlage und die Options-Wahl (`chooseOptionIndex`:
polytome Kategorie → Option, dichotom → korrekt/Distraktor).

## 7. Rauchtest in einer echten Instanz

Mit installierter Engine (`local_catquiz`, `mod_adaptivequiz`, catmodel `catquiz`):

1. Plugin installieren, unter *Website-Administration → Plugins → Lokale Plugins
   → CAT-Experiment-Suite* den Hauptschalter aktivieren.
2. Eine Ziel-Fragekategorie-ID notieren.
3. Ein Baseline-Experiment aufsetzen (siehe `durchfuehrung.md`):
   ```
   php local/catquizlab/cli/orchestrate.php --experimentid=<id> --questioncategoryid=<id>
   ```
   Prüfen: Skalenbaum + Kontext angelegt, Fragen in der Kategorie, CAT-Test als
   adaptivequiz-Instanz, Personen/Nutzer/Kurs, Attempts in der Queue.
4. Worker vorbereiten und laufen lassen:
   ```
   cd local/catquizlab/worker && npm install
   node run_attempt.js --base-url=<wwwroot> --token=<wstoken>
   ```
   (oder den Adhoc-Task `dispatch_worker` bzw. den geplanten Task
   `pipeline_tick` einschalten).
5. Aggregation laufen lassen (Adhoc-Task `aggregate_results`), Report unter
   `report.php?runid=<id>` prüfen, Export testen.
6. Aufräumen: `run_cleanup::cleanup($runid, ['course' => true, 'run' => true])`
   entfernt Lab-Store **und** Engine-Artefakte idempotent.

### Betrieb ohne Aufsicht

Den geplanten Task `pipeline_tick` aktivieren (unter *Website-Administration →
Server → Geplante Tasks*, standardmäßig deaktiviert). Er gibt hängengebliebene
Attempts wieder frei und dispatcht — bei aktiviertem Exec-Worker — den Worker-Pool
(`worker_concurrency`).

## 8. Feinjustier-Stellen in der Instanz

Wenn im Rauchtest etwas abweicht, sind das die vier bewusst gekapselten Stellen
(anpassen, ohne Testbarkeit/CI zu berühren):

- `test_provisioner::build_moduleinfo` — adaptivequiz-Basisfelder
  (`highestlevel`/`lowestlevel`/`startinglevel`).
- `materialiser::create_question` — `qtype_multichoice`-Speichern, Kategorie-
  Kontext, Bruchwerte.
- `scale_provisioner`/`item_registrar` — Kontext-/Skalen-/Item-Inserts.
- der vollständige Oracle-Pfad in `oracle_answer`.

Der Worker-DOM-Teil (Selektoren in `run_attempt.js`) ist gegen Theme-Varianz
defensiv; eine zusätzliche Selektor-Variante ist eine Ein-Zeilen-Ergänzung in der
jeweiligen Liste.
