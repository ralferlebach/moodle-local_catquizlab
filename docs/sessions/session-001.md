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

## Verifikationsstand
Container: PHP-Syntax, install.xml, YAML, Worker-JS grün; PHPCS (Moodle) 0/0
über alle PHP-Dateien; höchster Upgrade-Savepoint ≤ Plugin-Version. PHPUnit/
Behat laufen in der CI (kein Moodle im Container); Validator- und Sweep-Logik
zusätzlich per CLI-Harness geprüft.

## Next
E1.3 Run-Tabelle (wunderbyte_table) in der Verwaltungsseite mit Status/
Fortschritt; danach E2.1 Pool-Generator (Templates→Items, Skalenbaum),
E2.3/E2.4 (Personen als Nutzer, Kurse/Tests, Einschreibung) Richtung M1.
