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

## Lastmatrix mit echten CAT-Sittings (0.7.57)

`cli/load_matrix.sh` misst Worker × Modus (`WORKERS="1 5 10 25 50"`). Die Modi:
- classic und allsubs als lange Tests (`MINANSWERS`, Vorgabe 120 Fragen),
- ein kurzer Kontrollmodus (allsubs, 2–4 Fragen).

Pro Stufe:

1. `cli/smoke.php` spielt ein Experiment mit Personen = Worker × `PER`.
2. `cli/load_sampler.php` tastet alle 2 s ab:
   - Webserver-Prozesse,
   - deren RSS (Summe und Maximum),
   - DB-Verbindungen,
   - Load,
   - freien Speicher.
3. `cli/request_report.php` wertet jeden getracten Request der Stufe aus:
   - Status, 5xx, Fatals, Exceptions,
   - PHP-Spitze (max/p95/p50), RSS des Prozesses, Abfragen je Request,
   - Zahl der Webserver-Prozesse, Sittings und Korrelationen.
4. `cli/capacity_report.php` sichert den Zustand der Worker.

Danach steht `worker_concurrency` wieder auf dem vorherigen Wert.

## Tests

`tests/worker_capacity_test.php`: 60 → 50 mit Draining (keine Claims, Stop beim
Heartbeat, kein Neustart bis ausgelaufen, höchstens 50, niedrigste Slots
zuerst), Neustart setzt Uptime und Stop-Wunsch zurück; Sicherheitsobergrenze;
Lastschutz mit Schwelle, Cache, Ereignis einmal, Freigabe; Log-Zeilen.
Playwright `progress_time.spec.js`: Kapazitätszeile, Details eingeklappt.

## Lastschutz von Hand freigeben (#162, 0.7.65)

Befund CLARA, 15.11.2026, 13:32 UTC:
- 25 konfiguriert, Sicherheitsgrenze 25, wirksam 12.
- Der Lastschutz hatte 37 Kapazitätsfehler gezählt (`submit_cdp_timeout`: Antworten über 60 s, nachträglich
  abgeglichen) gegen 10 beendete Ausführungen: 37 / 47 = 78,7 % bei 20 % Schwelle.

Abwarten half nicht, denn jede Auswertung, die die Fehler noch im Fenster fand, verlängerte die Abkühlzeit. Auch
`loadguard_minfailures = 0` half nicht sofort: Es schaltete künftiges Auslösen ab, ließ das laufende `until` aber
stehen. Ein Bedienweg außer SQL fehlte. `tests/loadguard_release_test.php` spielt den Stand nach.

**Freigabe** (`worker_capacity::release_guard()`, Aktion `releaseguard` auf `operations.php`):
- **Wer und wie:**
  - nur mit `moodle/site:config` und Sitzungsschlüssel;
  - mit Begründung (Pflichtfeld) und bestätigter Prüfung von Webserver-Last und Fehlerursache.
- **Protokoll:**
  - Ereignis `\local_catquizlab\event\loadguard_released` im Website-Protokoll, mit Akteur, Zeit, Grenzen vorher und
    nachher, Sicherheitsgrenze, aufgehobenem Ende, Zählung und Ursachen;
  - `load_released` mit `manual` im Lebenszyklus-Log der Installation (`runid = 0`), ohne Nutzer-ID.
- **Wirkung:**
  - Beendet die laufende Abkühlzeit.
  - Der Lastschutz zählt ab dem Freigabezeitpunkt neu: Die 37 alten Fehler lösen ihn nicht sofort wieder aus, neue
    schon.
  - Nichts wird abgeschaltet.
  - Nichts geht über `worker_concurrency` oder `worker_safety_cap`; die Sicherheitsgrenze hat immer Vorrang.
  - Kein Worker wird beendet, kein Lease berührt.
- **Wiederanlauf:** `kick_pipeline` startet Worker für die frei gewordenen Plätze nacheinander, im Abstand von
  `worker_start_stagger` (#120). Mehr Klicks braucht es nicht.
- **Kein Pendeln:** Löst der Lastschutz innerhalb von 30 min nach einer Freigabe erneut aus (`RELEASE_PROBATION`),
  dann:
  - hält er die doppelte Abkühlzeit (höchstens 2 h);
  - bis dahin ist keine weitere Freigabe möglich (`retripped`).
  - Danach gilt wieder die normale Abkühlzeit.
- **Zwei Personen zugleich:** Die Freigabe läuft unter einer Sperre. Die zweite Person findet nichts mehr
  freizugeben (`not-throttled`).
- **Dauerhaft abschalten:** Das bleibt `loadguard_minfailures = 0`. Seit 0.7.65 endet eine laufende Abkühlzeit damit
  sofort, mit Eintrag `load_released`, „guard switched off“.

**Anzeige** (Worker-Karte der Fortschrittsseite, aufklappbar „Lastschutz“, bei Drosselung offen), aus einer Messung
mit UTC-Zeitstempel:
- konfiguriert, Sicherheitsgrenze, wirksam, halbiert;
- gezählt um/seit (UTC), Fehler gegen beendete Ausführungen, Anteil, Ursachen einzeln;
- Schwellen und Abkühlzeit;
- Drosselung bis (UTC);
- letzte Freigabe mit Begründung und Frist;
- die Erklärung, wovon der Anteil ein Anteil ist: Er ist die Zählung des Lastschutzes, nicht der Anteil gescheiterter
  Testbearbeitungen oder Personen. Ein abgeglichener Fehler ist eine fertige Testbearbeitung.

Daneben steht die vorsichtige Alternative: das Limit senken (Link zu den Einstellungen).

**Offen** (Betrieb, nicht Code): Messreihe 1/5/10/12/25 Worker auf CLARA oder einer gleichwertigen Umgebung (DoD-05),
mit `cli/load_matrix.sh` (`WORKERS="1 5 10 12 25"`) und der Webserver-Auslastung.

## Verwandte Issues (#124 DoD-LOAD-03)

- **#110 (HTTP 500, Zeitüberschreitung, Speicher):** was ein fehlgeschlagener oder zu später Antwort-POST beim Worker
  auslöst und wo er auf dem Server zu finden ist: `docs/dev/antwortuebermittlung.md` („Ein HTTP 500 finden“); die
  Speichergrenzen der Ergebnisseiten: `docs/dev/speicher-ergebnisse.md`.
- **#133 (Circuit Breaker, Lease, Retry):** `docs/dev/lease-retry-breaker.md`. Der Breaker hält einen Lauf an, der
  Lastschutz senkt die Zahl der Worker; beide zählen Fehler, aber verschiedene.
- **#120 (gestaffelter Start):** Worker beginnen im Abstand von `worker_start_stagger` (20 s); auch das Nachstarten nach
  einer Freigabe des Lastschutzes und nach dem Ablösen läuft gestaffelt.
- **#162, #163, #164, CatQuiz #27:** Abgrenzung in `docs/dev/clara-abgrenzung.md`.
