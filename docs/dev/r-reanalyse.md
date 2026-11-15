# R-Reanalyse: Kennzahlen aus „4. Results“ unabhängig nachrechnen (#157)

Code: `research/run_all.R`, `research/R/*.R` (Basis-R ≥ 4.1, keine Pakete), im Forschungsdatenpaket unter
`reanalysis/`. Referenz: `classes/local/research_reference.php` schreibt `reference/dashboard.csv` mit denselben
Aufrufen, die die Ergebnisseite macht. Test: `research_package_test::test_the_r_reanalysis_reproduces_the_dashboard`.
CI: `moodle-ci.yml`, Job PHPUnit, R 4.3.3 gepinnt, `CATQUIZLAB_REQUIRE_R=1`. Seit 0.7.61.

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
| Lokale Genauigkeit | über gültige Skalenergebnisse gezählter Testbearbeitungen (Filter „valid“; bei „all“ alle mit Item oder unbekannter Zahl): n, Bias = Mittel(estdelta−truedelta), RMSE, MAE, Pearson(truedelta, estdelta), mittlerer lokaler SE, Anteil innerhalb 1/2 SE (unter denen mit SE), Zahl mit SE | `local_summary` | verglichen |
| Je Kategorie, je Subskala | dito gruppiert | `subscale_figures` | verglichen |
| Skalenabdeckung, Streuung, Itemklassen, Itemplots | Zählkette der Skalenergebnisse | – | **nicht nachgerechnet** |

### Mode-specific detection

| Anzeige | Definition | R-Funktion | Stand |
|---|---|---|---|
| Testbearbeitungen | jede (gültig oder nicht) einer Strategie mit Rolle (relsubs: relevant, lowestsub: Defizit, highestsub: Stärke, allsubs/classic: Baselines) in einem Lauf mit Subskalen-Zuordnung, in der Lesereihenfolge (`attempts`) | `detection_sittings` | verglichen |
| Wahre Ziele | Subskalen mit wahrer Fähigkeit − globaler ≤ −0,5 (Defizit) bzw. ≥ 0,5 (Stärke); „relevant“ ohne Definition | `detection_sittings` | verglichen |
| Je Modus gegen die Wahrheit | gültige Skalenergebnisse mit Item: Bias, RMSE, MAE, Korrelation (Summenform, Varianz > 1e-12), Ziel-RMSE, Recall = TP/wahre Ziele, Precision, F1, Spearman/Top-1/Top-3/nDCG@3 auf orientierten Werten (Stärke umgedreht), Mittel über die Testbearbeitungen | `evaluate_sittings` | verglichen |
| Gegen jede Baseline | derselbe Twin unter denselben Bedingungen (Experiment, Ebene, Modell, Variante, Störstärke, Stratum, Stärke); erste Testbearbeitung je Schlüssel gilt; gepaart, auf gemeinsamen Skalen, ungepaart; mittlere Differenz der mittleren absoluten Fehler | `compare_with` | verglichen |
| Skalenergebnisse mit Item | zählt auch Ergebnisse ohne Schätzung, die das Paket nicht enthält | – | **NOT_REPRODUCED** |

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
| Vergleich der Verläufe | Twins nebeneinander | – | **nicht nachgerechnet** |

## Toleranz

Zählungen gleich. Sonst auf beiden Seiten 6 Nachkommastellen; Summationsreihenfolge (R summiert in erweiterter
Genauigkeit) und Rundung von Halbfällen (PHP rundet nach Vorrundung von null weg) können eine Einheit in der 6. Stelle
ausmachen: 1,5e-6. Prozente (vom Dashboard nicht gerundet): 1e-9 relativ.

## Nachweis

- Sauberer Lauf: 2PL und GPCM, 3 Strategien, 2 Replikationen, 2 Poolvarianten, 2 Strata, Testlängen 12–18 über alle
  vier Subskalen, gültige und ungültige Testbearbeitungen, Twins ohne Testbearbeitung: kein FAIL; je Familie (Bias,
  RMSE, Gini, lokaler RMSE, Robustheitsdifferenz, lokaler Robustheits-RMSE, Spearman, Top-3, nDCG@3, Erreichbarkeit)
  mindestens ein Wert, der auf beiden Seiten nicht leer ist und PASS hat. Mit Strategien jeder Erkennungsrolle
  (lowestsub, highestsub, relsubs, allsubs, classic): 681 und 1 009 Kennzahlen, alle PASS.
- Absichtlicher Fehler: Schätzung einer gezählten Testbearbeitung +0,5 Logit, Manifest angepasst → FAIL beim Bias,
  Paketprüfung grün. Eine Zeile aus `steps.csv` entfernt → FAIL der Paketprüfung.

## Grenzen

- Nicht nachgerechnet: die Zahl der Skalenergebnisse mit Item in der Erkennungstabelle (NOT_REPRODUCED im Vergleich),
  Skalenabdeckung/Streuung/Itemklassen und Vergleich der Verläufe („not reproduced“ in `coverage.csv`).
- Filter „invalid“ und „unknown“ für die Subskalen: nicht unterstützt (die Subskalenzeilen tragen keinen Zustand);
  die Subskalen-Kennzahlen fehlen dann und werden als FAIL gemeldet, nicht als PASS.
- TI@n je Schritt ist im Paket nur, wo seine Berechnung in die Zeitgrenze passt (`manifest.json` → `tiatn`).
