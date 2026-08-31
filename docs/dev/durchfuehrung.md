# Ein Experiment durchführen (End-to-End)

Diese Anleitung beschreibt, wie ein Experiment mit `local_catquizlab` in einer
Moodle-Instanz mit installierter CAT-Engine (`local_catquiz` + `mod_adaptivequiz`
+ catmodel-Subplugin) von der Definition bis zum Export durchläuft.

Stand: Release 0.1.43.

## 0. Voraussetzungen

- `local_catquiz`, `mod_adaptivequiz` und das catmodel-Subplugin `catquiz` sind
  installiert und funktionsfähig (das Plugin erkennt sie zur Laufzeit über
  `\local_catquizlab\local\environment`; ohne sie laufen die betreffenden
  Schritte als No-op).
- Das Plugin ist installiert; unter *Website-Administration → Plugins → Lokale
  Plugins → CAT-Experiment-Suite* ist der Hauptschalter aktiviert.
- Eine **Ziel-Fragekategorie** existiert, in der die materialisierten Fragen
  angelegt werden (ihre `id` wird unten als `--questioncategoryid` gebraucht).
- Für die Durchführung: entweder der **exec-Worker** ist konfiguriert
  (Einstellungen *Lokaler Worker*: aktivieren, Node-Pfad, Basis-URL, Token) —
  oder ein externer Worker pollt die Job-Queue über die Webservices.

## 1. Experiment definieren und in die Registry expandieren

Das Experimentformat ist deklarativ (siehe `docs/design/experiment-format.md`).
Ein Sweep expandiert Faktoren (Strategie × Pool-Variante × Stratum × …) zu
konkreten Runs. Über die CLI:

```
php local/catquizlab/cli/sweep.php --help
```

Das legt Experiment- und Run-Zeilen an (Status `DRAFT`). Alternativ lässt sich
die Registry über die Experiment-API befüllen. Jeder Run trägt Zelle, Seed und
Replikation.

## 2. Run(s) end-to-end aufsetzen

Der Orchestrator verkettet die Vorbereitung: Skalen/Kontext → Fragen/Items →
CAT-Test → Personen/Nutzer/Kurs/Einschreibung → Attempts einreihen. Danach steht
der Run auf `SCHEDULED`.

```
# Ein einzelner Run:
php local/catquizlab/cli/orchestrate.php --runid=42 --questioncategoryid=123

# Alle Runs eines Experiments:
php local/catquizlab/cli/orchestrate.php --experimentid=7 --questioncategoryid=123

# Alle Runs aller Experimente, in Tier-Reihenfolge (baseline → main → robustness → operative):
php local/catquizlab/cli/orchestrate.php --questioncategoryid=123

```

Polytome Items werden **nicht** mehr über einen Schalter angefordert: sie folgen
seit 0.2.0 aus dem Modell der Experimentdefinition (`"model": "gpcm"` oder ein
anderes polytomes Modell). Ein Run ist damit allein aus `configjson + seed`
rekonstruierbar, was mit einem separaten Setup-Parameter nicht der Fall war.

Off-request geht dasselbe über den Adhoc-Task
`\local_catquizlab\task\orchestrate_run::queue($runid, $options)`.

Was dabei passiert (jede Stufe engine-gekapselt):
1. **scales** — `scale_provisioner` legt einen CAT-Kontext und den catscales-Baum
   an und schreibt die `scalemap`-Zuordnung (Engine-Skala ↔ Profil-Subskala).
2. **materialise** — `materialiser` rendert je Item eine MC-Frage
   (`question_template`), legt sie in der Fragekategorie an und registriert sie
   als CAT-Item mit Parametern (`item_registrar`).
3. **test** — `test_provisioner` legt die adaptivequiz-Instanz mit
   catquiz-Einstellungen an; der catmodel-Handler schreibt die
   `local_catquiz_tests`-Zeile. `run.testcmid` wird gesetzt.
4. **people** — `person_generator` erzeugt die θ-Profile, `user_provisioner`
   die Nutzer, `course_provisioner` Kurs und Einschreibung.
5. **attempts** — `attempt_scheduler` reiht die Attempts (Status `QUEUED`) ein.

## 3. Attempts durchspielen (Worker)

- **exec-Variante:** den Task `\local_catquizlab\task\dispatch_worker` einreihen
  (bzw. über Cron laufen lassen); er startet `worker/run_attempt.js` auf dem
  Host und arbeitet die Queue ab.
- **Queue-Polling:** ein externer Node-Worker pollt `job_claim`, spielt den
  Attempt durch das echte adaptivequiz-UI, holt je Frage die Antwort beim
  Oracle-Webservice (`oracle_answer`) und meldet über `job_complete` zurück.

Der Worker antwortet **seed-deterministisch** und **subskalen-sensitiv**: das
Oracle liest die catscale des präsentierten Items aus der `scalemap` und nutzt
die zugehörige Subskalen-θ der Person.


### Unbeaufsichtigter Betrieb

Statt jeden Schritt manuell anzustoßen, kann der geplante Task `pipeline_tick`
aktiviert werden (unter *Website-Administration → Server → Geplante Tasks*,
standardmäßig deaktiviert). Er gibt hängengebliebene Attempts wieder frei
(Reclaim mit Backoff) und dispatcht — bei aktiviertem Exec-Worker — den Worker-Pool
in der über `worker_concurrency` konfigurierten Breite. Der Login-Modus des Workers
(`worker_login_mode`: Passwort-Konvention oder vorauthentifizierte URL-Vorlage) wird
mit durchgereicht.

## 4. Sammeln und Auswerten

- `collect_attempts` überführt die Engine-Traces (inkl. `debug_info` →
  Subskalen-θ + Exposition) in den Lab-Store.
- `aggregate_results` rechnet in einem Durchgang die **globalen**, die
  **Stratum-** und die **DPF-Ergebnisse** und persistiert sie als `result`-Zeilen
  (`run` / `stratum:*` / `dpf` scope).

Die Reports liegen unter `report.php?runid=N` (Metriktabelle je Scope +
Balkendiagramm) bzw. `report.php?experimentid=N` (Stabilitätstabelle +
Liniendiagramm über die Runs); Links stehen auf der Verwaltungsseite.

## 5. Exportieren

Antwortmatrix und weitere Datensätze lassen sich in mehreren Formaten ablegen:

```php
use local_catquizlab\local\run_exporter;

// Antwortmatrix eines Runs (Personen × Items) als CSV + JSON:
run_exporter::export_to_files($runid, ['csv', 'json']);

// Ebene × Umfang wählen (raw / groundtruth / metrics) × (run / experiment / tier):
run_exporter::export_dataset('metrics', 'experiment', $experimentid, ['csv', 'xlsx']);
```

Off-request über `\local_catquizlab\task\export_run::queue($runid, $formats)`.
Die Dateien liegen im System-Kontext (Dateibereich `export`) und werden über
`local_catquizlab_pluginfile` ausgeliefert (Fähigkeit `local/catquizlab:view`).

## 6. Optional: Hub-Modus (instanzübergreifend)

Auf einem Node mit konfigurierter Hub-Verbindung (Einstellungen *Hub-Verbindung*:
URL + Token):

```php
\local_catquizlab\local\transfer_package::submit_to_hub($runid);
```

Der Hub verifiziert die Integrität (SHA-256), nimmt den Run auf und rechnet
Metriken + DPF mit **identischer** Bibliothek nach — der eingebaute
Konsistenz-Check (Hub-Ergebnis = Node-Ergebnis).

## 7. Aufräumen

`\local_catquizlab\local\run_cleanup::cleanup($runid, $options)` entfernt
idempotent Attempts/Results/Personen, **die Engine-Artefakte des Laufs**
(Test-Modul, Items/Itemparams, catscales-Baum, catcontext) und die scalemap-
Zeilen (und optional die angelegten Nutzer sowie den vom Lauf erzeugten Kurs) und
setzt den Run zurück auf `DRAFT`.

Die Zeit-Straffunktion PF(t) ist standardmäßig aktiv; für einen Baseline-/
operativen Lauf lässt sie sich über `test_provisioner`-Option `timepenalty => false`
abschalten.

---

## Feinjustierung in der Ziel-Instanz

Bewusst engine-gekapselt und daher erst in der Instanz vollständig aktiv (bei
Abweichungen lokal nachziehen, ohne Testbarkeit/CI zu berühren):

- die adaptivequiz-Basisfelder bei `add_moduleinfo`
  (`highestlevel`/`lowestlevel`/`startinglevel`),
- die Fragenerzeugung (`qtype_multichoice`-`save_question`, Kategorie-Kontext,
  Bruchwerte),
- die Kontext-/Skalen-Inserts der Engine,
- der vollständige Oracle-Pfad.

Bei Abweichungen genügen meist Anpassungen in `test_provisioner::build_moduleinfo`,
`materialiser::create_question` bzw. `scale_provisioner`/`item_registrar`.


## Worker-E2E vorbereiten

Für einen vollständigen Worker-Durchlauf — echter Attempt durch die reale
`mod_adaptivequiz`-Oberfläche — bereitet ein eigenes CLI alles vor, was der
Worker braucht:

```bash
php local/catquizlab/cli/e2e_prepare.php --persons=1
```

Es legt ein kleines Experiment an, expandiert es zu einem Run, provisioniert
diesen, stellt die Attempts in die Warteschlange, aktiviert den
Worker-Webservice und gibt einen Token aus. Die Ausgabe ist zeilenweise
`schlüssel=wert` und damit direkt als GitHub-Actions-Output verwendbar:

```text
experimentid=12
runid=34
attempts=1
token=a1b2c3...
```

Anschließend spielt der Worker den Job:

```bash
cd local/catquizlab/worker
npm install --no-audit --no-fund
node run_attempt.js --base-url=http://127.0.0.1:8000 --token=<token> --max-jobs=1
```

Und die Gegenprobe:

```bash
php local/catquizlab/cli/e2e_prepare.php --verify --runid=34
```

Der Verify-Aufruf endet nur dann mit 0, wenn tatsächlich jeder eingereihte
Attempt abgeschlossen wurde — ein Worker, der nichts gespielt hat, gilt nicht
als Erfolg.

Ohne installierte Engine bricht die Vorbereitung mit Exit-Code 1 und einer
entsprechenden Meldung ab, statt einen unbrauchbaren Run zu erzeugen.

### Abgrenzung zum Toolchain-Test

Die CI trennt beides bewusst:

| Prüfung | Braucht Moodle | Braucht Netzwerk | Wann |
|---|---|---|---|
| `npm run check` | nein | nein | jeder Push |
| `npm test` | nein | nein | jeder Push |
| `npm run selftest` | nein | nein | jeder Push |
| `run_attempt.js` gegen eine Instanz | ja | ja | manuell, opt-in |

`npm run selftest` prüft Argumentverarbeitung, URL-Aufbau, Antwortauswahl und
dass Puppeteer lädt und einen Browser starten kann. Er ruft **keinen**
Webservice auf und claimt **keinen** Job. Mit `--no-browser` entfällt zusätzlich
der Browserstart, was auf Runnern ohne Chromium-Download nützlich ist.
