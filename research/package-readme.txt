# CAT experiment suite — research data package

Schema `{SCHEMA}` version {VERSION}. Tables in `data/` (UTF-8 CSV, RFC 4180, header row), their columns in `codebook.json` (and `codebook.csv`), counts, md5 and sha256 in `manifest.json` (and `manifest-tables.csv`), the experiment's definition, run manifests, seeds, effective parameters and versions in `reproducibility.json`.

- An empty cell is a missing value, never a zero. NaN and Inf are written as such. Booleans are 0/1, times Unix seconds (UTC), lists separated by `;`.
- `steps` are the ADMINISTERED answers. `twin_item_responses` are POTENTIAL answers to every item of the pool, computed by the function that answers in a sitting, for runs under RNG schema 2 only. Do not mix them without saying so.
- `attempts`, `steps`, `estimated_profiles` and `validity` hold every sitting with a trace, valid or not; `analysis_population` says which ones the results page counted under the filter in `manifest.json` (and `filter.csv`).
- Joins: `runid` → runs; `runid, personid` → twins; `runid, questionid` → items; `attemptid` → attempts; `replication, twinid, itemkey` pairs the same twin and item across runs of a replication.
- `reanalysis/`: R scripts (base R ≥ 4.1, offline) that recompute the figures of "4. Results" from these tables and compare each with the dashboard's in `reference/dashboard.csv`: `Rscript reanalysis/run_all.R`. See `reanalysis/README.md`.

# CAT-Experiment-Suite — Forschungsdatenpaket

Schema `{SCHEMA}` Version {VERSION}. Tabellen in `data/` (UTF-8-CSV, RFC 4180, Kopfzeile), ihre Spalten in `codebook.json` (und `codebook.csv`), Zeilenzahlen, md5 und sha256 in `manifest.json` (und `manifest-tables.csv`), Definition, Laufmanifeste, Seeds, effektive Parameter und Versionen in `reproducibility.json`.

- Eine leere Zelle ist ein fehlender Wert, nie eine Null. NaN und Inf stehen als solche da. Wahrheitswerte 0/1, Zeiten Unix-Sekunden (UTC), Listen mit `;` getrennt.
- `steps` sind die GESTELLTEN Antworten. `twin_item_responses` sind POTENZIELLE Antworten auf jedes Item des Pools, berechnet mit der Funktion, die in einer Testbearbeitung antwortet, nur für Läufe unter RNG-Schema 2. Nicht ungekennzeichnet mischen.
- `attempts`, `steps`, `estimated_profiles` und `validity` enthalten jede Testbearbeitung mit Trace, gültig oder nicht; `analysis_population` sagt, welche die Ergebnisseite unter dem Filter in `manifest.json` (und `filter.csv`) zählte.
- Verknüpfungen: `runid` → runs; `runid, personid` → twins; `runid, questionid` → items; `attemptid` → attempts; `replication, twinid, itemkey` paart denselben Twin und dasselbe Item über die Läufe einer Replikation.
- `reanalysis/`: R-Skripte (Basis-R ≥ 4.1, offline), die die Kennzahlen aus „4. Results“ aus diesen Tabellen nachrechnen und jede mit der des Dashboards in `reference/dashboard.csv` vergleichen: `Rscript reanalysis/run_all.R`. Siehe `reanalysis/README.md`.
