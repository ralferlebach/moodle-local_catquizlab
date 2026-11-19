# R-Reanalyse: Kennzahlen aus „4. Results“ unabhängig nachrechnen (#157)

Code: `research/run_all.R`, `research/R/*.R` (Basis-R ≥ 4.1, keine Pakete), im Forschungsdatenpaket unter
`reanalysis/`. Referenz: `classes/local/research_reference.php` schreibt `reference/dashboard.csv` mit denselben
Aufrufen, die die Ergebnisseite macht. Test: `research_package_test::test_the_r_reanalysis_reproduces_the_dashboard`.
CI: `moodle-ci.yml` und `moodle-release.yml`, Job PHPUnit, R 4.3.3 gepinnt, `CATQUIZLAB_REQUIRE_R=1`. Seit 0.7.61; vollständig
(ohne NOT_REPRODUCED) seit 0.7.71.

## Ablauf

1. Paket prüfen: jede Tabelle hat die Zeilenzahl und md5 aus `manifest-tables.csv`.
2. Tabellen lesen: Typen aus `codebook.csv`; leere Zelle bei Zahlen = NA, bei Text = "".
3. Population: `attempts` mit `analysis_population.inpopulation = 1`. Fehler = `esttheta − truetheta` aus den
   Rohfähigkeiten (nicht aus der Spalte `error`); ohne Schätzung NA.
4. Kennzahlen nachrechnen (`R/figures.R`), mit der Referenz vergleichen (`R/compare.R`), Plotdaten und Plots schreiben
   (`R/plots.R`), Abdeckungsmatrix (`R/coverage.R`).
5. Exit 0 ohne FAIL, sonst 1.

## Kennzahleninventur (DoD1)

Jede Anzeige der sechs Tabs, ihre Definition, die Spalten, aus denen R sie rechnet, und ob sie verglichen wird.

### Overview

| Anzeige | Definition | R-Funktion | Spalten | Stand |
|---|---|---|---|---|
| Testlänge, SE, Bias, Laufzeit (Karten, Strategie- und Zellentabelle) | n, Mittel, SD (n−1), SE und 95-%-KI des Mittels über die unabhängigen Einheiten (Mittel je Einheit, 1,96), Median, Q1, Q3 (Typ 7), Min, Max, Zahl der Einheiten; je auf 6 Stellen | `describe` | `attempts.nitems, se, error (=esttheta−truetheta), runtimems`; Einheit aus `twinid, experimentid, stratum, severity, replication` | verglichen |
| RMSE | √(Σ(est−true)²/n) über Testbearbeitungen mit Schätzung | `recovery` | `attempts.truetheta, esttheta` | verglichen |
| Korrelation | Pearson est/true, null bei n<2 oder Varianz 0 | `recovery` | dito | verglichen |
| Stopp-Erfolg | 100 × Anteil `stopreached` über **alle** Testbearbeitungen der Auswahl (gültig oder nicht); je Strategie, je Zelle | `stop_success` | `attempts.stopreached` | verglichen |
| Konzentration (Gini) | Gini über die Expositionsraten (Anzahl Vorkommen/gezählte Testbearbeitungen, auf 6 Stellen), Pool mit Nullen aufgefüllt | `concentration` | `steps.questionid` der gezählten, `items` (Poolgröße) | verglichen |
| Läufe, Testbearbeitungen, Personen je Gruppe | verschiedene `runid`; Zeilen; verschiedene Einheiten | `summary_rows` | `attempts` | verglichen |
| Testlänge gegen Präzision (Plot) | Punkte (nitems, se) | `write_plot_data` | `attempts` | Plotdaten (alle Punkte) |
| Simulierte Personen | wahre Fähigkeiten | – | `twins`, `truth_profiles` | nur Daten |

### Global metrics

| Anzeige | Definition | R-Funktion | Stand |
|---|---|---|---|
| Schätzung gegen Wahrheit, Fehler gegen Wahrheit | Bias (wie oben), RMSE, Korrelation; Punkte | `global_figures`, `write_plot_data` | verglichen, Plotdaten |
| Außerhalb des Skalenbereichs | true < `abilitymin` oder > `abilitymax`, nur mit Schätzung | `global_figures` | verglichen |
| Exposition | Gini, HHI (Σ Anteil²), größte und mittlere Rate, Anteil > 2 × Mittel, benutzte und unbenutzte Items, größte Rate; Kurve | `global_figures`, `write_plot_data` | verglichen, Plotdaten |
| Zellentabelle | wie Overview | `overview_figures` | verglichen |

### Raw data, Export

Zeigen die Zeilen selbst; `attempts` und die übrigen Tabellen sind sie. Keine abgeleitete Kennzahl.

### Subscales

| Anzeige | Definition | R-Funktion | Stand |
|---|---|---|---|
| Lokale Genauigkeit | über gültige Skalenergebnisse gezählter Testbearbeitungen (Filter „valid“; bei „all“ alle mit Item oder unbekannter Zahl; bei „invalid“/„unknown“ die Skalenergebnisse in diesem Zustand aller Testbearbeitungen mit Subskalen-Zuordnung, gültig oder nicht, mit Item oder unbekannter Zahl): n, Bias = Mittel(estdelta−truedelta), RMSE, MAE, Pearson(truedelta, estdelta), mittlerer lokaler SE, Anteil innerhalb 1/2 SE (unter denen mit SE), Zahl mit SE | `local_summary` | verglichen |
| Je Kategorie, je Subskala | dito gruppiert | `subscale_figures` | verglichen |
| Skalenabdeckung | über alle Testbearbeitungen der Auswahl mit Subskalen-Zuordnung, gültig oder nicht: Testbearbeitungen; Skalenergebnisse mit verabreichtem Item = Skalenergebnisse mit Item + Subskalen, die laut `steps` ⋈ `items.assignedcatscaleid` ⋈ `scale_maps` (Ebene subscale) ein Item bekamen, aber keine Schätzung haben; einbezogen (gültig im Filterzustand); aus ungültigen Testbearbeitungen; ohne Schätzung; Itemzahl unbekannt; ohne Item. Zählkette: aus gültigen Testbearbeitungen → gepaart → gültig → mit SE; Gründe des Ausschlusses (`scalereasons`, leer = „engine“) | `coverage_figures`, `administered_scales` | verglichen |
| Streuung je Strategie | alle und gültige Skalenergebnisse: n, SD (n−1) der wahren und geschätzten Abweichung, Verhältnis, Korrelation (Varianz > 1e-12), RMSE, Bias | `spread_of` | verglichen |
| Itemklassen | lokale Genauigkeit je Klasse 0, 1, 2, 3–4, 5+, unbekannt (verabreichte Items der Skala) | `item_class`, `subscale_figures` | verglichen |
| Itemplots | Fehler und lokaler SE gegen die Items der Skala | `write_plot_data` | Plotdaten `subscales_items.csv` (alle Punkte) |

### Mode-specific detection

| Anzeige | Definition | R-Funktion | Stand |
|---|---|---|---|
| Testbearbeitungen | jede (gültig oder nicht) einer Strategie mit Rolle (relsubs: relevant, lowestsub: Defizit, highestsub: Stärke, allsubs/classic: Baselines) in einem Lauf mit Subskalen-Zuordnung, in der Lesereihenfolge (`attempts`) | `detection_sittings` | verglichen |
| Wahre Ziele | Subskalen mit wahrer Fähigkeit − globaler ≤ −0,5 (Defizit) bzw. ≥ 0,5 (Stärke); „relevant“ ohne Definition | `detection_sittings` | verglichen |
| Je Modus gegen die Wahrheit | gültige Skalenergebnisse mit Item: Bias, RMSE, MAE, Korrelation (Summenform, Varianz > 1e-12), Ziel-RMSE, Recall = TP/wahre Ziele, Precision, F1, Spearman/Top-1/Top-3/nDCG@3 auf orientierten Werten (Stärke umgedreht), Mittel über die Testbearbeitungen | `evaluate_sittings` | verglichen |
| Gegen jede Baseline | derselbe Twin unter denselben Bedingungen (Experiment, Ebene, Modell, Variante, Störstärke, Stratum, Stärke); erste Testbearbeitung je Schlüssel gilt; gepaart, auf gemeinsamen Skalen, ungepaart; mittlere Differenz der mittleren absoluten Fehler | `compare_with` | verglichen |
| Skalenergebnisse mit Item | je Testbearbeitung die Subskalen, die ein Item bekamen, aus `steps` ⋈ `items.assignedcatscaleid` ⋈ `scale_maps` — auch ohne Schätzung | `administered_scales` | verglichen |

### Robustness

| Anzeige | Definition | R-Funktion | Stand |
|---|---|---|---|
| Globale Ergebnisse je Zelle (Ebene, Strategie, Modell, Stratum, Stärke, Variante, Störstärke) | RMSE, Bias, Korrelation, mittlerer SE, mittlere Testlänge, Stopp-Erfolg (Anteil, nicht Prozent), mittlere Laufzeit | `robustness_figures` | verglichen |
| Differenzen zur idealen Zelle | Wert − Wert der Zelle mit idealem Pool und gleicher Ebene, Strategie, Modell, Stratum, Stärke, auf 6 Stellen | `robustness_figures` | verglichen |
| Lokale Ergebnisse | n, RMSE, Bias, innerhalb 1/2 SE der gültigen Skalenergebnisse der Zelle | `robustness_figures` | verglichen |
| Rangkennzahlen | je Testbearbeitung mit ≥ 2 gültigen Subskalenergebnissen: Werte orientiert (Stärkenstrategie umgedreht) und negiert, Spearman (Durchschnittsränge bei Gleichstand), Top-3-Übereinstimmung und nDCG@3 (Relevanz n − Position, ab 3 Ergebnissen; stabile Sortierung); dann Mittel über die Testbearbeitungen | `sitting_ranking` | verglichen |

### Test flow

| Anzeige | Definition | R-Funktion | Stand |
|---|---|---|---|
| Erreichbarkeit des Präzisionsziels | benötigt 1/SE_Ziel², erreicht 1/SE²; erreicht ≥ benötigt → reached; sonst gestoppt → stoppedearly; sonst Testlänge ≥ Maximum → budgetexhausted; sonst missed; ohne Ziel oder SE unknown | `testflow_figures` | verglichen |
| Eine Testbearbeitung Schritt für Schritt | Schätzung, SE, Information je Schritt | – | nur Daten (`steps`) |
| Vergleich der Verläufe | je gezählte Testbearbeitung: Schrittzahl, Schätzung und SE nach jedem Schritt | `flow_figures` | verglichen; Plotdaten `testflow_flows.csv` |

## Toleranz

Zählungen gleich. Sonst auf beiden Seiten 6 Nachkommastellen; Summationsreihenfolge (R summiert in erweiterter
Genauigkeit) und Rundung von Halbfällen (PHP rundet nach Vorrundung von null weg) können eine Einheit in der 6. Stelle
ausmachen: 1,5e-6. Prozente (vom Dashboard nicht gerundet): 1e-9 relativ.

## Nachweis

`research_package_test::test_the_r_reanalysis_reproduces_the_dashboard` (seit 0.7.71, #157):

| Fall | Modell | Strategien | Skalen × Items | Testlänge | Filter |
|---|---|---|---|---|---|
| Modi mit Baselines | 2PL | lowestsub, allsubs, classic | 2 × 2 × 10 | 12–18 | valid, all, invalid, unknown |
| Modi mit Baselines | GPCM | highestsub, relsubs, allsubs | 2 × 2 × 10 | 12–18 | valid, all |
| ohne Baseline | 2PL | lowestsub, highestsub, classic | 2 × 2 × 10 | 12–18 | valid |
| eine Subskala | 2PL | lowestsub, allsubs, classic | 1 × 1 × 20 | 12–18 | valid, all |
| 625-Fragen-Run | GPCM | allsubs | 5 × 5 × 25 | 625 | valid |

Jeweils 2 Replikationen, Twins ohne Testbearbeitung, ungültige Testbearbeitungen (Fehlerende, keine Endschätzung) und
im ersten Fall eine Testbearbeitung ohne Aufzeichnung der Fragen (Zustand „unknown“). Geprüft: jede Zeile des
Vergleichs PASS, kein NOT_REPRODUCED, `coverage.csv` ohne „not reproduced“; je Familie (Bias, RMSE, Exposition, lokale
Genauigkeit, Itemklassen, Abdeckung, Zählkette, Streuung, Erreichbarkeit, Verläufe; mit zwei Poolvarianten
Robustheit; mit Modus und Baseline Erkennung inkl. Skalenergebnissen mit Item) ein Wert, der auf beiden Seiten nicht
leer ist.

Verfälschungen (Manifest jeweils angepasst, damit die Paketprüfung grün bleibt), je in 2PL und GPCM:

- Bias: Schätzung einer gezählten Testbearbeitung +0,5 Logit → FAIL beim Bias, Exit 1.
- Step: Schätzung nach einem Schritt einer gezählten Testbearbeitung +0,5 Logit → FAIL im Verlaufsvergleich, Exit 1.
- Join: `scale_maps` des ersten Laufs zeigt auf Engine-Skalen, denen kein Item zugeordnet ist → FAIL bei den
  Skalenergebnissen mit Item bzw. der Abdeckung, Exit 1.
- Eine Zeile aus `steps.csv` entfernt (Manifest nicht angepasst) → FAIL der Paketprüfung.

## Grenzen

- Robustheit rechnet, wie das Dashboard, immer mit gültigen Skalenergebnissen gültiger Testbearbeitungen, unabhängig
  vom Filter.
- SE je Schritt steht im Paket nur, wo die Engine-Information berechnet wurde; sonst ist er auf beiden Seiten leer.
- TI@n je Schritt ist im Paket nur, wo seine Berechnung in die Zeitgrenze passt (`manifest.json` → `tiatn`).
