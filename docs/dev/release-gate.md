# Release-Gate: was vor einem Release grün sein muss

Stand: 0.7.71 (2026111802). Das Gate sind **vier** Workflows, grün auf demselben Commit: Release-CI, Worker-CI,
Interface-E2E und — neu in 0.7.71 — die Abnahmemessungen (`acceptance.yml`, #110/#136/#137/#149/#163/#164). Dazu je
Job ein Release-Manifest aller Komponenten (Abschnitt 6), die geprüften Skips (Abschnitt 3) und die eine lesende
SQL-Inventur (Abschnitt 7). Welche Abnahme welches Issue belegt: `abnahme-messungen.md`.

## 1. Die Prüfungen und wann sie laufen

| Workflow | Jobs | Push/PR auf `main` | Push auf andere Zweige | von Hand |
|---|---|---|---|---|
| Moodle Plugin Release CI (`moodle-release.yml`) | Lint/PHPDoc/Struktur (PHP 8.2, 8.3); PHPUnit in 10 Zellen (4.5 mit PHP 8.2/8.3, 5.2 mit 8.3, 5.3 mit 8.3/8.4, je PostgreSQL und MariaDB); Behat (4.5, 5.2, 5.3); Upgrade 0.7.60 → dieser Commit in 6 Zellen (4.5/5.2/5.3 × PostgreSQL/MariaDB); alles **mit der gepinnten Engine** und mit R | ja | – | – |
| Moodle Plugin CI (`moodle-ci.yml`) | dasselbe plus informative Linter | – | ja | – |
| Interface end-to-end (`ui-e2e.yml`) | je Stack (4.5, 5.2, 5.3) ein Experiment über die Oberfläche, **alle** Playwright-Specs, danach das tiefe Löschen (#161); Webserver mit `memory_limit=512M` | ja | – | ja |
| Interface end-to-end (`ui-e2e.yml`), Jobs `screen-reader` (NVDA) und `screen-reader-voiceover` | NVDA (Windows) und VoiceOver (macOS) je Stack, siehe `screenreader-ci.md` | ja | – | ja |
| Acceptance measurements (`acceptance.yml`) | Scheduler mit 11 und 25 echten Workern auf PostgreSQL und MariaDB (#163), Last 1/5/12/25/50 Worker je Modus (#110), 2 × 2-Tracing-Matrix kalt/warm (#164), Engine-Sitzungen, -Migration und -Kosten (#136/#137), Bestands-Scan synthetischer Logs (#149), Inventur, ein Release-Manifest aller Gate-Jobs | ja | – | ja |
| Worker CI (`worker-e2e.yml`) | Werkzeugkette und Node-Tests | ja | ja | ja |
| Worker CI (`worker-e2e.yml`) | End-to-end je Stack (4.5 Legacy, 5.2, 5.3): Reihenfolge der Ausführungsreihe (#163), Sittings je Strategie, Trace unter Gleichzeitigkeit (#138), Löschen (#161), Ein-Klick-Start (#151), Stopp/Fortsetzen (#165/#166), langer CLASSIC- und ALLSUBS-Lauf, 300 Sittings Last, Log-Redaktion (#149); danach **keine** 5xx und **kein** PHP-Fatal | ja | – | ja |

Für den Branch-Schutz von `main` gehören als Pflicht-Checks dazu:
„CI complete (release)“, „Interface end-to-end (Playwright)“,
„Worker end-to-end (legacy, release engine set)“ und
„Worker end-to-end (moodle5, release engine set)“ (bis 0.7.68: „… needs the CAT engine“; nach dem Push umbenennen).
Diese Einstellung liegt im Repository auf GitHub, nicht im Code.

## 2. Engine-Set: Zweig und Commit

Zwei Pinsätze: `.github/engine-pins.env` (`release`) für Release-CI und Worker-E2E, `.github/engine-pins-interface.env`
(`candidate`, gepinnt auf CatQuiz #146) für das Interface-E2E und seit 0.7.69 zusätzlich für je einen Worker-E2E-Job
je Stack („Worker end-to-end (legacy, candidate engine set)“ usw.). Der Kandidat wird zum Release-Satz, wenn seine
Worker-E2E-Jobs grün sind (#136), nicht vorher. Jeder Job mit Engine schreibt „engine set …“ mit den drei Commits ins Log;
der CI-Nachweis listet sie je Job.

`.github/engine-pins.env` legt für beide Linien Zweig **und** Commit fest:
local_catquiz, mod_adaptivequiz und adaptivequizcatmodel_catquiz, dazu
local_wunderbyte_table. Alle Jobs, die die Engine installieren, holen sie über
`.github/scripts/fetch-engine.sh`. Dazu gehören PHPUnit, Behat, UI-E2E und
Worker-E2E. Jeder dieser Jobs legt ein Manifest der tatsächlich getesteten
Commits ab, als Artefakt `engine-manifest-…` bzw. in den E2E-Evidenzen.

Vor 0.7.41 holten die beiden E2E-Workflows mod_adaptivequiz und das Catmodel
vom Default-Zweig (`master`/`main`) statt vom Legacy-Zweig. Die Release-CI
installierte gar keine Engine, ihre engine-berührenden Tests liefen dort leer.

Das Set nachziehen:
1. Die neuen Heads gegen catquizlab prüfen. Dazu gehören CHANGELOG der Engine,
   Statuscodes, Progress-Format und Gültigkeitsgründe.
2. Mit `ENGINE_FOLLOW_HEADS=1` (in den E2E-Workflows: Eingabe „engine_heads“)
   gegen die Heads testen.
3. Die SHAs in einem Commit ersetzen.

## 2a. Moodle 5: drei Wurzeln, und was nach der Installation laufen muss (#135)

Ab Moodle 5.1 liegen Plugins und Web-Einstiegspunkte unter `public/`. Die
Kommandozeilenskripte von Moodle selbst und `config.php` bleiben oben.
`worker-e2e.yml` führt die drei Orte deshalb getrennt:

| Matrix-Feld | Inhalt | Moodle 4.5 | Moodle 5.2 |
|---|---|---|---|
| `coreroot` | `config.php`, `admin/cli/*.php` (Installation, Ad-hoc-Tasks, Cron) | `moodle` | `moodle` |
| `dirroot` | Plugins (`$CFG->dirroot`) und ihre `cli/*.php` | `moodle` | `moodle/public` |
| `webroot` | Was der Webserver ausliefert | `moodle` | `moodle/public` |

Der Schritt „Check the three roots“ prüft vor der Installation, dass jede
Wurzel enthält, was die folgenden Schritte darin suchen. Ein Layoutfehler
scheitert dort mit Namen, nicht drei Schritte später als „Could not open input
file“. Genau so war der Moodle-5.2-Job am 17.10. gescheitert: Er hatte den
Installer aus `moodle/public` gestartet.

Nach der Installation laufen die Ad-hoc-Tasks einmal von `coreroot`
(`php admin/cli/adhoc_task.php --execute`). Moodle 5 stellt bei der
Installation die Übertragung der Fragensammlungen in Fragensammlungs-Aktivitäten
in die Warteschlange. Bis sie gelaufen ist, zeigt mod_adaptivequiz „Item bank
is not configured properly“ statt eines Start-Buttons, und der Worker meldet
nur „No way to start the attempt was found“. Diese Lücke kam erst nach der
Korrektur der Wurzeln zum Vorschein. Seitdem ist sie auch im Plugin abgefangen:
`preflight` blockiert den Start mit Begründung, und `cli/e2e_prepare.php`
bricht mit derselben Begründung ab.

## 2b. Version, Savepoints und Upgrade (#167)

- **Versionsnummer:** `version.php` trägt `JJJJMMTTNN` mit dem Tag der Auslieferung, die Release-Bezeichnung zählt
  `0.7.x`. Die Nummer liegt nie unter dem höchsten Savepoint von `db/upgrade.php`, sonst lehnt Moodle den Code als
  Downgrade ab (so geschehen mit 0.7.64 auf GitHub: `version` 2026111400, eigener Savepoint 2026111500).
- **`tests/release_version_test.php`** prüft das bei jedem PHPUnit-Lauf: Version ≥ höchster Savepoint, jeder Block
  speichert genau die Version, mit der er beginnt (`if ($oldversion < N)` … `savepoint(N)`), die Savepoints steigen in
  der Reihenfolge, in der sie stehen, die installierte Testsite hat die Version des Codes.
- **Upgrade-Job** (Release-CI und `moodle-ci.yml`): Installation von **0.7.60 (2026111400)**, dem Stand von CLARA, mit
  Daten aus dem alten Code (eingerichteter Lauf, beendetes und gestopptes Experiment, technischer Fehler); dann der
  neue Code, Upgrade, `cli/upgrade_check.php` vergleicht das Schema mit `db/install.xml` und liest die alten Daten;
  ein zweites `upgrade.php` muss „No upgrade needed“ melden (STDERR mit `2>&1`). Auf PostgreSQL und MariaDB, Moodle
  4.5/5.2/5.3.
- **CLARA:** Der Weg ist derselbe wie im Upgrade-Job, weil er vom selben Stand ausgeht. Das Upgrade auf CLARA selbst
  macht der Betrieb; davor und danach `cli/upgrade_check.php` (siehe dort).

## 2c. Moodle 5.2 → 5.3 auf derselben Installation (#136, seit 0.7.69)

Job `upgrade-moodle` der Release-CI, je PostgreSQL 17 und MariaDB 11.4: Moodle 5.2 mit Engine (5.x-Satz) und dem
Plugin dieses Commits installieren, Daten schreiben (bereitgestelltes Experiment, fertig, blockiert, gestoppt wie auf
CLARA, eine technisch gescheiterte Bearbeitung), `cli/upgrade_check.php --phase=before`; dann im selben Verzeichnis
nur den Kern auf `MOODLE_503_STABLE` wechseln (config.php, moodledata, Datenbank, Plugin-Verzeichnisse bleiben),
`admin/cli/upgrade.php`, `upgrade_check.php --phase=after` (Tabellen wie install.xml, keine Zeile verloren oder
verändert, Läufe lesbar) und ein zweites Upgrade ohne Arbeit.

## 3. Keine stillen Skips im Gate

Playwright-Specs prüfen ihre Fixtures mit `needs()` aus
`tests/playwright/support/needs.js`. Lokal überspringt ein fehlendes Fixture
den Test. Mit `CATQUIZLAB_E2E_STRICT=1`, das der UI-E2E-Workflow setzt, lässt
es den Test scheitern, mit Grund. Ein grüner Gate-Lauf hat also jeden Spec
wirklich ausgeführt.

**PHPUnit (seit 0.7.71):** Die Release-CI ruft PHPUnit mit `--fail-on-incomplete --testdox` auf und schreibt das Log
mit. Der Schritt „Skips checked“ (`.github/scripts/phpunit-skips.py`) liest daraus jeden übersprungenen Test mit Namen
und vergleicht ihn mit `.github/phpunit-allowed-skips.txt` (Klasse, Testname, Begründung). Ein Skip, der dort nicht
steht, lässt den Job scheitern; die Liste je Job liegt als `phpunit-skips-….json` im Manifest-Artefakt. Die Liste ist
leer: Die Release-Jobs haben Engine, R, Node und PHP 8.2–8.4, und der letzte Skip des Gates — die R-Reanalyse ohne R,
„Skipped: 1“ in allen zehn Jobs der Release-CI vom 15.11.2026 — scheitert seit `CATQUIZLAB_REQUIRE_R` statt zu
überspringen. Lokal (Moodle 4.5, PostgreSQL 16, PHP 8.3, Engine installiert): 1 199 Tests, kein Skip.

## 4. Speicher: 512M ist die Referenz

Die HTTP-500 aus #110 traten bei `memory_limit=512M` auf. Die E2E-Workflows
starten den Webserver deshalb mit diesem Limit; einstellbar über die Eingabe
„memory_limit“. Die Kommandozeile behält 1024M für die Seed-Skripte.

`cli/request_report.php` liest den Request-Trace des Servers. Es nennt je Seite
die Zahl der Anfragen, den PHP-Peak (MB) und die 5xx sowie insgesamt die
PHP-Fatals. Mit `--max-5xx`, `--max-fatals` und `--max-peak-mb` wird es zum
Gate. Den residenten Speicher der Webserver-Prozesse tastet der Worker-E2E alle
zwei Sekunden ab (`php-rss.csv` in den Evidenzen).

## 5. Lokal

`make check` führt die statischen Prüfungen und PHPUnit aus. Ohne
moodle-plugin-ci prüft `make phpdoc` die Regel, an der die Release-CI am
16.10. scheiterte: Parameterliste im Docblock gleich der Signatur
(`tools/phpdoc_params.php`). Die vollständige PHPDoc-Prüfung läuft in der CI.

## 6. Release-Manifest (seit 0.7.71)

Jeder Gate-Job schreibt mit `.github/scripts/release-manifest.sh` eine JSON-Datei: Plugin-Commit, Version und Release;
Moodle-Zweig, -Commit und -Version; das Engine-Set mit local_catquiz, mod_adaptivequiz, adaptivequizcatmodel_catquiz
und local_wunderbyte_table (Zweig und Commit) samt `engine-manifest.txt`; PHP (CLI, FPM, `memory_limit` des Webservers),
Webserver, Datenbank mit Version, Node, npm, die Browser (Chromium, Chrome-Headless-Shell), Betriebssystem, Kerne,
Speicher. Fehlende Teile stehen als `null` darin, nicht weggelassen. Artefakte `manifest-<job>`: Release-CI (PHPUnit,
Behat), Worker-CI (sechs E2E-Jobs), Interface-E2E (drei Stacks), Abnahmemessungen (alle Jobs).

Der Job „Release manifest“ von `acceptance.yml` fasst sie zu **einer** Datei zusammen (`release-manifest.json`):
die eigenen und — sobald deren Läufe auf demselben Commit fertig sind, bis zu 90 Minuten gewartet — die der drei
anderen Workflows; was fehlt, steht unter `missing`. Er scheitert, wenn ein Job einen anderen Plugin-Commit getestet
hat. Die geprüften Skips der PHPUnit-Jobs stehen unter `skips`.

## 7. Die eine SQL-Inventur (seit 0.7.71)

`cli/acceptance_inventory.php` (Klasse `acceptance_inventory`) liest den Bestand einer Installation, ohne zu schreiben,
ohne Cron und ohne Worker: Plugin-Version und Schema, Statussummen von Experimenten, Läufen und Bearbeitungen, fällig
(`nextruntime ≤ jetzt`) getrennt von tatsächlich beanspruchbar, Holder und Reihenfolge der Ausführungsreihe,
Pausegrund des Circuit Breakers aus dem Lauf-Manifest, Widersprüche zwischen Worker-Registry, Claims und Leases,
Waisen und Doppeleinträge, gesammelt/abgebrochen/gescheitert disjunkt, Ergebnis-, Provenienz- und Exportzahlen,
Task-Status und letzte Dispatch-Diagnose. Ausgabe als Text oder `--json`; am Ende die Zahl der Datenbank-Schreibvorgänge
während der Inventur (muss 0 sein). In der CI läuft sie nach jedem Scheduler-Job (Artefakt `inventory.json`); auf
CLARA führt sie der Betrieb einmal aus und legt das JSON zum Release.

