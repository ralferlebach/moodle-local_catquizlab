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

## HTTP-Abnahme (0.7.57)

`cli/accept_logs.php --runid=N` prüft die Logseite über den Webserver, wie ein Mensch sie benutzt:

1. Schreibt 250 000 Request-Zeilen der Sittings des Laufs über 30 Stunden in die Trace-Dateien des Servers,
   also über eine Tagesgrenze hinweg in zwei Tagesdateien. Jede 997. Zeile ist ein Serverfehler mit Exception.
2. Meldet sich als temporärer Manager an und fragt ab:
   - drei Seiten (keine Zeile doppelt, lückenlos aneinander, chronologisch),
   - die neueste Zeile zuerst,
   - nur die Fehler (genau die geschriebenen 251),
   - eine Suche nach genau einer Zeile,
   - beide Downloads (Filter-Provenienz im Kopf),
   - ein JSON-Fenster ±10 Minuten um Mitternacht (beide Dateien, steigende Zeit, erwartete Zeilenzahl),
   - den Live-Tail über eine Minute, während Zeilen geschrieben werden (jede Abfrage bringt genau die neuen),
   - einen Nutzer ohne Rolle (abgewiesen),
   - einen Download per GET mit Schlüssel in der URL (abgewiesen, #149).
3. Jede Anfrage trägt den Trace-Header. Damit schreibt der Server Status, Dauer, PHP-Spitze, Datenbankabfragen und
   RSS des Prozesses auf, und das Skript liest diese Werte zurück.
4. Prüft die Aufbewahrung: Eine abgelaufene Tagesdatei wird gelöscht, die heutige bleibt Zeile für Zeile erhalten.
5. Nimmt seine eigenen Zeilen wieder heraus (`trace_seed::remove()`).

Ergebnis lokal (Apache 2.4, mod_php 8.3, `memory_limit` 512M, Moodle 4.5.15, 11.11.2026, 0.7.57):

| DB | Lauf | Seite (ms / PHP-Spitze / Abfragen) | JSON-Download 5000 Zeilen | max. PHP-Spitze | max. RSS | Fehler |
|---|---|---|---|---|---|---|
| PostgreSQL 16 | 27 (2000 Sittings) | 2448 ms / 16 MB / 156 | 2341 ms / 45,9 MB | 45,9 MB | 170,9 MB | 0 |
| MariaDB | 1 (300 Sittings) | 2255 ms / 16 MB / 56 | — | 43,9 MB | 171,8 MB | 0 |

Rohdaten: `132-accept-pg.txt`, `132-accept-pg.json`, `132-accept-maria.txt` im Nachweispaket.

## Offen

Abnahme auf dem Zielsystem (DoD 6), auf clara:

```
sudo -u www-data php local/catquizlab/cli/accept_logs.php --runid=51 > /root/132-accept.txt
```
