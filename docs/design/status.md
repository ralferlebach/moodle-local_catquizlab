> **Stand 0.4.2:** Die Suite ist von der Experimentdefinition bis zum Export einmal vollständig an echten Daten belegt. Eine simulierte Person absolviert einen adaptiven Test durch die reale `mod_adaptivequiz`-Oberfläche, der Trace wird eingesammelt, global und lokal ausgewertet und exportiert. Die Abschlussstudie umfasst 90 Attempts über drei Poolvarianten × fünf Replikationen × sechs Personen; alle acht Reiter der Ergebnisoberfläche rendern darauf ohne Ausnahme. Studienparameter sind festgelegt: Trennschärfe `Beta(3,4)` auf (0, 5] mit Modus 2, Rateparameter `Beta(2,2)` auf (0; 0,5) mit Modus 0,25. Verifiziert mit PHPUnit (423 Tests, 2752 Assertions), Behat (27 Szenarien), Worker-Tests, phpcs, PHPDoc und einer Ladeprüfung unter PHPUnit 11.5.
>
> **Verbleibend sind drei Punkte, keiner davon blockierend.** Beide gemeldeten Engine-Defekte sind in `local_catquiz` 2026090204 behoben — catquiz#59 (`get_ability_range` deklariert jetzt `int`) und catquiz#62 (`lastquestion` wird abgesichert gelesen); `tests/engine_defects_test.php` hält die Reparaturen jetzt fest statt der Defekte. Offen ist catquiz#64: Ein Abbruch nach der ersten Frage läuft nicht über den Fehlerpfad der Engine (`catquizerror = false`), sodass die dafür eingebaute Stufenzählung `catquizstagecounts` bei genau diesem Fall nicht gesetzt wird. Zweitens braucht es für publikationsreife Aussagen Replikationszahlen deutlich über fünf — bei 30 Attempts je Zelle überlappen die Konfidenzintervalle der Poolvarianten noch fast vollständig.

# Projektstatus local_catquizlab

Stand: Release 0.1.21 (Version 2026081020). Diese Übersicht fasst zusammen, was
erledigt ist und was noch offen ist. Details je Arbeitspaket im `backlog.md`.

## Kurzfassung

Die gesamte **core-taugliche Kette** steht als reine, getestete Bausteine bereit
und läuft ohne Engine in der CI grün:

Definition/Sweep/Registry → Ground Truth (Personen-θ + Item-Pool inkl. Varianten)
→ Provisionierung (echte Nutzer, Kurs, Einschreibung, geplante Attempts) →
Antwortmodell (Oracle) → Auswertung (Metriken + Diagnostik, je Run und je Stratum)
→ Export (CSV/JSON/XML). Offen sind vor allem die **engine-/worker-abhängigen**
Stücke (Materialisierung der Blaupausen zu echten Fragen, adaptivequiz-Instanz je
Lauf, Worker-Durchführung, Trace-Collect) sowie der Hub-Modus, die Report-UI und
die eigentliche Experiment-Durchführung.

## Erledigt

- **E0 – Fundament (komplett):** Plugin-Gerüst, Datenmodell (8 Tabellen),
  Webservice-Stubs, Run-Manifest.
- **E1 – Definition & Sweep (komplett):** deklaratives JSON-Format + Validierung,
  Sweep-Expansion mit Ausschluss/Fraktionierung/Tiering, Registry-UI
  (`index.php`, Mustache, ausklappbar) + CLI.
- **E2 – Provisionierung (Core-Teile):**
  - 2.0 Naming-Engine
  - 2.1 Pool-Blaupause (Ideal-Pool 10×10×25, genestete Schwierigkeiten) — *Teil 1*
  - 2.2 Pool-Mutator (alle Varianten, Blaupause-Ebene) — *komplett*
  - 2.3 Personen-Generator + Nutzer-Provisionierung + Privacy-Provider — *komplett*
  - 2.4 Kurs-Provisionierung + Einschreibung — *Core-Hälfte*
  - 2.5 Reset-/Aufräum-Routinen (`run_cleanup`) — *komplett*
- **E3 – Orchestrierung (Core-Teile):**
  - 3.1 Attempt-Scheduler + Adhoc-Task (Warteschlange) — *Teil 1*
  - 3.4 Response-Oracle (IRT 1PL/2PL/3PL, θ-Auflösung, deterministisch) — *Rechenkern*
- **E4 – Auswertung (komplett bis auf Verlaufs-/Report-Teile):**
  - 4.1 Basismetriken (Bias/RMSE/MAE/Korrelation, Testlänge, SE, Exposure), je Stratum ✅
  - 4.2 Diagnostik/Ranking (Spearman, Top-k, nDCG@k, Konfusionsmatrix, 1·SE/2·SE, Precision@k) ✅
  - 4.4 Asynchrone Aggregation (Task + ergebnisweise Persistierung, run/stratum) ✅
  - Ergebnis-Aggregator (Traces → gespeicherte `result`-Zeilen)
- **E6 – Export (Kernformate):** CSV/JSON/XML-Serialisierer — *Teil 1*
- **Infrastruktur:** volle `make check`-Suite (CI-Spiegel), grüne CI über
  PHP 8.1–8.3 und Moodle 4.05/5.00/5.02 (PHPUnit + Behat), moodle-cs 3.7.

## Offen

- **E2.4 (Rest):** **adaptivequiz-Instanz** je Lauf + `local_catquiz_tests`-Settings
  (füllt `run.testcmid`; engine-/host-abhängig).
- **E3.1 (Rest):** Per-Attempt-Staffelung (nextruntime), Retry/faildelay, Abbruch.
- **E3.4 (Rest):** polytome Antwortauswahl (GPCM/GRM); Verdrahtung des Oracle-WS,
  sobald Frage→Item-Parameter auflösbar sind.
- **E5:** Hub-Modus (Transferpaket, Endpunkte, instanzübergreifende Aggregation).
- **E6 (Rest):** xlsx/ods über Dataformat-/Workbook-API; Ebenen-/Umfangsauswahl +
  Datenaufsammlung; Antwortmatrix-Export; Export-Task für große Datensätze.

## Trennlinie CI-tauglich vs. engine-abhängig

Alles bisher Umgesetzte nutzt ausschließlich Core-APIs bzw. reine Logik und ist
mit synthetischen Daten testbar. Die verbleibenden Kernstücke berühren die
Engine (`local_catquiz`), die Host-Aktivität (`mod_adaptivequiz`) oder den
externen Puppeteer-Worker. Diese werden so gekapselt, dass sie bei fehlender
Engine sauber abschalten und die CI grün bleibt.
