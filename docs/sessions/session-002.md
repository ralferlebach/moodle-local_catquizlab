# Session 002 — Issues #1–#7: die Experimentdefinition steuert den Run, und eine Web-UI dafür

Datum: 2026-08-31. Ziel dieses Chats: die sieben offenen GitHub-Issues vollständig
abarbeiten, das Plugin in einem echten Moodle zum Laufen bringen und die
Verifikation bis Behat durchziehen.

> Konvention (siehe `docs/sessions/README.md`): 1 Session = 1 Chat. Dieses
> Dokument ist das **einzige** Protokoll dieses Chats; die Phasen wurden
> nacheinander in derselben Session erarbeitet.

Ausgangsstand: `243e298` auf `main`, Release 0.1.50 (Version `2026081049`),
PHPUnit 180 Tests grün. Endstand: Release 0.2.0 (Version `2026083100`),
PHPUnit 249 Tests / 1774 Assertions, Behat 14 Szenarien / 79 Schritte, phpcs
und PHPDoc ohne Befund.

---

## Phase 0 — Beschaffung und Analyse

Die als beigelegt genannte Codebase, der Sessionstart-Prompt und
`environment-setup.md` waren nicht im Upload enthalten. Beschafft wurde
stattdessen:

- **Codebase** per Klon von `github.com/ralferlebach/moodle-local_catquizlab`.
  Remote existiert nur `main`, kein `development`.
- **Issues #1–#7** über die GitHub-Oberfläche, lokal als Markdown abgelegt.
- **Engine als Ground Truth**: `ralferlebach/moodle-local_catquiz` geklont.
  Bestätigt: Strategiekonstanten `FASTEST=1` … `RELSUBS=8`; vorhandene
  catmodels `rasch`, `raschbirnbaum`, `mixedraschbirnbaum`, `grm`,
  `grmgeneralized`, `pcm`, `pcmgeneralized`. Damit ist das GPCM-Mapping auf
  `pcmgeneralized` aus Issue #5 belegt und nicht geraten.
- **Plugintemplate**: Das ZIP von `archive/refs/heads/main.zip` zeigte nur 13
  von 44 Dateien, weil `.gitattributes` mit `export-ignore` greift. Nach
  vollständigem Klon waren `docs/prompt-templates/`, `makefile`, `tools/`,
  fünf CI-Workflows, `db/removed_files.txt` und `tests/coverage.php`
  verfügbar. Eine `environment-setup.md` gibt es dort nicht; sie wurde in
  dieser Session aus dem real durchlaufenen Setup neu geschrieben
  (`docs/dev/environment-setup.md`).

**Befund der Analyse:** Vier Issues (#1, #3, #5, #6) beschreiben dasselbe
strukturelle Defizit aus verschiedenen Blickwinkeln — die Experimentdefinition
steuerte den realen Run nicht. `stage_test()` reichte nur den Namen weiter,
`plan_items()` hardcodete `discrimination=1.0`/`guessing=0.0`, `item_registrar`
fiel auf `raschbirnbaum` zurück, `pool_mutator::mutate()` wurde im Runtime-Pfad
überhaupt nie aufgerufen. Einzeln repariert hätte das dieselbe Mapping-Logik
viermal erzeugt. Deshalb zuerst zwei Fundament-Phasen.

## Phase 1 — Testsystem

Im Container aufgesetzt: PHP 8.3 mit pgsql/gd/soap/bcmath, PostgreSQL 16,
Moodle 4.5.13 (`MOODLE_405_STABLE`), Composer-Abhängigkeiten, Locale
`en_AU.UTF-8`, `max_input_vars=8000`. Bestehende Suite vor allen Änderungen
grün (180 Tests, 1015 Assertions) — belastbare Ausgangsbasis.

Später ergänzt: moodle-cs 3.x über Composer global, `local_moodlecheck` für
PHPDoc, Chrome for Testing 131 plus Chromedriver für Behat.

## Phase 2 — Fundament: Kataloge

- **`strategy_catalog`** — interner Key → Engine-Konstante → Publication-Label
  → Beschreibung, in einer Tabelle. Engine-IDs werden zur Laufzeit aus den
  Konstanten gelesen; die Vertragswerte greifen nur, wenn die Engine gar nicht
  installiert ist. Eine installierte, aber zu alte Engine wird mit
  verständlicher Meldung abgewiesen statt still gemappt.
- **`model_catalog`** — publikationsnaher Name (`1pl`/`2pl`/`3pl`/`gpcm`/…) →
  Engine-Key, Modellfamilie, benötigte Itemparameter, Oracle-Familie. Legacy-
  und Engine-Namen werden als Aliase akzeptiert und beim Eingang normalisiert.
- **`distribution`** — deklarative Verteilungen (constant, uniform, normal,
  lognormal) mit Validierung, Clamp und seed-determiniertem Ziehen.

## Phase 3 — Fundament: Seed-Domänen

**`seed_domains`** trennt die Zufallsquellen: Personen-Basis,
Personen-Abweichung, Pool, Mutation, Antwort. Ableitung über gekürztes sha256
statt crc32, weil sich die Teile oft nur in einem kurzen Token unterscheiden
(`mild`/`medium`/`strong`) und crc32 solche Eingaben nahe beieinander hält.
Der Personen-Seed hängt nicht mehr am Cellkey — genau das machte vorher jede
Strategieänderung zu einer Neuziehung der Stichprobe.

## Phase 4 — Schema v2 der Experimentdefinition

Neu: `budgets.global`/`budgets.subscale`/`budgets.se` getrennt, `modelparams`,
`pool.recipe`, `persons.severity` und `persons.twins`, `schema`/`schemaversion`
mit Abweisung zu neuer Versionen, `publication`-Begriff (Tier `main`/`robustness`).
Schema-1-Definitionen validieren weiter: `setarget`, flaches
`minitems`/`maxitems` und `raschbirnbaum` werden normalisiert und gespiegelt.

**Regression dabei gefunden und behoben:** Die erste Fassung ließ `validate()`
auf der normalisierten Definition arbeiten, wodurch die Defaults fehlende
Pflichtblöcke auffüllten und zwei Defekttests grün wurden, die rot sein
mussten. Jetzt trennt `normalise($def, $fillrequired)` beide Fälle: die
Validierung löst nur Aliase auf und erfindet nichts.

## Phase 5 — Issue #1: Definition → CAT-Konfiguration

`test_provisioner::options_from_definition()` und `effective_parameters()`;
`run_orchestrator::stage_test()` reicht Strategie, beide Budgetebenen und beide
SE-Grenzen durch. `DEFAULT_STRATEGY = 4` ist als deprecated markiert und wird
nicht mehr konsultiert. Das Manifest hält die Beziehung `I = 1/SE²` als
Zielinformation fest.

## Phase 6 — Issue #2: Poolvarianten wirksam machen

`pool_mutator` läuft jetzt im E2E-Pfad. Studienwerte `+1.0` und `×1.25`.
`gappy` ist eine Fixed-N-Umverteilung: Items innerhalb des Bandes werden an den
näheren Rand geschoben statt entfernt, `depleted` bleibt die verkleinernde
Störung — vorher waren beide dasselbe. `validate_recipe()` prüft
variantenspezifische Schlüssel und Wertebereiche und verlangt bei
Publication-Runs explizite Angaben. `finalise_views()` gibt jedem Item beide
Sichten: wahre und gespeicherte Schwierigkeit, wahre und zugewiesene Skala.

## Phase 7 — Issue #3: modellgerechte Itemparameter

`pool_planner` zieht a und c seed-deterministisch aus den deklarierten
Verteilungen und legt bei polytomen Modellen geordnete Schwellen an.
`materialiser::plan_items()` übernimmt Modell, Schwierigkeit, a und c;
`item_registrar` löst den Engine-Key über den Katalog auf.

## Phase 8 — Schemaerweiterung

Neue Tabelle `local_catquizlab_item` (wahre vs. gespeicherte Parameter, wahre
vs. zugewiesene Skala). `local_catquizlab_pool` wird produktiv: `runid`, beide
Seeds, `itemcount` — vorher ein totes Architekturartefakt. `person` erhält
`twinid`/`twinindex`/`severity`, `run` erhält `masterseed`. Upgrade mit
Savepoint auf `2026083100`.

## Phase 9 — Issue #4: Digital Twins und Strata

Zwei getrennte Ströme: globale Fähigkeit aus dem Base-Twin-Seed, lokale
Abweichungen aus dem Deviation-Seed. Stabile `twinid` (`r001-t00042`).
Die Stratum-Tabelle war fachlich falsch — `subscalevariation` stand auf
`[0.0, 0.5]` und löschte die Kategorievariation, statt sie zu ergänzen; jetzt
`[0.5, 0.5]`, also kumulativ. `chaotic` ist ein eigener Generatormodus: die
Subskalen-θ hängen am globalen Wert statt an ihrer Kategorie, womit die
Hierarchieannahme tatsächlich verletzt wird. Severity skaliert die
Basisabweichung und ist als Sweep-Faktor nutzbar.

## Phase 10 — Oracle auf Ground Truth

`oracle_answer::compute()` liest wahre Subskala und wahre a/b/c aus
`local_catquizlab_item`. Ohne das hätte sich der Taggingfehler selbst
neutralisiert: ein absichtlich falsch getaggtes Item wäre schlicht ein Item der
anderen Subskala geworden. `response_oracle::respond_item()` bestimmt die
Antwortfamilie über den Modellkatalog; die alte Substring-Prüfung auf „grm"
hätte `pcmgeneralized` nur zufällig richtig behandelt.

## Phase 11 — Issue #5: GPCM end-to-end

`question_template::default_polytomous()` nimmt die Kategorienzahl als
Parameter. Vorher waren es fest vier — bei fünf Kategorien wäre die fünfte
unerreichbar und das Item still abgeschnitten worden. Polytomie folgt
ausschließlich aus dem Modell; der externe `polytomous`-Schalter ist aus dem
Orchestrator-Pfad verschwunden.

## Phase 12 — Issue #6: Terminologie

`manifest::build_for_run()` schreibt Strategie-Key, Publication-Label und
Engine-ID gemeinsam, dazu Modell mit Engine-Key, Poolvariante mit aufgelöstem
Recipe, Stratum mit Severity und die effektiven CAT-Parameter. Der Export
bekam `twinid`/`stratum`/`severity` und eine neue `export_dataset::items()`.

## Phase 13 — Issue #7a/b: Service-Layer und JSON-Austausch

`experiment_service` als gemeinsame Schicht für CLI, Web und API, mit
`sweep_spec()` als einzigem Spec-Builder — ein Test belegt, dass UI-Vorschau
und direkte Expansion dieselben Zellen liefern. `experiment_io` mit
deklarativem und normalisiertem Export, Import mit Größenlimit, Schemaprüfung,
deterministischer Migration von Schema 1 und drei expliziten Konfliktmodi.
Ein Experiment mit Runs wird nie überschrieben; ein Import startet nie einen
Sweep.

## Phase 14 — Issue #7: Web-UI

`experiment.php` (Editor mit feldbezogener Validierung, Sweep-Vorschau,
Sweep-Erzeugung, Duplizieren, Archivieren, Löschen, beide Exporte),
`import.php`, `runs.php` (Übersicht mit Filtern, Detail mit Manifest,
Reproduzieren, Abbrechen), `compare.php` (Zellvergleich mit Mittelwert, SD,
95%-Intervall, Diagramm, CSV-Export). Die Verwaltungsseite hat statt des
Hinweises „Editor ist geplant" jetzt die Primäraktion **Neues Experiment
anlegen**.

Drei neue Capabilities `:edit`, `:execute`, `:export`, getrennt von `:manage`.
Jede zustandsändernde Aktion prüft `sesskey` plus die zu ihr gehörende
Capability.

## Phase 15 — Issue #7c/d: Run-Registry

`run_registry` löst die experimentellen Koordinaten pro Run aus dem Manifest
auf, statt sie in die Run-Tabelle zu denormalisieren. Die Vergleichsaggregation
gruppiert nach Faktor statt nach Run, weil eine Zelle mit Replikationen die
Vergleichseinheit ist. Das 95%-Intervall bleibt bei einer einzelnen Replikation
bewusst `null`.

## Phase 16 — Behat und Abschluss

`behat_local_catquizlab_generator` (delegiert an den PHPUnit-Generator),
`behat_local_catquizlab` mit einem Sweep-Schritt über den Service. Der
PHPUnit-Generator erzeugt jetzt vollständige, valide Definitionen.

**Zwei Befunde aus dem Behat-Lauf:** ein Bug in `index.php`, wo `$row + [...]`
das `statuslabel` wirkungslos ließ (der `+`-Operator bevorzugt den linken
Operanden) — jetzt `array_merge`; und drei rote Szenarien, die nicht am Code
lagen, sondern daran, dass der Background zur Seite navigierte, bevor das
Szenario-`Given` die Daten anlegte.

Für Behat war Chrome for Testing 131 nötig; ein vorhandenes Chrome 141 unter
`/opt/google/chrome` übernahm die Sitzung, bis das Binary explizit im
`behat_profiles`-Block gesetzt war.

## Phase 17 — Worker-CI (Nachtrag)

Der Toolchain-Job des Worker-Workflows schlug deterministisch fehl, weil er den
produktiven Worker mit Dummy-Zugangsdaten gegen `https://example.invalid`
startete. Der Worker liest das nicht als Selbsttest: er begann seinen normalen
Polling-Loop, rief `local_catquizlab_job_claim` auf und starb an
`getaddrinfo ENOTFOUND`. Ein Job, der konstruktionsbedingt rot ist, sagt nichts
über den Code aus.

Ersetzt durch drei netzunabhängige Schritte — `npm run check`, `npm test` und
einen neuen `--self-test`-Modus, der Argumentverarbeitung, URL-Aufbau,
Antwortauswahl sowie das Laden von Puppeteer und den Start eines Browsers prüft.
Letzteres war das, was der alte Schritt eigentlich belegen sollte.

Der echte E2E-Test steht jetzt als eigener, opt-in-Job daneben: PostgreSQL,
Moodle, Engine und Trägeraktivität, ein vorbereiteter Run mit eingereihtem
Attempt, ein gültiger Worker-Token, ein durchgespielter Attempt und eine
Gegenprobe der Queue. Die Vorbereitung liegt in `cli/e2e_prepare.php` statt im
YAML, damit der E2E-Pfad nicht zu einer zweiten Provisionierungslogik wird.

Belegt statt behauptet: jeder der drei Schritte wurde einmal absichtlich
gebrochen (Syntaxfehler, fehlschlagende Assertion, kaputter Helper) und meldete
jeweils Exit 1 — die Pipeline bleibt also rot, wenn wirklich etwas kaputt ist.

Nebenbei entfernt: der `--polytomous`-Schalter aus `cli/orchestrate.php`. Seit
0.2.0 folgt Polytomie aus dem Modell; ein separater Setup-Parameter machte einen
Run aus `configjson + seed` allein nicht rekonstruierbar.

---

## Verifikationsstand

| Gate | Ergebnis |
|---|---|
| PHPUnit (pgsql) | 249 Tests, 1774 Assertions — grün |
| Behat (Chrome, inkl. Accessibility) | 14 Szenarien, 79 Schritte — grün |
| Worker (`check` / `test` / `selftest`) | Syntax ok, 11 Tests, Selbsttest inkl. Browserstart |
| phpcs (moodle-cs 3.x) | Exit 0 |
| PHPDoc (local_moodlecheck) | 0 Befunde |
| Savepoints | `2026083100` ≤ Version `2026083100` |
| Sprachdateien | 297 Strings je Sprache, sortiert, deckungsgleich |

## Next

- Erster echter Durchlauf gegen eine Instanz mit installierter Engine: die vier
  engine-gekapselten Stellen (Materialisierung, Test-Anlage, Attempt, Trace)
  sind bisher nur ohne Engine getestet.
- Ergebnis-Dashboard vertiefen: Defiziterkennungsmetriken (Precision@k,
  nDCG@k, Konfusionsmatrix) sind im `diagnostics`-Kern vorhanden, aber noch
  nicht in der Vergleichsansicht sichtbar.
- Testverlaufsansicht je Run (Kapitel 15 aus Issue #7): braucht die
  `debug_info`-Schrittdaten aus einem echten Attempt.
- Die konkreten Studienparameter für a- und c-Verteilungen sowie die
  Severity-Skalen festlegen — Catquizlab führt sie reproduzierbar aus, legt sie
  aber bewusst nicht selbst fest.
