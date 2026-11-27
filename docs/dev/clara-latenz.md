# Latenzspitzen unter Langlauf auf CLARA messen (#177, 0.7.84)

## Ausgangslage

Der Export von Run 103 (27.11.2026) enthält um 03:12 UTC eine gemeinsame Latenzspitze bei den AdaptiveQuiz-POSTs
mehrerer Bearbeitungen, ohne HTTP-Fehler.

Mehr gibt der Export dazu nicht her:
- Er enthält keine Maschinen-Stichproben (CPU, Speicher, Apache, Datenbankverbindungen), keine Engine-Phasen und
  keine Sperrzeiten.
- Er ist bei 5000 Zeilen abgeschnitten (03:10–03:26 statt 02:35–03:40).

Die Ursache der Spitze ist aus diesem Material **nicht aufklärbar**.
- Sie ist auch nicht die Ursache der Abbrüche: Die stammen vom festen 1000er-Guard des Workers (#172) und kommen auch
  ohne jede Last vor (Test `worker/test/long_run.test.js`, mit dem Worker von 0.7.83).

## Messung

Gemessen wird erst nach den funktionalen Fixes von 0.7.84. Verwendet wird die bestehende Infrastruktur aus
#110/#164, kein neuer Aufbau.

- **Konfiguration:** wie in Run 103, mit denselben Seeds und den gepinnten Engine-Ständen.
- **Ressourcengrenzen:** `.github/scripts/server-limits.sh` hält sie vor der Messung fest.
- **Stufen:** 1, 5, 12, 20, 25 und 50 Worker, soweit die Kapazität reicht. Eine Stufe, die nicht lief, wird als
  fehlend ausgewiesen, nicht als PASS:

```sh
WORKERS="1 5 12 20 25 50" MODES=classic POOL=2500 MINANSWERS=2502 MINUTES=180 PER=1 \
  STATUS_URL=http://127.0.0.1/server-status?auto WEBLIMIT=512M OUT=/var/tmp/clara-177 \
  MOODLE_ROOT=/var/www/moodle bash local/catquizlab/cli/load_matrix.sh
```

Was jede Stufe schreibt, steht in `clara-benchmark.md`:
- **Stichproben** (`*-samples.jsonl`): CPU, Speicher, Apache busy/idle, DB-Verbindungen, Worker, Lastschutz.
- **Anfragen** (`*-requests.json`): Status, Peak, RSS, Queries.
- **Engine-Phasen** (`*-traces.json`): Selektor, DB-, Sperr- und Clientzeit.
- **Datenbank** (`*-db.json`): Grenzen, Deadlocks, Rollbacks.
- **Unsichere Abgaben** (`*-cdp.json`).

Ausgewertet wird je Stufe mit
`php local/catquizlab/cli/request_report.php --from=… --until=… --json --peak-seconds=10`:

- **je Seite:** n, p50, p95, p99 und Maximum der Anfragedauer;
- **Ausreißer:** Anfragen über dem p99 ihrer Seite, mit Bearbeitung und Ausführung (`sitting.execution`);
- **synchrone Spitzen:** 10-s-Fenster, in denen mindestens 3 Anfragen von mindestens 2 Bearbeitungen über dem p95
  ihrer Seite lagen.

Engine-Phasen kommen aus `trace_report.php` (je Anfrageart p50/p95/p99), der Lebenslauf der Ausführungen aus
`export_lifecycle.php`.

## Wie eine Spitze zugeordnet wird

Jede synchrone Spitze wird neben die Stichproben desselben Zeitfensters gelegt, ±30 s:

- CPU und Last, I/O-Wartezeit;
- Apache busy/idle;
- DB-Verbindungen und Sperrzeiten (`lockwaitms` der Anfragen);
- Engine-Phase (Selektor gegen DB- und Sperrzeit);
- Trace-INSERT im Query-Log-Schritt;
- Browser/CDP der Worker.

Als Ursache gilt eine Ressource nur, wenn sie im Fenster der Spitze anschlägt und in den Fenstern ohne Spitze nicht.
Sonst wird die Spitze als „nicht aufklärbar mit diesen Daten“ dokumentiert.

Eine fehlende Stichprobe ist eine fehlende Stichprobe: nicht 0 und kein PASS (`clara_bench.py` prüft das seit
0.7.80).

## Was nicht getan wird

- Timeout und Speicher werden nicht ohne kausale Evidenz erhöht.
- Strategie, Itemparameter, Seeds und Stoppregeln werden nicht verändert.

## Stand 0.7.84

- **Werkzeug:** `request_report.php` weist die Seiten-Perzentile, die Ausreißer und die synchronen Spitzen aus;
  `load_matrix.sh` misst standardmäßig auch die Stufe 20.
- **Messung auf CLARA:** steht aus und ist Sache des Betriebs. Erst ihre Rohdaten entscheiden AK1 bis AK3 von #177.
- **Langlauf-Vollständigkeit und Trace-Integrität:** Sie werden getrennt davon im Acceptance-Job `long-run` belegt
  (#172 bis #175).
