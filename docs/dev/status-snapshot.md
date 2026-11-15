# Ein Status je Experiment: Snapshot, Phasen, nächster Schritt (#128)

Code: `classes/local/experiment_status.php`. Gelesen von: Statusleiste unter den
Tabs (`output/shell`, `templates/shell.mustache`), Fortschrittskarte und
Experimentzeile (`progress_view`), Live-Poll (`local_catquizlab_live_status`:
`status`, `progress.label`, `experiment.label`). Ein Snapshot je Request
(memoisiert) bzw. je Poll (`snapshot($id, true)`); er trägt seinen Zeitpunkt.

## Felder

| Feld | Inhalt |
|---|---|
| `phase`, `label` | Phase im Leben des Experiments, in Worten |
| `done`, `total`, `percent`, `sittings` | Testbearbeitungen gesammelt / geplant (aus `live_progress`) |
| `problems`, `problemtext` | endgültig gescheiterte Bearbeitungen + angehaltene Läufe |
| `reason` | Grund bei wartend/startend/pausiert/blockiert |
| `next` | genau eine zulässige Aktion (`kind`, `label`, `url`, `post`, `action`) |
| `technical` | Worker-Zahlen, getrennt vom wissenschaftlichen Status |
| `computed`, `computedtext` | Zeitpunkt |

## Phasen und nächster Schritt

| Phase | Wann | Nächster Schritt |
|---|---|---|
| planned | keine Läufe, nicht gestartet | **Experiment starten** (POST `startexperiment`, seit 0.7.64, #151) |
| created | Läufe angelegt, nicht gestartet, nicht in der Warteschlange | Experiment starten |
| preparing | Läufe in Vorbereitung; oder die Warteschlange bereitet es an seiner Reihe selbst vor (#151) | Fortschritt ansehen — bzw. Experiment starten, wenn es nicht in der Warteschlange steht |
| ready | alle Läufe bereit, nichts gestartet, nicht in der Warteschlange | Experiment starten |
| queued | in der Warteschlange, wartet auf seine Reihe | Fortschritt ansehen — **kein zweiter Start** |
| starting | an der Reihe und vorbereitet, noch keine Testbearbeitung: Worker werden gestartet (#151) | Fortschritt ansehen |
| collecting / waiting / paused | aus `live_progress` | Fortschritt ansehen |
| blocked | Lauf angehalten/gescheitert | Probleme ansehen (`runs.php#catquizlab-recovery`) |
| aggregating | Ergebnisse werden berechnet | Fortschritt ansehen |
| complete / completefailures | nichts mehr offen (gesammelt oder endgültig gescheitert) | Ergebnisse ansehen |

Seit 0.7.64 (#151) gibt es **einen** Start: „Experiment starten“ in planned/created/preparing/ready, solange das
Experiment nicht in der Warteschlange steht. Er nimmt nur den Auftrag an (`experiment_runner::start()`); Läufe
anlegen, starten, vorbereiten und ausführen folgen von selbst (`docs/dev/ein-start.md`). Die früheren Einzelschritte
„Läufe anlegen“ und „Läufe starten“ sind keine Hauptaktion mehr; „Vorbereiten“ bleibt als Wiederherstellung bei
blockiert. `execution_queue::enqueue` ist idempotent, unter einer Sperre. Auf dem Schritt, auf den die Aktion führt, wird sie nicht
als Knopf angeboten (außer POST-Aktionen und „Probleme ansehen“).

## Weitere Korrekturen

- Die Installationszeile ohne gewähltes Experiment las Felder, die es nicht gab
  (`state`, `reason`, `good/watch/bad`), und zeigte nur das interne Wort
  „working“/„failing“; jetzt Überschrift, Detail und Farbe.
- Ein Experiment mit endgültig gescheiterten Bearbeitungen blieb für immer
  „Wartet“; jetzt „Abgeschlossen, mit endgültig gescheiterten Bearbeitungen“.
- „Show“ ersetzt: „Ausführungsdetails: wie ein Experiment abläuft“,
  „Diese Ebene anzeigen“, „Diese Bearbeitung anzeigen“, „Worker-Details (n)“.

## Tests

`tests/experiment_status_test.php` (Phasen und Aktionen, kein Start bei
eingereiht/laufend, gleicher Status in Kopf, Karte, Zusammenfassung und Poll,
Installationszeile in Worten); `tests/playwright/status_bar.spec.js` (laufend,
blockiert, abgeschlossen, geplant; fünf Tabs und Ergebnis-Untertabs; kein
nacktes „Show“; benannte Region, Statusrolle, Tastaturfokus).
