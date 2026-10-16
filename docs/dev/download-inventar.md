# Downloads und Exporte: Inventar (#121)

Stand 0.7.37. Jede Zeile ist in `tests/playwright/downloads.spec.js` (bzw.
`logs.spec.js`) gedrückt oder abgerufen, und die gelieferte Datei geöffnet:
JSON geparst, CSV mit Kopfzeile, PDF/PNG/ZIP an der Signatur, SVG am `<svg`.

| Seite (Tab) | Bedienelement | Endpunkt | Methode | Recht | Datei | Prüfung |
|---|---|---|---|---|---|---|
| Ergebnisse (Results), über den Analysen | „Reproduzierbarkeitspaket herunterladen“ | `results.php` `action=package` | POST + sesskey | `view` (Seitenzugang `local_catquizlab_manage`) | `catquizlab-experiment-<id>.json` | JSON; Schlüssel generated, experiment, versions, runs (mit definition, effective, stoprules, Seeds, Manifest, Log), completeness; Secrets redigiert |
| Ergebnisse ▸ Export | je Ebene (run, attempt, subscale, item, step, detection, detectionsummary) CSV / JSON / Metadaten | `results.php` `action=csv\|json\|meta&level=…` + aktive Filter | GET | `export` | `catquizlab_<ebene>_<filter>.csv/json/.metadata.json` | CSV mit Kopfzeile, JSON geparst; groß: 4000 Bearbeitungen / 24 000 Schritte vollständig |
| Ergebnisse ▸ Overview / Global / Test flow (jede Abbildung) | SVG / CSV / JSON / PNG / PDF | SVG/CSV/JSON/PNG im Browser; PDF `plotpdf.php?key=…&sesskey=…` | Klick / GET | `view` | `catquizlab-<plot>-exp<id>-<zeit>.<format>` | Signatur bzw. Parser |
| Experiment-Plan | Definition exportieren (deklarativ / normalisiert) | `experiment.php?id=…&action=export[&variant=…]` | GET | `view` | `catquizlab-<name>-<variante>.json` | JSON |
| Vorbereitung (Experimentliste) | Export je Experiment | `experiment.php?id=…&action=export` | GET | `view` | wie oben | JSON |
| Vergleich | Metriken als CSV | `compare.php?experimentid=…&action=export` | GET | `export` | `catquizlab-metrics-<id>.csv` | CSV (Kopfzeile; ohne gespeicherte Lauf-Metriken nur Kopfzeile) |
| Fortschritt ▸ Lauf | Debug-ZIP (Lauf) | `runs.php` `action=artefactzip&scope=run` | POST + sesskey | `debug` | `catquizlab-run-<id>-run.zip` | ZIP |
| Fortschritt ▸ Lauf | Debug-ZIP (fehlgeschlagene Bearbeitungen) | `runs.php` `action=artefactzip&scope=failed` | POST + sesskey | `debug` | `catquizlab-run-<id>-failed.zip` | ZIP; **nur angeboten, wenn es fehlgeschlagene Bearbeitungen gibt**, sonst deaktiviert mit Begründung |
| Fortschritt ▸ Lauf ▸ Bearbeitung | Debug-ZIP (eine Bearbeitung) | `runs.php` `action=artefactzip&scope=attempt&attemptid=…` | POST + sesskey | `debug` | `catquizlab-run-<id>-attempt.zip` | ZIP |
| Fortschritt (bei aktiver Debug-Aufzeichnung) | Debug-Protokoll exportieren | `operations.php` `action=exportdebug` | POST + sesskey | `debug` | `catquizlab-debug-<zeit>.json` | JSON |
| Logs | Auswahl als Text / als JSON | `logs.php` `download=1` / `downloadjson=1` + Filter | POST + sesskey | `view` | `catquizlab-log-<zeit>.txt/.json` | Kopf mit Filter, Zeitraum, Limit, `truncated`, `partial`, Leseprofil |
| Logs | Live-Tail | `logs.php?tail=1&after=<ms>` | GET (fetch) | `view` | JSON-Antwort | nur Zeilen nach `after` |

## Fehlerfälle (AK 2, AK 5)

| Fall | Verhalten | Test |
|---|---|---|
| Paket ohne sesskey | Moodle-Fehlerseite, keine Datei | `downloads refuse cleanly` |
| Paket für unbekannte Experiment-ID | Fehlerseite „Es gibt kein Experiment mit der ID …“ (HTTP 404) | PHPUnit `reproducibility_test`, Playwright |
| Unbekannte Exportebene | fällt auf Bearbeitungsebene zurück, liefert Datei | Playwright |
| Angemeldeter Nutzer ohne Rechte | Zugriff verweigert, keine Datei (Export, Definition, Vergleich, Paket) | Playwright mit `cli/seed_plain_user.php` |
| Debug-ZIP ohne Inhalt | Schaltfläche deaktiviert, Hinweis „keine Debug-Artefakte“ | Playwright (Lauf-Seite) |

## Ursache des Screenshots (DoD 1)

`results.php` (0.7.36, Zeile 41–56) behandelte `action=package` **vor**
`admin_externalpage_setup()`. Erst dieser Aufruf lädt über die Admin-Bibliothek
`lib/filelib.php`, in der `send_file()` definiert ist. Der Zweig rief
`send_file()` also auf, bevor es die Funktion gab: „Call to undefined function
send_file()“. Alle anderen Downloads liegen hinter dem Seiten-Setup und waren
nicht betroffen. Behoben in 0.7.37: Zweig hinter Login und Rechteprüfung,
`filelib.php` ausdrücklich geladen.
