# Trace-Vertrag und Abschluss einer Sitzung (#138, 0.7.52)

Eine Sitzung gilt nur dann als **gesammelt** (`STATUS_COLLECTED`), wenn ihr Trace den Vertrag erfüllt.
Andernfalls wartet sie (`STATUS_TRACEPENDING`). Bleibt der Mangel nach `TRACE_RETRIES` Versuchen bestehen,
wird sie als technisches Nicht-Ergebnis aufgegeben (`STATUS_FAILED`). Sie erreicht dann weder ein Ergebnis
noch eine Grafik noch einen Export.

## Vertrag (`attempt_collector::defects()`)

| Code | Bedeutung | Warum es kein Ergebnis ist |
|---|---|---|
| `noengine` | Keine Engine, kein adaptivequiz oder keine Engine-Attempt-ID | nichts zu lesen |
| `noattempt` | adaptivequiz-Attempt nicht gefunden | nichts zu lesen |
| `notcomplete` | adaptivequiz-Attempt nicht `complete` | Antworten können noch geschrieben werden |
| `noengineresult` | kein `local_catquiz_attempts`-Satz (beide Schreibweisen der Komponente) | Die Endfähigkeit würde erfunden; bis 0.7.51 stand dort 0.0 |
| `noanswers` | keine einzige beantwortete Frage (Fraction `null` = gezeigt, nicht beantwortet) | kein Nachweis einer Bearbeitung |
| `itemsmismatch` | `items`, Schlüssel von `responses` und `nitems` stimmen nicht überein (Reihenfolge eingeschlossen) | Item-Zuordnung unklar |
| `badresponse` | Antwort nicht zwischen 0 und 1 oder nicht endlich | keine Fraction |
| `badtheta` | Endfähigkeit nicht endlich | keine Schätzung |
| `nostop` | weder Stoppkriterium noch Host-Stopcode noch Engine-Status | Ende nicht begründet |

Steht der Mangel fest, landet er in `lasterror`, z. B. `trace_missing: [noengineresult] …`. So lässt sich auf
dem Zielserver zählen, woran es scheitert:

```sql
SELECT SUBSTRING(lasterror FROM 1 FOR 60) AS grund, COUNT(*)
  FROM mdl_local_catquizlab_attempt
 WHERE status IN (25, 40) AND lasterror LIKE 'trace_missing:%'
 GROUP BY 1 ORDER BY 2 DESC;
```

(MariaDB/MySQL: `SUBSTRING(lasterror, 1, 60)`. Statuswerte: 25 = TRACEPENDING, 40 = FAILED.)

**Bewusst nicht im Vertrag:** die Übereinstimmung zwischen `adaptivequiz_attempt.questionsattempted` und der
Zahl der gelesenen Antworten. Diese Zahlen werden in jedem Trace unter `consistency`
(`hostattempted`, `answered`, `presented`) abgelegt. Zur harten Regel werden sie erst, wenn reale Läufe aller
Strategien auf clara die Semantik belegen. Abfrage dafür (Status 20 = COLLECTED, 30 = VALIDATED):

MariaDB/MySQL:

```sql
SELECT JSON_VALUE(tracejson, '$.consistency.hostattempted') = JSON_VALUE(tracejson, '$.consistency.answered')
           AS gleich,
       COUNT(*)
  FROM mdl_local_catquizlab_attempt
 WHERE status IN (20, 30) AND JSON_VALUE(tracejson, '$.consistency.answered') IS NOT NULL
 GROUP BY 1;
```

PostgreSQL:

```sql
SELECT (tracejson::json #>> '{consistency,hostattempted}') = (tracejson::json #>> '{consistency,answered}')
           AS gleich,
       COUNT(*)
  FROM mdl_local_catquizlab_attempt
 WHERE status IN (20, 30) AND tracejson::json #>> '{consistency,answered}' IS NOT NULL
 GROUP BY 1;
```

Sitzungen, die vor 0.7.52 gesammelt wurden, haben kein `consistency`; sie fallen durch die Bedingung heraus.

## Ein Schreiber pro Sitzung (`sitting_lock`)

Eine Sitzung kann auf fünf Wegen enden oder neu gelesen werden:

1. Abschlussmeldung des Workers (`job_complete`)
2. dieselbe Meldung noch einmal nach Timeout (#134)
3. Lease-Wiederherstellung (`reclaim_stale`)
4. Suche nach einem fehlenden Trace (`retry_pending_traces`)
5. erneutes Einsammeln eines Laufs (`collect_run`)

Jeder dieser Wege nimmt die Sperre `local_catquizlab_sitting/attempt<id>`, liest die Sitzung unter der Sperre
neu und entscheidet anhand dessen, was er dann vorfindet. Der Collector selbst sperrt nicht. Wartezeiten:

| Weg | Wartezeit | Ist die Sitzung gesperrt … |
|---|---|---|
| `job_complete` | 15 s | … wird die Meldung nicht übernommen (`job:busy`). Die Lease läuft ab, die Wiederherstellung holt die Sitzung aus der Engine. |
| `reclaim_stale`, `retry_pending_traces` | 0 s | … bleibt die Sitzung bis zum nächsten Tick liegen. Das zählt nicht als Versuch und nicht als Aufgabe. |
| `collect_run` | 5 s | … wird die Sitzung nicht neu gelesen. |

Status, Trace, Engine-Attempt-ID und Laufzeit werden **in einem Update** geschrieben. Ein Leser sieht also nie
eine Sitzung als „gesammelt“ ohne Trace und nie „laufend“ mit Engine-Attempt.

## Tests

- `tests/trace_integrity_test.php`:
  - `test_the_trace_contract` (alle Klauseln)
  - `…_the_engine_has_not_recorded_waits_with_the_reason` (echter Collector, Engine-Satz fehlt, dann vorhanden)
  - `test_an_attempt_still_being_written_is_not_collected`
  - `test_a_sitting_held_by_another_request_is_left_alone` (Datei-Sperre, damit zwei Halter in einem Prozess
    sich ausschließen)
  - `test_a_sitting_collected_meanwhile_is_not_looked_at_again`
- `tests/playwright/experiment.spec.js`: echter Lauf. Lokal am 09.11.2026: 5 von 5 Sitzungen gesammelt,
  `hostattempted = answered = presented = 20`, Stoppkriterium, Host-Stopcode und Engine-Status vorhanden
  (local_catquiz 1.2.1, Strategie aus dem Spec).
