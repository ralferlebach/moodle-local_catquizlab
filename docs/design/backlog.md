# Backlog der CAT-Experimenten-Suite (`local_catquizlab`)

Stand: 09.08.2026 · Architektur siehe `architektur.md` · Status-Legende: ✅ erledigt (Stub 0.1.0) · ⏳ teilweise · ohne Marker = offen

## Stub-Abdeckung (Release 0.1.0)

**Epic E0 (Plugin-Fundament) ist mit Release 0.1.1 vollständig abgeschlossen.**
Der Stub deckt ab: Plugin-Gerüst mit Settings (Hauptschalter, Node/Hub-Rolle,
Umgebungsstatus), Capabilities (inkl. `worker` und `hubtransfer`), das
vollständige Lab-Store-Datenmodell (8 Tabellen) mit `db/upgrade.php`, die fünf
Webservices (Oracle-Answer, Job-Queue claim/complete, Hub submit_run/
fetch_results) samt zwei vordefinierten Services, den Run-Manifest-Builder
(`classes/local/manifest.php`), Generator und Tests dazu, Worker-Grundgerüst,
CI-Pipeline und Makefile. Alles Weitere (E1–E7) ist offen.

## Epics

Gegenüber Rev. 1 entfallen: externe Analyse-Workbench (Python/R), Driver A (In-Process) und Driver B (DB-getrieben) samt go-clara-Refactoring zum Runner. `go-clara.php` bleibt nur noch als Referenz für die Oracle-Likelihood-Anbindung relevant; `getit-horst.php` geht als ein Exportformat im Export-Modul auf. Neu bzw. aufgewertet: Provisionierung strikt über Moodle-APIs, Task-/Worker-Orchestrierung, PHP-Metrik-Bibliothek, Hub-Modus, Dataformat-Exporte.

### E0 – Plugin-Fundament
| # | Arbeitspaket | Hinweise |
| --- | --- | --- |
| 0.1 ✅ | Plugin-Gerüst `local_catquizlab`: version.php, Abhängigkeiten (local_catquiz, mod_adaptivequiz, local_wunderbyte_table), Capabilities, Settings | Hub/Node-Rollenschalter in den Settings |
| 0.2 ✅ | Datenmodell in `db/install.xml`: experiment, run, cohort/person (Ground-Truth-Profile), pool_variant, attempt_trace, result, export_log, transfer_log | Ablösung der ad-hoc-Tabelle `local_catquiz_ppsimulation` |
| 0.3 ✅ | Webservices in `db/services.php`: Oracle-Answer, Job-Queue (claim/complete), Hub-Transfer (submit_run, fetch_results) | Token-/Capability-Konzept für Worker und Nodes |
| 0.4 ✅ | Run-Manifest: Engine-Git-Hash, Plugin-Versionen, Konfiguration, Seeds, Umgebung | Reproduzierbarkeits-Grundlage für den Artikel |

### E1 – Experiment-Definition & Sweep
| # | Arbeitspaket | Hinweise |
| --- | --- | --- |
| 1.1 ✅ | Deklaratives Experimentformat (JSON) + Validierung: Modell, Pool-Variante, Stratum, Strategie, Budgets/SE-Ziele, R, Seeds, Timing-Profil der Tasks, **Kurs-/CAT-Test-Spezifikation** (referenzieren oder neu anlegen), **Namensregeln** (Personen, Items/Questions) und **Fragen-Templates** (Blanks + Zielparameter) | Timing-Profil neu wegen getimter Adhoc-Tasks; Kurse/Tests/Namen/Templates gem. Architektur 2.6 |
| 1.2 ✅ | Sweep-Expansion mit Ausschlussregeln und fraktioniertem Design; Tier-Zuordnung (Baseline/Haupt/Robustheit/operativ) | Kapazitätsschätzung je Zelle (Attempts × erwartete Dauer) einblenden |
| 1.3 ✅ | Registry-UI: Verwaltungs-/Bearbeitungsseite `index.php` (Navbar-Button neben CATQUIZ via `*_render_navbar_output` **und** Website-Administration › Berichte via `admin_externalpage`), Umgebungs-/Experimentliste, **Run-Tabelle mit Status und Run-Zahl je Experiment** (`classes/local/registry.php` persistiert Expansionen; Core-Tabelle statt wunderbyte_table, damit CI/Stand-alone ohne Engine grün bleibt — wunderbyte_table als spätere Aufwertung bei vorhandener Engine), **CLI-Pendant `cli/sweep.php`** (expandieren/persistieren/auflisten). Task-Warteschlangen-Spalte kommt mit E3 | erledigt |

### E2 – Provisionierung über Moodle-APIs
| # | Arbeitspaket | Hinweise |
| --- | --- | --- |
| 2.0 ⏳ | Vorarbeit: Naming-Engine `classes/local/naming.php` (Muster `{key}`/`{key:0Nd}`, `sequence()`) für Item- und Personennamen (Anforderung 2.6.D) | steht; wird von 2.1/2.3 genutzt |
| 2.1 ⏳ teilweise | Pool-Generator: Skalenbaum 10×10, 25 Items/Subskala, genestete Schwierigkeitsverteilungen (parametrisierbar), Fragenerzeugung via Question-Bank-API aus **Templates mit Blanks**, Parameter/Skalen via Engine-Importer. **Realisierung über echte Items + Item-Skalen, NICHT über CAT-Kontexte** (Architektur 2.6.A). **Systematische Item/Question-Namen** nach Regeln | seed-deterministisch; ein Arbeits-Kontext je Lauf. **Teil 1 erledigt:** `classes/local/pool_planner.php` erzeugt die Item-Blaupause (Skalenbaum, genestete Schwierigkeiten, Namen); offen: Materialisierung als Fragen via Question-Bank/Engine-Importer |
| 2.2 ✅ | Pool-Mutator: shifted/stretched/kombiniert, gappy, Kalibrierungsfehler 5–20 %, Taggingfehler 5–20 %, Depleted −25/50/75 % – je als **eigener Item-/Skalensatz (verschiedene Fragen)** mit protokolliertem Mutationsrezept, **nicht als abgeleiteter Kontext** (Architektur 2.6.A) | Depletion = Items entfernen, kein Kontext-Trick. **Erledigt (Blaupause-Ebene):** `classes/local/pool_mutator.php` — shifted/stretched/gappy/depleted/calibrationerror/taggingerror/combined, seed-deterministisch; Materialisierung als Fragen bleibt Engine-abhängig |
| 2.3 ⏳ teilweise | Personen-Generator: 4 Strata, hierarchische Profile (θ_global/Kategorie/Subskala) orientiert an der Testinformation des Ideal-Pools; **Anlage je Person als eigener Moodle-Nutzer** (User-API, Architektur 2.6.B), **Namensvergabe nach Regeln** (2.6.D), Kohortenverwaltung, Zugangsdaten fürs Worker-Login | Ground Truth in Lab-Store, verknüpft über person.moodleuserid. **Teil 1 erledigt:** `classes/local/person_generator.php` erzeugt seed-deterministische hierarchische θ-Profile + Namen und persistiert sie; offen: Anlage echter Nutzer (User-API), Kohorten, Einschreibung |
| 2.4 | Test-Setup-Automat: **spezifizierbare Kurse und adaptivequiz-Instanzen (CAT-Tests) je Lauf** – vorhandene referenzieren oder per API anlegen; `local_catquiz_tests`-Settings je Sweep-Zelle über Course-Module- und Engine-Routinen; **Einschreibung der Simulanten in die jeweiligen Kurse** (Enrol-API) vor der Attempt-Planung (Architektur 2.6.C) | ersetzt manuelle Formularpflege |
| 2.5 | Reset-/Aufräum-Routinen: Runs rückstandsfrei entfernen bzw. Instanz in definierten Ausgangszustand bringen | wichtig für PF(t)=1-Phase vs. spätere PF(t)-Phase |

### E3 – Orchestrierung & Puppeteer-Durchführung
| # | Arbeitspaket | Hinweise |
| --- | --- | --- |
| 3.1 | Adhoc-Task „schedule_attempt": Payload, nextruntime-Staffelung, Retry/faildelay, Abbruch-Handling | ein Task = ein Attempt-Auftrag |
| 3.2 | Worker-Anbindung, Variante exec (gleicher Host) und Variante Queue-Polling per WS (getrennter Worker-Host) | konfigurierbar; beide tasks-getriggert |
| 3.3 | Puppeteer-Skript: Login, Attempt-Start, DOM-Erkennung von Frage/Slot, Oracle-Abfrage, Antwort setzen, Submit-Loop bis Engine-Stopp; Screenshot-Option für Dokumentation | Worker enthält keine Simulationslogik |
| 3.4 | Oracle-Webservice: modellkonforme Antworten (1PL/2PL/3PL, später GPCM/GRM) über die `catmodel_*`-Likelihoods, seed-deterministisch je (run, person, item); deviante Muster (Stärke/Anzahl/Position) für DPF-Sensitivität | polytome Antwortauswahl klären (Kategorienwahl statt richtig/falsch) |
| 3.5 | Abschluss-Task „collect_attempt": Validierung, Übernahme der Verlaufsdaten aus Engine-Tabellen/Debug-Info in `attempt_trace`, Laufzeit-/Query-Messung | prüfen, ob Debug-Info alle Score-Komponenten liefert; sonst minimaler upstream-fähiger Trace-Hook in der Engine (offener Punkt) |
| 3.6 | Parallelisierung & Kapazität: mehrere Worker/Browser-Kontexte, Messlauf Attempts/Stunde, daraus Feinplanung des Rasters | früh durchführen (Meilenstein M1) |

### E4 – Auswertung im Plugin
| # | Arbeitspaket | Hinweise |
| --- | --- | --- |
| 4.1 | Metrik-Interface + Basissatz: Bias, RMSE, Korrelation, SE-Vergleich, Testlängen-Statistik, je Stratum/Tier | |
| 4.2 | Ranking-/Diagnostik-Maße: Defizit-Übereinstimmung (absolut, 1·SE/2·SE, Top-3/5/10), Precision@k, Recall, nDCG@k, Spearman-ρ, Konfusionsmatrix „detektiert vs. wahr" | |
| 4.3 | Verlaufs-/Stabilitätsanalysen: Skalen-An-/Abschaltung, Stoppgründe, intra-personale Vergleiche über R Replikationen, Robustheit (Kalibrier-/Taggingfehler, Depleted), Exposure-Profile | |
| 4.4 | Asynchrone Berechnungs-Tasks mit ergebnisweiser Persistierung; Ergebnis-Cache | keine Web-Timeouts |
| 4.5 | Report-UI: Run-Report, Vergleichsreport (Strategie × Pool × Stratum), Tier-Report; Charts via Moodle-Charts-API; strukturierter Trace-Report je auffälligem Attempt (Basis für spätere LLM-Zusammenfassung) | |

### E5 – Zentrale Berechnungsinstanz (Hub-Modus)
| # | Arbeitspaket | Hinweise |
| --- | --- | --- |
| 5.1 | Transferformat „Run-Paket" (Traces + Ground Truth + Baseline + Manifest) und Node-seitiger Submitter nach Vorbild `response_submitter` | |
| 5.2 | Hub-Endpunkte: Annahme, Integritätsprüfung (Hashes analog `remote/hash`), Nachberechnung mit identischer Metrik-Bibliothek, Ergebnisbereitstellung | |
| 5.3 | Instanzübergreifende Aggregation + Konsistenzprüfung Hub- vs. Node-Ergebnis | eingebauter Korrektheitstest der lokalen Berechnung |

### E6 – Export
| # | Arbeitspaket | Hinweise |
| --- | --- | --- |
| 6.1 | Export-Modul auf Dataformat-API: xlsx, ods, csv, json; eigener XML-Writer; Auswahl von Ebene (Rohdaten/Ground Truth/Metriken/Aggregation) und Umfang (Run/Experiment/Tier) | |
| 6.2 | Antwortmatrix-Export (Nachfolger getit-horst) als eine von mehreren Rohdaten-Sichten; Schema-/Spaltenkatalog je Export beilegen | |
| 6.3 | Export per Task für große Datensätze + Download-Ablage/Dateibereich | |

### E7 – Experiment-Durchführung nach Tiering (nutzt E0–E6)
| # | Arbeitspaket | Hinweise |
| --- | --- | --- |
| 7.1 | **Baseline-Tier:** Ideal-Pool, klassische Volltestung + CAT-Global, PF(t)=1; Gegenprüfung Ground Truth ↔ Baseline | Meilenstein M2 |
| 7.2 | **Haupt-Tier:** alle fünf Strategien, Strata 1–3, Testlängen-/SE-Raster, Skalen-Budgetierung, R=100–250 | |
| 7.3 | **Robustheits-Tier:** Alternativ-Pools, Stratum 4, problematische Item-Konstellationen 1–4 | |
| 7.4 | **Operatives Tier:** PF(t) aktiv, Test-Wiederholung mit/ohne Historie (Task-Timing inhaltlich nutzen), Konstellationen 5–7, Laufzeit-/Effizienzmessung | |

**Meilensteine:** M1 = ein vollständiger Puppeteer-Attempt läuft tasks-getriggert durch und landet validiert im Lab-Store inkl. Kapazitätsmessung (E0, E2.1/2.3/2.4, E3). M2 = Baseline-Tier ausgewertet und exportiert (E4.1, E6, E7.1). M3 = Haupt-Tier abgeschlossen. M4 = Hub-Nachberechnung verifiziert. M5 = Robustheits- und operatives Tier.

---

