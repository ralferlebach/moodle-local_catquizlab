> **Stand 0.2.9:** Die zehn GitHub-Issues (#1–#10) sowie die elf Teilbefunde der Restliste sind abgearbeitet. Die Experimentdefinition steuert den Run vollständig, jeder Run führt seine eigene Sweep-Zelle aus, die Engine-Grenze ist gekapselt und wird über den realen Abrufpfad verifiziert, Experimente liegen in einem gemeinsamen konfigurierten Kurs mit einer Section je Experiment, die Ergebnisoberfläche deckt acht Reiter auf einer gemeinsamen Datengrundlage ab, und die Outcome-Pipeline persistiert alle im Artikel geforderten globalen und lokalen Kennwerte inklusive Multi-k. Getrennte Referenzsysteme verhindern Ground-Truth-Leakage; Replikationsstreuung wird ausschließlich innerhalb einer Zelle berechnet. Verifiziert mit PHPUnit (390 Tests), Behat (27 Szenarien inkl. Accessibility), phpcs, PHPDoc und einer Ladeprüfung unter PHPUnit 11.5; die CI ist über die Matrix 4.5/5.0/5.2 grün.
>
> **Verbleibend ist im Kern eines:** der erste echte Durchlauf gegen eine Instanz mit installierter CAT-Engine. Alle engine-nahen Pfade sind bislang nur als Guard-Pfad getestet, weil in der Entwicklungsumgebung keine Engine vorhanden ist. `cli/e2e_prepare.php` bereitet einen solchen Durchlauf vor, `cli/verify.php` prüft ihn gegen die reale Engine und benennt das erste gebrochene Kettenglied.

> **Stand 0.2.0 (Session 002 abgeschlossen):** Die sieben Issues #1–#7 sind abgearbeitet. Die Experimentdefinition ist jetzt die alleinige fachliche Quelle für Strategie, Budgets, SE-Grenzen, IRT-Modell samt Itemparametern und Poolvariante samt Recipe; der Mutator läuft im E2E-Pfad, Ground Truth und Engine-Sicht sind getrennt, die Personen folgen einem gepaarten Digital-Twin-Design mit getrennten Seed-Domänen, GPCM ist deklarativ lauffähig, und eine Web-UI deckt Definition, Validierung, Sweep-Vorschau, Run-Verwaltung, Vergleich und JSON-Austausch ab. Verifiziert mit PHPUnit (249 Tests), Behat (14 Szenarien inkl. Accessibility), phpcs und PHPDoc auf Moodle 4.5.13/PostgreSQL 16. Verbleibend ist Instanz-/Entscheidungsabhängiges: der erste echte Durchlauf mit installierter Engine, die konkreten Studienparameter für a-/c-Verteilungen und Severity-Skalen, die Testverlaufsansicht je Run und die Durchsatz-Messfahrt (M1).

> **Stand 0.1.50 (Session 001 abgeschlossen):** Der gesamte Backlog (E0–E7) ist umgesetzt, betriebsgehärtet (Retry/Reclaim/Abort, Teardown, Worker-Pool, geplanter `pipeline_tick`, Events, deviante Muster, PF(t)-Toggle) und CI-grün. Dokumentation auf as-built-Stand (Architektur Rev. 2.3, Durchführungs- und Testanleitung). Verbleibend ist nur noch Instanz-/Entscheidungsabhängiges: der erste echte Durchlauf mit Feinjustierung der vier engine-gekapselten Stellen, konkrete SE-Schwellen/Deviance-Muster und die Durchsatz-Messfahrt (M1).

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
