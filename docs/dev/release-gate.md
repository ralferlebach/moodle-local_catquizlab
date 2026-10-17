# Release-Gate: was vor einem Release grün sein muss

Stand: 0.7.41 (Re-Audit vom 16.10.2026).

## 1. Die Prüfungen und wann sie laufen

| Workflow | Job | Push/PR auf `main` | Push auf andere Zweige | von Hand |
|---|---|---|---|---|
| Moodle Plugin Release CI (`moodle-release.yml`) | Lint, PHPDoc, Struktur; PHPUnit (4.5 und 5.2, MariaDB und PostgreSQL, PHP 8.1–8.3); Behat; **mit der gepinnten Engine** | ja | – | – |
| Moodle Plugin CI (`moodle-ci.yml`) | dasselbe plus informative Linter, **mit der gepinnten Engine** | – | ja | – |
| Interface end-to-end (`ui-e2e.yml`) | Moodle 4.5 + Engine-Set, ein Experiment über die Oberfläche, **alle** Playwright-Specs, Webserver mit `memory_limit=512M` | ja | – | ja |
| Worker CI (`worker-e2e.yml`) | Werkzeugkette und Node-Tests | ja | ja | ja |
| Worker CI (`worker-e2e.yml`) | End-to-end je Engine-Linie (Moodle 4.5 / Legacy, Moodle 5.2 / 1.3): Sittings je Strategie, langer CLASSIC- und ALLSUBS-Lauf, verlorene Antwort/Anfrage, 300 Sittings Last, Webserver mit 512M; danach **keine** 5xx und **kein** PHP-Fatal (`cli/request_report.php --max-5xx=0 --max-fatals=0`) | ja | – | ja |

Für den Branch-Schutz von `main` gehören als Pflicht-Checks dazu:
„CI complete (release)“, „Interface end-to-end (Playwright)“,
„Worker end-to-end (legacy, needs the CAT engine)“ und
„Worker end-to-end (moodle5, needs the CAT engine)“. Diese Einstellung liegt
im Repository auf GitHub, nicht im Code.

## 2. Engine-Set: Zweig und Commit

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

## 3. Keine stillen Skips im Gate

Playwright-Specs prüfen ihre Fixtures mit `needs()` aus
`tests/playwright/support/needs.js`. Lokal überspringt ein fehlendes Fixture
den Test. Mit `CATQUIZLAB_E2E_STRICT=1`, das der UI-E2E-Workflow setzt, lässt
es den Test scheitern, mit Grund. Ein grüner Gate-Lauf hat also jeden Spec
wirklich ausgeführt.

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
