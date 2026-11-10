# Speicher der Ergebnisseiten (#147, #110, 0.7.53)

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
local_catquizlab memory: phase=basis peak=511MB limit=512MB phases=start=20MB,filter=21MB url=/local/catquizlab/results.php?… experimentid=0 tab=overview sittings=… traces=…MB largestbatch=…MB read=…MB
```

Suchen mit:

```
grep "local_catquizlab memory" /var/log/apache2/error.log
```

Der Pfad hängt von der Serverkonfiguration ab.
