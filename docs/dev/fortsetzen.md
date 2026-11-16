# Stopp, Abbruch und Fortsetzen (#166, #165, 0.7.65)

Code: `classes/local/experiment_stop.php` (`stop()`, `close_sittings()`, `REASON`, `LEGACY_TEXTS`),
`classes/local/purger.php` (`kill_pipeline()`, `PIPELINE_STOPPED`, `LEGACY_PIPELINE_TEXTS`),
`attempt_scheduler::STATUS_CANCELLED` und `abort()`, `attempt_history::CANCELLED`/`RESUMED`,
`classes/local/experiment_resume.php` (`preview()`, `resume()`, `restart_inventory()`),
`classes/output/resume_view.php`, `runs.php` (Aktionen `resumeexperiment`, `restartstopped`, `resumerun`),
`db/upgrade.php` (`local_catquizlab_upgrade_reclassify_cancelled()`, Schritt 2026111501).
Tests: `tests/operator_cancel_test.php`, `tests/experiment_resume_test.php`, `tests/experiment_stop_test.php`.

## Befund (CLARA, 15.11.2026, 0.7.60)

Experiment „10x10x25x1000 short (15/25/35)“ wurde gestoppt. Danach standen alle sechs Runs #84–#89 auf „Cancelled“:
- 6000 Testbearbeitungen geplant, 1018 gesammelt, 4982 „fehlgeschlagen“, 0 wartend, 0 laufend.
- #86 hatte 997 gesammelt und 3 offen, #89 21 und 979, #84/#85/#87/#88 je 0 und 1000.

Zwei Fehler:
- **#166:** Der Stopp schloss die offenen Testbearbeitungen als `FAILED` (40), mit dem Text „experiment stopped by an
  operator“. Fehlerquote, Run-Karten, Ergebnisse und Forschungsdatenpaket zählten eine Entscheidung als technischen
  Fehler.
- **#165:** Weiter ging es nur über „Zurücksetzen und erneut ausführen“ auf jeder Run-Seite. Das löscht die
  Testbearbeitungen, Personen, Items und Ergebnisse des Runs, hier also auch die 1018 gesammelten.

## Abgebrochen ist nicht fehlgeschlagen (#166)

| Status | Wert | Wer | `lasterror` | Verlauf |
|---|---|---|---|---|
| `STATUS_FAILED` | 40 | Technik: Timeout, Engine-Fehler, Lease, Breaker | der Fehler | `failed` |
| `STATUS_CANCELLED` | 45 | eine Person | `stopped_by_operator`, `pipeline_stopped_by_operator`, `aborted_by_operator` | `cancelled` |

- **Wer setzt `CANCELLED`:**
  - `experiment_stop::close_sittings()` beim Stopp eines Experiments und beim Abbrechen eines einzelnen Runs;
  - `purger::kill_pipeline()` beim Anhalten der ganzen Pipeline;
  - `attempt_scheduler::abort()` beim Abbrechen eines Runs (Werkzeug `cli/claim_race.php`).
- Jede so geschlossene Testbearbeitung bekommt eine Verlaufszeile `cancelled` mit dem Grund als Code
  (`reasoncode`, in jeder Sprache gleich; `attempt_history::record_many()`, in Blöcken zu 500). Das Fortsetzen
  schreibt ebenso `resumed` mit `resumed_by_operator`.
- Beide sind Endzustände (`attempt_scheduler::TERMINAL`). Eine abgebrochene Testbearbeitung wird nicht wieder
  vergeben, bis jemand fortsetzt.
- **Getrennt gezählt:** in `run_figures`, `run_lifecycle::attempt_counts()`, `live_progress`, `live_status`
  (Webservice, Rückgabe `cancelled`), `attempt_scheduler::queue_breakdown()`, `eta`, `experiment_status`,
  `status_report`, `run_registry` (Filter „Abgebrochen“), `results_page`, `analysis_population`, `reproducibility`,
  `population_plan` und im Forschungsdatenpaket (`sittingscancelled` im Codebuch).
- „Fehlgeschlagen“ zählt nur noch technische Fehler.

### Upgrade 2026111501

`local_catquizlab_upgrade_reclassify_cancelled()` setzt Altbestände um, in Blöcken, einmal und idempotent. Eine
fehlgeschlagene Testbearbeitung wird abgebrochen, wenn
- ihr `lasterror` der Stopptext ist („experiment stopped by an operator“ oder „Experiment von Hand gestoppt“) und ihr
  Run `CANCELLED` ist, **oder**
- ihr `lasterror` der Text des Pipeline-Stopps ist („pipeline stopped by an operator“ oder „Pipeline von einer Person
  angehalten“).

Jede andere fehlgeschlagene Testbearbeitung bleibt fehlgeschlagen. `cli/upgrade_check.php` prüft das gegen den
Bestand vor dem Upgrade (#167): genau die erwarteten Zeilen wechseln, alle anderen bleiben gleich.

## Fortsetzen (#165)

### Wo

- **Fortschrittsseite:** „Experiment fortsetzen“ (primär) und getrennt „Gestoppte Runs zurücksetzen und neu ausführen
  (löscht)“, solange ein Run abgebrochen ist, nichts läuft und das Experiment nicht in der Reihe steht
  (`experiment_runner::state()['canresume']`).
- **Statusleiste:** Der nächste Schritt eines gestoppten Experiments ist „Fortsetzen“ (POST).
- **Bibliothek:** im Menü „Mehr“.
- **Run-Seite:** Ein einzeln abgebrochener Run hat „Diesen Run fortsetzen (Gesammeltes bleibt)“, vor dem
  zerstörerischen Zurücksetzen.

Alle Wege nutzen POST, Sesskey und `local/catquizlab:execute`. Zuerst kommt die Vorschau, dann die Bestätigung.

### Vorschau (`preview()`)

Pro Run:
- geplant, gesammelt (bleibt), davon validiert, technisch fehlgeschlagen (bleibt), vom Stopp geschlossen, erneut
  gespielt;
- nach dem Stopp in der Engine fertig (wird eingesammelt, nicht neu gespielt);
- Engine-Versuch läuft weiter;
- noch von einem Worker gehalten.

Dazu die Aktion des Runs:

| Aktion | Wann | Was passiert |
|---|---|---|
| `resume` | einmal spielbereit (im Run-Log `provisioning_ready` oder ein Statuswechsel nach `ready`/`running`), Testbearbeitungen da, Aktivität da, kein Lease | wieder READY |
| `prepare` | nichts gebaut: keine Testbearbeitung, keine Person, keine Aktivität | wieder DRAFT, wird in der Runde des Experiments vorbereitet |
| `blocked` / `partial` | beim Vorbereiten gestoppt: was existiert, weiß der Run nicht | bleibt; zurücksetzen oder neues Replikat |
| `blocked` / `noactivity` | Aktivität gelöscht | bleibt; zurücksetzen oder neues Replikat |
| `blocked` / `leased` | ein Worker hält noch eine Testbearbeitung | bleibt; nach Ablauf des Leases erneut |

Die Tabelle hat eine Beschriftung (`caption`) und Zeilenköpfe (`th scope="row"`). Ein Screenreader liest so jede
Zahl mit ihrem Run und ihrer Spalte.

### Ausführung (`resume()`)

Unter der Sperre `local_catquizlab_resume`/`e<id>` und in einer Transaktion:
1. Jeder `resume`-Run:
   - Testbearbeitungen, deren Engine-Versuch nach dem Stopp fertig wurde, gehen auf `TRACEPENDING` mit
     `engineattemptid`. Sie werden eingesammelt, nicht neu gespielt, und kosten keinen Versuch (AK4).
   - Die übrigen vom Stopp geschlossenen gehen auf `QUEUED`, an ihrem Platz, mit Verlauf `resumed`.
   - Eine mit laufendem Engine-Versuch führt der nächste Worker fort (#136).
   - Der Run geht auf READY, mit Run-Log `run_resumed`.
2. Jeder `prepare`-Run geht auf DRAFT.
3. `blocked`-Runs bleiben, wie sie sind.

Danach:
- Das Experiment wird neu bewertet (`refresh_experiment_by_id`).
- Es reiht sich am Ende der Ausführungsreihe ein (#163). Steht es schon darin, weil ein einzelner Run fortgesetzt wird,
  behält es seinen Platz.
- `kick_pipeline` wird angestoßen.

Unverändert bleiben gesammelte, validierte und fehlgeschlagene Testbearbeitungen samt Traces. Ebenso Ergebnisse, Items
mit Fingerprints, Seeds und Design. Ein zweites Fortsetzen findet nichts (`nothing`). Ein später Bericht eines
Workers, der eine Testbearbeitung vor dem Stopp hielt, wird nicht angewandt (Lease-Besitz, #133).

### Zerstörerisch, getrennt (`restartstopped`)

Das Inventar zeigt pro Run, was endgültig gelöscht wird: Testbearbeitungen, davon gesammelte, Personen, Items und
Ergebniszeilen. Dazu kommen ein roter Hinweis und das Pflichtfeld „Ich verstehe, dass das oben Aufgeführte endgültig
gelöscht wird.“. Erst danach wird jeder Run mit `run_lifecycle::reset_and_rerun()` zurückgesetzt und gestartet. Runs,
die nicht zurückgesetzt werden konnten, werden mit Grund genannt.

## Nachweise

- **`experiment_resume_test`:**
  - der Live-Fall 6000/1018/4982: die 1018 Zeilen samt Traces gleich, die 4982 wartend, alle Runs READY, das Experiment
    in der Reihe und an der Reihe, zweites Fortsetzen „nothing“;
  - Blocker und `prepare`;
  - nach dem Stopp in der Engine fertig: eingesammelt; laufender Engine-Versuch: fortgesetzt; später Bericht:
    abgelehnt;
  - ein einzelner Run;
  - 100 Runs in einer Aktion.
- **`operator_cancel_test`:** Stopp, Live-Fall mit Zeiten („Gestoppt“), Upgrade-Umklassifizierung, Abbruch eines Runs
  (Seite und `abort()`), Verlaufszeilen mit Code.
- **Upgrade von 0.7.60:** `cli/upgrade_check.php` und der CI-Job „Upgrade“ (#167).
- **Echte Worker:** `cli/resume_check.php` im Worker-E2E (alle drei Stacks): gestoppt, während gespielt wird,
  fortgesetzt, von Cron zu Ende gebracht.
- **Oberfläche:** `tests/playwright/experiment_resume.spec.js`.

## Prognose

Ein gestopptes Experiment zeigt in den Zeiten „Gestoppt“ statt „Abgeschlossen“ (`eta::STOPPED`):
- Restarbeit: „N vom Stopp geschlossene Testbearbeitung(en); gespielt, wenn das Experiment fortgesetzt wird“;
- kein vorhergesagtes Ende;
- jeder gestoppte Run ebenso.

„Alle Experimente“ lässt es aus der Prognose heraus wie ein fertiges.
