# Re-analysis in R / Reanalyse in R

## English

This directory recomputes the figures of "4. Results" from the raw tables of this research data package, without
Moodle, a database or a network connection, and compares every figure with the one the dashboard showed when the
package was made (`reference/dashboard.csv`).

**Run** (from the unpacked package's directory; R 4.1 or later, base R only, no packages):

    Rscript reanalysis/run_all.R

or `Rscript reanalysis/run_all.R path/to/package.zip --out=results`. It writes into `reanalysis-out/`:

- `figures.csv` — every recomputed figure, keyed (tab, section, groupby, group, metric, stat) like the reference;
- `comparison.csv` — each figure: expected (dashboard), actual (R), difference, tolerance, PASS / FAIL /
  NOT_REPRODUCED, and a note;
- `coverage.csv` — which part of each tab is recomputed, by which function, from which columns, and which is not;
- `plotdata/*.csv` — every point of the plots (no sampling, no jitter); `plots/*.png` where a PNG device exists;
- `report.txt` — the summary.

The exit status is 0 when no figure FAILs and 1 otherwise (2 when the package cannot be read). Before any figure, the
package is checked: every table has the rows and the md5 that `manifest-tables.csv` names.

**What it computes from what:** the counted sittings are those with `inpopulation = 1` in `analysis_population`; the
error is estimate minus truth from `attempts.esttheta` and `attempts.truetheta`, and a sitting without an estimate has
none (it is never an estimate of 0). Means, SDs, quantiles (type 7) and the 95 % interval of the mean over the
independent units (a twin in its replication, experiment, stratum and severity; normal approximation) as the dashboard
defines them; stop success over all sittings, valid or not; exposure from the questions really played (`steps`)
against the whole pool (`items`). See `coverage.csv` for the full list.

**Tolerance:** counts must be equal. Other figures are rounded to 6 decimals on both sides; the two sum in a
different order and round half-way cases differently, so they may differ by one unit in the 6th decimal: 1.5e-6.
Percentages: 1e-9 relative.

**Not reproduced in this version** (never PASS): the count of scale results with an administered item in the
detection table (it includes results without an estimate, which the package does not hold) is NOT_REPRODUCED; scale
coverage, spread and item classes of the subscales tab and the comparison of flows are listed in `coverage.csv` as
not reproduced.

## Deutsch

Dieses Verzeichnis rechnet die Kennzahlen aus „4. Results“ aus den Rohtabellen dieses Forschungsdatenpakets nach,
ohne Moodle, Datenbank oder Netz, und vergleicht jede mit der, die das Dashboard beim Erstellen des Pakets zeigte
(`reference/dashboard.csv`).

**Aufruf** (im entpackten Paketverzeichnis; R ab 4.1, nur Basis-R, keine Pakete):

    Rscript reanalysis/run_all.R

oder `Rscript reanalysis/run_all.R pfad/zum/paket.zip --out=ergebnisse`. Ausgabe in `reanalysis-out/`:
`figures.csv` (jede nachgerechnete Kennzahl), `comparison.csv` (Soll, Ist, Abweichung, Toleranz, PASS / FAIL /
NOT_REPRODUCED), `coverage.csv` (was woraus nachgerechnet wird und was nicht), `plotdata/*.csv` (alle Punkte der
Diagramme, ohne Stichprobe und ohne Jitter), `plots/*.png`, `report.txt`.

Exit-Status 0, wenn keine Kennzahl FAIL ist, sonst 1 (2, wenn das Paket nicht lesbar ist). Vor jeder Kennzahl wird
das Paket geprüft: Jede Tabelle hat die Zeilenzahl und die md5, die `manifest-tables.csv` nennt.

**Woraus:** Gezählt werden die Testbearbeitungen mit `inpopulation = 1` in `analysis_population`; der Fehler ist
Schätzung minus Wahrheit aus `attempts.esttheta` und `attempts.truetheta`, eine Testbearbeitung ohne Schätzung hat
keinen (nie eine Schätzung 0). Mittelwerte, SD, Quantile (Typ 7) und das 95-%-Intervall des Mittelwerts über die
unabhängigen Einheiten (Twin in Replikation, Experiment, Stratum, Stärke; Normalapproximation) wie im Dashboard
definiert; Stopp-Erfolg über alle Testbearbeitungen, gültig oder nicht; Exposition aus den wirklich gespielten Fragen
(`steps`) gegen den ganzen Pool (`items`).

**Toleranz:** Zählungen müssen gleich sein. Übrige Kennzahlen sind auf beiden Seiten auf 6 Nachkommastellen gerundet;
Summationsreihenfolge und Rundung von Halbfällen unterscheiden sich, daher bis zu einer Einheit in der 6. Stelle:
1,5e-6. Prozente: 1e-9 relativ.

**In dieser Version nicht nachgerechnet** (nie PASS): die Zahl der Skalenergebnisse mit Item in der Erkennungstabelle
(sie enthält Ergebnisse ohne Schätzung, die das Paket nicht hat) ist NOT_REPRODUCED; Skalenabdeckung, Streuung und
Itemklassen des Subskalen-Tabs sowie der Vergleich der Verläufe stehen in `coverage.csv` als nicht nachgerechnet.
