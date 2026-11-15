# Forschungsdatenpaket (#156)

Code: `classes/local/research_package.php` (Aufbau, Prognose, Versand), `classes/local/research_codebook.php`
(Datenwörterbuch EN/DE), `classes/task/build_research_package.php` (Erstellung im Hintergrund), Endpunkt
`results.php` (`action=researchpackage`, `action=researchpackagelater`), Anzeige im Export-Tab
(`results_page::render_research_package()`), Link auf der Experimentseite. Seit 0.7.61.

## Was drin ist

Ein ZIP je Experiment und Auswahl (der Filter der Ergebnisseite):

| Datei | Inhalt |
|---|---|
| `data/experiments.csv` | Experiment, Design-Hash, Hash der Definition |
| `data/runs.csv` | Läufe der Auswahl: Designkoordinaten, Seeds, RNG-Schema, Pool-Fingerprint, Zahl der Twins und Testbearbeitungen |
| `data/items.csv` | Jedes erzeugte Item: wahre und gespeicherte Parameter, wahre und zugewiesene Platzierung, `itemkey`, Fingerprints |
| `data/twins.csv` | Jeder Twin jedes Laufs, auch ohne Testbearbeitung; Abweichung (Stärke, Subskalen) |
| `data/truth_profiles.csv` | Wahres Fähigkeitsprofil im Langformat (global, Kategorie, Subskala) |
| `data/twin_item_responses.csv` | **Potenzielle** Antworten: jeder Twin × jedes Item seines Pools (nur RNG-Schema 2) |
| `data/attempts.csv` | Jede Testbearbeitung mit Trace, gültig, ungültig oder unbekannt |
| `data/validity.csv` | Die Gültigkeitsurteile getrennt (Regel, Statistik, Engine, Ende) |
| `data/analysis_population.csv` | Welche Testbearbeitungen die Ergebnisseite unter dem Filter zählt, und warum die anderen nicht |
| `data/steps.csv` | **Gestellte** Antworten Schritt für Schritt (Frage, Slot, Zeit, Zustand, Antworttext, Bewertung, Schätzung, SE, Ziehung) |
| `data/estimated_profiles.csv` | Geschätzte Fähigkeit je Subskala mit lokalem SE und Gültigkeit |
| `reproducibility.json` | Das Reproduzierbarkeitspaket: Definition, Manifeste, Seeds, effektive Parameter, Verträge, Versionen, Analysebasis |
| `codebook.json` | Tabellen mit Schlüssel und Verknüpfungen, jede Spalte mit Typ, Einheit, Bedeutung (EN/DE) |
| `manifest.json` | Schema und Version, Versionen, Auswahl und Filter, je Lauf RNG- und Item-Schema, je Tabelle Datei, Zeilen, Bytes, Spalten, sha256; zuletzt geschrieben |
| `README.md` | Lesehilfe EN/DE |

## Regeln

- **Format:** UTF-8, RFC 4180, Komma, Kopfzeile, Zeilenende `\n`. Eine leere Zelle ist ein fehlender Wert (NA), nie
  eine Null. `NaN`, `Inf`, `-Inf` stehen als solche da. Wahrheitswerte 0/1, Zeiten Unix-Sekunden (UTC), Listen mit
  `;`. Zahlen in der kürzesten Darstellung, die exakt zurückgelesen wird.
- **Vollständigkeit:** `attempts`, `steps`, `estimated_profiles`, `validity` enthalten jede Testbearbeitung mit Trace,
  unabhängig vom Gültigkeitsfilter. Welche gezählt werden, sagt `analysis_population` (`inpopulation`, `reason`). Twins
  ohne Testbearbeitung stehen in `twins`, nicht in `attempts`; `runs` nennt dazu Testbearbeitungen insgesamt, mit
  Trace und endgültig gescheitert. Wiederholte Ausführungen einer Testbearbeitung sind eine Zeile (`executions`).
- **Ohne Schätzung keine Null:** Eine Testbearbeitung ohne finale Fähigkeit hat leere `esttheta` und `error`. Sie geht
  nicht als Schätzung 0 in RMSE und Korrelation ein (seit 0.7.61 auch auf der Ergebnisseite, wenn „alle“ gewählt ist).
- **Potenziell vs. gestellt:** `twin_item_responses` wird mit `twin_response::draw()` berechnet, derselben Funktion,
  die in einer Testbearbeitung antwortet; unter Schema 2 ist jede gestellte Antwort in `steps` gleich der potenziellen
  desselben Twins für dasselbe Item. Die Tabelle ersetzt keine Interaktion und geht in keine Kennzahl ein. Läufe unter
  Schema 1 bekommen keine potenziellen Antworten (ihre damalige Ziehung wird nicht rekonstruiert).
- **Verknüpfungen:** `runid` → runs; `runid, personid` → twins; `runid, questionid` → items; `attemptid` → attempts;
  `replication, twinid, itemkey` paart denselben Twin und dasselbe Item über die Läufe einer Replikation.
- **Spätere Umbenennung (#152):** ändert `experiments.name`, nie `designhash` oder die Läufe.

## Größe und Hintergrund

Der Export-Tab zeigt vor dem Download je Tabelle die Zeilenzahl (gruppierte Zählabfragen, kein Lesen der
Testbearbeitungen): genau für alle Tabellen außer `steps` und `estimated_profiles`, die nach oben begrenzt werden
(Testbearbeitungen × Fragenbudget bzw. Pool, Testbearbeitungen × zugeordnete Subskalen) und als „höchstens“ dastehen.
Ob direkt gestreamt wird, entscheidet diese Obergrenze; ein Paket, das passen soll, passt also. Bis `research_package::DIRECT_ROWS` (1 000 000 Zeilen) wird direkt gestreamt (ZipStream, jede
Tabelle über einen temporären Strom, Speicher begrenzt). Darüber gibt es nur „Im Hintergrund erstellen“: eine
Ad-hoc-Aufgabe legt das ZIP im Dateibereich `export` ab; der Export-Tab listet wartende, laufende und fertige Pakete.
Ausgeliefert über `local_catquizlab_pluginfile` nur mit `local/catquizlab:export`.

TI@n je Schritt kommt nur hinein, wo seine Berechnung die weiche Grenze des Schrittexports nicht übersteigt
(`results_export::ENGINE_INFO_SOFT_LIMIT`); das Manifest sagt unter `tiatn`, ob.

## Rechte und Schutz

Download und Hintergrundauftrag nur per POST mit Sitzungsschlüssel und `local/catquizlab:export`. Die Daten sind
synthetisch: simulierte Twins, keine Moodle-Benutzer-IDs der Twins.

## Abfragen

Je Lauf eine feste Zahl gruppierter Abfragen, keine je Testbearbeitung, Schritt oder Twin
(`research_package_test::test_no_query_per_sitting`). Gefunden dabei: Der Schrittexport fragte je Testbearbeitung die
Wurzelskala ab (TI@n-Pfad); jetzt einmal je Lauf (`test_flow::$roots`).
