# Worker-Kapazität: Grenze, Draining, Lastschutz, Uptime (#124)

Code: `classes/local/worker_capacity.php`; eingebunden in `worker_launcher`
(`config_from_settings`, `slots_to_start`, `launch_pool`), `job_claim`,
`worker_heartbeat`, `worker_registry::adopt`, Einstellungen (Formular und
Admin-Einstellungen), Fortschrittsseite (Worker-Karte).

## Ursache der 248 Worker bei `worker_concurrency = 50`

`launch_pool()` zählte freie Plätze nur in den Slots 1…`concurrency`. Nach einer
Absenkung 250 → 50 liefen die Worker in den Slots 51…250 weiter (nichts bat sie
zu stoppen), und jeder Tick startete in freien Slots ≤ 50 neue. Die Summe lag so
bei bis zu 248. Jetzt zählt jeder lebende Worker gegen die Grenze, auch einer
oberhalb (`worker_launcher::slots_to_start`).

## Die Grenze in Kraft

`limit = min(worker_concurrency, worker_safety_cap (falls > 0), Lastschutz)`,
`effective()['by']` nennt, wer sie setzt (`configured`, `cap`, `loadguard`).

- **worker_safety_cap** (neu, Standard 0 = aus): aus einer Messung des Webservers
  zu setzen (Apache `MaxRequestWorkers`, PHP-Speicher; siehe Messprotokoll).
- **Lastschutz**: Fenster 5 min; zählt Ausführungen (`collected`/`failed`) und
  Kapazitätsfehler (`failed`/`recovered` mit Grund aus
  `circuit_breaker::CAPACITY_CAUSES`: HTTP 5xx, Connect-Timeout/-Fehler,
  CDP-Timeout, …). Ab ≥ 5 Fehlern und ≥ 20 % Anteil: Grenze halbiert für 10 min
  nach dem letzten Auslöser. Auswertung höchstens alle 30 s (Plugin-Config
  `loadguard`). Deterministische Fehler (404, Fehlerseite, Engine-Exception)
  zählen nicht — sie sind keine Lastfrage.

## Draining

Worker in Slots über der Grenze werden gebeten, ihre laufende Bearbeitung zu
beenden und zu stoppen (`stoprequested`), nie beendet. Sie bekommen keinen neuen
Claim (`job_claim` → „Keine neue Bearbeitung …“), ihr nächster Heartbeat
antwortet `stop`. Der Launcher startet nichts, solange lebende Worker die Grenze
ausschöpfen. Ausgelöst durch: Speichern der Einstellungen (Formular und
Admin-Einstellung), jeden Launcher-Tick (`note_change`), den Heartbeat eines
Workers über der Grenze. Idempotent: jeder Worker wird einmal gebeten.

Leases bleiben unberührt (#133): ein auslaufender Worker meldet seine
Bearbeitung regulär; keine Bearbeitung geht verloren oder wird doppelt geclaimt.

## Uptime

Neues Feld `local_catquizlab_worker.timestarted`, gesetzt bei jeder
Slot-Übernahme (auch beim Wiederverwenden der Zeile). Uptime =
letzte Meldung − Prozessstart, nicht mehr Alter der Registry-Zeile.

## Anzeige

Worker-Karte: „Worker: 50 konfiguriert, 50 wirksam · 60 aktiv (… bearbeiten,
… starten, … warten auf gestaffelten Start, 10 laufen aus) · 0 freie Plätze“ und
der Grund einer niedrigeren Grenze (Obergrenze / Lastschutz mit Fehlern, Anteil,
Ursachen, Ende). Worker-Details eingeklappt; Status „Läuft aus“.

## Log

Installationsereignisse im Lebenszyklus-Log mit `runid = 0`, im Log als
„installation …“: `capacity_changed` (von/nach, Obergrenze), `workers_draining`
(Grenze, Ursache, Anzahl; Warnung), `load_throttled` (Fehler, Ausführungen,
Anteil, Ursachen; Warnung), `load_released`.

`cli/capacity_report.php [--hours=2] [--json]`: Stand, Lastschutzfenster,
Ausführungen/Kapazitätsfehler/sonstige Fehler/rekonziliierte Übermittlungen je
5 Minuten, Installationsereignisse — als Beleg vor und nach einer Änderung.

## Messprotokoll (DoD-LOAD-01, auf dem Zielserver)

Für 1, 5, 10, 25, 50 Worker je ≥ 15 min mit einem Experiment mit genügend
Bearbeitungen:

1. `worker_concurrency` setzen, `cli/capacity_report.php` vorher.
2. Während des Laufs je Minute: Apache `server-status?auto` (BusyWorkers,
   IdleWorkers), `grep -c AH00161` im Error-Log, PHP-FPM/Apache-Speicher
   (`ps -o rss`), 5xx-Rate im Access-Log.
3. Danach `cli/capacity_report.php --hours=1 --json` sichern.
4. Größte Workerzahl ohne AH00161 und ohne Kapazitätsfehler ⇒
   `worker_safety_cap` mit Reserve setzen.

## Tests

`tests/worker_capacity_test.php`: 60 → 50 mit Draining (keine Claims, Stop beim
Heartbeat, kein Neustart bis ausgelaufen, höchstens 50, niedrigste Slots
zuerst), Neustart setzt Uptime und Stop-Wunsch zurück; Sicherheitsobergrenze;
Lastschutz mit Schwelle, Cache, Ereignis einmal, Freigabe; Log-Zeilen.
Playwright `progress_time.spec.js`: Kapazitätszeile, Details eingeklappt.
