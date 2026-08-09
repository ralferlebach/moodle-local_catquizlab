# Backlog der CAT-Experimenten-Suite (`local_catquizlab`)

Stand: 09.08.2026 · Architektur siehe `architektur.md` · Status-Legende: ✅ erledigt (Stub 0.1.0) · ⏳ teilweise · ohne Marker = offen

## Stub-Abdeckung (Release 0.1.0)

Der ausgelieferte Stub deckt aus E0 bereits ab: Plugin-Gerüst mit Settings
(Hauptschalter, Node/Hub-Rolle, Umgebungsstatus), Capabilities, erste
Lab-Store-Tabelle `local_catquizlab_experiment` inkl. Generator und Tests,
Worker-Grundgerüst (Argument-Validierung), CI-Pipeline und Makefile. Alles
Weitere ist offen.

## Epics

Gegenüber Rev. 1 entfallen: externe Analyse-Workbench (Python/R), Driver A (In-Process) und Driver B (DB-getrieben) samt go-clara-Refactoring zum Runner. `go-clara.php` bleibt nur noch als Referenz für die Oracle-Likelihood-Anbindung relevant; `getit-horst.php` geht als ein Exportformat im Export-Modul auf. Neu bzw. aufgewertet: Provisionierung strikt über Moodle-APIs, Task-/Worker-Orchestrierung, PHP-Metrik-Bibliothek, Hub-Modus, Dataformat-Exporte.

### E0 – Plugin-Fundament
| # | Arbeitspaket | Hinweise |
| --- | --- | --- |
| 0.1 ✅ | Plugin-Gerüst `local_catquizlab`: version.php, Abhängigkeiten (local_catquiz, mod_adaptivequiz, local_wunderbyte_table), Capabilities, Settings | Hub/Node-Rollenschalter in den Settings |
| 0.2 ⏳ teilweise | Datenmodell in `db/install.xml`: experiment, run, cohort/person (Ground-Truth-Profile), pool_variant, attempt_trace, result, export_log, transfer_log | Ablösung der ad-hoc-Tabelle `local_catquiz_ppsimulation` |
| 0.3 | Webservices in `db/services.php`: Oracle-Answer, Job-Queue (claim/complete), Hub-Transfer (submit_run, fetch_results) | Token-/Capability-Konzept für Worker und Nodes |
| 0.4 | Run-Manifest: Engine-Git-Hash, Plugin-Versionen, Konfiguration, Seeds, Umgebung | Reproduzierbarkeits-Grundlage für den Artikel |

### E1 – Experiment-Definition & Sweep
| # | Arbeitspaket | Hinweise |
| --- | --- | --- |
| 1.1 | Deklaratives Experimentformat (JSON) + Validierung: Modell, Pool-Variante, Stratum, Strategie, Budgets/SE-Ziele, R, Seeds, Timing-Profil der Tasks | Timing-Profil neu wegen getimter Adhoc-Tasks |
| 1.2 | Sweep-Expansion mit Ausschlussregeln und fraktioniertem Design; Tier-Zuordnung (Baseline/Haupt/Robustheit/operativ) | Kapazitätsschätzung je Zelle (Attempts × erwartete Dauer) einblenden |
| 1.3 | Registry-UI (wunderbyte_table): Runs mit Status, Fortschritt, Fehlläufen, Task-Warteschlange; CLI-Pendant | |

### E2 – Provisionierung über Moodle-APIs
| # | Arbeitspaket | Hinweise |
| --- | --- | --- |
| 2.1 | Pool-Generator: Skalenbaum 10×10, 25 Items/Subskala, genestete Schwierigkeitsverteilungen (parametrisierbar), Fragenerzeugung via Question-Bank-API, Parameter/Skalen via Engine-Importer, eigener CAT-Kontext | seed-deterministisch |
| 2.2 | Pool-Mutator: shifted/stretched/kombiniert, gappy, Kalibrierungsfehler 5–20 %, Taggingfehler 5–20 %, Depleted −25/50/75 % – je als abgeleiteter Kontext mit protokolliertem Mutationsrezept | |
| 2.3 | Personen-Generator: 4 Strata, hierarchische Profile (θ_global/Kategorie/Subskala) orientiert an der Testinformation des Ideal-Pools; Anlage als echte Nutzer (User-/Enrol-API), Kohortenverwaltung, Zugangsdaten fürs Worker-Login | Ground Truth in Lab-Store |
| 2.4 | Test-Setup-Automat: adaptivequiz-Instanzen + `local_catquiz_tests`-Settings je Sweep-Zelle über Course-Module- und Engine-Routinen | ersetzt manuelle Formularpflege |
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

