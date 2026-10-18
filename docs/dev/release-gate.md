# Release-Gate: was vor einem Release grün sein muss

Stand: CI-Erweiterung vom 18.10.2026; Moodle 5.3 (Candidate), Releaseabnahme noch ausstehend.

## 1. Die Prüfungen und wann sie laufen

| Workflow | Job | Push/PR auf `main` | Push auf andere Zweige | von Hand |
|---|---|---|---|---|
| Moodle Plugin Release CI (`moodle-release.yml`) | Lint, PHPDoc, Struktur; PHPUnit (4.5/5.2/5.3, MariaDB und PostgreSQL, PHP 8.1–8.4 nur in unterstützten Kombinationen); Behat; **mit der gepinnten Engine** | ja | – | – |
| Moodle Plugin CI (`moodle-ci.yml`) | dasselbe plus informative Linter, **mit der gepinnten Engine** | – | ja | – |
| Interface end-to-end (`ui-e2e.yml`) | Moodle 4.5/5.2/5.3 mit separaten Candidate-Engine-Pins, ein Experiment über die Oberfläche, **alle** Playwright-Specs, Webserver mit `memory_limit=512M` | ja | – | ja |
| Worker CI (`worker-e2e.yml`) | Werkzeugkette und Node-Tests | ja | ja | ja |
| Worker CI (`worker-e2e.yml`) | End-to-end je Engine-Linie (Moodle 4.5 / Legacy, Moodle 5.2 und 5.3 / 1.3): Sittings je Strategie, langer CLASSIC- und ALLSUBS-Lauf, verlorene Antwort/Anfrage, 300 Sittings Last, Webserver mit 512M; danach **keine** 5xx und **kein** PHP-Fatal (`cli/request_report.php --max-5xx=0 --max-fatals=0`) | ja | – | ja |

Für den Branch-Schutz von `main` gehören als Pflicht-Checks dazu:
„CI complete (release)“, „Interface end-to-end (legacy, pinned CatQuiz #146)“,
„Interface end-to-end (moodle5, pinned CatQuiz #146)“,
„Interface end-to-end (moodle53, pinned CatQuiz #146)“,
„Worker end-to-end (legacy, needs the CAT engine)“ und
„Worker end-to-end (moodle5, needs the CAT engine)“ und
„Worker end-to-end (moodle53, needs the CAT engine)“. Diese Einstellung liegt
im Repository auf GitHub, nicht im Code.

## 2. Engine-Set: Zweig und Commit

`.github/engine-pins.env` legt für beide Linien Zweig **und** Commit fest:
local_catquiz, mod_adaptivequiz und adaptivequizcatmodel_catquiz, dazu
local_wunderbyte_table. Alle Jobs, die die Engine installieren, holen sie über
`.github/scripts/fetch-engine.sh`. Dazu gehören PHPUnit, Behat, UI-E2E und
Worker-E2E. Die Interface-Matrix nutzt vorläufig `.github/engine-pins-interface.env` mit
neueren CatQuiz-#146-Commits; PHPUnit/Behat und Worker behalten bis zur
Abnahme `.github/engine-pins.env`. Dieser Unterschied ist im Gate nachzuweisen.
Jeder dieser Jobs legt ein Manifest der tatsächlich getesteten
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

| Matrix-Feld | Inhalt | Moodle 4.5 | Moodle 5.2 und 5.3 |
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

## 2b. Moodle 5.3: tatsächliche Mindestversionen und Releasefreigabe

Der offizielle Branch `MOODLE_503_STABLE` ist am 18.10.2026 vorhanden
(Core `public/version.php`: 5.3+ Build 20261013, Version 2026100500.02).
Laut `public/admin/environment.xml` sind **PHP >=8.3, PostgreSQL >=17
und MariaDB >=11.4** erforderlich. Deshalb verwenden die 5.3-Jobvarianten
`postgres:17` bzw. `mariadb:11.4`, während 4.5/5.2 ihre bisherigen
DB-Container behalten. PHPUnit prüft PHP 8.3 und zusätzlich PHP 8.4;
Behat/Worker/UI nutzen PHP 8.3.

Das Verzeichnislayout entspricht 5.2: CLI und `config.php` unter
`moodle/`; Plugins und Webroot unter `moodle/public/`.
`.github/scripts/fetch-engine.sh` ordnet 5.3 der Core-Mindestversion
`2026100500` zu und verwendet für die CAT-Plugins das M5-Pinset.

**Kein automatischer Stabilitätsnachweis:** Eine grüne 5.3-CI muss
vollständige Moodle-/Engine-Installation, PHPUnit, Behat, strikte
Playwright-Tests und echte Worker-Attempts inklusive 512M-Läufen belegen.
Bis dahin ist 5.3 ein getesteter **Kandidat**, nicht ein bereits
abgenommener Produktivstack. Bereits bekannte Fehler aus #129/#137
(Responsive-UI) und #136 (Engine-Integration) gehören zur Abnahme.

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
