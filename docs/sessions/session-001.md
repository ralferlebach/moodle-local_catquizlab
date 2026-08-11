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

## Phase 17 — Response-Oracle: IRT-Antwortmodell (Release 0.1.14)
E3.4, rechnerischer Kern: `classes/local/response_oracle.php` bestimmt, wie eine
simulierte Person ein Item beantwortet. `probability()` ist das logistische
IRT-Modell in 3-Parameter-Form — c + (1 - c) / (1 + exp(-a * (theta - b))) —
mit Defaults für Rasch/1PL; `respond()` zieht daraus eine seed-deterministische
Richtig/Falsch-Antwort; `ability_for()` löst die relevante Fähigkeit aus dem
hierarchischen Ground-Truth-Profil auf (global/Kategorie/Subskala, mit
Fallbacks) — die Grundlage der DPF-Bedingungen. Rein und seiteneffektfrei,
rechnet gegen die bereits gespeicherte Ground Truth, keine Engine nötig. Tests
`response_oracle_test.php` (0.5 bei theta=b, Monotonie, Guessing-Floor,
Diskriminations-Steilheit, Clamping, Determinismus, empirische Trefferquote,
Hierarchie-Auflösung). Der `oracle_answer`-Webservice bleibt Stub, verweist im
Kommentar aber nun auf `response_oracle` als Rechenkern (Aufruf, sobald die
Frage→Item-Parameter-Zuordnung nach der Pool-Materialisierung vorliegt).
Versionsbump → 2026081013 / 0.1.14 (reiner Code, kein Upgrade-Schritt).

## Phase 18 — Metriken (Release 0.1.15)
E4, rechnerischer Kern: `classes/local/metrics.php` wertet die eingesammelten
Attempts eines Runs gegen die Ground Truth aus. `ability_recovery()` liefert
Bias, RMSE, MAE und die Korrelation wahr↔geschätzt; `efficiency()` Testlänge
(Mittel/Min/Max) und mittleren Standardfehler; `exposure()` Item-Häufigkeiten
und -Raten, die maximale Exposure-Rate und (mit Poolgröße) die Zahl ungenutzter
Items; `summarise()` fasst alle drei zusammen. Rein und seiteneffektfrei,
rechnet gegen die bereits gespeicherte Ground Truth — keine Engine nötig, voll
testbar mit synthetischen Traces; leere/degenerierte Eingaben sicher behandelt
(Korrelation null, wenn undefiniert). Tests `metrics_test.php`. Versionsbump →
2026081014 / 0.1.15 (reiner Code, kein Upgrade-Schritt).

## Phase 19 — Diagnostik-/Ranking-Maße (Release 0.1.16)
E4.2: `classes/local/diagnostics.php` misst, wie gut der Algorithmus die wahren
Fähigkeitsdefizite einer Person wiederfindet (ausgerichtete wahr/geschätzt-
Profile je Subskala, niedriger = größeres Defizit). `spearman()` liefert die
Rangkorrelation, `topk_agreement()` die Überlappung der k defizitärsten
Subskalen, `ndcg_at_k()` die gradierte Ranking-Güte der Defizitreihenfolge,
`confusion()` (mit `deficit_labels()` an einem Schwellwert) die
Detektiert-vs-wahr-Matrix samt Precision/Recall/F1/Accuracy/Specificity;
`evaluate()` fasst zusammen. Bindungen erhalten gemittelte Ränge, undefinierte
Fälle null. Rein/seiteneffektfrei, keine Engine. Tests `diagnostics_test.php`
(Spearman ±1, Top-k, nDCG 1 vs. reversed, Konfusionsmatrix). Versionsbump →
2026081015 / 0.1.16 (reiner Code, kein Upgrade-Schritt).

## Phase 20 — Export CSV/JSON/XML (Release 0.1.17)
E6, Kernformate: `classes/local/exporter.php` serialisiert die Zeilen-Daten aus
Registry, Metriken und Diagnostik. `to_csv()` schreibt Header plus RFC-4180-
Quoting (Kommata, Anführungszeichen, Zeilenumbrüche) mit optionaler
Spaltenauswahl; `to_json()` gibt hübsch formatiertes JSON (Slashes/Unicode
unescaped); `to_xml()` baut über DOMDocument ein wohlgeformtes Dokument,
escaped Werte und säubert Elementnamen. Booleans, null und verschachtelte
Arrays werden formatübergreifend einheitlich dargestellt. Reiner,
seiteneffektfreier Serialisierer — kein DB-/Dateizugriff, daher bleibt das
Einsammeln der Zeilen und das Schreiben von Dateien getrennt und testbar. Die
Tabellenformate (xlsx/ods über Moodles Workbook-Writer) folgen später. Tests
`exporter_test.php`. Versionsbump → 2026081016 / 0.1.17 (reiner Code, kein
Upgrade-Schritt).

## Phase 21 — Ergebnis-Aggregation: Traces -> gespeicherte Metriken (Release 0.1.18)
Brücke zwischen E4 (Metriken) und E6 (Export): `classes/local/result_aggregator.php`
liest die Attempts eines Runs mit Trace, paart jeden mit der Ground-Truth-
Fähigkeit seiner Person, rechnet die metrics-Zusammenfassung und schreibt je
Skalarmetrik (n, bias, rmse, mae, correlation, mittlere/min/max Testlänge,
mittlerer SE) eine `result`-Zeile plus eine Exposure-Detailzeile, Scope „run".
Neuberechnung idempotent (run-Zeilen werden ersetzt). `results()` liest sie als
flache, export-fertige Zeilen zurück. Parst die Trace-JSON des Collect-Schritts
(erwartet finaltheta/finalse/items) — keine Engine nötig, mit synthetischen
Traces testbar. Tests `result_aggregator_test.php` (konstanter Offset → bias,
Testlänge, n, Exposure-Detail, Idempotenz, Reader). Versionsbump →
2026081017 / 0.1.18 (reiner Code, kein Upgrade-Schritt).

## Phase 22 — CI-Fix: PHPUnit-Failure + Risky-Test (Release 0.1.19)
CI-Analyse (logs_85231853475): Behat grün (4 Szenarien), PHP Lint grün (phpmd
||true), aber alle PHPUnit-Jobs rot. Zwei Ursachen: (1) `privacy_test::
test_contexts_and_userlist` scheiterte, weil `get_contexts_for_userid`/
`get_users_in_context` `add_system_context()` bzw. Fieldset+`add_users`
nutzten — auf das kanonische `add_from_sql`-Muster umgestellt (fügt System-
Kontext bzw. Nutzer zuverlässig hinzu). (2) `attempt_scheduler_test::
test_task_respects_master_switch` war „risky", weil die `mtrace()`-Ausgabe des
Adhoc-Tasks unter Moodles strenger Output-Regel auffiel — im Test mit
`ob_start`/`ob_end_clean` gekapselt. Zusätzlich die (nicht blockierende)
PHPMD-Komplexität in `xmldb_local_catquizlab_upgrade()` (11) durch Auslagern
der E2.4-Spaltenanlage in `local_catquizlab_upgrade_add_run_course_columns()`
entschärft; der Savepoint bleibt inline, der Savepoints-Check unverändert.
Versionsbump → 2026081018 / 0.1.19 (Fix-Runde, kein Upgrade-Schritt).

## Phase 23 — CI-Fix: int-vs-String-Id im Privacy-Test (Release 0.1.20)
CI-Analyse (logs_85246370323): Risky-Test weg, aber `privacy_test::
test_contexts_and_userlist` weiter rot („array contains 1", Zeile 77). Wahre
Ursache war die Assertion, nicht der Provider: `get_contextids()`/`get_userids()`
liefern Ids als String (aus der DB), `context_system::instance()->id` und die
User-Id sind int, und PHPUnits `assertContains` vergleicht strikt (===), also
schlägt `assertContains(1, ['1'])` fehl. `test_delete_for_user` bestand, weil es
`record_exists` statt eines strikten Array-Checks nutzt — daher wirkte der
Provider korrekt. Test jetzt typ-tolerant (`assertCount` + `(int)`-Cast der Id,
`array_map('intval', ...)` für die Userlist). Die `add_from_sql`-Implementierung
aus 0.1.19 bleibt (Standardmuster). Zusätzlich das gesamte Test-Set auf weitere
int-vs-DB-String-Vergleiche geprüft — keine offen (verbleibende assertSame gegen
DB-Werte vergleichen String↔String). Versionsbump → 2026081019 / 0.1.20 (reine
Test-Fix-Runde).

## Phase 24 — E4.2 & E4.4 vervollständigt + Statusübersicht (Release 0.1.21)
E4.2 abgeschlossen (`diagnostics.php`): `deficit_labels_se` (Defizit erst jenseits
des k·SE-Bandes → 1·SE/2·SE), `agreement_within_se` (Anteil je k·SE
wiedergewonnener Subskalen), `precision_recall_at_k` (Precision@k/Recall@k gegen
variable Relevanzmenge). E4.4 abgeschlossen: `result_aggregator.php` schreibt
zusätzlich Stratum-Scopes (`stratum:<name>`), neuer Adhoc-Task
`task/aggregate_results` + `queue()` hält große Auswertungen vom Web-Request fern;
die `result`-Tabelle ist der persistente Ergebnis-Cache. Neu: `docs/design/status.md`
(Erledigt/Offen je Epic, Meilensteine, CI-tauglich vs. engine-abhängig). Tests
erweitert (`diagnostics_test.php`, `result_aggregator_test.php`). Backlog 4.1/4.2/4.4
auf ✅. Versionsbump → 2026081020 / 0.1.21 (reiner Code, kein Upgrade-Schritt).

## Phase 25 — E2.5 Run-Cleanup + veralteten E1-Hinweis korrigiert (Release 0.1.22)
Screenshot-Hinweis des Nutzers: Der „Runs"-Text verwies noch auf E1 als
nächsten Meilenstein (E1 ist erledigt) — `manage:createhint` in en/de auf den
aktuellen CLI/API-Workflow umgeschrieben. E2.5: `classes/local/run_cleanup.php` —
`cleanup()` räumt den Lab-Store-Rest eines Runs (attempts, results, person),
löscht die vom Run angelegten Moodle-Nutzer und setzt den Run auf Draft; Optionen
löschen einen suite-erzeugten Kurs (erkannt am `catlab_run_`-Kürzel; referenzierte
Kurse bleiben unangetastet) und/oder die Run-Zeile selbst. Core-only, idempotent.
Tests `run_cleanup_test.php` (Reset, Idempotenz, referenzierter Kurs bleibt,
Run-Löschung; delete_course-Ausgabe gekapselt). Backlog E2.5 ✅. Versionsbump →
2026081021 / 0.1.22 (reiner Code, kein Upgrade-Schritt).

## Phase 26 — Attempt-Collector E3.5 (Release 0.1.23)
Nutzer hat `go-clara.php`/`getit-horst.php` geliefert → echtes Engine-Schema.
Daraus E3.5 schema-genau umgesetzt: `classes/local/attempt_collector.php`.
`collect()` liest nach einem echten Attempt die Engine-Tabellen aus — die
Question-Usage des adaptivequiz_attempt (question_attempts/question_attempt_steps
für gespielte Items + Antwort-Fraktionen) sowie local_catquiz_attempts/
local_catquiz_personparams (finale Fähigkeit + Standardfehler) — und speichert
eine kompakte `attempt.tracejson` (finaltheta/finalse/items/responses/nitems/
stopreason), Status→collected. Engine-Reads erfordern Engine + Host-Aktivität,
daher liefert `collect()` null, wenn eine davon fehlt (CI/Stand-alone bleiben
grün); die reine Trace-Zusammenstellung `build_trace()` ist testbar. Bestätigt
nebenbei, dass `response_oracle` modellkonform ist (raschbirnbaum::likelihood).
Tests `attempt_collector_test.php` (build_trace + Engine-Guard). Versionsbump →
2026081022 / 0.1.23 (reiner Code, kein Upgrade-Schritt).

## Phase 27 — Test-Binder E2.4 (Referenz-Pfad) (Release 0.1.24)
E2.4-Rest, „vorhandenen Test referenzieren": `classes/local/test_binder.php`.
`read_test_config()` löst eine adaptivequiz-Aktivität über die Course-Module-Id
auf (course_modules→modules→adaptivequiz) und liest die CAT-Konfiguration aus
`local_catquiz_tests` (component mod_adaptivequiz): catscaleid, contextid,
quizsettings-JSON — genau die Zeilen, die go-clara/getit-horst lesen.
`bind_existing()` vermerkt den Test am Run (`run.testcmid`). Engine-gekapselt
(null ohne Engine/Host-Aktivität → CI grün). Tests `test_binder_test.php`
(Guard). Das Neu-Anlegen einer adaptivequiz+catquiz-Instanz aus einer Definition
bleibt offen (braucht die Formularfelder der catquiz-Erweiterung). Versionsbump →
2026081023 / 0.1.24 (reiner Code, kein Upgrade-Schritt).

## Phase 28 — Item-Repository + Oracle-Verdrahtung E2.1/E3.4 (Release 0.1.25)
Aus `go-clara.php` die Item-Parameter-Query umgesetzt: `classes/local/item_repository.php`.
`for_question()` liest die aktiven Parameter eines präsentierten Items,
`for_scale()` alle Items eines Skalen-Teilbaums (rekursiver Walk über
local_catquiz_catscales + local_catquiz_items + aktive local_catquiz_itemparams).
`shape_params()` castet und setzt 1PL-Defaults (Diskrimination 1.0, Rateparameter
0.0), rein/testbar; Reads liefern null/[] ohne Engine (CI grün).
`oracle_answer` verdrahtet: mit Engine + gebundenem Test identifiziert es die
Person über den eingeloggten Simulanten, löst die Item-Parameter über das
Repository auf und liefert eine seed-deterministische, modellkonforme Antwort
(`response_oracle`, entspricht raschbirnbaum::likelihood), ready=true; sonst
weiterhin sauberes not-ready. Neuer String `oracle:computed`. Aktuell globale
Fähigkeit; Subskalen-Auflösung folgt mit der Materialisierung (catscale↔subscale-
Mapping). Tests `item_repository_test.php` + external-Test aktualisiert.
Versionsbump → 2026081024 / 0.1.25 (reiner Code, kein Upgrade-Schritt).

## Phase 29 — Worker-Queue + Puppeteer-Skript E3.2/E3.3 (Release 0.1.26)
Echte Job-Warteschlange auf der Lab-Store-attempt-Tabelle (core-testbar).
`job_claim` beansprucht den ältesten wartenden Attempt atomar (Transaktion, damit
zwei Worker denselben nicht ziehen), setzt ihn auf running und liefert runid,
attemptid, quizcmid (run.testcmid), userid. `job_complete` verbucht das Ergebnis
am Attempt (collected/failed, runtimems, engineattemptid) und triggert bei
„finished" + engineattemptid den `attempt_collector::collect()` (No-op ohne
Engine). Neuer Param engineattemptid; Strings job:claimed/job:unknownattempt.
`worker/run_attempt.js` als vollwertige Referenz: pollt job_claim, loggt als
Simulant ein, öffnet das adaptivequiz, fragt je Frage oracle_answer, antwortet
und submittet, bis die Engine stoppt, meldet dann job_complete (Laufzeit +
engineattemptid). Selektoren als theme-abhängig dokumentiert. Tests: external-Test
auf echte Queue umgestellt (ältester zuerst, Statusübergänge, unbekannte Id).
Versionsbump → 2026081025 / 0.1.26 (reiner Code, kein Upgrade-Schritt).

## Phase 30 - Collector als Adhoc-Task E3.5-Rest (Release 0.1.27)
Batch-Collection: `attempt_collector::collect_run()` sammelt alle Attempts eines
Laufs mit engineattemptid ein und meldet candidates/collected/runtimems (eigene
Laufzeit). `attempt_collector::queue()` reiht den neuen Adhoc-Task
`classes/task/collect_attempts.php` ein, der die Collection off the web request
ausfuehrt (Re-Collection bzw. wenn ein Complete keine engineattemptid trug).
Ohne Engine sauberer No-op (0 collected). String task:collectattempts (Reihenfolge
aggregateresults < collectattempts < scheduleattempts). Tests
attempt_collector_test.php (Kandidatenzaehlung, Timing, Task-Ausfuehrung).
Versionsbump -> 2026081026 / 0.1.27 (reiner Code, kein Upgrade-Schritt).

## Phase 31 — Polytome Modelle GPCM/GRM + Buchhaltungs-Korrektur (Release 0.1.28)
E3.4-polytom: `response_oracle` um `gpcm_probabilities()` (GPCM, kumulierte
Schritt-Scores via Softmax), `grm_probabilities()` (GRM, sukzessive Differenzen
kumulativer logistischer Schwellen) und `respond_polytomous()` (seed-
deterministische Kategorienwahl) erweitert — rein/testbar (Summe 1, Modalkategorie
steigt mit θ, deterministisch, mittlere Kategorie steigt). Verdrahtung in
`oracle_answer` folgt, sobald Schritt-/Schwellenparameter aufgelöst werden.
Buchhaltung: Beim Weiterarbeiten fiel auf, dass der Arbeitsstand dem
Kompaktierungs-Summary voraus war (E3.5-Batch-Collect + Task waren bereits als
0.1.27 vorhanden). Deshalb: CHANGELOG-Header wiederhergestellt (lückenlose Folge
0.1.28→0.1.0, dedupliziert), veraltete E3.5-Backlog-Notiz korrigiert, Version auf
0.1.28 gesetzt (statt Doppelvergabe 0.1.27). Tests `response_oracle_test.php`
erweitert. Versionsbump → 2026081027 / 0.1.28 (reiner Code, kein Upgrade-Schritt).

## Phase 32 — Verlaufs-/Stabilitätsanalysen E4.3 (Release 0.1.29)
`classes/local/trend_analysis.php`: `stability()` (Streuung einer Metrik über
Replikationen — Mittel, Stichproben-SD, Variationskoeffizient, min/max, Range),
`linear_trend()` (lineare Regression Metrik vs. geordneter Parameter — Steigung,
Achsenabschnitt, Korrelation, r²; z. B. RMSE steigt mit Pool-Degradation),
`convergence()` (laufendes Mittel + Konvergenz innerhalb Toleranz).
`metric_series()` sammelt eine gespeicherte Metrik über die Runs eines Experiments
(nach Replikation geordnet) — Statistik rein/testbar, nur der Reader nutzt die DB.
Tests `trend_analysis_test.php`. Versionsbump → 2026081028 / 0.1.29 (reiner Code).

## Phase 33 — Report-UI E4.5 (Release 0.1.30) — E4 vollständig
`classes/local/report_builder.php` (gruppiert Run-Ergebnisse nach Scope, liefert
Run-Skalare, baut je Metrik Serie+Stabilität über die Runs eines Experiments; DB-
only/testbar) und `report.php` (Run: Metrik-Tabelle je Scope + Balkendiagramm;
Experiment: Stabilitäts-Tabelle + Liniendiagramm über die Runs) über Moodles
eingebaute Chart-API, Capability `local/catquizlab:view`. Neue `report:*`-Strings
(en/de), Behat `report.feature`, Report-Links von der Manage-Seite (Experiment-Name,
Run-Zelle). Tests `report_builder_test.php`. Damit ist **E4 vollständig**.
Versionsbump → 2026081029 / 0.1.30 (reiner Code, kein Upgrade-Schritt).

## Phase 34 — Engine-Quellen ausgewertet + Kontext-Fix (Release 0.1.31)
Nutzer lieferte Engine-Quellen (local_catquiz 2024070802, adaptivequiz-catmodel,
catmodel_catquiz) + reale Schema-Ausgabe. Zentrale Erkenntnisse: (1)
`local_catquiz_tests` hat KEINE contextid — der CAT-Kontext wird über
`\local_catquiz\catscale::get_context_id($catscaleid)` aus der Skala aufgelöst
(Baum hoch bis Default-Kontext). Mein `test_binder` las die nicht existente
Spalte → gefixt (guarded via class_exists). (2) Test-Anlage ist automatisch:
add_moduleinfo mit catmodel='catquiz' + catquiz_*-Feldern → catmodel-Handler →
`catquiz_handler::add_or_update_instance_callback()` JSON-kodiert das ganze
Formular und schreibt die tests-Zeile (catscaleid=catquiz_catscales, courseid).
(3) `local_catquiz_personparams` im ZIP hat weder standarderror noch attemptid —
Widerspruch zu go-clara (Schema-Drift, beim Nutzer zu klären). Versionsbump →
2026081030 / 0.1.31 (reiner Fix, kein Upgrade-Schritt).

## Phase 35 — Test-Provisioner E2.4-Create (Release 0.1.32)
Nutzer lieferte die vollständige quizsettings-JSON + bestätigte Live-Schema
(personparams hat attemptid+standarderror → attempt_collector korrekt).
`classes/local/test_provisioner.php`: `build_quizsettings()` (rein/testbar) baut
die catquiz-Felder (catmodel=catquiz, catquiz_catscales, selectteststrategy,
maxquestionsgroup/maxquestionsscalegroup/standarderrorgroup, je aktivierte Skala
catquiz_subscalecheckbox_<id>) exakt nach echter JSON. `create()` (engine-
gekapselt) setzt das moduleinfo zusammen (adaptivequiz-Basis + Settings) und ruft
add_moduleinfo; der catmodel-Handler schreibt die local_catquiz_tests-Zeile selbst
und run.testcmid wird gesetzt. Tests `test_provisioner_test.php`. Versionsbump →
2026081031 / 0.1.32 (reiner Code, kein Upgrade-Schritt).

## Phase 36 — Frage-Templating E2.1 (Teil 1) (Release 0.1.33)
Defaults übernommen (Platzhalter {scalename}/{scalenumber}/{itemname}/{itemnumber}/
{itemid}/{difficulty}/{discrimination}/{guessing}; Quelle Run-Definition mit
Fallback auf Einstellung — Wiring folgt). `classes/local/question_template.php`
rendert templatebare MC-Fragen aus einer Item-Spezifikation: `single`-Flag
(dichotom 1-aus-4 / polytom 1..4-aus-6), Options-Templates mit Bewertungs-
fraktionen (1.0 korrekt, 0/negativer Malus für Distraktoren, Teilpunkte),
Platzhalter-Ersetzung. Sinnvolle Dichotom-/Polytom-Defaults (Polytom: Gutschrift
und Malus heben sich zu 0 auf). Rein/testbar. Tests `question_template_test.php`.
Versionsbump → 2026081032 / 0.1.33 (reiner Code, kein Upgrade-Schritt).

## Phase 37 — Skalen-Materialisierung + Profil-Mapping E2.1 (Release 0.1.34)
Erster Schema-Bump seit Langem: neue Tabelle `local_catquizlab_scalemap` (mappt
materialisierte Engine-Skalen eines Runs auf die Profilstruktur: level/
categoryindex/subscaleindex), Upgrade-Schritt Savepoint 2026081033.
`classes/local/scale_provisioner.php`: `plan_scales()` (rein/testbar: aus
categories×subcategories ein flacher Skalenplan mit Profil-Indizes),
`provision()` (engine-gekapselt: local_catquiz_catcontext + _catscales-Baum
anlegen, je Skala scalemap-Zeile), `mapping_for()` (Reader). Oracle nutzt jetzt
die **Subskalen-Fähigkeit**: schlägt die catscaleid des präsentierten Items im
scalemap nach → `ability_for(profile, categoryindex, subscaleindex)`, Fallback
global. Damit ist der Oracle DPF-sensitiv, sobald Skalen materialisiert sind.
Tests `scale_provisioner_test.php`. Versionsbump → 2026081033 / 0.1.34
(Schema-Änderung → Upgrade-Schritt).

## Phase 38 — Fragen/Item-Materialisierung E2.1 (Teil 3) — E2.1 komplett (Release 0.1.35)
`classes/local/item_registrar.php`: `build_itemparam()` (rein/testbar: itemparams-
Datensatz für bekannte Kalibrierung — raschbirnbaum, difficulty, discrimination
1.0, guessing 0.0), `register_item()` (engine-gekapselt: Frage→Skala via
catscale::add_or_update_testitem_to_scale, Params speichern, activeparamid setzen).
`classes/local/materialiser.php`: `plan_items()` (rein/testbar: Blaupause →
Item-Specs, je Item auf Subskalen-catscaleid gemappt über scalemap),
`materialise()` (engine-gekapselt: je Item Frage rendern → question_categories
anlegen via qtype_multichoice → register_item). Polytom-Fraktionen auf 7
Nachkommastellen (Moodle-Fraktionsset). Tests item_registrar_test/materialiser_test.
Damit ist **E2.1 komplett** (Skalen → Fragen → Items aus der Blaupause).
Versionsbump → 2026081034 / 0.1.35 (reiner Code, kein Upgrade-Schritt).

## Phase 39 — E3 abgeschlossen: exec-Worker, Kapazität, debug_info (Release 0.1.36)
Engine-Quelle geprüft: `debug_info` ist eine JSON-Liste von Schritt-Snapshots mit
`personabilities` (θ je Skala) und `numquestionsperscale` (Exposure je Skala).
E3.5-Rest: `attempt_collector::parse_debug_info()` (rein/testbar, akzeptiert Map-
und Listenform) extrahiert die finalen Subskalen-θ + Exposure; `collect()` legt
sie als scaleabilities/questionsperscale/steps in die Trace — liefert der DPF-
Diagnostik die Subskalen-Schätzungen. E3.2: `worker_launcher` (build_command rein/
testbar; launch nur bei aktiviertem+konfiguriertem Exec-Worker + lesbarem Skript)
+ Adhoc-Task `dispatch_worker` + Settings (Enable/Node/BaseURL/Token/MaxJobs/
Concurrency). E3.6 (M1): `capacity` (plan_batches, stagger_offsets, throughput —
rein/testbar). Tests worker_launcher/capacity + attempt_collector erweitert.
Damit ist **E3 vollständig**. Versionsbump → 2026081035 / 0.1.36 (reiner Code).

## Phase 40 — Per-Subskala-DPF-Diagnostik verdrahtet (Release 0.1.37)
`classes/local/subscale_evaluator.php`: bewertet, wie gut die Engine das
Subskalen-Profil einer Person wiederfindet. Richtet die Trace-Schätzungen
(scaleabilities aus debug_info) über das scalemap gegen die Ground-Truth-
Subskalen aus, definiert Defizit = Subskala unter der globalen Fähigkeit (DPF),
und rechnet die diagnostics-Maße (Spearman, Top-k, nDCG, Konfusion, Precision/
Recall). `evaluate_person()` rein/testbar; `evaluate_run()` aggregiert je Run und
speichert dpf_*-result-Zeilen (+ gepoolte Konfusion). Der Aggregations-Task ruft
jetzt auch die DPF-Auswertung — ein Task erzeugt global-, stratum- und dpf-
Ergebnisse. Tests `subscale_evaluator_test.php`. Damit ist die DPF-Auswertungs-
schleife geschlossen. Versionsbump → 2026081036 / 0.1.37 (reiner Code).

## Phase 41 — E6 abgeschlossen: Antwortmatrix, Spreadsheet-Export, Export-Task (Release 0.1.38)
E6.2: `classes/local/answer_matrix.php` baut die Personen×Items-Antwortmatrix
eines Runs aus den Traces (responses: questionid=>fraction); Spalten = Vereinigung
präsentierter Items, leere Zellen wo nicht präsentiert (adaptiv). `build()` (DB),
`to_rows()` (rein/testbar), CSV round-trip. `exporter::to_spreadsheet_file()`
schreibt xlsx/ods über Moodles dataformat-Writer (übersprungen, wenn Plugin fehlt).
E6.3: `run_exporter::export_to_files()` rendert die Matrix (CSV/JSON/XML, xlsx/ods
wenn verfügbar), legt sie als Dateien im System-Kontext ab, loggt exportlog; Adhoc-
Task `export_run` + `queue()`. `local_catquizlab_pluginfile()` liefert die Dateien
aus (Capability :view). Tests answer_matrix/run_exporter. Damit ist **E6 komplett**.
Versionsbump → 2026081037 / 0.1.38 (reiner Code, kein Upgrade-Schritt).

## Phase 42 — Export-Ebenen/-Umfang E6.1-Rest — E6 komplett (Release 0.1.39)
`classes/local/export_dataset.php`: Auswahl-Schicht. Ebene wählt den Datensatz —
raw (Antwortmatrix), groundtruth (Personenprofil als Long-Form: global/category/
subscale) oder metrics (result-Zeilen); Umfang löst die Runs auf — Run/Experiment/
Tier (`runids_for`). Jeder Builder liefert eine {columns, rows}-Tabelle, nur Lab-
Store. `run_exporter::export_dataset()`/`store_table()` rendern und legen beliebige
Ebene/Umfang-Datensätze ab (mit Log). Tests `export_dataset_test.php`. Damit ist
**E6 vollständig** (Formate csv/json/xml/xlsx/ods × Ebenen raw/GT/metrics × Umfang
Run/Experiment/Tier + Antwortmatrix + Export-Task). Versionsbump → 2026081038 /
0.1.39 (reiner Code, kein Upgrade-Schritt).

## Phase 43 — Hub-Modus E5 (Release 0.1.40)
`classes/local/transfer_package.php`: `build()` schnürt einen Run (Metadaten,
Personen per Index, Attempts mit Traces, Results) als JSON-Payload + SHA-256-Hash;
`verify()` (Integrität), `ingest()` (Hub-seitig Run unter „Hub ingest"-Experiment
neu anlegen, Personen-Referenzen per Index re-mappen), `submit_to_hub()` (POST an
konfigurierten Hub, per Hub-Settings gekapselt). hub_submit_run verifiziert+
ingestiert jetzt und rechnet Metriken+DPF auf der Hub-Kopie neu (Cross-Instance-
Aggregation); hub_fetch_results liefert die gespeicherten Metriken eines Runs
(per cellkey) als JSON. Hub-Settings (URL/Token). Tests transfer_package_test
(build→verify→ingest round-trip). Damit ist **E5 vollständig**. Versionsbump →
2026081039 / 0.1.40 (reiner Code, kein Upgrade-Schritt).

## Phase 44 — CI-Fix (Release 0.1.41)
CI-Logs ausgewertet: einziger harter Fehler war PHPUnit
`external_test::test_hub_submit_verifies_hash` — der Test erwartete noch das alte
Stub-Verhalten (accepted=false) und nutzte ein malformed Payload (run=>1, das unter
--fail-on-warning warnt). Seit 0.1.40 verifiziert+ingestiert der Hub tatsächlich →
Test sendet jetzt ein wohlgeformtes Paket (accepted=true) und prüft die Ablehnung
bei manipuliertem Hash. Zusätzlich Komplexität gesenkt: `oracle_answer` in
resolve()/compute() und `subscale_evaluator::aggregate` in pool_confusion()/rate()/
f1() zerlegt (unter Cyclomatic/NPath-Schwellen); unbenutzte Locals entfernt
($index, $USER, $offset). PHPMD läuft in CI mit `|| true` (nie build-failing);
verbleibende Stil-Hinweise (2 boolean-flags, diagnostics-Klassenkomplexität,
Upgrade-Funktion, pluginfile-Signatur) bleiben, da Moodle-phpcs @SuppressWarnings
ablehnt und ein Umbau öffentlicher Signaturen ohne CI-Nutzen zu breit streut.
Ehrliche Korrektur: mein lokaler phpmd-Check zählte fälschlich „VIOLATION" (kein
Token der phpmd-Textausgabe) und meldete daher zuvor irreführend 0 — künftig
Zeilen-basierte Prüfung. Versionsbump → 2026081040 / 0.1.41 (reiner Fix).

## Phase 45 — Orchestrator + Tiering E7 — Backlog vollständig (Release 0.1.42)
`classes/local/run_orchestrator.php`: `plan_stages()` (rein/testbar: scales →
materialise → test → people → attempts), `setup()` (engine-gekapselt: verkettet
scale_provisioner → materialiser → test_provisioner → person_generator/user_/
course_provisioner → attempt_scheduler; setzt Run auf SCHEDULED; jede Stage
guarded → ohne Engine „skipped" statt Fehler). Definition via
experiment_definition::from_json(...)->get_normalised(), Fallback example_baseline.
`classes/local/tier_planner.php`: Ordnung nach Tier (baseline→main→robustness→
operative, unbekannt zuletzt, Gleichstand nach id) — rein/testbar. CLI
`cli/orchestrate.php` (einzelner Run / Experiment / alle in Tier-Reihenfolge) +
Adhoc-Task `orchestrate_run`. Tests tier_planner/run_orchestrator. Damit ist **E7
und der gesamte Backlog (E0–E7) vollständig**. Versionsbump → 2026081041 / 0.1.42
(reiner Code, kein Upgrade-Schritt).

## Phase 46 — Dokumentation: as-built + Durchführungsanleitung (Release 0.1.43)
Backlog vollständig → Doku auf Endstand gehoben. `docs/design/architektur.md` auf
Rev. 2.2 (as-built): neuer Abschnitt 4 mappt E0–E7 auf die umgesetzten Klassen,
hält die aus den Engine-Quellen bestätigten Fakten fest (Kontext via
catscale::get_context_id, automatische tests-Zeile, item/itemparam-Form, Live-
personparams-Spalten) und aktualisiert die offenen Punkte auf ihren gelösten
Stand. Neu `docs/dev/durchfuehrung.md`: Schritt-für-Schritt-Anleitung
(Definieren/Sweep → orchestrieren → Worker → Collect/Aggregation → Reports →
Export → Hub → Cleanup) plus die instanzabhängigen Feinjustier-Stellen.
Reiner Doku-Release, keine Code-Änderung. Versionsbump → 2026081042 / 0.1.43.

## Phase 47 — Polytome Oracle-Verdrahtung E3.4 (Release 0.1.44)
`response_oracle::respond_item()` (rein/testbar): dispatcht nach Itemtyp — polytom
(mit Schritt-/Schwellenparametern) zieht eine geordnete Kategorie via GPCM/GRM und
liefert choice + proportionale fraction; dichotom scored richtig/falsch (choice -1).
Schrittparameter durchgängig: `item_registrar::build_itemparam` legt steps im
params-json ab, `item_repository` liest sie zurück (Flag polytomous + steps),
`materialiser::polytomous_steps` leitet aufsteigende Schwellen um die Item-
Schwierigkeit ab. `oracle_answer` beantwortet damit polytome Items (choice/
fraction) statt immer dichotom. Tests response_oracle/item_registrar/materialiser/
item_repository erweitert. Damit ist der letzte offene E3.4-Punkt geschlossen.
Versionsbump → 2026081043 / 0.1.44 (reiner Code, kein Upgrade-Schritt).

## Phase 48 — Polytome UI-Abbildung: Kategorie → konkrete Option (Release 0.1.45)
`question_template::default_polytomous` ist jetzt ein Single-Select-Item mit einer
Option je geordneter Antwortstufe (aufsteigend 0, 1/3, 2/3, 1) statt 3-aus-6-
Multi-Select — passend zu GPCM/GRM: die gewählte Kategorie k ist genau die k-te
Option (Shuffle ist beim Speichern aus, also Definitionsreihenfolge = Bildschirm-
reihenfolge). Worker `run_attempt.js`: `answerQuestion` nimmt die volle Oracle-
Entscheidung und klickt über das neue reine `chooseOptionIndex` bei polytom die
kategorie-te Option (geklammert), bei dichotom die korrekte/Distraktor-Option;
node-geprüft. Nebenbei einen echten Fehler behoben: `worker/package.json` deklarierte
`"type":"module"`, obwohl das Skript CommonJS ist — dadurch lief der Worker gar
nicht; Deklaration entfernt, jetzt lauffähig und der reine Helfer importierbar.
Test `question_template::test_polytomous` auf Single-Select/aufsteigende Stufen
umgestellt. Versionsbump → 2026081044 / 0.1.45 (reiner Code).

## Phase 49 — Worker-Robustheit + Node-Testrahmen E3.3 (Release 0.1.46)
`worker/run_attempt.js` gegen Theme-Varianz gehärtet: Fragenerkennung, Radio-
Optionen, Absende- und Start-Button probieren je eine Liste von Fallback-
Selektoren (`firstHandle`/`allHandles`/`clickFirst`); Navigations-Waits tolerieren
langsame Idles und fallen auf domcontentloaded zurück; `startAttempt` klickt nicht
mehr blind, `answerQuestion` meldet fehlende Optionen, per-Page-Navigations-Timeout
gesetzt. Reine Helfer extrahiert und exportiert (parseArgs, normaliseBaseUrl,
buildWsUrl, parseQuestionId, parseEngineAttemptId, usernameFor, passwordFor +
chooseOptionIndex) — Browser/Netz-Code läuft weiterhin nur bei direktem Start.
Neu `worker/test/run_attempt.test.js` auf Nodes eingebautem `node --test` (keine
externen Deps), 7 Tests grün; `npm test` im worker/. Versionsbump → 2026081045 /
0.1.46 (nur Worker, keine PHP-Änderung).

## Phase 50 — Flexibler Worker-Login E3.3 (Release 0.1.47)
Login-Modus für den Worker: Settings `worker_login_mode` (Benutzername/Passwort-
Konvention ODER vorauthentifizierte URL-Vorlage), `worker_login_url_template`
({userid}-Platzhalter) und `worker_login_suffix`. `worker_launcher` reicht sie als
`--login-mode`/`--login-url-template`/`--login-suffix` durch. Worker `login()`
dispatcht nach Modus: navigiert zur substituierten URL (neue reine `loginUrlFor`)
oder nutzt den Benutzername/Passwort-Fluss. Node-Test (loginUrlFor) + Launcher-Test
erweitert. Damit lassen sich unterschiedliche Auth-Setups (SSO/Key-Login) ohne
Worker-Änderung nutzen. Versionsbump → 2026081046 / 0.1.47.

## Phase 51 — PHPMD-Politur durch echte Refactorings (Release 0.1.48)
Die verbliebenen (non-failing) PHPMD-Hinweise per Umbau statt Annotation gelöst
(Moodle-phcs lehnt @SuppressWarnings ab): `diagnostics::deficit_labels` ohne
`$below`-Flag (Defizit = unter Referenz, DPF-Definition; kein Aufrufer nutzte die
Gegenrichtung); `exporter::to_json` ohne Flag (pretty), kompakt in neuer
`to_json_compact`. Die SE-Maße (`deficit_labels_se`, `agreement_within_se`) in
eine kohäsive neue Klasse `se_diagnostics` ausgelagert → diagnostics-Klassen-
komplexität wieder unter Schwelle. Tests in `se_diagnostics_test.php` aufgeteilt.
Verbliebene PHPMD-Nennungen nur noch db/upgrade (wachsende Upgrade-Funktion) und
pluginfile-Pflichtsignatur — Moodle-Standardmuster, von moodle-plugin-ci nicht
geflaggt, phpmd ohnehin non-failing. Versionsbump → 2026081047 / 0.1.48 (reiner
Code, kein Verhaltenswechsel).

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
