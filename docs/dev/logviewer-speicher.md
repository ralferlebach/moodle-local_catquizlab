# Logviewer: Speicher und Laufzeit (#132)

## Ursache

Fünf HTTP 500 auf `/local/catquizlab/logs.php` am 12.10.2026 waren PHP-Fatals
„Allowed memory size of 536870912 bytes exhausted“, gemeldet in
`classes/local/request_trace.php:296` (0.7.36).

- `request_trace::read()` las jede Tagesdatei `catquizlab/requests/JJJJ-MM-TT.jsonl`
  mit `file()` vollständig ein und hielt jede Zeile als dekodiertes Array.
- `log_view::lines()` hängte diese Zeilen mit allen anderen Quellen zusammen,
  sortierte alles und schnitt erst dann auf 500 Zeilen.
- Je Zeile mit unbekannter Bearbeitung kam eine DB-Abfrage dazu (Lauf zur Bearbeitung).
- Der Live-Tail las bei jeder Abfrage (alle 3 s) das ganze Zeitfenster erneut.
- Die Datenbankquellen lasen die *ältesten* 2000 Zeilen des Fensters; bei viel
  Betrieb zeigte die Seite deshalb nicht das Neueste.

## Umsetzung (0.7.37)

| Stelle | Vorher | Jetzt |
|---|---|---|
| Tagesdatei lesen | `file()`, alles im Speicher | `request_trace::each()`: Generator, `fgets()`, eine Zeile im Speicher |
| Zeitfenster | nach dem Dekodieren | vor dem Dekodieren am Zeitstempel (erstes Feld jeder Zeile) |
| Leseumfang | unbegrenzt | `request_trace::scan_plan()`: höchstens 256 MB je Ansicht, vom Neuesten rückwärts; Ausgelassenes wird angezeigt („partial“) |
| Filter | nach dem Zusammenführen | je Zeile beim Lesen (`log_view::matches()`), vor dem Begrenzen |
| Gehaltene Zeilen | alle | je Quelle die neuesten (Seite + 1) × 500 |
| Lauf/Experiment-Filter | eine Abfrage je Bearbeitung | eine Abfrage für die Bearbeitungen des Laufs/Experiments; Lauf der angezeigten Zeilen in einer Abfrage |
| DB-Quellen | älteste 2000 | neueste max(2000, Bedarf) |
| Zeitstempel formatieren | jede gelesene Zeile | nur angezeigte Zeilen |
| Seiten | keine | `log_view::window()`, bis 20 Seiten à 500, „Ältere/Neuere Zeilen“ |
| Live-Tail | ganzes Fenster je Abfrage | ab der letzten angezeigten Zeile |
| Downloads | 5000 Zeilen ohne Angaben | Filter, Zeitraum, Limit, `truncated`, `partial`, Leseprofil in der Datei |
| Sortierung | ms, seq | ms, seq, Quelle |

## Profil vorher/nachher

Feste Umgebung: Moodle 4.5.15, PHP 8.3.6 CLI, PostgreSQL 16, 2 Kerne.
Messung `log_view` für die Logseite (500 Zeilen, letzte 24 h), Daten aus
`cli/seed_request_trace.php`.

| Trace | Version | memory_limit | Ergebnis | Speicherspitze | Dauer |
|---|---|---|---|---|---|
| 250 000 Zeilen, 65 MB | 0.7.36 | 512 MB | Fatal: memory exhausted | — | — |
| 250 000 Zeilen, 65 MB | 0.7.36 | 2048 MB | 500 Zeilen | 652 MB | 5,4 s |
| 250 000 Zeilen, 65 MB | 0.7.37 | 64 MB | 500 Zeilen | 18 MB | 1,3 s |
| 400 000 Zeilen, 111 MB | 0.7.36 | 512 MB | Fatal: memory exhausted | — | — |
| 400 000 Zeilen, 111 MB | 0.7.37 | 64 MB | 500 Zeilen | 18 MB | 1,7 s |

Der Speicherbedarf hängt jetzt am Seitenlimit, nicht an der Dateigröße
(`log_view_bounds_test::test_memory_stays_bounded`). Die Logseite zeigt das
Leseprofil jeder Ansicht unter der Zeilenzahl an.

## Tests

- PHPUnit `tests/log_view_bounds_test.php`: begrenztes Lesen, Zeitfenster,
  Seiten, Filter vor dem Begrenzen, Speicher, neueste DB-Zeilen, Lesebudget,
  Download-Metadaten, feste Zahl an DB-Abfragen.
- Playwright `tests/playwright/logs.spec.js` gegen 250 000 Zeilen: öffnen,
  zurückblättern, filtern (alle 251 Serverfehler), Deep-Link, Live-Tail, Download.

## Offen

Abnahme auf dem Zielsystem (DoD 6) mit `logs.php?runid=51`.
