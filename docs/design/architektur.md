# Architektur der CAT-Experimenten-Suite (Rev. 2.1)

Stand: 09.08.2026 · Diese Fassung ist die maßgebliche Architekturbeschreibung des Plugins `local_catquizlab`; das zugehörige Backlog liegt in `backlog.md`. Eingearbeitete Vorgaben:
Vorbereitung **und** Auswertung vollständig in einem Local-Plugin · Vorbereitung über interne Routinen und Moodle-APIs · Auswertung wahlweise in derselben Instanz oder nachberechnet in einer zentralen Berechnungsinstanz · vollständiger Export (Excel, OpenDocument, CSV, XML, JSON) für optionale externe Berechnung · **keine** notwendigen externen Berechnungen · Testdurchführung **nicht** intern/in-process, sondern extern per Puppeteer, getriggert durch getimte Adhoc-Tasks

---

## 1. Revidiertes Zielbild

Die Suite ist **ein einziges Local-Plugin** (Arbeitstitel `local_catquizlab`), das den gesamten Lebenszyklus eines Experiments abdeckt: Definition → Provisionierung → Durchführung → Erfassung → Auswertung → Report → Export. Es gibt keine externe Analyse-Workbench mehr; der komplette Metrik-Katalog des experimentellen Designs (RMSE, Korrelation, Bias, Spearman-ρ, Precision@k/Recall, nDCG@k, Konfusionsmatrizen, SE-/Testlängen-Statistiken, Stratum-Aggregationen) wird in PHP innerhalb des Plugins gerechnet. Externe Werkzeuge sind eine reine Option, für die das Plugin saubere Exporte bereitstellt – nie eine Voraussetzung.

Die Testdurchführung ist bewusst **maximal implementierungsnah**: Simulierte Testversuche laufen als echte Browser-Sitzungen durch das reale Attempt-Interface von `mod_adaptivequiz`. Ein Puppeteer-Worker außerhalb von Moodle spielt die Attempts; ausgelöst und überwacht wird jeder Lauf durch getimte Adhoc-Tasks des Plugins. Damit entfällt die Driver-A/B/C-Unterscheidung aus Rev. 1 vollständig – es gibt genau einen Durchführungspfad (UI/Puppeteer), und die frühere Sorge, In-Process-Läufe könnten vom realen Systemverhalten abweichen, erledigt sich per Konstruktion.

Für die Auswertung gibt es zwei gleichwertige Betriebsmodi:

1. **Lokal:** Die erhebende Instanz rechnet die Metriken selbst (Adhoc-/Scheduled-Tasks, ergebnisweise persistiert).
2. **Zentral nachberechnet:** Die erhebende Instanz überträgt ihre Rohdatensätze an eine zentrale Berechnungsinstanz, die dieselbe Metrik-Bibliothek ausführt und Ergebnisse zurückliefert bzw. instanzübergreifend aggregiert. Als Vorbild dient das bereits in `local_catquiz` vorhandene Hub/Node-Muster (`classes/remote/client/response_submitter`, `external/hub/collect_responses`, `external/node/fetch_parameters` usw.): Die Suite übernimmt dieses Webservice-basierte Übertragungsmodell für ihre eigenen Datensätze, statt etwas Neues zu erfinden.

---

## 2. Architektur (Rev. 2.1)

```
┌────────────────────────────────────────────────────────────────────┐
│  Moodle-Instanz (erhebend)                                         │
│                                                                    │
│  local_catquizlab (ein Plugin)                                    │
│  ┌──────────────┐ ┌──────────────────┐ ┌────────────────────────┐  │
│  │ Experiment-  │ │ Provisionierung  │ │ Orchestrierung         │  │
│  │ Registry &   │→│ über Moodle-APIs │→│ getimte Adhoc-Tasks:   │  │
│  │ Sweep        │ │ (Nutzer, Kurse,  │ │ je Task = 1 Attempt-   │  │
│  │ (UI + CLI)   │ │ Fragen, Skalen,  │ │ Auftrag an Worker      │  │
│  └──────────────┘ │ Kontexte, Tests) │ └───────────┬────────────┘  │
│                   └──────────────────┘             │ WS-Auftrag    │
│  ┌──────────────┐ ┌──────────────────┐             │ + Oracle-WS   │
│  │ Auswertung   │ │ Trace-Erfassung  │             │               │
│  │ (PHP-Metrik- │←│ (Engine-Tabellen │◄────────────┼───────────┐   │
│  │ Bibliothek,  │ │ + Debug-Info →   │             │           │   │
│  │ Reports/     │ │ Lab-Store)       │             ▼           │   │
│  │ Charts)      │ └──────────────────┘   ┌──────────────────┐  │   │
│  └──────┬───────┘                        │ Puppeteer-Worker │──┘   │
│         │                                │ (Node, extern):  │      │
│  ┌──────▼───────┐                        │ Login als Simu-  │      │
│  │ Export       │                        │ lant, spielt     │      │
│  │ xlsx·ods·csv │                        │ Attempt durch    │      │
│  │ xml·json     │                        │ echtes UI        │      │
│  └──────┬───────┘                        └──────────────────┘      │
└─────────┼──────────────────────────────────────────────────────────┘
          │ Datensatz-Transfer (Hub/Node-Muster, Webservices)
          ▼
┌────────────────────────────────────────────────────────────────────┐
│  Zentrale Berechnungsinstanz (optional): gleiches Plugin, Rolle    │
│  „Hub" – Nachberechnung mit identischer Metrik-Bibliothek,         │
│  instanzübergreifende Aggregation, Ergebnis-Rückverteilung         │
└────────────────────────────────────────────────────────────────────┘
```

### 2.1 Provisionierung über Moodle-APIs (statt Direkt-SQL)

Die in `go-clara.php` verwendeten direkten `insert_record`-Ketten auf `question_usages` / `question_attempts` entfallen für die Durchführung komplett (das erledigt jetzt der echte UI-Durchlauf) und werden für die Vorbereitung durch offizielle Routinen ersetzt:

- **Simulanten:** `user_create_user()` / User-API, Kohorten-Zuordnung, Einschreibung über die Enrol-API; **jede Person ist ein eigener Nutzer** (siehe 2.6.B), Namensvergabe nach spezifizierbaren Regeln (siehe 2.6.D); Login-Fähigkeit für Puppeteer über generierte Zugangsdaten oder Webservice-Token.
- **Kurs & Aktivität:** Kurs- und Course-Module-APIs zum Anlegen (oder Referenzieren vorhandener) `adaptivequiz`-Instanzen je Sweep-Zelle; **welche Kurse und CAT-Tests ein Lauf nutzt, ist in der Experiment-/Run-Definition spezifizierbar** (siehe 2.6.C). Die CAT-Einstellungen (Strategie, Budgets, SE-Ziele) über die Formular-/Settings-Strukturen von `local_catquiz` (`local_catquiz_tests`), nicht per Hand-SQL.
- **Item-Pool:** Fragenerzeugung über die Question-Bank-API (generierte MC-Fragen als Träger), Item-Parameter und Skalenbaum über den vorhandenen CSV-Importer bzw. die Importer-Klassen von `local_catquiz`. **Unterschiedliche Item-Parametrisierungen werden als physisch verschiedene Fragen mit je eigenen Parametern realisiert, organisiert über Item-/Skalen (catscales) – nicht über CAT-Kontexte** (siehe 2.6.A). Fragen sind als **Templates mit Blanks** hinterlegbar und werden nach spezifizierbaren Regeln zu konkreten, systematisch benannten Items instanziiert.
- **Kurse & CAT-Tests:** je Testlauf spezifizierbar (vorhandene referenzieren oder per Kurs-/Course-Module-API neu anlegen); die zugehörigen Simulanten werden in die jeweiligen Kurse eingeschrieben, bevor Attempts geplant werden (siehe 2.6.C).
- **Ground Truth:** eigene Lab-Tabellen per `db/install.xml` (Ablösung der ad-hoc erzeugten `local_catquiz_ppsimulation`), mit Profilstruktur je Skalenebene, Stratum, Seed, Run-ID.

### 2.2 Durchführung: Adhoc-Tasks + Puppeteer

Der Ablauf pro Testversuch:

1. Der Sweep-Manager erzeugt pro Replikation einen **getimten Adhoc-Task** (`\core\task\manager::queue_adhoc_task()` mit gesetzter `nextruntime`), Payload: Run-ID, Simulant, Quiz-Instanz, Seed. Die Zeitstaffelung verteilt die Last und erlaubt realistische Wiederholungs-Szenarien (Expositionskontrolle, PF(t), Test-Wiederholung mit Historie – dort sind zeitliche Abstände sogar inhaltlich notwendig).
2. Der Task übergibt den Auftrag an den Puppeteer-Worker – wahlweise per direktem Prozessstart (`exec` auf dem Applikationsserver) oder, robuster für getrennte Worker-Hosts, indem der Task den Auftrag in eine Job-Queue stellt, die der Worker über einen Webservice pollt. Beide Varianten bleiben tasks-getriggert; die Queue-Variante entkoppelt nur die Laufumgebung.
3. Puppeteer meldet sich als Simulant an, startet den Attempt und spielt ihn durch das echte Interface: Frage lesen (Frage-/Slot-ID aus dem DOM), Antwortentscheidung beim **Oracle-Webservice** des Plugins abholen (`local_catquizlab_oracle_answer(runid, questionid) → fraction/choice`), Antwort im UI setzen, absenden, bis die Engine stoppt. Das Oracle rechnet serverseitig modellkonform (Likelihood-Aufrufe der `catmodel_*`-Subplugins, seed-deterministisch) bzw. erzeugt für die DPF-Sensitivitätsbedingungen gezielt lokal deviante Muster (Stärke/Anzahl/Position parametrisiert). So bleibt die gesamte Simulationslogik im Plugin; der Worker ist ein dummer Ausführer.
4. Nach Abschluss meldet der Worker Status und Laufzeitmessung zurück; ein Folge-Task validiert den Attempt (vollständig? Stoppgrund plausibel?), zieht die Verlaufsdaten aus den Engine-Tabellen (`local_catquiz_attempts`, `_progress`, `_personparams`, Attempt-JSON/Debug-Info) in den Lab-Store und markiert die Replikation als erledigt. Fehlläufe werden über den Task-Retry-Mechanismus (faildelay) wiederholt.

Konsequenz für die Skalierungsplanung: Der UI-Pfad ist um Größenordnungen langsamer als ein In-Process-Lauf. Durchsatz entsteht über parallele Worker (mehrere Browser-Kontexte/Instanzen) und die Task-Staffelung; das Versuchsraster muss von Beginn an fraktioniert und nach dem Tiering priorisiert werden (siehe Backlog E1/E7). Eine grobe Kapazitätsrechnung (Attempts/Stunde je Worker) gehört zu den ersten Arbeitspaketen.

### 2.3 Auswertung im Plugin

Die Metrik-Bibliothek wird als PHP-Klassensatz im Plugin implementiert (eine Metrik pro Klasse, gemeinsames Interface `compute(traces, groundtruth, baseline): result`). Alle Maße des Designs sind in PHP problemlos umsetzbar; Rechenläufe erfolgen asynchron über Scheduled-/Adhoc-Tasks mit ergebnisweiser Persistierung, damit auch große Runs ohne Timeout durchlaufen. Darstellung im Plugin über `local_wunderbyte_table` (ohnehin Abhängigkeit der Engine) und die Moodle-Charts-API (θ̂-vs-θ, SE-Verläufe, Testlängenverteilungen, Testinformationskurven, Konfusionsmatrizen); Report-Seiten je Run, je Vergleich (Strategie × Pool-Variante × Stratum) und je Tier.

### 2.4 Zentrale Berechnungsinstanz

Gleiches Plugin, per Einstellung in der Rolle „Hub". Erhebende Instanzen („Nodes") übertragen abgeschlossene Runs (Traces, Ground Truth, Engine-Ergebnisse, Manifest) per Webservice an den Hub – analog zu `response_submitter`/`collect_responses` der Engine. Der Hub rechnet mit identischer Metrik-Bibliothek nach, aggregiert instanzübergreifend und stellt Ergebnisse zur Rückabholung bereit. Nutzen: Entlastung der erhebenden Instanz, Zusammenführung mehrerer Erhebungs-Setups, unabhängige Nachprüfbarkeit der lokalen Berechnung (Hub-Ergebnis muss lokalem Ergebnis gleichen – ein eingebauter Korrektheitstest).

### 2.5 Export

Ein Export-Modul auf Basis der Moodle-**Dataformat-API**, die xlsx, ods, csv und json bereits mitbringt; XML wird als eigener Writer ergänzt. Exportierbar auf jeder Ebene: Rohdaten (Antwortmatrix im getit-horst-Format, Item-Sequenzen, θ/SE-Verläufe, Score-Komponenten), Ground Truth, Baseline-Referenzen, Metrik-Ergebnisse, Aggregationen sowie das vollständige Run-Manifest (Seeds, Konfiguration, Engine-Version). Jeder Export ist selbstbeschreibend (Spaltenkatalog/Schema im Paket), sodass externe Berechnungen möglich, aber nie nötig sind.

### 2.6 Präzisierungen (Rev. 2.1) — Item-/Personen-Realisierung

Vier Festlegungen konkretisieren die Provisionierung. Sie sind normativ (MUSS) und Bestandteil des Lasten-/Pflichtenhefts.

**A. Item-Parametrisierung über echte Items/Questions und Item-Skalen — nicht über Kontexte.**
Unterschiedliche Item-Parametrisierungen (Ideal-Pool wie alle Mutationen: shifted, stretched, gappy, Kalibrierfehler, Taggingfehler, depleted, kombiniert) werden als **physisch verschiedene Fragen** in der Fragensammlung mit je eigenen Item-Parametern realisiert und über den **Item-/Skalenbaum (catscales)** organisiert. CAT-Kontexte (`local_catquiz`-catcontext) sind dafür **nicht geeignet** und werden **nicht als Variantenträger** verwendet: Ein catcontext modelliert einen Kalibrier-/Auswertungs-Scope *derselben* Items, nicht getrennte Pools; ihn als Varianten-Container zu missbrauchen würde Ground Truth, Tagging, Expositions- und Depletion-Effekte vermischen und die Fragen-Wiederverwendung über Varianten hinweg erzwingen. Ein Lauf arbeitet in einem einzigen Arbeits-Kontext; die Varianten unterscheiden sich durch ihre **Items und Skalen**. *Schema-Konsequenz:* In `local_catquizlab_pool` entfällt `contextid` zugunsten von `scaleid` (Wurzel-Item-Skala der Variante) und `questioncategoryid` (Fragen-Kategorie mit den erzeugten Item-Objekten).

**B. Personen als verschiedene Nutzer.**
Jede simulierte Person ist ein **eigener Moodle-Nutzer** (User-API), kein bloßer Datensatz. Die Ground Truth (hierarchisches θ-Profil) verbleibt im Lab-Store und ist über `local_catquizlab_person.moodleuserid` mit dem Nutzer verknüpft. Anlage, Kohortenbildung und Aufräumen laufen über offizielle APIs.

**C. Kurse und CAT-Tests je Testlauf spezifizierbar; Personen kursweise einschreiben.**
Die Experiment-/Run-Definition gibt an, **welche Kurse** und **welche adaptivequiz-Instanzen (CAT-Tests)** ein Lauf verwendet — entweder vorhandene referenzieren oder per API neu anlegen. Die zu einem Lauf gehörenden Simulanten werden vor der Attempt-Planung in die jeweiligen Kurse **eingeschrieben** (Enrol-API). Damit sind mehrere Kurse/Tests je Experiment und die saubere Zuordnung Person→Kurs→Test abgedeckt.

**D. Systematische Namensvergabe und Fragen-Templates.**
Personen- und Item/Question-Namen werden nach **spezifizierbaren Regeln** vergeben: Muster mit Platzhaltern (z. B. Stratum, Kategorie, Subskala, laufender Index), seed-stabil, kollisionsfrei und rekonstruierbar. Fragen sind als **Templates mit Blanks** hinterlegbar — ein Template (Fragetext und Optionen mit Platzhaltern samt Ziel-Parametern) wird nach Regeln instanziiert, um viele konkrete, systematisch benannte Items zu erzeugen. Die Namens- und Template-Regeln sind Teil des deklarativen Experimentformats (Backlog E1.1) und werden bei der Provisionierung (E2) angewandt.

---

## 3. Offene Punkte

1. **Trace-Tiefe der Engine:** Liefern Attempt-JSON/Debug-Info alle Selektions-Score-Komponenten (FI, PF, SF_process/scale/item) maschinenlesbar? Falls nein: minimaler, upstream-fähiger Trace-Hook in `local_catquiz` – einzige tolerierte Engine-Berührung.
2. **PF(t)-Schalter:** Deaktivierbarkeit von `lasttimeplayedpenalty` per Test-Setting prüfen (Design verlangt zunächst PF(t)=1).
3. **Polytome Antworten im UI:** Für GPCM/GRM muss das Oracle eine Antwortkategorie wählen und Puppeteer die passende UI-Option treffen – Fragetyp-Wahl bei der Pool-Generierung darauf abstimmen.
4. **SE-Schwellen auf Subskalen** („noch zu entscheiden" laut Design) vor der Sweep-Definition fixieren.
5. **Durchsatz:** UI-Durchführung ist der Flaschenhals; Kapazitätsmessung in M1 entscheidet über Fraktionierung des Rasters und Worker-Anzahl.
6. **Simulanten-Authentifizierung:** Passwort-Login vs. tokenbasierter Auto-Login für Puppeteer (Sicherheits- und Komfortabwägung, nur Testinstanzen).
