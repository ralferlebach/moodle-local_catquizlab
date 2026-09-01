# Issue-Abarbeitung Session 002

Eine Zuordnung der sieben GitHub-Issues zu dem, was umgesetzt wurde, und zu den
Tests, die es absichern. Die vollständige Begründung steht im Sitzungsprotokoll
`docs/sessions/session-002.md`.

---

## #1 — Experimentdefinition erreicht die CAT-Testkonfiguration nicht

**Umgesetzt:** `strategy_catalog` als zentrale Mapping-Quelle;
`test_provisioner::options_from_definition()` und `effective_parameters()`;
`run_orchestrator::stage_test()` reicht Strategie, globale und Subskalenbudgets
sowie SE_min/SE_max durch. `DEFAULT_STRATEGY = 4` ist deprecated und wird nicht
mehr konsultiert. Das Manifest hält die effektiven Parameter und die
Zielinformation `I = 1/SE²` fest.

**Tests:** `catalog_test` (jede Strategie auf die erwartete Engine-ID,
Label-Eindeutigkeit, unbekannter Key wird abgewiesen),
`definition_drives_run_test::test_strategy_reaches_the_cat_configuration`
(Regression: `classic` → 7, nicht 4), `test_budgets_and_se_reach_the_cat_configuration`,
`test_budget_cells_differ`, `test_effective_parameters_document_the_information_target`.

## #2 — Poolvarianten wirkten im E2E-Pfad nicht

**Umgesetzt:** `pool_mutator::mutate()` wird in `materialiser::materialise()`
aufgerufen; Variant, Recipe und beide Seeds landen in `local_catquizlab_pool`,
das damit produktiv wird. Studienwerte `+1.0` und `×1.25`. `gappy` ist
Fixed-N (Umverteilung an den Bandrand), `depleted` bleibt die verkleinernde
Störung. `validate_recipe()` prüft Schlüssel und Wertebereiche und verlangt bei
Publication-Runs explizite Angaben. Neue Tabelle `local_catquizlab_item` trennt
wahre von gespeicherter Schwierigkeit und wahre von zugewiesener Skala;
`oracle_answer` liest die Ground Truth. Eine nicht materialisierbare Mutation
lässt den Run fehlschlagen statt als geplant zu gelten.

**Tests:** `pool_mutator_test::test_gappy` (Itemzahl konstant),
`test_gappy_remove_mode_reduces_the_pool`,
`definition_drives_run_test::test_calibration_error_separates_truth_from_engine_view`,
`test_tagging_error_separates_assigned_from_true_scale`,
`test_ideal_pool_has_no_divergence`.

## #3 — 2PL/3PL materialisierten keine modellgerechten Itemparameter

**Umgesetzt:** `model_catalog` trennt publikationsnahe Bezeichnung vom
Engine-Key (`1pl`→`rasch`, `2pl`→`raschbirnbaum`, `3pl`→`mixedraschbirnbaum`);
`distribution` macht die a-/c-Verteilungen deklarativ; `pool_planner` zieht sie
seed-deterministisch; `materialiser::plan_items()` übernimmt Modell und
Parameter; `item_registrar` löst den Engine-Key über den Katalog auf. Ein
Publication-Run mit konstantem a wird abgewiesen, sofern er nicht ausdrücklich
als Kontrollbedingung markiert ist.

**Tests:** `definition_drives_run_test::test_1pl_pool_is_controlled`,
`test_2pl_pool_has_varying_discrimination`, `test_3pl_pool_has_guessing`,
`test_pool_generation_is_seed_deterministic`,
`test_materialiser_uses_the_model_engine_key`, `test_registrar_respects_the_model`.

## #4 — Digital-Twin- und Strata-Generator

**Umgesetzt:** `seed_domains` trennt Personen-Basis, Personen-Abweichung, Pool,
Mutation und Antwort; der Personen-Seed hängt nicht mehr am Cellkey. Stabile
`twinid` je Replikation und Person. `subscalevariation` ist kumulativ
(`[0.5, 0.5]` statt `[0.0, 0.5]`), `chaotic` ist ein eigener Generatormodus mit
weitgehend unabhängigen lokalen Ziehungen, Severity skaliert die
Basisabweichung und ist Sweep-Faktor. Manifest und Export führen Twin-ID,
Stratum und Severity.

**Tests:** `seed_domains_test` (Twins überleben Strategie- und Poolzellen,
Stratum 3 enthält Stratum 2, Severity skaliert, chaotic ist eigener Modus,
conforming bleibt flach, Manifest dokumentiert die Abhängigkeiten).

## #5 — Polytome Zusatzexperimente (GPCM)

**Umgesetzt:** GPCM ist deklarativ wählbar und wird auf `pcmgeneralized`
abgebildet (am Engine-Quellcode verifiziert); PCM, GRM und GGRM ebenso.
Polytomie folgt aus dem Modell statt aus einer Setup-Option. Der Blueprint
enthält geordnete Schwellen; die Kategorienzahl ist deklarativ und bestimmt die
Optionenzahl der erzeugten Frage. Die Oracle wählt ihre Antwortfamilie über den
Katalog.

**Tests:** `catalog_test::test_gpcm_and_grm_are_not_conflated`,
`definition_drives_run_test::test_gpcm_pool_has_ordered_steps`,
`test_gpcm_is_not_materialised_as_grm`, `question_template_test`.

## #6 — Praxisnahe Strategiebezeichnungen

**Umgesetzt:** `strategy_catalog` führt internen Key, Engine-Konstante,
englisches Label und Beschreibung an einer Stelle. UI, Manifest und Export
geben Key und Label gemeinsam aus; Cellkeys verwenden weiter den internen Key,
bestehende Runs bleiben lesbar.

**Tests:** `catalog_test::test_every_strategy_has_a_descriptor`,
`test_strategy_labels_are_unique`,
`experiment_service_test::test_overview_carries_publication_labels`,
`run_registry_test::test_compare_uses_publication_labels`.

## #7 — Web-UI für Anlegen, Ausführen und Auswerten

**Umgesetzt:** `experiment_service` als gemeinsame Schicht für CLI, Web und API;
`experiment_io` für den JSON-Austausch mit Schemaversionierung, Migration und
Konfliktbehandlung; die Seiten `experiment.php`, `import.php`, `runs.php` und
`compare.php`; `run_registry` für Filter, Run-Detail und Zellvergleich; drei
zusätzliche Capabilities; die Verwaltungsseite mit „Neues Experiment anlegen".

**Tests:** `experiment_service_test` (21 Fälle: Speichern, Unveränderlichkeit
ausgeführter Experimente, Vorschau deckungsgleich mit der CLI-Expansion,
Export-Roundtrip, Schemaabweisung, Migration, Konflikte, Import startet nichts),
`run_registry_test` (12 Fälle: Auflösung, Filter, Paging, Vergleich mit
Intervall, Fehlermeldung), `experiment_workflow.feature` (9 Szenarien).

**Nicht umgesetzt, bewusst:** Die Testverlaufsansicht je Run (Kapitel 15)
braucht die `debug_info`-Schrittdaten aus einem echten Attempt und ist ohne
laufende Engine nicht sinnvoll zu bauen. Die Defiziterkennungsmetriken sind im
`diagnostics`-Kern vorhanden, aber noch nicht in der Vergleichsansicht
sichtbar.
