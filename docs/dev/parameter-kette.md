# Parameterkette: Formular → Definition → Zelle → Engine → Bearbeitungen (#123)

Jede Zeile wird je Lauf von `run_contract::chain()` verfolgt und auf der
Laufseite, im Reproduzierbarkeitspaket (`runs[].contract`) und – je Bearbeitung –
im Export (`contractviolations`) ausgewiesen.

## Zuordnung

| Parameter | Formular | Definition (JSON) | Wirksam (`test_provisioner::options_from_definition`) | Engine (`local_catquiz_tests.json`) | Beobachtet |
|---|---|---|---|---|---|
| Strategie | Strategie / Sweep-Faktor `strategy` | `strategy` | `teststrategy` (Engine-ID) | `catquiz_selectteststrategy` | – |
| Globales Maximum | Max. Fragen (global), Zellbudget | `budgets.global.maxitems` | `maxquestions` (−1 = unbegrenzt) | `maxquestionsgroup.catquiz_maxquestions` | meiste Fragen einer Bearbeitung |
| Globales Minimum | Min. Fragen (global) | `budgets.global.minitems` | `minquestions` (Vorgabe 10) | `maxquestionsgroup.catquiz_minquestions` | wenigste Fragen |
| Maximum je Subskala | Max. Fragen je Subskala | `budgets.subscale.maxitems` | `maxquestionspersubscale` | `maxquestionsscalegroup.catquiz_maxquestionspersubscale` | meiste Fragen auf einer Subskala (aus den Schritten) |
| Minimum je Subskala | Min. Fragen je Subskala | `budgets.subscale.minitems` | `minquestionspersubscale` | `maxquestionsscalegroup.catquiz_minquestionspersubscale` | – |
| SE untere Grenze | SE min | `budgets.se.min` | `se_min` | `catquiz_standarderrorgroup.catquiz_standarderror_min` | kleinster End-SE |
| SE obere Grenze | SE max | `budgets.se.max` | `se_max` | `catquiz_standarderrorgroup.catquiz_standarderror_max` | größter End-SE |
| Pilotfragen | Pilot | `pilot.include`, `pilot.ratio` | `includepilot` (neutralisiert, wo die Strategie keine kann) | `catquiz_includepilotquestions` | – |
| Skalenbereich | Fähigkeitsverteilung | `persons.ability` | `ability_distribution::of()` min/max | Wurzelskala `minscalevalue` / `maxscalevalue` | – |
| Alle Items (klassisch) | Strategie „klassischer Test“ | – | Poolgröße | `catquiz_maxquestions` = −1 | Fragen je Bearbeitung |

Welche Strategie welche Grenze nutzt, steht in `strategy_catalog` (`uses`);
eine ungenutzte geht als neutraler Wert an die Engine und wird als
„von der Strategie nicht genutzt“ ausgewiesen, nicht verschwiegen.

## Status je Zeile

`stimmt überein` · `von der Strategie nicht genutzt` · `Vorgabe des Plugins`
(nicht angefordert, Vorgabe genannt) · `ersetzt` (angefordert ≠ wirksam ohne
Neutralisierung) · `Engine weicht ab` · `von Bearbeitungen verletzt` ·
`nichts zu vergleichen` (kein bereitgestellter Test, keine Bearbeitung).

## Regeln je Bearbeitung

| Regel | Art | Bedingung |
|---|---|---|
| `global_max_exceeded` | Defekt | Fragen > globales Maximum (> 0) |
| `global_min_undercut` | Defekt | Fragen < globales Minimum, Stopcode nicht „Pool erschöpft“ |
| `pool_exhausted_before_min` | Design | Fragen < globales Minimum, Stopcode `noremainingquestions` / `no_eligible_item_before_minimum` |
| `subscale_max_exceeded` | Defekt | eine Subskala (Blatt) mit mehr Fragen als ihr Maximum, wo die Strategie es nutzt |
| `classic_not_all_items` | Defekt | klassischer Test mit weniger Fragen als der Pool |
| `se_stop_above_target` | Defekt | Stopcode `standarderror`, End-SE über dem SE-Maximum (+ 0,0005 Rundungstoleranz), wo die Strategie den SE nutzt (seit 0.7.46) |
| `subscale_min_undercut` | Defekt | eine bei Testende **aktive** Subskala (`progress.activescales`) mit weniger Fragen als ihr Minimum, bei einem Ende aus eigenem Kriterium (nicht Pool erschöpft, nicht globales Maximum), wo die Strategie das Subskalen-Minimum nutzt. Gestrichene oder gesperrte Skalen zählen nicht (seit 0.7.46) |

Ein Defekt der Engine (Grenze überschritten) wird als Issue im Engine-Repository
gemeldet (https://github.com/ralferlebach/moodle-local_catquiz/issues), nicht
im Lab umgangen.

## Nachweis

- PHPUnit `tests/run_contract_test.php`: Kette, Vorgaben, Neutralisierung,
  simulierte Grenzverletzungen (Maximum, Minimum mit/ohne erschöpften Pool,
  Subskalen-Maximum, klassischer Test), Export-Spalte.
- `cli/smoke.php` prüft nach jedem Lauf die Kette; Drift, Ersetzung oder eine
  Defekt-Regel lassen ihn scheitern. `smoke_all.sh` (Worker-E2E) läuft je reale
  Strategie: fastest, allsubs, lowestsub, highestsub, classic, relsubs – lokal
  alle ohne Defekt.
- Engine-Rücklesung bleibt in `provisioning_check` (Fall 102/104 im Worker-E2E).

## End-to-End-Matrix (0.7.57)

`cli/contract_matrix.sh` spielt pro Fall ein eigenes Experiment mit echten Sittings. Der Worker spielt sie im echten
Browser durch das echte adaptive Quiz.

Die Fälle:
- die des Rücklesetests: fastest, allsubs und classic mit 2–4 Fragen; global 3–6 und 1–8; Subskala 1–3 und 2–2;
  SE 0,30 und 0,50/0,9,
- der klassische Test mit allen Items,
- Sweep mit Zell-Overrides (#104),
- Wurzelskala (#102).

Jeder Fall prüft mit `cli/smoke.php`:

1. Die Engine hält, was verlangt wurde (Rücklesung).
2. Die Sittings halten ihren Vertrag: Keine Grenze ist verletzt, kein Parameter driftet.
3. Jedes Sitting endet überall gleich und ist überall gleich lang. Verglichen wird:
   - der Engine-Eintrag, unabhängig gelesen: Status in `local_catquiz_attempts`, `stopcode` von `adaptivequiz_attempt`
     und Stopptext,
   - die Analyse, also das, was das Attempt-Detail und die Reiter zeigen,
   - der Export der Sittings,
   - der Vertrag im Reproduktionspaket.

`cli/smoke.php --budget=G1,G2,S1,S2,SE1,SE2` spielt einen einzelnen Fall mit eigenen Budgets.
