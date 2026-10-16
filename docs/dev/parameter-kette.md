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
