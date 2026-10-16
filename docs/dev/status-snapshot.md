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
| planned | keine Läufe | Läufe anlegen (POST `prepareexperiment`) |
| preparing | Läufe in Vorbereitung | Fortschritt ansehen |
| ready | alle Läufe bereit, nichts gestartet | Experiment starten (POST `startexperiment`) |
| queued | in der Ausführungswarteschlange, nichts gestartet | Fortschritt ansehen — **kein zweiter Start** |
| starting / collecting / waiting / paused | aus `live_progress` | Fortschritt ansehen |
| blocked | Lauf angehalten/gescheitert | Probleme ansehen (`runs.php#catquizlab-recovery`) |
| aggregating | Ergebnisse werden berechnet | Fortschritt ansehen |
| complete / completefailures | nichts mehr offen (gesammelt oder endgültig gescheitert) | Ergebnisse ansehen |

Der Startknopf erscheint nur in `ready`; `execution_queue::enqueue` ist
zusätzlich idempotent. Auf dem Schritt, auf den die Aktion führt, wird sie nicht
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
