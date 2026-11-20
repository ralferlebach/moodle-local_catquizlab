# Abnahmemessungen: welcher Nachweis welches Issue belegt (0.7.71, nachgeschärft in 0.7.72)

Seit 0.7.71 gehört der Workflow **Acceptance measurements** (`.github/workflows/acceptance.yml`) zum Release-Gate
(`release-gate.md`). Er läuft bei jedem Push und Pull Request auf `main` und von Hand; jeder Job lädt hoch, was er
gemessen hat, und scheitert, wenn eine Bedingung verletzt ist. Nichts davon wird nach Augenschein beurteilt.

Dieses Dokument ordnet die acht offenen Issues der Schlussrunde (Re-Audit 18.11.2026) ihren Nachweisen zu und sagt
offen, was davon vor der Auslieferung **lokal** gelaufen ist und was erst die CI auf GitHub misst.

## Übersicht

| Issue | Nachweis | Wo | Artefakt |
|---|---|---|---|
| #110 | Lastmatrix CLASSIC, ALLSUBS, HIGHESTSUB, Kontrollmodus × 1/5/12/25/50 echte Worker, Apache + PHP-FPM mit `memory_limit=512M`; Fehlerstufe mit 12 Workern (verlorene Antwort, verlorene Anfrage, verspätete Antwort) | `acceptance.yml`, Job `load` | `catquizlab-acceptance-load-<modus>` (`matrix.jsonl`, Stichproben, Request-Bericht, Traces, Kapazität, Inventur) |
| #136 | zwei offene Versuche in einer Sitzung, zweiter Lerner, Cache-Purge, TTL 610 s, Itemparameter geändert; je Stack (4.5, 5.2, 5.3) und Engine-Satz (release gepinnt, candidate = Zweig-Heads ungepinnt) | `acceptance.yml`, Job `engine-session` | `catquizlab-acceptance-engine-session-<stack>-<satz>` |
| #136 AK 7 | offene Versuche über das Engine-Upgrade previous → release auf derselben Installation | Job `engine-migration` | `catquizlab-acceptance-engine-migration-<stack>` (`before.json`, `after.json`, `engine-upgrade.txt`) |
| #136 DoD 3 | Kosten einer Bearbeitung: 10/50/100/150 Fragen × Pools 100/500/1000, 512 MiB, previous gegen release | Job `engine-bench` | `catquizlab-acceptance-engine-bench-<stack>-<satz>` (`bench.jsonl`) |
| #137 | derselbe Browsertest wie #136 (Moodle 5.2/5.3, aktualisierte Pins, gleiche und verschiedene Sitzungen, Fortschritt, Stoppkriterium, Fragenverwendung, Trennung der Lerner) | Job `engine-session` | wie #136 |
| #144 | Moduswechsel-Plot mit zwei und mit genau einer gemeinsamen gültigen Subskala: Punktzahl = gültige (Twin, Subskala)-Paare, Vorzeichen, Nullachsen, 45°-Linie, Tooltip, Tastatur, SVG/PNG/PDF/CSV/JSON aus denselben Punkten; deutsche Texte | Interface-E2E (`mode_single_subscale.spec.js`), PHPUnit (`mode_plot_language_test`) | `catquizlab-ui-e2e-<stack>` |
| #149 | Bestands-Scan synthetischer Logs aller Formate, nur Zahlen, Kategorien, Stunden; Betreiberprozess oder Risikoverzicht | Job `scheduler` (pgsql, 11), PHPUnit `log_scan_test` | `catquizlab-acceptance-scheduler-pgsql-11` (`log-scan-149.json`); `log-datenschutz.md` |
| #157 | R-Reanalyse aller Kennzahlen, Plots und Filter aus den Rohtabellen; Verfälschungen von Bias, Schritt und Join werden rot | PHPUnit `research_package_test` (Release-CI, R 4.3.3) | Testlog; `r-reanalyse.md` |
| #163 | 11 und 25 echte Worker, PostgreSQL und MariaDB, fünf Experimente, Altbestand 1 200 fällige Bearbeitungen, Stopp/Fortsetzen, Circuit Breaker, 30 Minuten Polling auf künstlicher Uhr; Claim-Querybudget und Plan | Job `scheduler` | `catquizlab-acceptance-scheduler-<db>-<worker>` (`timeline.jsonl`, `summary.json`, `claim-bench.txt`, `inventory.json`) |
| #164 | 2 × 2-Matrix `attempttrace` × `debuglevel`, FASTEST und HIGHESTSUB, ABCD dann DCBA, kalt und warm; Tracetabelle mit 200 000 Zeilen, Lesefenster und Abfrageplan, Aufbewahrung, keine Geheimnisse | Job `trace` | `catquizlab-acceptance-trace-<cache>` (`report.md`, `trace-volume.json`) |
| Gate 3 | Release-Manifest aller Jobs aller vier Workflows; Skips geprüft | Job `manifest`; Release-CI „Skips checked“ | `release-manifest` |
| Abschnitt 3 | die eine lesende SQL-Inventur | `cli/acceptance_inventory.php` | `inventory.json` je Scheduler- und Lastjob |

## #163 im Einzelnen

`cli/scheduler_check.php` legt fünf Experimente an: O, ein Altbestand mit 1 200 seit einem Tag fälligen Bearbeitungen,
dessen Lauf der Circuit Breaker hält; A (zwei Läufe), B (zwei Läufe), S und C (vom Breaker gehalten). Gestartet in der
Reihenfolge A, B, S, C, gespielt von 11 bzw. 25 echten Workern. Während des Spiels: A wird gestoppt, während es spielt,
und nach 20 s fortgesetzt; C wird freigegeben, während B die Reihe hält; S wird gestoppt, während es spielt. Danach
30 Minuten Polling auf einer künstlichen Uhr (alle Zeitstempel je Schritt 30 s zurück, `take_turn`, jede Minute der
Pipeline-Tick): nichts darf sich ändern. Dann wird S fortgesetzt und zu Ende gespielt. Bei jeder Ablesung (alle 3 s):
höchstens ein Holder, jede Bearbeitung im Spiel gehört dem Holder, der Altbestand unberührt, gestoppt heißt aus der
Reihe. Am Ende: jede Bearbeitung von A, B, C und S gesammelt, keine zweimal gestartet, O nie gestartet und durch seinen
Stopp geschlossen, die Reihe leer, die Inventur ohne Widerspruch.

Lokal (Moodle 4.5, PostgreSQL 16, 11 Worker, Altbestand 60, Polling 10 Minuten): **PASS**. Der erste lokale Lauf deckte
zwei Fehler der Prüfung auf, nicht des Schedulers: Spuren, die nach dem Ende einer Bearbeitung ankommen
(`STATUS_TRACEPENDING`, #138), holt in der Produktion der Tick ab — die Prüfung rief ihn in den Spielphasen nicht auf und
las das spätere Abholen als Änderung während des Pollings. Seit 0.7.71 tut sie, was der Tick jede Minute tut, und wartet
auf diese Bearbeitungen. Die Läufe mit 25 Workern, auf MariaDB und mit 1 200 Altfällen laufen nur auf GitHub.

## #110 im Einzelnen

`cli/load_matrix.sh` spielt je Modus und Worker-Stufe ein Experiment mit echten Workern gegen Apache + PHP-FPM
(64 Prozesse, `memory_limit=512M`) und tastet alle 2 s ab (`cli/load_sampler.php`): CPU, Last, IO-Wartezeit,
RSS der PHP- und Browserprozesse, Apache busy/idle aus `mod_status`, Datenbankverbindungen und Wartende. Jede Anfrage
wird mit Status, Dauer, PHP-Spitze, RSS und Queries mitgeschrieben (`cli/request_report.php --raw`). Je Stufe eine Zeile
in `matrix.jsonl` mit echten Stichprobenumfängen, p50/p95/p99 der Anfragezeiten, 5xx/404/303, Fatals, Frage-Nr. und
Stoppcode der Bearbeitungen. Die Fehlerstufe (12 Worker) lässt in jeder Bearbeitung eine Antwort und eine Anfrage
verloren gehen und eine Antwort zu spät kommen; jede muss abgeglichen werden, nichts doppelt beantwortet. Der Job
scheitert bei einer nicht zu Ende gespielten Bearbeitung, einer 5xx-Antwort oder einem PHP-Fatal. Die Selektorzeit
(Span `strategy`, die ganze Strategie der Engine; seit 0.7.73 — vorher `strategy:select_question`, das in den neuen
Engines nur noch die letzte Wahl misst) liest `cli/trace_report.php` aus den Engine-Traces des Jobs, ohne den
Trockenlauf der Vorbereitung (dessen first_item zählt die Laufzeit des ganzen CLI-Prozesses, 33–64 s).

**Seit 0.7.72 (Re-Audit 19.11.2026): das Urteil je Stufe aus dem, was die Stufe hinterlässt**, nicht aus dem Exitcode
von `smoke.php` allein (`cli/load_step_verdict.py`, Selbsttest vor jeder Messreihe). `smoke.php --summary` schreibt je
Stufe: Bearbeitungen in der Warteschlange, an der Zeitgrenze gesammelt/gescheitert/offen, jede offene mit ihren Antworten
bis dahin und den Sekunden seit der letzten, nach der Grenze gesammelt/gescheitert/zurückgehalten/noch im Spiel, die
Gültigkeit jeder gesammelten Bearbeitung und das Urteil der Prüfungen. Daraus:

- **PASS** — alles in der Zeit gesammelt, mindestens eine gültig, kein 5xx, kein Fatal;
- **CAPACITY** — alles gesammelt und gültig, kein 5xx/Fatal, keine gescheitert, keine hängend (Antwort jünger als
  `STALL`, 300 s), keine zurückgehalten, keine danach noch im Spiel — nur einige erst nach der Grenze: die Maschine war
  voll, das Plugin hat nicht versagt. Wird mit Zahlen berichtet, die sichere Last je Modus steht in `safe-load.txt`. Bei 1
  oder 5 Workern ist das keine Last: dann FAIL;
- **CAPACITY als kontrollierte Überlast** (seit 0.7.73, CI d23b39c0) — der Lastschutz hat in der Stufe ausgelöst und
  Worker gebeten aufzuhören, jede gescheiterte Ausführung scheiterte an einer Überlastursache (Zeitüberschreitung,
  Verbindung; `circuit_breaker::CAPACITY_CAUSES`) und keine an einer anderen (Zustandsabweichung, Fehlerseite,
  Engine-Ausnahme, unbekannt), kein 5xx/Fatal, eine gültige, mehr als 5 Worker. Noch laufende und gescheiterte
  Bearbeitungen werden gezählt und genannt; waren nach Grenze und Wartezeit noch welche im Spiel, wird nichts verglichen
  (der Lauf bewegt sich) und das steht im Urteil. `smoke.php` schreibt dazu jede gescheiterte Ausführung (Ursache,
  Überlastursache ja/nein, Text, bis 200) und die Auslösungen des Lastschutzes in die Zusammenfassung;
- **FAIL** — alles andere, mit Gründen.

Ungültige Bearbeitungen werden gezählt und genannt (`validity` je Stufe); ein Fehler sind sie nur, wenn keine einzige
gültig ist. Der Job scheitert bei einem FAIL.

Befund auf ca851469 (0.7.71, Lauf 44453835263), aus den Artefakten korreliert:
- *control* (FAIL in allen Stufen, jede Bearbeitung gesammelt): Das Design der Stufe beendete jede Bearbeitung nach vier
  Fragen mit SE ≈ 1,02 über `se_max` 1,0 — jede ungültig, also „Results report no observations“. Ein Fehler des
  Testdesigns, nicht des Plugins; seit 0.7.72 `se_max` 1,5 (gültig nach Definition der Engine, weiter kurz).
- *allsubs@50* (FAIL nach 2 439 s, 50 gesammelt): 42 in den 40 Minuten, 8 noch beim Antworten, alle 8 innerhalb von 35 s
  nach der Grenze gesammelt, alle 50 gültig, kein 5xx/Fatal; CPU ab 12 Workern bei 100 %, p95 der Anfragen von 0,7 s
  (5 Worker) auf 23,5 s (50 Worker). Kapazität des 4-vCPU-Runners, kein Defekt — heute CAPACITY.
- *classic*, *highestsub*: alle Stufen PASS; je eine ungültige Bearbeitung bei classic@25/@50 (extremer Twin, SE 0,41
  nach 102 Items, `noremainingquestions`) — gezählt, kein Fehler.

Befund auf d23b39c0 (0.7.72, Lauf 44616989449): *allsubs@50* und *highestsub@50* FAIL mit Urteil „error“ —
derselbe Code und dieselbe Engine wie auf ca851469, aber ein langsamerer Runner ab der ersten Stufe (highestsub bei 1
Worker 60 s statt 47 s, mittlere Anfrage 181 statt 114 ms). Bei 50 Workern 99,5 % CPU, p95 31 s bzw. 41 s; die Browser
liefen beim Absenden in ihre 60 s (`submit_cdp_timeout`, 3 bzw. 10), der Lastschutz löste aus und bat die Worker über 25
aufzuhören; `smoke.php` brach an seiner Navigationsprüfung ab, bevor irgendetwas verglichen war. highestsub@50 hatte
dazu 9 `submit_state_mismatch` und 1 `unknown_failure` — nach der neuen Regel weiter FAIL. Die wahrscheinliche Ursache
der Abweichungen: eine aufgegebene Antwort lief auf dem Server noch (in der Warteschlange oder mit der Sitzung), als der
Worker fragte, „nicht gespeichert“ — und wurde kurz danach gespeichert; der Worker zählte eine Antwort zu wenig. Seit
0.7.73 fragt er nach dem Wiedereinstieg über die Aktivität (hinter der Sitzungssperre) noch einmal: eine mehr ist diese
späte Antwort, einmal gezählt (`storedlate`); alles andere beendet die Ausführung wie bisher.

Die Engine-Traces jeder Stufe liest `trace_report.php` jetzt, solange die Stufe noch existiert (`--keep`, danach
`cli/delete_experiment.php`); vorher löschte `smoke.php` sie mit dem Experiment, und die Selektorzeit kam aus fast keinem
Trace. Lokal (4.5, control mit 1 und 2 Workern): PASS, „every step passed up to 2 worker(s)“.

**Historischer Nachweisverzicht.** Die Original-Logs der HTTP-500-Vorfälle auf CLARA (Oktober 2026) liegen dem Entwickler
nicht vor. Ihre Ursache wird deshalb nicht nachträglich rekonstruiert. Belegt wird stattdessen der heutige Stand unter
Last (oben); ob er die alten Vorfälle ausschließt, sagt die Messung, nicht eine Vermutung über damals. Ursachen, die in
der Engine liegen, gehören als Issues in die Engine-Repositories (local_catquiz, mod_adaptivequiz) und werden dort
geführt; hier wird nur gemessen.

> Historischer Nachweisverzicht #110 (Betreiber): Die Server-Logs der HTTP-500-Vorfälle vor 0.7.56 stehen nicht mehr
> zur Verfügung bzw. werden nicht ausgewertet. Die Ursache dieser Vorfälle wird nicht rekonstruiert. Abgenommen wird
> der Stand von 0.7.71 anhand der Lastmatrix der CI. Datum, Name, Rolle.

## #136 / #137 im Einzelnen

- **Sitzung** (`engine_session.spec.js`, `cli/engine_session_seed.php`): zwei adaptive Tests A und B, über denselben
  Weg wie jeder Lauf bereitgestellt; Lerner 1 spielt A und B abwechselnd in **einer** Sitzung (zwei Tabs), Lerner 2 A
  in eigener Sitzung dazwischen. Mitten im Spiel `purge_caches.php`, dann 610 s Warten bei offenen Versuchen (über die
  600-s-Lebensdauer des Engine-Caches), dann eine Verschiebung der gespeicherten Schwierigkeit aller Items von A um
  +0,25 Logit (Neukalibrierung bei offenen Versuchen). Nach jedem Schritt vom Server gelesen: derselbe Versuch und
  dieselbe Fragenverwendung, jede Antwort gespeichert, keine zu viel, nichts verloren oder verändert, keine Frage
  doppelt, jede aus dem eigenen Pool; am Ende jeder Versuch mit der vorgegebenen Fragenzahl beendet, mit
  Stoppkriterium, und der Engine-Datensatz des Versuchs zählt dieselben Items.
- **Migration** (`engine_migration.spec.js`): mit dem vorigen Release-Satz (`engine-pins-previous.env`) zwei Versuche
  in einer Sitzung öffnen; den Satz an Ort und Stelle auf den Release-Satz heben (`upgrade.php`, Version muss steigen); mit derselben Sitzung
  weiter, „Fortsetzen“, nie „Neu beginnen“; dieselben Versuche, nichts verloren, beide beendet.
- **Kosten** (`cli/engine_bench.sh`): je Poolgröße und Länge eine Bearbeitung mit genau dieser Fragenzahl; je Zelle
  Anfragezeit (n, Mittel, 95-%-Intervall, p50/p95/p99), Queries je Anfrage, PHP-Spitze, RSS, HTTP-Fehler und der Verlauf
  vom ersten zum letzten Zehntel der Bearbeitung; previous gegen release auf derselben Art Maschine.

Lokal: Sitzungstest mit Purge und Itemänderung **PASS** (Moodle 4.5, ohne TTL-Wartezeit); die Migrationsphasen lokal
geprüft. Alle sechs Stack-/Satz-Kombinationen, die TTL-Wartezeit und die Kostenmatrix laufen nur auf GitHub.

**Seit 0.7.72 (Re-Audit 19.11.2026):**
- **AK 3** — Expositionskontrolle und Aktualität: `engine-aktualitaet.md` (Quellen, Alter, Wirkung; Item inaktiv und
  Parameter geändert bei offenen Versuchen: aus dem Pool sofort, nie mehr gefragt; Alter der Nutzungszahlen gemessen).
- **AK 5** — jede Ausgabe sagt dasselbe: `smoke.php` vergleicht in jedem Smoke-Lauf (Worker-CI, beide Engine-Sätze, alle
  Stacks; Lastmatrix) unter den Filtern „valid“ und „all“ die Analyse (was die Tabellen zeigen), den CSV- und den
  JSON-Export der Bearbeitungen und der Skalenergebnisse und die Plots der Übersicht und des Global-Tabs, wie sie
  gerendert werden: dieselbe gefilterte Stichprobe; je Bearbeitung Gültigkeitszustand und Urteile (einheitlich, Engine,
  statistisch), SE, TI@n, Ende der Engine (fertig, Endcode, Terminalcode), gebrochene Grenzen (darunter
  `max_questions_exceeded`), Zahl der Engine gegen ihren eigenen Datensatz; je Skalenergebnis die verabreichten Items
  gegen die Schritte (tatsächliche Skalenexposition); die Punkte der Plots gegen die Analyse. Jede Abweichung lässt den
  Lauf scheitern. Lokal mit FASTEST, ALLSUBS und CLASSIC: alles gleich.
- **DoD 2** — die Worker-CI auf ca851469 (Lauf 44453835100) ist vollständig grün, alle sieben Jobs inklusive Moodle 5.3
  mit beiden Engine-Sätzen; das Release-Manifest desselben Commits fasst 45 Jobs aus allen vier Workflows zusammen,
  nichts fehlt, kein Widerspruch.

## #164 im Einzelnen

`cli/trace_matrix.sh` spielt die vier Zellen (`attempttrace` 0/1 × `debuglevel` off/trace) mit FASTEST und HIGHESTSUB,
gleichem Seed, gleichem Worker-Limit, Reihenfolge ABCD dann DCBA, je einmal mit kaltem und warmem Cache (zwei Jobs).
Je Zelle: jede Anfrage gezählt (Serverzeit aus dem Request-Trace des Labors, unabhängig vom Engine-Tracing), die
Antwortanfragen mit Mittel, p50/p95/p99 und Bootstrap-95-%-Intervallen, 5xx, CDP-Timeouts, die Selektorzeit der Engine
(Span `strategy`; seit 0.7.73, siehe oben), geschriebene Traces und Bytes, das Debug-Log, CPU, Speicher,
Schreibvolumen.
`cli/trace_matrix_compare.py` rechnet die Effekte als Differenz von Median und Mittel zweier Zellen derselben Strategie
mit Bootstrap-Intervall (2 000 Ziehungen, fester Seed): Engine-Tracing an/aus, Labor-Debug an/aus, und den Anteil des
Selektors an der Anfrage. Ein Intervall, das 0 enthält, heißt „mit dieser Stichprobe kein Unterschied messbar“, nicht
Kosten; die Wartezeit außerhalb des Servers (CDP, Navigation) ist nicht darin, die Timeouts des Workers werden getrennt
gezählt. `cli/trace_volume_check.php` liest die echten Traces der Matrix auf Geheimnisse (Anzahl muss 0 sein, nie ein
Wert ausgegeben), legt 200 000 Tracezeilen über 30 Tage an, misst das Lesen eines Stundenfensters vorher und nachher
samt Abfrageplan (Index auf `timecreated`, kein Scan der ganzen Tabelle), lässt die Aufbewahrungs-Task der Engine mit
14 Tagen laufen (nichts Älteres darf bleiben) und räumt auf.

**Seit 0.7.72 fail-closed (Re-Audit 19.11.2026).** Auf ca851469 war der Job grün, obwohl die Auswertung jeder Zelle mit
`AttributeError: 'list' object has no attribute 'get'` abbrach (eine leere PHP-Map wurde als JSON-Liste geschrieben),
eine gescheiterte Zelle nur notiert wurde, der Vergleich fehlende Paare übersprang — und `smoke.php` das Experiment samt
Engine-Traces löschte, bevor der Bericht sie las (1–2 Selektor-Spans gegen 240 Antworten). Jetzt:

- `trace_report.php --json` schreibt jede Map als Objekt und je Lab-Bearbeitung die Traces nach Art und die Selektionen
  darin (`bysitting`), dazu jeden Trace ohne Lab-Bearbeitung (`unlinked`) mit der Angabe, ob sein Engine-Versuch noch
  existiert;
- jede Zelle wird mit `--keep` gespielt, gelesen, dann gelöscht;
- `cli/trace_matrix_check.py` prüft jede Zelle: Dateien da und lesbar, Smoke bestanden, kein 5xx/Fatal, Maps als Maps;
  Tracing aus → kein Trace; Tracing an → jede Antwortanfrage (attempt.php POST) einer Bearbeitung auf genau einen
  next_item-/complete-Trace, ihr erster Aufruf auf genau einen first_item-Trace; die Traces einer Bearbeitung der Reihe
  nach (`sequence`, seit 0.7.73): first_item und jeder next_item genau ein `strategy:select_question`-Span, außer dem
  letzten Trace, der die Bearbeitung beendet hat (die Release-Engine bis 0.7.72 nennt ihn next_item, er wählt nichts —
  CI d23b39c0: „21 item(s) chosen, 20 selector span(s)“), complete keiner, nie zwei; jeder Trace einer Bearbeitung zugeordnet außer dem Trockenlauf der Vorbereitung
  (`engine_dryrun`: first_item auf einem zurückgerollten Engine-Versuch); dann die ganze Matrix (jede Zelle jeder
  Strategie REPEAT-mal);
- `trace_matrix_compare.py` scheitert an fehlendem Paar, leerer Stichprobe, nicht lesbarer Datei, Liste statt Map;
- jeder Fehlerfall als Selbsttest (`--self-test` beider Skripte, 32 bzw. 4 Fälle), im Job vor der Matrix;
- der Job scheitert bei jedem Befund.

Lokal (4.5, FASTEST, 2 Worker, eine Wiederholung): alle vier Zellen sauber, je 40 Antworten auf 40 next_item-/
complete-Traces, 41 Selektor-Spans auf 41 Itemwahlen, 1 Trockenlauf-Trace; der Vergleich vollständig. Die volle Matrix
(FASTEST und HIGHESTSUB, 4 Worker × 3, ABCD/DCBA, kalt und warm) läuft auf GitHub.

## #157, #144, #149

Siehe `r-reanalyse.md`, `testen.md` und `log-datenschutz.md`. Alle drei sind vor der Auslieferung lokal gelaufen
(R-Reanalyse: fünf Versuchspläne, vier Filter, drei Verfälschungen; die #144-Specs auf Moodle 4.5; der Log-Scan).
