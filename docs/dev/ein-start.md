# Ein Start, alles Weitere von selbst (#151)

Seit 0.7.64. Code: `experiment_runner::start()`, `experiment_runner::prepare_in_background()`,
`execution_queue::enqueue()`/`advance()`, `task\prepare_experiment`, `task\kick_pipeline`, `experiment_status`;
Prüfung ohne Menschen: `cli/one_start_check.php`.

## Zwei Handlungen

1. **Experiment anlegen und speichern** (Plan).
2. **„Experiment starten“, einmal.** Das ist die eine Hauptaktion in der Statusleiste von Plan und Fortschritt, solange
   das Experiment nicht in der Warteschlange steht. Die Bibliothek öffnet das Experiment.

Danach: Browser schließen, abmelden, wegfahren. Was folgt, erledigen Cron, die Warteschlange, Aufgaben und Worker.

## Was der Start-POST tut, und nur das

`runs.php`, `action=startexperiment`, mit Sesskey und `local/catquizlab:execute` → `experiment_runner::start()`:

| Prüfung | Ergebnis |
|---|---|
| Experiment fehlt oder wird gelöscht | abgelehnt, mit Grund |
| Definition ungültig (`experiment_service::validate`) | **abgelehnt, sofort**, mit den Fehlern. Das ist das, was eine Person ändern muss |
| läuft, fertig, angehalten, blockiert | abgelehnt („Zustand: …“). Blockiert heißt: Wiederherstellung |
| sonst (geplant, angelegt, in Vorbereitung, bereit) | **angenommen**: Eintrag in der Warteschlange (`execqueue`, `waiting`), unter Sperre. Ein zweiter Start findet ihn (`already-queued`) |

Fehlt der Installation noch etwas (Worker, Browser, Cron, Kurs, Token …), wird der Auftrag **trotzdem angenommen**
und wartet mit Grund („wartet, bis die Installation …“). Ist es behoben, geht es von selbst weiter.

Danach fragt der POST einen Takt sofort an (`task\kick_pipeline`, ein Ad-hoc-Task, nicht doppelt) und leitet zur
Fortschrittsseite: „**Start angenommen.** … noch läuft nichts.“ Er legt keine Läufe an und startet keinen Worker.
Große Sweeps kosten den Request deshalb nichts.

## Was danach von selbst geschieht

| Wann | Wer | Was |
|---|---|---|
| jeder Takt (geplant alle 5 min, angefragt sofort) | `execution_queue::advance()` unter Sperre | vorne in der Warteschlange: Installation bereit? Sonst warten (`setup-incomplete`). Nicht vorbereitet → Eintrag `running`, Grund `preparing`, `task\prepare_experiment` einreihen. Vorbereitet → `running` |
| sobald Cron Ad-hoc-Tasks ausführt | `task\prepare_experiment`, als die Person, die gestartet hat, unter Sperre je Experiment | Läufe anlegen, falls keine da sind (`create_sweep`); jeden Entwurfslauf starten (`run_lifecycle::start_drafts()`: Preflight, je Lauf eine Einrichtungsaufgabe) |
| je Lauf | `task\orchestrate_run` | Skalen, Items, Fragen, Test, Personen, Sitzungen; dann bereit (`provisioned`), und ein Takt wird sofort angefragt |
| Takt | `worker_launcher::launch_pool()` | Worker nach Bedarf, in freie Plätze, gestaffelt (#120, #124) |
| Worker | Claims, Leases, Heartbeats | Testbearbeitungen, Traces (#138), Wiederholungen mit Backoff, Schutzschalter (#133) |
| Takt | `worker_registry::reap()`, `reclaim_stale()`, `retry_pending_traces()` | tote Worker, abgelaufene Leases, fehlende Traces |
| letzte Sitzung | `aggregate_results` | Ergebnisse; Lauf und Experiment `finished` |
| Takt | `advance()` | fertig oder blockiert → Eintrag geschlossen; das nächste Experiment beginnt |

Eine Seite, die offen ist, treibt nichts an. Weder Seitenaufrufe noch die Live-Abfrage stoßen etwas an.

## Unterbrechungen (AK4)

- **Vorbereitung verloren:** Der Task fehlt, ist endgültig gescheitert, oder Cron wurde neu gestartet. Solange das
  Experiment im Eintrag `preparing` noch keine Läufe hat oder Entwurfsläufe, reiht jeder Takt den Task wieder ein. Ein
  wartender wird nicht verdoppelt. Ein solcher Eintrag gilt nicht als verwaist.
- **Vorbereitung verweigert** (Preflight: Kurs, Capability, Engine): Der Grund steht am Eintrag
  (`preparing: <Grund>`) und in der Warteschlangenliste. Die Reihe bleibt gehalten; nach Behebung geht es weiter.
- **Worker tot oder überfällig:** Die Leases laufen ab, der Takt erntet die Worker (`reap`) und startet neue.
- **Cron gestoppt:** Es geschieht nichts, und nichts geht verloren. Danach geht es weiter, wo es stand.
- **Angehalten** (`experiment_stop`): Der Eintrag wird geschlossen. Ein noch wartender Vorbereitungstask tut dann
  nichts mehr („no longer in the line“).

## Reihenfolge und Doppelungen (AK7)

- Ein Experiment zur Zeit: Vorbereitet wird erst an der Reihe. Ein zweites gestartetes bleibt `queued`, ohne Läufe,
  bis das erste fertig ist. Seit 0.7.65 gilt das auch für die Claims (#163, `docs/dev/claim-reihenfolge.md`): Ein Worker
  bekommt nur Testbearbeitungen des Experiments, das die Reihe hält; außerhalb gestartete Läufe reiht die Reihe hinten
  ein; ein Halter, dessen Testbearbeitungen alle vergeben und fertig sind, gibt die Reihe ab.
- Doppelklick, zwei Browser: ein Eintrag, unter Sperre.
- Zwei Takte zugleich (geplant und angefragt): `advance()` unter Sperre.
- Zwei Vorbereitungen zugleich: Sperre je Experiment, Läufe nur ohne Läufe, Starts nur für Entwürfe.

## Was die Oberfläche sagt (AK9)

| AK9 | Phase (`experiment_status`) | Bedeutung |
|---|---|---|
| queued | `queued` | in der Warteschlange, wartet auf seine Reihe |
| validating | – | geschieht im Start-POST selbst, synchron; ungültig wird sofort abgelehnt |
| provisioning | `preparing` | an der Reihe, wird vorbereitet (oder Läufe in Vorbereitung) |
| ready | `ready` | vorbereitet, nicht gestartet (nur ohne Warteschlange, etwa nach Wiederherstellung) |
| worker starting | `starting` | an der Reihe und vorbereitet, noch keine Testbearbeitung |
| running / collecting | `collecting`, `waiting`, `paused` | Testbearbeitungen laufen, warten auf Wiederholung, pausiert |
| aggregating | `aggregating` | Ergebnisse werden berechnet |
| finished | `complete`, `completefailures` | fertig |
| blocked | `blocked` | eine Person muss etwas tun (Wiederherstellung) |

Die Startbestätigung sagt nur „angenommen“ und „noch läuft nichts“. Bei fehlender Installation steht dabei, worauf
es wartet.

## Was unverändert bleibt (AK11)

Der Start ändert weder Definition noch Seeds, Einstellungen, Stoppcodes oder Nenner. Die Vorbereitung auch nicht. Die
Tests vergleichen `configjson` vor und nach Start und Vorbereitung.

## Nachweise

- **PHPUnit** `one_start_test`:
  - Start nur Annahme;
  - Vorbereitung an der Reihe, als Startende;
  - verweigert mit Grund, behoben, weiter;
  - zweimal ist einmal;
  - zwei Experimente nacheinander;
  - verlorene Vorbereitung wieder angefragt;
  - Installation nicht bereit: wartet, geht weiter;
  - ungültig, blockiert, wird gelöscht: abgelehnt;
  - an der Reihe, vorbereitet: „starting“;
  - 120 Läufe im Hintergrund;
  - angehalten: nicht vorbereitet;
  - Takt sofort angefragt.

  Angepasst: `experiment_runner_test`, `experiment_status_test`, `stalled_start_test`.
- **Interface-E2E**, alle Stacks, `experiment.spec.js`:
  - Definition gespeichert, dann genau ein Start-POST;
  - jede Formularsendung an das Plugin wird gezählt, bis zu den Ergebnissen genau `startexperiment`;
  - danach abgemeldet (Sitzung beendet), Browserkontext geschlossen;
  - später eine neue Sitzung, die nur liest, bis alle Testbearbeitungen gesammelt sind, mit Ergebnissen.
- **Worker-E2E**, alle Stacks, „Issue 151 — one start, then nobody“ (`cli/one_start_check.php`):
  - gespeichert, einmal gestartet, danach nur Cron als eigene Prozesse;
  - unterwegs Cron 90 s gestoppt;
  - alle Worker-Prozesse getötet, sobald die erste Testbearbeitung da ist;
  - PASS nur bei fertigem Experiment, allen Testbearbeitungen, Ergebnissen und geschlossenem Warteschlangeneintrag.
