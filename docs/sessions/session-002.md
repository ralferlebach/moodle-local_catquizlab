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

## Phase 18 — Bausteinbibliothek und neu gebaute Startseite (0.3.0-Arbeit, später auf 0.2.3 zurückbenannt)

Ein Screenshot zeigte die Startseite als drei zugeklappte Dreiecke ohne Inhalt:
alles steckte in `<details>`-Sektionen, und wer die Seite zum ersten Mal
öffnete, fand keinen Einstieg. Neu gebaut nach dem Mock-up „Experimente
verwalten": Überblickspanel mit Zählern je Status, Primäraktionen über den
Tabellen, Experimenttabelle mit Zeilenaktionen, die zehn neuesten Runs mit
Fortschrittsbalken.

Dazu die neue Anforderung: wiederverwendbare Itempool- und Personen-Bausteine
(`preset_library`). Jeder Baustein trägt einen Fingerabdruck über seine
sortierte Payload, der im Manifest landet — damit ist belegbar, dass zwei
Experimente denselben Bauplan benutzt haben. Bewusst **nicht** Teil eines
Bausteins: die Poolvariante samt Recipe, weil eine Robustheitsbedingung zur
Studie gehört und nicht zum Pool, den sie stört, und die Personenzahl, weil der
Stichprobenumfang eine Designentscheidung ist.

Gefunden: `json_encode(1.0)` liest sich als `int 1` zurück. Eine Trennschärfe
wechselte beim Speichern den Typ. Alle drei Definitions-Encoder setzen jetzt
`JSON_PRESERVE_ZERO_FRACTION`.

## Phase 19 — Editor nach Mock-up

Abschnittsnavigation mit Einzeilen-Zusammenfassung je Abschnitt, Formular in der
Mitte, mitscrollendes Validierungspanel rechts. Studienmetadaten ergänzt:
Beschreibung, stabile Experiment-ID, Version, Tags.

Zwei Befunde aus dem Behat-Lauf: Ich hatte „Sweep erzeugen" beim Umbau vom
POST-Button zum GET-Link gemacht — ein Zustandswechsel, den man jemandem
unterschieben kann. Und das Panel zählte Hinweise, zeigte aber keinen davon.

## Phasen 20–24 — Ergebnisoberfläche in fünf Etappen

`results_query` als gemeinsame Datengrundlage: Tabellen und Diagramme lesen
durch dieselbe Berechnung, sonst kann ein Leser bei zwei abweichenden Zahlen
nicht entscheiden, welcher er glauben soll. Beobachtungseinheit ist ein
Attempt; aggregiert wird danach, und jede Aggregation nennt Ebene, Anzahl und
Streuungsdefinition. Eine Gruppe mit einer Beobachtung meldet **kein**
Intervall.

`scatter_chart` als eigener SVG-Renderer, weil Moodles Chart-API keinen Scatter
kennt und die Spezifikation mehrere verlangt — mit Achsenbeschriftung samt
Einheit, Referenzlinien und begleitender Kennzahlentabelle, da ein SVG für sich
für einen Screenreader unlesbar ist.

Acht Reiter: Übersicht, Globale Metriken, Subskalen, Defiziterkennung (Titel
folgt der Strategie), Robustheit, Testverlauf, Rohdaten, Export.

Fachliche Kernentscheidungen:

- **Lokale Diagnostik rechnet mit Abweichungen**, nicht mit absoluten
  Subskalenfähigkeiten. Ein Test, der alle Subskalen um ein Logit zu hoch
  verortet, hat die lokale Struktur rekonstruiert und das globale Niveau
  verfehlt; ein Vergleich absoluter Werte lastete das der lokalen Diagnostik an.
- **Robustheit ist eine Differenz unter sonst gleichen Bedingungen.** Referenz
  ist die Idealpool-Zelle mit demselben Tier, derselben Strategie, demselben
  Modell, Stratum und derselben Severity — sonst stünde der Strategieunterschied
  als Robustheitseffekt in der Tabelle.
- **Erreichbarkeit vor Stop-Regel-Urteil.** Ein SE-Ziel impliziert eine
  Information `I = 1/SE²`; kann das Budget sie nicht liefern, wäre der Test auch
  bei perfekter Auswahl durch Erschöpfung geendet.

Gefunden: `install.xml` und `upgrade.php` waren auseinandergelaufen —
`twinid`/`twinindex`/`severity` standen nur im Upgrade, jede Neuinstallation
verlor die Twin-Identität. Und `stop_reached()` fing `error` als Teilstring und
stufte `standarderror` als Erschöpfung ein.

## Phase 25 — Engine-Grenze (Issue #10)

Ein Run meldete Erfolg, während der CAT-Manager keine Fragen zeigte. Ursache war
eine Definition: Das Plugin zählte Moodle-Question-Zeilen und schrieb die
Engine-Tabellen selbst, wenn die Engine-API keine Zeile angelegt hatte — so
wurde eine **abgelehnte** Zuordnung zum Erfolg. `cat_item_provisioner` hält
jetzt die Grenze: Engine-Verdikt ist verbindlich, Parameter einmal je Item und
Kontext, danach Rückholprobe über `catscale::get_testitems()`. Getrennte Zähler,
`planned = 0` scheitert für jede Variante, `cli/verify.php` benennt das erste
gebrochene Kettenglied.

## Phase 26 — CI grün

Drei `CHAR NOT NULL`-Spalten mit `DEFAULT=""`. Moodle korrigiert das selbst und
schreibt eine Debugging-Meldung; `moodle-plugin-ci` wertet jede Debugging-Ausgabe
bei der Installation als Fehlschlag. Drei wirkungslose Attribute nahmen die
gesamte Matrix mit. Dazu: `twinid` war `NOT NULL` ohne Default und wäre auf jeder
befüllten Tabelle gescheitert; drei Capabilities hatten keine Sprachstrings.

## Phase 27 — Shared Course (Issue #8)

`test` lief vor `people`, brauchte aber die `courseid`, die `people` erst
anlegte — der Aufruf lieferte bei jedem Run `null`, und `null` galt als Erfolg.
Neue Reihenfolge mit eigener Container-Stage. Ein Kurs pro Run hätte bei hundert
Replikationen hundert Kurse für eine Bedingung erzeugt; jetzt ein konfigurierter
Kurs, eine Section je Experiment, eine Aktivität je Run.

Behat fing dabei einen Fehler von mir, der weit über das Plugin hinausreichte:
Die Kursliste in `settings.php` lief beim Aufbau des Admin-Baums, und
`format_string()` initialisierte das Filtersystem, das den Baum erneut
anforderte — jede Admin-Seite der Site brach ab. Jetzt lazy über
`load_choices()`.

## Phase 28 — PHPUnit 10/11

Ein Hilfsmethodenname `result()` ist in PHPUnit 10/11 final und damit ein Fatal
beim Laden der Datei, das die ganze Suite mitreißt. Moodle 4.5 liefert PHPUnit 9
— die Fehlerart war genau dort unsichtbar, wo ich testete. `testcase_names_test`
prüft jetzt gegen die 86 finalen Methodennamen aus den Quellen von 10.5 und
11.5. Verifiziert gegen ein echtes PHPUnit 11.5.56.

## Phase 29 — Experiment-Validität (Issue #9, Befunde 1–3)

Drei Befunde, die Ergebnisse ungültig machen:

- `definition_for()` las die Basisdefinition statt der Zelldefinition aus dem
  Manifest. Ein Sweep über vier Zellen hätte viermal dieselbe Bedingung
  ausgeführt, während Cellkey und Manifest vier verschiedene dokumentierten.
- `subscale_evaluator` klassifizierte die **geschätzten** Werte gegen die
  **wahre** globale Fähigkeit. Die bewertete Ausgabe wurde teilweise aus der
  Antwort konstruiert, gegen die sie bewertet wurde.
- `metric_series()` poolte alle Runs eines Experiments. Die SD mischte
  Replikationsrauschen mit Bedingungsunterschieden und wurde groß, gerade wenn
  das Experiment funktionierte.

## Phase 30 — Outcome-Pipeline, degeneriertes 2PL, Identität, Doku

Stop-Erfolg, Expositionskonzentration und Laufzeit wurden berechnet, aber nicht
persistiert — ein Outcome, das nur während einer geöffneten Seite existiert,
lässt sich nicht aggregieren. Multi-k (1, 3, 5, 10) statt eines konfigurierten
k. Der Editor setzte `allowdegenerate` automatisch und hebelte damit die
Prüfung aus, für die das Flag existiert. Import matchte auf den Anzeigenamen
statt auf `experimentkey` und `version`.

## Phase 31 — Sweep-Faktoren und Lifecycle

Modell, Budgets, SE-Fenster und Störungsstärke als Sweep-Faktoren in der UI.
Budgets werden als Paar variiert: getrennte Enden erzeugten Zellen wie 40/15,
die nichts beschreiben. Ein Test fand dabei, dass die Schema-1-Spiegel der
Budgets dem gesweepten Wert widersprachen — der Validator wies Zellen wegen
einer Unstimmigkeit zurück, die der Sweep selbst erzeugt hatte.

Lifecycle um `ready`, `aggregating` und `cancelled` erweitert. Abgebrochen ist
keine Spielart von Fehlgeschlagen: das eine hält eine Entscheidung fest, das
andere einen Defekt, und in einer Liste, in der beides gleich aussieht,
verstecken sich die Defekte zwischen den Entscheidungen.

---

## Verifikationsstand

| Gate | Ergebnis |
|---|---|
| PHPUnit (Moodle 4.5, pgsql) | 390 Tests, 2679 Assertions — grün |
| PHPUnit 11.5 (Ladeprüfung) | alle Testklassen laden fehlerfrei |
| Behat (Chrome, inkl. Accessibility) | 27 Szenarien, 187 Schritte — grün |
| Worker (`check` / `test` / `selftest`) | Syntax ok, 11 Tests, Selbsttest inkl. Browserstart |
| phpcs (moodle-cs 3.x) | Exit 0 |
| PHPDoc (local_moodlecheck) | 0 Befunde |
| Frische Installation | keine Debugging-Ausgabe |
| Savepoints | ≤ Version `2026090103` |
| Sprachdateien | 587 Strings je Sprache, sortiert, deckungsgleich |
| CI (GitHub Actions) | grün über die Matrix 4.5/5.0/5.2 × PHP 8.1–8.3 × mariadb/pgsql |

## Abgearbeitete Issues

| Issue | Gegenstand | Stand |
|---|---|---|
| #1 | Definition erreicht die CAT-Testkonfiguration | erledigt |
| #2 | Poolvarianten im E2E-Pfad | erledigt |
| #3 | Modellgerechte Itemparameter | erledigt |
| #4 | Digital Twins und Strata | erledigt |
| #5 | Polytome Experimente (GPCM) | erledigt |
| #6 | Praxisnahe Strategiebezeichnungen | erledigt |
| #7 | Web-UI (inkl. Addendum) | erledigt |
| #8 | Shared Course und Provisionierungsreihenfolge | erledigt |
| #9 | Restliste, elf Teilbefunde | erledigt |
| #10 | Engine-Grenze der Materialisierung | erledigt |
| #9 (Worker-CI) | `example.invalid` im Toolchain-Job | erledigt |

## Next

- **Erster echter Durchlauf gegen eine Instanz mit installierter Engine.** Das
  ist der einzige verbliebene Punkt von Substanz: alle engine-nahen Pfade sind
  bisher nur als Guard-Pfad getestet, weil in dieser Umgebung keine Engine
  vorhanden ist. `cli/e2e_prepare.php` und `cli/verify.php` sind genau dafür
  gebaut — letzteres benennt das erste gebrochene Kettenglied.
- **Upstream-Issue zu `local_catquiz_progress`** einreichen
  (`docs/design/issue-catquiz-progress-retention.md`): Aufbewahrung und
  Verlaufstiefe sollten konfigurierbar sein statt implizit.
- **Studienparameter festlegen** — a-/c-Verteilungen und Severity-Skalen.
  Catquizlab führt sie reproduzierbar aus und verlangt sie für Publication-Runs
  ausdrücklich, legt sie aber bewusst nicht selbst fest.
- **Eine Moodle-5.x-Instanz in der Entwicklungsumgebung.** Der
  PHPUnit-10/11-Fehler war auf 4.5 unsichtbar; eine zweite Instanz fände solche
  Divergenzen vor dem CI-Lauf.
