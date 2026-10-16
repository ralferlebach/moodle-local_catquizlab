# Zähleinheiten und Unsicherheit in den Ergebnissen (#127)

## Was gezählt wird

| Größe | Bedeutung | Quelle im Code |
|---|---|---|
| Runs | Versuchsdurchläufe (`local_catquizlab_run`), aus denen die Bearbeitungen stammen | `stream_summary::runs()`, `results_query::provenance()['runs']` |
| Replikationen | unabhängige Ziehungen der Personenstichprobe | `provenance()['replications']` |
| Sittings / Testbearbeitungen | eine Bearbeitung einer simulierten Person in einem Run | `stream_summary::count()`, `provenance()['attempts']` |
| Personen (Twins) | **unabhängige Einheit**: ein Twin in einer Replikation (`results_query::unit_key()` = `replication|twinid`) | `stream_summary::people()`, `provenance()['people']` |
| Zellen | Versuchszellen Stufe × Strategie × Modell × Variante × Stratum × Schweregrad (`results_query::cell_key()`) | `provenance()['cells']` |
| Skalen | Abdeckung der Subskalen | Export-Metadaten `population.scales` (#115) |

Vorher stand in der Spalte „Runs“ die Zahl der Bearbeitungen (`summary->count()`):
ein Run mit 250 Bearbeitungen las sich als „250 Runs“.

## Unsicherheitsmaß

Estimand: Mittelwert einer Kennzahl (Testlänge, SE, Bias, Laufzeit) über die
simulierten Personen der Auswahl.

- Einheit: simulierte Person. Dieselbe Person unter zwei Strategien (gepaarte
  DigiTwins) oder mit mehreren Bearbeitungen (Messwiederholung) zählt **einmal**.
- Standardfehler: Standardabweichung der **Personenmittel** / √(Anzahl Personen)
  (Cluster-Mittel-Ansatz). Bei einer Bearbeitung je Person ist das die übliche
  Formel sd/√n.
- Intervall: Mittelwert ± 1,96 · SE (Normalapproximation).
- Weniger als zwei Personen: kein Intervall, angezeigt als „CI n/a“
  (`dispersion:na`) — nie ein Intervall „über Replikationen“, das es nicht gibt.
- RMSE: ohne Intervall.

Implementierung: `results_query::describe_values($values, $clusters)`; der
Stream (`stream_summary`) führt zu jedem Wert die Personennummer mit, sodass
Tabelle (Stream) und Array-Pfad (`summarise()`) dasselbe liefern (Test
`statistics_units_test::test_paired_twins_count_once`).

## Wo es steht

- Grundlagenzeile über jedem Tab: Bearbeitungen, Personen, Runs, Replikationen,
  Zellen, Unsicherheitsmethode.
- Gruppentabellen: Spalten Runs, Sittings, People.
- Plot: Basiszeile (`catquizlab-plot-basis`) mit Bearbeitungen und Runs.
- Export-Metadaten (`action=meta`): `runs`, `attempts`, `replications`,
  `people`, `cells`, `uncertainty` (Intervall, Einheit, SE-Formel, RMSE).

Tabelle, Plot und Export lesen dieselbe Auswahl (`results_query` mit demselben
Filter); `tests/playwright/statistics.spec.js` prüft die Gleichheit.

## Tests

- `tests/statistics_units_test.php`: n = 1, n > 1 (250 Bearbeitungen), gepaart,
  mehrere Replikationen, Methodentext.
- `tests/results_query_test.php::test_provenance_reports_the_aggregation_level`:
  Personen und Zellen.
- `tests/playwright/statistics.spec.js`: Tabelle, Grundlagenzeile, Plot und
  Export nennen dieselbe Stichprobe.
