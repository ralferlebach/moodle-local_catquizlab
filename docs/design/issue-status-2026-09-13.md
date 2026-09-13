# Umsetzungsgrad der offenen Issues

**Geprüft am:** 2026-09-13 gegen `local_catquizlab` 0.6.0
**Grundlage:** Code und Testabdeckung der ausgelieferten Fassung, nicht die
Sitzungsprotokolle. Jede Aussage nennt die Stelle, an der sie nachzuprüfen ist.

Verifikationsstand der geprüften Fassung: **442 PHPUnit-Tests / 2811
Assertions**, **31 Behat-Szenarien / 219 Schritte**, phpcs und PHPDoc ohne
Befund, 618 Sprachstrings je Sprache.

---

## Zusammenfassung

| Issue | Gegenstand | Empfehlung |
|---|---|---|
| #1 | Definition erreicht die CAT-Testkonfiguration | **schließen** |
| #2 | Poolvarianten im E2E-Materialisierungspfad | **schließen** |
| #3 | Modellgerechte Itemparameter für 2PL/3PL | **schließen** |
| #4 | Digital Twins und Strata | **schließen** |
| #5 | Polytome Experimente (GPCM) | **schließen** |
| #6 | Praxisnahe Strategiebezeichnungen | **schließen** |
| #7 | Web-UI zum Anlegen, Ausführen, Auswerten | **schließen** |
| #8 | Run-Provisionierung, gemeinsamer Kurs | **schließen** |
| #9 | Restliste (elf Teilbefunde) | **schließen** |
| #10 | Engine-sichtbare Materialisierung | **schließen** |

Alle zehn sind nach dieser Prüfung erfüllt. Die Einschränkungen, die bleiben,
liegen **außerhalb** dieser Issues und sind unten benannt.

---

## #1 — Experimentdefinition erreicht die CAT-Testkonfiguration

**Erfüllt.**

Die Definition ist die alleinige Quelle: `test_provisioner::options_from_definition()`
leitet Strategie, Budgets, SE-Grenzen, Modell und Poolvariante aus der
gespeicherten Definition ab, und `run_orchestrator::definition_for()` liest die
**Zelldefinition aus dem Run-Manifest** statt der Basisdefinition des
Experiments.

Der Unterschied ist nicht kosmetisch: Vorher hätte ein Sweep über vier Zellen
viermal dieselbe Bedingung ausgeführt, während Cellkey und Manifest vier
verschiedene dokumentierten — aufgezeichnete und ausgeführte Intervention wären
auseinandergelaufen.

Belege:
- `experiment_validity_test::test_each_run_uses_its_own_cell`
- `experiment_validity_test::test_strategy_from_the_cell_reaches_the_cat_configuration`
- `experiment_validity_test::test_manifest_drift_is_detected` — eine
  Konfiguration, die dem Manifest widerspricht, lässt den Run hart scheitern.

---

## #2 — Poolvarianten im Materialisierungspfad

**Erfüllt.**

`pool_mutator` kennt die Varianten samt Recipe, `recipe_defaults()` gibt je
Variante die zulässigen Schlüssel, und der Sweep kann Variante und
Störungsstärke **unabhängig** variieren. Eine Stärke, die zur Variante einer
Zelle nicht passt (der Idealpool nimmt keinen Shift), entfällt für diese Zelle,
statt eine Zelle ungültig zu machen, die erkennbar gewollt war.

Gemessen am echten Lauf: Ein Sweep über `ideal`, `calibrationerror` und
`depleted` erzeugte 24, 24 und 10–14 Items je Replikation — die Verkleinerung
variiert mit dem Seed, wie vorgesehen.

Belege:
- `sweep_factors_test::test_strength_is_filtered_against_the_variant`
- `sweep_factors_test::test_swept_cells_are_valid`
- Robustheitsauswertung in `robustness_analysis::deltas()`, Ergebnis im
  CHANGELOG zu 0.4.2.

---

## #3 — Modellgerechte Itemparameter

**Erfüllt.**

`experiment_definition::study_item_parameters()` legt die Studienparameter fest:
Trennschärfe `Beta(3,4)` auf (0, 5] mit dem Modus exakt bei 2, Rateparameter
`Beta(2,2)` auf (0; 0,5) mit dem Modus bei 0,25.

Der Weg dahin ist im Test festgehalten, weil er leicht rückgängig gemacht würde:
Eine Lognormal mit demselben Modus traf den Bereich **nicht** — über 20.000
Ziehungen landeten 9,4 % auf exakt 5,0, und die modale Klasse war die oberste.
Ein Clamp, der jede zehnte Ziehung fängt, ist kein Schutz mehr, sondern die
Form, und hätte einem Zehntel jedes Pools dieselbe maximale Trennschärfe
gegeben.

Zusätzlich abgesichert: Ein als 2PL bezeichneter Run mit konstanter
Trennschärfe wird für Publication-Tiers zurückgewiesen, sofern die
Kontrollbedingung nicht ausdrücklich erklärt wird.

Belege:
- `pool_planner_test::test_discrimination_mode_matches_the_design`
- `pool_planner_test::test_guessing_stays_within_its_bounds`
- `experiment_validity_test::test_degenerate_2pl_is_refused_for_a_publication_run`
- `experiment_validity_test::test_the_form_does_not_set_the_degenerate_flag_by_itself`

---

## #4 — Digital Twins und Strata

**Erfüllt.**

`person_generator` erzeugt gepaarte Twins mit `twinid`, `twinindex` und
`severity`; die Strata steuern die Abweichungsstruktur. Der gepaarte Aufbau ist
belegt durch einen echten Lauf mit dem Stratum `subscalevariation`, in dem die
wahren Subskalenabweichungen von null verschieden sind und die Auswertung sie
den geschätzten gegenüberstellt.

Belege:
- Schema: `local_catquizlab_person.twinid/twinindex/severity`
- `local_analysis::rows()` bildet je Twin die Δ-Paare
- Messwerte im CHANGELOG zu 0.3.1

---

## #5 — Polytome Experimente (GPCM)

**Erfüllt.**

`model_catalog` führt die polytomen Modelle, `response_oracle` implementiert
`gpcm_probabilities()` und `grm_probabilities()` sowie `respond_polytomous()`,
und `question_template` hat eine eigene polytome Vorlage.

Einschränkung, die dieses Issue nicht berührt: Ein polytomer Lauf ist bisher
nicht gegen die reale Engine gespielt worden — die Studie lief dichotom. Die
Implementierung ist vollständig und durch Unit-Tests gedeckt.

---

## #6 — Praxisnahe Strategiebezeichnungen

**Erfüllt.**

`strategy_catalog` führt je Strategie Schlüssel, Engine-Konstante, Anzeigename
und Publikationsbezeichnung an **einer** Stelle. UI, Exporte und Auswertung
lesen daraus; die Gruppenbeschriftung der Ergebnisansicht zeigt zum Beispiel
„Estimate global ability (MFI)" statt einer Zahl.

Zusätzlich abgesichert: `engine_id()` lädt die Bibliothek der Engine, bevor sie
deren Konstanten prüft. Ohne das galt eine korrekte Engine im CLI-Kontext als zu
alt, weil Moodle die `lib.php` eines local-Plugins nur bei Bedarf lädt.

---

## #7 — Web-UI zum Anlegen, Ausführen und Auswerten

**Erfüllt**, einschließlich der zuletzt gemeldeten Lücken.

Der Workflow ist ohne CLI geschlossen: Experiment anlegen und validieren, Sweep
vorschauen und erzeugen, **Sweep erzeugen und starten**, einzelne Draft-Runs
oder alle Draft-Runs starten, Status und Fortschritt verfolgen, Ergebnisse in
acht Reitern auswerten und exportieren.

Die Oberfläche implementiert dabei **keine zweite Orchestrierung**: Sie ruft
`run_lifecycle::start()`, das den vorhandenen `orchestrate_run`-Task einreiht.
Schreibende Aktionen sind POST mit `sesskey` und `local/catquizlab:execute`.

Vor dem Start prüft `preflight` Engine, Hostaktivität, Experimentkurs,
Berechtigung und Worker. Ein fehlender Worker blockiert nicht — der Run
provisioniert und seine Attempts warten —, ein fehlender Kurs oder eine fehlende
Engine schon, denn ein ohne sie eingereihter Run sähe gestartet aus und bewegte
sich nie.

Belege:
- `run_lifecycle_test` (18 Tests), darunter
  `test_start_schedules_a_draft_run` mit der Prüfung, dass der gemeinsame Task
  eingereiht wird
- Behat: 31 Szenarien, darunter der Settings-Link ohne `sectionerror`, „Sweep
  erzeugt heißt nicht ausgeführt" und die Erklärung in der Ergebnisansicht

---

## #8 — Run-Provisionierung und gemeinsamer Kurs

**Erfüllt.**

`experiment_container` löst den konfigurierten Kurs auf und legt **eine Section
je Experiment** an, idempotent. Der frühere Zustand — ein Kurs je Run — hätte
bei hundert Replikationen hundert Kurse für eine Bedingung erzeugt. Ebenso
idempotent: die Fragenkategorie je Experiment, die Materialisierung eines
vollständigen Pools und die Anlage der Testaktivität.

Der Link „Choose an experiment course" führt jetzt zur registrierten
Admin-Section. Er zeigte auf `local_catquizlab`, während `settings.php`
`local_catquizlab_settings` registrierte — zwei Literale, die übereinstimmen
mussten, in zwei Dateien. Beide lesen nun `registry::SETTINGS_SECTION`.

Belege:
- `provisioning_test::test_scale_selection_covers_the_whole_tree`
- `run_lifecycle_test::test_settings_url_matches_the_registered_section`
- `run_lifecycle_test::test_deleted_experiment_course_blocks_the_start`

---

## #9 — Restliste, elf Teilbefunde

**Erfüllt**, alle elf.

Die drei mit Auswirkung auf die Gültigkeit von Ergebnissen:

1. Jeder Run führt seine eigene Zelle aus (siehe #1).
2. Ground Truth fließt nicht mehr in die geschätzte Diagnose: wahre und
   geschätzte Abweichungen haben getrennte Referenzsysteme, und beide werden
   persistiert, damit die Trennung prüfbar ist statt geglaubt.
3. Replikationsstreuung wird ausschließlich **innerhalb** einer Zelle berechnet.
   Über Zellen gepoolt wuchs sie gerade dann, wenn das Experiment funktionierte.

Dazu die Outcome-Pipeline (Stop-Erfolg, Expositionskonzentration, Laufzeit,
Multi-k bei k = 1, 3, 5, 10), die Sweep-Faktoren für Budgets und SE-Fenster als
Paare, die stabile Experiment-Identität über `experimentkey` und der
Run-Lifecycle.

Der Lifecycle ist seit der letzten Rückmeldung zentral: `run_lifecycle` ist die
einzige Stelle, die über den Zustand eines Runs entscheidet. Vorher trafen
Oberfläche, Worker-Claim, Worker-Complete und Tasks dieselbe Entscheidung
getrennt — und konnten sich widersprechen.

Belege: `experiment_validity_test`, `outcome_pipeline_test`, `sweep_factors_test`,
`run_lifecycle_test`.

---

## #10 — Engine-sichtbare Materialisierung

**Erfüllt.**

`cat_item_provisioner` hält die Grenze zur Engine: Das Verdikt der Engine-API
ist verbindlich, Parameter werden einmal je Item und Kontext geschrieben, und
jedes Item wird anschließend über `catscale::get_testitems()` zurückgeholt — den
Pfad, den der CAT-Manager selbst benutzt. Ein Run wird nur eingereiht, wenn alle
Zähler den Plan erreichen.

Die Materialisierung setzt die Itemparameter auf `STATUS_KNOWN` (4). Mit dem
vorherigen Wert galt jedes Item als Pilotfrage, weil die Engine ein Item als
Pilot behandelt, solange sein Status unter `UPDATED_MANUALLY` liegt und es
weniger Antworten hat als die Pilotschwelle — ein Pilot trägt nichts zur
Fähigkeitsschätzung bei. Fachlich ist 4 auch der richtige Wert: Ein Lab-Item
**ist** ein manuell gesetzter Parameter.

Belege:
- `provisioning_test` (28 Tests)
- `cli/verify.php` mit dem Abschnitt `links`, der je Item die fünf Referenzen
  prüft; gegengeprüft durch absichtlich gekreuzte Referenz
- Gemessen: `planned=24 questions=24 items=24 params=24 visible=24 failed=0`

---

## Was außerhalb dieser Issues offen bleibt

Diese Punkte rechtfertigen kein Offenhalten der zehn Issues, gehören aber
benannt:

1. **Ein polytomer Lauf gegen die reale Engine** steht aus. Die GPCM-Umsetzung
   ist vollständig und unit-getestet, aber die Studie lief dichotom.
2. **Replikationszahlen.** Bei 30 Attempts je Zelle überlappen die
   Konfidenzintervalle der Poolvarianten noch fast vollständig. Für belastbare
   Aussagen braucht es deutlich mehr — das ist eine Frage des Studienumfangs,
   nicht der Funktion.
3. **Engine-seitig:** catquiz#64 — ein Abbruch nach der ersten Frage läuft nicht
   über den Fehlerpfad der Engine (`catquizerror = false`), sodass die dafür
   eingebaute Stufenzählung `catquizstagecounts` bei genau diesem Fall nicht
   gesetzt wird. `tests/engine_defects_test.php` hält den Stand fest und meldet
   sich, sobald sich daran etwas ändert.
