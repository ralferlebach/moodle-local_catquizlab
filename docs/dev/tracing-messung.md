# Tracing unter Last messen (#164, 0.7.65)

Code: `classes/local/live_correlation.php`, `cli/trace_report.php`, `cli/trace_matrix.sh`; Tests:
`tests/live_correlation_test.php`. English summary at the end.

## Befund (CLARA, 15.11.2026, 10 Minuten bis 13:27 UTC)

Beide Schalter waren an: `local_catquiz/attempttrace = 1` und `local_catquizlab/debuglevel = trace`.

| Art | Traces | Ø Anfragezeit | JSON |
|---|---:|---:|---:|
| `next_item` | 88 | 64,24 s | 0,612 MiB |
| `first_item` | 24 | 58,26 s | 0,172 MiB |
| `reload` | 61 | 0,05 s | 0,073 MiB |

- In Traces mit 2496–2500 Kandidaten dauert `strategy:select_question` 56–65 s, `pool:cache_lookup` ≈ 12 ms.
- Die geringe Datenmenge und der dominierende Selektor sprechen gegen das Schreiben der Traces als Hauptursache.
- Bewiesen ist das ohne Vergleich unter gleichen Bedingungen nicht. Der Selektor selbst ist CatQuiz #27 (eigenes Repo,
  nicht Teil dieses Plugins).

## Trace ↔ Testbearbeitung, auch während des Spiels (AK4)

Die 60 jüngsten Traces lasen „lab_attemptid NULL“. Die Ursache:
- Das Labor speichert die Engine-Bearbeitung (`local_catquizlab_attempt.engineattemptid`) erst beim Einsammeln.
- Ein Trace entsteht aber bei jeder Anfrage, also während der Bearbeitung.
- Das war kein verlorener Trace, sondern eine noch nicht gezogene Verbindung.

`live_correlation::link()` zieht sie über den Moodle-Nutzer, den die Engine an jeden Trace schreibt:
- Jede simulierte Person hat ihren eigenen Nutzer, je Person und Lauf (`catlab_r<Lauf>_…`, `person.moodleuserid`).
- Von dort: Nutzer → Person → Lauf, Experiment, Testbearbeitung → Worker mit Lease.
- Nur Lesen, drei Abfragen für beliebig viele Traces. An der Engine wird nichts geändert und nichts gespeichert.

Jede Verbindung sagt, wie sicher sie ist:

| `how` | Bedeutung |
|---|---|
| `stored` | gespeicherte Engine-Bearbeitung der Testbearbeitung = Trace (eingesammelt) |
| `inplay` | die Testbearbeitung der Person, die gerade gespielt wird; Engine-Bearbeitung noch nicht gespeichert |
| `person` | die Testbearbeitung der Person, nicht im Spiel (Wiederholung wartet, gescheitert, Trace ausstehend) |
| `none` | kein simulierter Nutzer dieses Labors (echter Nutzer, Lauf gelöscht) |

## Ein Messfenster lesen: `cli/trace_report.php`

    php local/catquizlab/cli/trace_report.php --from=UNIXZEIT [--until=UNIXZEIT] [--json] [--rows=20]

Alle Traces des Fensters werden gezählt, nicht die 100 langsamsten (AK8). Je Art (`first_item`, `next_item`, …) und für
`strategy:select_question` sowie `pool:cache_lookup` stehen:
- n, Mittel, Standardabweichung;
- das 95-%-Intervall des Mittels (Student-t für kleine n);
- p50, p95, p99, Maximum, KiB der Spans.

Dazu:
- die Verbindung jedes Traces zur Testbearbeitung (Zählung nach `how`, die jüngsten einzeln);
- die Ausführungen des Fensters nach Ausgang;
- die Kapazitätsursachen (`submit_cdp_timeout` u. a.);
- das Debug-Log des Labors: Zeilen und Bytes je Kanal.

Ist das Tracing der Engine aus, sagt der Bericht das, statt Nullen als Messung auszugeben (AK7).

## Die 2 × 2-Matrix: `cli/trace_matrix.sh`

    WORKERS=4 PER=3 STRATEGIES="fastest highestsub" REPEAT=2 COLD=1 \
        MOODLE_ROOT=/var/www/moodle local/catquizlab/cli/trace_matrix.sh

**Zellen:** `attempttrace` 0/1 × `debuglevel` off/trace. Jede Zelle hält alles andere gleich:
- dieselben Strategien und Personen (fester Seed des Smoke-Experiments);
- dasselbe Worker-Limit und denselben Server.

**Je Zelle und Strategie:**
- `smoke.php` spielt die Testbearbeitungen mit echten Workern;
- `load_sampler.php` tastet Webserver-Prozesse, RSS und DB-Verbindungen ab;
- `request_report.php` liest jede Anfrage (Status, PHP-Spitze, RSS, 5xx, Fatals);
- `trace_report.php` liest wie oben.

**Ablauf:**
- Reihenfolge ABCD, bei `REPEAT=2` danach DCBA: Eine Drift des Servers über die Stunde trifft so nicht eine Zelle.
- `COLD=1` leert vor jeder Zelle die Caches (kalt), `COLD=0` lässt sie (warm).
- Am Ende stehen beide Schalter wieder wie vorher, auch nach einem Abbruch (`trap`).

**Tabelle:** Ergebnis, Anfragen, 5xx, `submit_cdp_timeout`, `next_item` p50/p95, `select_question` p50/p95, Traces,
KiB, Webserver-RSS, verbundene Traces. Die vollständigen Statistiken liegen je Zelle in `$OUT/*-traces.txt`.

**Auswertung (AK3):**
- Der Tracing-Aufwand ist die Differenz von `next_item`-p50/p95 zwischen trace0 und trace1 bei gleichem debuglevel.
- Gegenüber dem Selektor ist er am Vergleich mit `select_question` im selben Trace abzulesen.
- Nur ganze Intervalle vergleichen. Liegen die 95-%-Intervalle übereinander, ist kein Unterschied belegt.

**Offen (Betrieb):** Laufen muss die Matrix auf CLARA oder einer gleichwertigen Umgebung (DoD-01). Dort ist die Last
echt; dieser Rechner hat 2 Kerne und keinen 2500-Kandidaten-Pool.

## Die Anfragezeit zerlegt (AK02, DoD02; 0.7.77)

Gemessen, nicht abgeleitet — jeder Teil aus einem eigenen Zähler (`classes/local/trace_decomposition.php`):

| Teil | Zähler | wo |
|---|---|---|
| Trace-INSERT der Engine | Ausführungszeit jedes `INSERT INTO …local_catquiz_trace`, gemessen von Moodles Datenbankschicht, protokolliert im Query-Log (`dboptions['logall']`); über die Request-ID in der Zeile seinem Trace zugeordnet | Query-Log-Zelle |
| Server, ganze Anfrage | `durationms` der Anfragezeile des Labors (`request_trace`), am Rand der Anfrage | jede Zelle |
| Server, Datenbank | `moodle_database::perf_get_queries_time()` — Summe aller Abfragen der Anfrage, dazu Lese-/Schreibzahl | jede Zelle |
| Server, Sitzungssperre | `$PERF->sessionlock` — gewartet und gehalten | jede Zelle |
| Selektor | Span `strategy` der Engine-Traces (ganze CAT-Strategie) | Zellen mit Tracing |
| Client | Laufzeit der Bearbeitung beim Worker (`runtimems`) minus der Zeit, in der der Server für sie arbeitete (Vereinigung der Intervalle ihrer Anfragen, ohne Webservice-Aufrufe): Browser, CDP, Navigation, Netz | jede Zelle |

**Warum ein eigener Zähler für den INSERT:** Die Engine berechnet `requestms` vor dem Schreiben
(`timeline::flush()`); die Schreibzeit steht in keinem Trace. Das Labor patcht die Engine nicht. Moodles Query-Log
misst jede Abfrage mit demselben Zeitgeber wie `perf_get_queries_time()` und hält ihre Parameter — darin die
Request-ID des Traces. `config.php` schaltet es ein, solange `$CFG->dataroot/catquizlab-querylog` existiert
(`trace_report.php --querylog=on|off`; die CI schreibt die Zeile in `acceptance-setup.sh`).

**Eigene Zelle:** Das Query-Log kostet jede Abfrage einen INSERT. Die Zellen des 2 × 2-Vergleichs laufen ohne; danach je
Strategie eine Zelle `trace1-debugquerylog` (Tracing an, Debug aus, Log an; `PERSIST=0` lässt sie weg). Ihre
Anfragezeiten sind instrumentiert und nicht mit den Vergleichszellen zu vergleichen; ihre INSERT-Zeiten sind die
Messung von AK02.

**Fail closed:** Jeder Teil heißt `measured` nur, wenn er für alle seine Anfragen bzw. Traces vorliegt; sonst
`incomplete` oder `not_measured`, mit Grund. `trace_matrix_check.py` macht daraus einen Befund: in jeder Zelle Server
und Client gemessen, in der Query-Log-Zelle zusätzlich jeder Trace-INSERT; fehlt die Query-Log-Zelle (`PERSIST=1`), ist
die Matrix rot; misst eine Vergleichszelle INSERTs, war das Log dort an — rot. `report.md` bekommt die Tabelle je Zelle
(p50/p95/p99, n).

**Grenzen:** Die Client-Zeit gilt nur für Bearbeitungen mit einer Ausführung (`tries = 1`; die Laufzeit gehört zur
gesammelten Ausführung). Die Sperrzeiten stammen aus Moodles Sitzungsverwaltung; mit `READ_ONLY_SESSION` gibt es keine.

## Datenschutz und Umfang (AK5, AK6)

- `trace_report.php` gibt keine Antworten, Tokens oder Fähigkeitswerte aus: Ids, Zeiten, Größen, Ursachen.
- Die Traces gehören der Engine. Aufbewahrung und Bereinigung regelt CatQuiz (`local_catquiz_trace`, Index auf
  `timecreated`).
- Das Debug-Log des Labors läuft über `debug_trace::retention()`; seine Größe zeigt der Bericht je Kanal.

## English summary

- **Live link (AK4):** `live_correlation::link()` connects each engine trace to its lab sitting, run, experiment and
  worker while the sitting is being played. It goes through the trace's Moodle user, which is the simulated person's
  own. Read only. Each link says how sure it is (stored / in play / person / none).
- **`cli/trace_report.php`:** counts every trace of a window. Per kind and for `strategy:select_question` it gives
  n, mean, sd, 95 % CI, p50/p95/p99 and max, plus bytes and the links. It also gives execution outcomes, capacity
  causes and the lab's debug log volume. With tracing off it says so and prints no zeros.
- **Request time taken apart (AK02, 0.7.77):** the engine trace's own INSERT timed by Moodle's query log in a cell of
  its own; per request the server's database time and session lock (Moodle's counters, at the request's edge); per
  sitting the client's time (runtime less the server's busy time). Fail closed: a part not measured whole fails.
- **`cli/trace_matrix.sh`:** plays the 2 × 2 matrix (`attempttrace` × `debuglevel`) with real workers. Order ABCD/DCBA,
  cold or warm cache, settings restored at the end. The measurement itself belongs on a production-like server.
