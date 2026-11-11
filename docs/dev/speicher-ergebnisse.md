# Speicher der Ergebnisseiten (#147, #110, 0.7.53, 0.7.57)

## Ursache

Die Ergebnisseite auf clara (MariaDB, 512 MB) brach vor der Analysebasis ab und meldete „limit -1“. Lokal auf
MariaDB 10.11 mit 512 MB ist das nachgestellt:

- 600 Sitzungen mit je etwa 0,9 MB Trace, zusammen 561 MB;
- also weit unter der Grenze von 50.000 Sitzungen.

Mit 0.7.52 erschien dieselbe Meldung wie auf clara, mit 0.7.53 rendert dieselbe Seite in 12 s.

Zwei Dinge kamen zusammen:

1. **Feste Stapelgröße bei beliebig großen Traces.** Die Traces wurden zu je 500 Sitzungen gelesen, unabhängig
   von ihrer Größe. Ein Trace ist die Kopie des Engine-Satzes einer Sitzung: 40 KB bei 20 Fragen, etwa 1 MB bei
   600 Fragen mit vielen Subskalen. Den größten Teil macht `progress` aus.
2. **MySQL/MariaDB puffert ganze Ergebnisse im PHP-Speicher.** Moodles mysqli-Treiber liest jede Abfrage mit
   `MYSQLI_STORE_RESULT`. mysqlnd legt diesen Puffer im PHP-Speicher an, er zählt also gegen `memory_limit`, bevor
   die erste Zeile gelesen ist. Ein Recordset über einen Lauf ist dort der ganze Lauf im Speicher.
   PostgreSQL puffert außerhalb des PHP-Limits (libpq). Deshalb war es auf der Entwicklungsumgebung nie zu sehen.

„limit -1“ kam daher, dass Moodles Shutdown-Handler das Limit auf „unbegrenzt“ setzt, bevor andere
Shutdown-Funktionen laufen. Die Meldung las `ini_get()` erst danach.

## Lösung

- `local\trace_reader` liest Traces in Stapeln von höchstens 500 Sitzungen **und** 16 MB Trace. Ein größerer
  Trace wird allein gelesen.
- Die Größe jedes Traces steht in `local_catquizlab_attempt.tracebytes`. Sie wird mit dem Trace geschrieben,
  beim Upgrade für alle vorhandenen Traces nachgetragen und bei älteren Zeilen ohne Wert gemessen.
- Diese Stellen lesen über den `trace_reader`:
  - Ergebnisstrom, also alle Reiter, Exporte und die Analysebasis;
  - Antwortmatrix;
  - Subskalen-Auswertung beim Laufende und bei `hub_submit_run`;
  - Vertragskette der Fortschrittsseite eines Laufs;
  - Transferpaket.
- Das Debug-ZIP eines Laufs liest keine Traces mehr.
- Die Größenprüfung zählt Sitzungen (> 50.000) und gespeicherte Trace-Bytes (> 4 GiB). Bei Überschreitung lehnt
  die Seite mit Zahl und Größe ab.
- Die Meldung nimmt das Limit aus der Fehlermeldung selbst. Sie nennt außerdem die Phase (Filter, Analysebasis,
  Reiter) und den belegten Speicher.

## Gemessen

`tests/results_memory_test.php`: 200 Sitzungen mit je 1 MB Trace, Spitzenspeicher je Schritt in MB.

| Schritt | 0.7.52 PostgreSQL | 0.7.52 MariaDB | 0.7.53 PostgreSQL | 0.7.53 MariaDB |
|---|---|---|---|---|
| Analysebasis | 190,9 | 368,1 | 23,2 | 33,2 |
| Übersicht | 188,6 | 365,2 | 21,9 | 32,0 |
| Antwortmatrix | 198,9 | 365,1 | 25,3 | 35,4 |
| Subskalen-Auswertung | 194,9 | 365,2 | 28,3 | 38,4 |
| Vertragskette (Lauf) | 13,7 | 196,7 | 28,3 | 38,4 |

Die Vertragskette braucht auf PostgreSQL jetzt mehr als vorher (28 statt 14 MB): Vorher lief sie über einen
Recordset, dessen Puffer PostgreSQL außerhalb des PHP-Limits hält. Jetzt holt sie Stapel von bis zu 16 MB in den
PHP-Speicher. Dafür ist der Bedarf auf beiden Datenbanken begrenzt und hängt nicht mehr von der Laufgröße ab.

## Auf clara prüfen

Wie groß die Traces dort sind (nach dem Upgrade auf 0.7.53):

```sql
SELECT COUNT(*) AS sitzungen,
       ROUND(SUM(tracebytes) / 1048576) AS mb_gesamt,
       ROUND(AVG(tracebytes) / 1024) AS kb_mittel,
       ROUND(MAX(tracebytes) / 1024) AS kb_max
  FROM mdl_local_catquizlab_attempt
 WHERE tracejson IS NOT NULL;
```

Läuft eine Seite trotzdem in das Limit, schreibt sie eine Zeile ins PHP-Fehlerprotokoll des Webservers:

```
local_catquizlab memory: rid=ZxQ3fH8AAQEAAB@kAbcAAAAD ridsource=apache phase=basis peak=511MB limit=512MB phases=start=20MB,filter=21MB path=/local/catquizlab/results.php experimentid=0 tab=overview sittings=… traces=…MB largestbatch=…MB read=…MB
```

Seit 0.7.56 (#149) enthält die Zeile **keinen Query-String** mehr, nur erlaubte, typisierte Felder:

| Feld | Inhalt |
|---|---|
| `rid`, `ridsource` | Request-ID: von Apache (`mod_unique_id`, `apache`), aus `X-Request-Id` (`header`), die Korrelations-ID des Workers (`worker`) oder zufällig (`local`). Dieselbe ID steht in der Fehlermeldung auf der Seite (`data-requestid`), ein Screenshot findet also seine Zeile. |
| `phase`, `peak`, `limit`, `phases` | wo und mit wie viel Speicher die Seite abbrach (#147) |
| `path` | das Skript ohne Query, Fragment und Slash-Argumente |
| `experimentid`, `runid`, `replication`, `sittings` | Zahlen |
| `tab` | nur ein Reitername der Ergebnisseite |
| `traces`, `largestbatch`, `read` | Größen in MB |
| `dropped` | wie viele weitere Angaben der Seite verworfen wurden, weil sie nicht erlaubt oder nicht vom erwarteten Typ waren |

Jeder Wert ist ein einzelnes druckbares ASCII-Token von höchstens 160 Byte. Zeilenumbrüche, Steuerzeichen und
Leerzeichen aus der Anfrage können keine eigene Logzeile beginnen.

Ältere Versionen schrieben `url=` mit dem ganzen Query-String, also möglicherweise mit `sesskey`. Bestehende Logs
prüft:

```
php local/catquizlab/cli/scan_logs_for_secrets.php --file=/var/log/apache2/error.log,/var/log/apache2/access.log --json
php local/catquizlab/cli/scan_logs_for_secrets.php --file=… --redact-to=/root/redacted-logs
```

Das Skript zählt Zeilen und geheime Parameter nach Namen und Zeilennummer, nie nach Wert, und schreibt mit
`--redact-to` eine Kopie ohne die Werte. Ein `sesskey` gilt nur für die Sitzung, in der er ausgegeben wurde; eine
abgelaufene Sitzung macht ihn wertlos.

Suchen mit:

```
grep "local_catquizlab memory" /var/log/apache2/error.log
```

Der Pfad hängt von der Serverkonfiguration ab.


## 0.7.57: Digests, Durchläufe, Abfragen, Profil

### Was die Ergebnisse lesen

Eine lange Sitzung besteht fast nur aus dem Protokoll jedes Schritts: `progress.playedquestions` und `abilitypath`.
Bei 625 Fragen und 20 Subskalen sind das 94–96 % des Traces. Keine Auswertung der Ergebnisseite liest sie.

- `local_catquizlab_attempt.digestjson` ist der Trace ohne diese Teile (`trace_digest::DROP`, `DROP_PROGRESS`).
  - Geschrieben wird er beim Sammeln und beim Import.
  - Ältere Sitzungen füllt der Ad-hoc-Task `build_trace_digests` nach.
  - Bis dahin bilden die Ergebnisse den Digest beim Lesen aus dem Trace.
- Den ganzen Trace lesen nur der Schritt-Export (in Stapeln), die Einzelansicht des Testverlaufs und der Vergleich von
  Verläufen.
- Gleichheit wird geprüft:
  - `trace_digest_test` vergleicht jede Zahl, Zeile und Datei aus Digests mit denen aus ganzen Traces.
  - `cli/measure_results.php --compare` macht dasselbe auf jeder Installation.

### Messen

| Werkzeug | Was |
|---|---|
| `results.php?…&profile=1` | Für Site-Administratoren: jede Phase und jeder Schritt der Anfrage mit ms, Abfragen und Spitzenspeicher, dazu Durchläufe, gelesene MB, RSS/HWM und die cgroup-Werte. Gemessen im PHP des Webservers. |
| `cli/measure_results.php --experimentid=N [--compare] [--json]` | Dieselben Schritte als CLI, jeder in einem eigenen Prozess. Ein Schritt, der an Speicher oder Zeit stirbt, wird mit seiner Fehlermeldung festgehalten. |
| `cli/seed_results_scale.php --sittings=9000 --questions=625 --scales=20` | Ein großes Experiment langer Sitzungen für Messungen (Zwillinge, zwei Strategien, ungültige Anteile); `--delete=N` entfernt es wieder. |

### Speichergrenzen

- **Seite:**
  - Mehr als 50.000 Sitzungen oder mehr als 4 GiB, die die Analyse liest (Digests), lehnt die Seite vor jeder
    Auswertung ab, mit Zahl und Größe.
  - Jeder Durchlauf liest in Stapeln von höchstens 500 Sitzungen und 16 MiB.
- **Subskalen-Reiter:**
  - Er hält seine Ergebnisse gepackt, 49 Byte pro Subskalenergebnis.
  - Seine drei Grafiken zeigen höchstens 2.000 Punkte, eine stabile Stichprobe; die Grafik sagt das.
- **`memory_limit = -1`:**
  - PHP setzt dann keine Grenze.
  - Ein Abbruch ist dann einer des Systems („Out of memory (allocated …)“) und wird als solcher gemeldet
    (`kind=system`), mit den cgroup-Werten im Log.
- **CLI und Web:**
  - Beide Wege nutzen dasselbe PHP und dieselbe Datenbank.
  - Im Web gelten `max_execution_time` (Ergebnisseite 300 s, Downloads 600 s) und das Limit des Webservers.
  - In der CLI gilt keine Zeitgrenze.
  - Die Messungen in den Issue-Kommentaren nennen jeweils, welcher Weg gemessen wurde.
