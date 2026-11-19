# Abnahmemessungen: welcher Nachweis welches Issue belegt (0.7.71)

Seit 0.7.71 gehört der Workflow **Acceptance measurements** (`.github/workflows/acceptance.yml`) zum Release-Gate
(`release-gate.md`). Er läuft bei jedem Push und Pull Request auf `main` und von Hand; jeder Job lädt hoch, was er
gemessen hat, und scheitert, wenn eine Bedingung verletzt ist. Nichts davon wird nach Augenschein beurteilt.

Dieses Dokument ordnet die acht offenen Issues der Schlussrunde (Re-Audit 18.11.2026) ihren Nachweisen zu und sagt
offen, was davon vor der Auslieferung **lokal** gelaufen ist und was erst die CI auf GitHub misst.

## Übersicht

| Issue | Nachweis | Wo | Artefakt |
|---|---|---|---|
| #110 | Lastmatrix CLASSIC, ALLSUBS, HIGHESTSUB, Kontrollmodus × 1/5/12/25/50 echte Worker, Apache + PHP-FPM mit `memory_limit=512M`; Fehlerstufe mit 12 Workern (verlorene Antwort, verlorene Anfrage, verspätete Antwort) | `acceptance.yml`, Job `load` | `catquizlab-acceptance-load-<modus>` (`matrix.jsonl`, Stichproben, Request-Bericht, Traces, Kapazität, Inventur) |
| #136 | zwei offene Versuche in einer Sitzung, zweiter Lerner, Cache-Purge, TTL 610 s, Itemparameter geändert; je Stack (4.5, 5.2, 5.3) und Engine-Satz (release, candidate) | `acceptance.yml`, Job `engine-session` | `catquizlab-acceptance-engine-session-<stack>-<satz>` |
| #136 AK 7 | offene Versuche über das Engine-Upgrade release → candidate auf derselben Installation | Job `engine-migration` | `catquizlab-acceptance-engine-migration-<stack>` (`before.json`, `after.json`, `engine-upgrade.txt`) |
| #136 DoD 3 | Kosten einer Bearbeitung: 10/50/100/150 Fragen × Pools 100/500/1000, 512 MiB, release gegen candidate | Job `engine-bench` | `catquizlab-acceptance-engine-bench-<stack>-<satz>` (`bench.jsonl`) |
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
(`strategy:select_question`) liest `cli/trace_report.php` aus den Engine-Traces des Jobs.

Lokal: Skripte und Auswertung auf diesem Rechner mit kleinen Stufen geprüft; die Messreihe selbst (bis 50 Worker) läuft
nur auf GitHub. Ergebnisse gibt es erst nach dem Push — dieses Dokument behauptet keine.

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
- **Migration** (`engine_migration.spec.js`): mit dem Release-Satz zwei Versuche in einer Sitzung öffnen; den Satz an
  Ort und Stelle auf den Kandidaten (CatQuiz #146) heben (`upgrade.php`, Version muss steigen); mit derselben Sitzung
  weiter, „Fortsetzen“, nie „Neu beginnen“; dieselben Versuche, nichts verloren, beide beendet.
- **Kosten** (`cli/engine_bench.sh`): je Poolgröße und Länge eine Bearbeitung mit genau dieser Fragenzahl; je Zelle
  Anfragezeit (n, Mittel, 95-%-Intervall, p50/p95/p99), Queries je Anfrage, PHP-Spitze, RSS, HTTP-Fehler und der Verlauf
  vom ersten zum letzten Zehntel der Bearbeitung; release gegen candidate auf derselben Art Maschine.

Lokal: Sitzungstest mit Purge und Itemänderung **PASS** (Moodle 4.5, ohne TTL-Wartezeit); die Migrationsphasen lokal
geprüft. Alle sechs Stack-/Satz-Kombinationen, die TTL-Wartezeit und die Kostenmatrix laufen nur auf GitHub.

## #164 im Einzelnen

`cli/trace_matrix.sh` spielt die vier Zellen (`attempttrace` 0/1 × `debuglevel` off/trace) mit FASTEST und HIGHESTSUB,
gleichem Seed, gleichem Worker-Limit, Reihenfolge ABCD dann DCBA, je einmal mit kaltem und warmem Cache (zwei Jobs).
Je Zelle: jede Anfrage gezählt (Serverzeit aus dem Request-Trace des Labors, unabhängig vom Engine-Tracing), die
Antwortanfragen mit Mittel, p50/p95/p99 und Bootstrap-95-%-Intervallen, 5xx, CDP-Timeouts, die Selektorzeit der Engine
(`strategy:select_question`), geschriebene Traces und Bytes, das Debug-Log, CPU, Speicher, Schreibvolumen.
`cli/trace_matrix_compare.py` rechnet die Effekte als Differenz von Median und Mittel zweier Zellen derselben Strategie
mit Bootstrap-Intervall (2 000 Ziehungen, fester Seed): Engine-Tracing an/aus, Labor-Debug an/aus, und den Anteil des
Selektors an der Anfrage. Ein Intervall, das 0 enthält, heißt „mit dieser Stichprobe kein Unterschied messbar“, nicht
Kosten; die Wartezeit außerhalb des Servers (CDP, Navigation) ist nicht darin, die Timeouts des Workers werden getrennt
gezählt. `cli/trace_volume_check.php` liest die echten Traces der Matrix auf Geheimnisse (Anzahl muss 0 sein, nie ein
Wert ausgegeben), legt 200 000 Tracezeilen über 30 Tage an, misst das Lesen eines Stundenfensters vorher und nachher
samt Abfrageplan (Index auf `timecreated`, kein Scan der ganzen Tabelle), lässt die Aufbewahrungs-Task der Engine mit
14 Tagen laufen (nichts Älteres darf bleiben) und räumt auf.

Lokal: `trace_volume_check.php` und der Vergleich (mit synthetischen Zellen) geprüft; die Matrix selbst läuft nur auf
GitHub.

## #157, #144, #149

Siehe `r-reanalyse.md`, `testen.md` und `log-datenschutz.md`. Alle drei sind vor der Auslieferung lokal gelaufen
(R-Reanalyse: fünf Versuchspläne, vier Filter, drei Verfälschungen; die #144-Specs auf Moodle 4.5; der Log-Scan).
