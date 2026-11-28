# Fragenfortschritt nach Identität (#180)

Stand: CatQuizLab 0.7.85 (2026112701). Code: `classes/local/question_progress.php`. Lesend prüfen mit
`cli/question_progress.php --runid=N [--json]`.

## Ausgangslage (CLARA, Run 121, 27.11.2026)

Die Seite zeigte 1.162 Antworten als „in Ausführungen, die nicht zählen“. Es waren die Antworten zweier Bearbeitungen
(587 und 575 Antworten), die nach einem Verbindungsfehler wieder in die Warteschlange kamen. Ihre Bindung und ihr
Engine-Versuch blieben bestehen; eine Wiederholung hätte im selben Versuch weitergemacht.

Bis 0.7.84 wurde so gezählt:
- **Laufende Bearbeitungen:** über den neuesten Engine-Versuch der Person (`MAX(id)`), nicht über die Bindung.
- **„Discarded“:** alles, was von der Gesamtsumme übrig blieb. Wartende und angehaltene Bearbeitungen fielen dadurch
  aus dem Fortschritt heraus.

## Regel

Jeder Engine-Versuch der Personen des Runs in dessen Test wird genau einmal gezählt. Entscheidend ist, was er für das
Labor ist, nicht seine ID oder sein Alter.

| Eimer | Was | Im Fortschritt (`done`) |
|---|---|---|
| collected | gebunden; Bearbeitung eingesammelt, validiert oder wartet auf die Trace | ja |
| technical | gebunden; eingesammelt, aber laut Abschlussvertrag technisch beendet oder unvollständig (#179) | ja |
| running | gebunden; Bearbeitung läuft | ja |
| queued | gebunden; Bearbeitung wartet auf eine Wiederholung, die Antworten bleiben erhalten | ja |
| held | gebunden; fehlgeschlagen oder abgebrochen, Engine-Versuch offen (fortsetzbar nach Entscheidung) | ja |
| terminal | gebunden; fehlgeschlagen oder abgebrochen, Engine-Versuch geschlossen | ja |
| discarded | an keine Bearbeitung gebunden; durch eine Recovery-Entscheidung ausgemustert (`local_catquizlab_retired`) | nein |
| unknown | an keine Bearbeitung gebunden, nicht ausgemustert (Altbestand ohne Bindung, Start noch nicht gebunden, zweiter Versuch ohne Entscheidung) | nein |
| foreign | gebunden, aber nicht an einen Versuch der Person im Test des Runs | nein, Diagnose |
| missing | gebunden, aber der Versuch existiert nicht mehr | nein, Diagnose |

**Was gilt außerdem:**
- **Keine automatische Zuordnung:** `discarded` nur mit Entscheidung als Beleg. Es wird nichts geraten, kein
  `MAX(id)`, keine Auswahl nach Datum.
- **Mehrdeutigkeit wird gezählt:** Personen mit mehreren Versuchen, von denen keiner gebunden ist (`ambiguous`).
- **Validität ist getrennt:** Aus dem Fortschritt folgt keine fachliche Gültigkeit; die regelt der Abschlussvertrag
  (#179).

## Nenner, Restarbeit, nicht anstehend

**Nenner (`expected`):**
- **Feste Form:** Pool × Bearbeitungen. Beispiel: 50 × 2500 = 125.000. Der Nenner schrumpft nicht, wenn etwas
  scheitert.
- **Adaptiv:** Geschätzt aus regulär eingesammelten Bearbeitungen. Technisch beendete oder unvollständige zählen nicht
  als Länge einer Bearbeitung. Vor drei solchen gilt das Maximum als Obergrenze. Die Art steht als `kind` dabei.

**Restarbeit (`remaining`):** die Arbeit, die vor den noch zu spielenden Bearbeitungen liegt.
- running und queued: jeweils abzüglich der behaltenen Antworten;
- noch ungebunden: die ganze Länge.

**Nicht anstehend (`notahead`, nur bei fester Form):** was angehaltene, beendete und technisch beendete Bearbeitungen
ohne Entscheidung nicht mehr beantworten werden. Es gilt immer:
`done + remaining + notahead = expected`.

## Invarianten und Diagnose

Ein SQL-Statement liefert alle Eimer zugleich. Unter PostgreSQL ist das ein gemeinsamer Snapshot. Dazu kommt die
Summe aller Versuche der Personen im Test (`hostall`).

| Diagnose | Bedeutung |
|---|---|
| `doublebound` | ein Versuch ist an zwei Bearbeitungen gebunden: `hostall` < gezählt |
| `unaccounted` | `hostall` > gezählt |
| `overcount` | mehr Antworten als die Form hat |
| `foreign`, `missing`, `collectednohost` | falsche oder fehlende Bindung |

Die Diagnosen stehen auf der Fortschrittsseite („Geht nicht auf: …“) und im CLI. Negative Differenzen werden nicht mit
`max(0)` verdeckt.

**Abfragebudget:** eine feste Zahl Abfragen je Aufruf, unabhängig von der Zahl der Runs und Bearbeitungen
(`test_the_query_count_does_not_grow_with_runs`, höchstens 6).

## ETA

Eine wartende Bearbeitung, die ihren Engine-Versuch behält, macht dort weiter. Ihre behaltenen Antworten verringern die
Warteschlangenarbeit (`eta::queued_credit()`, in Einheiten von Bearbeitungen). Das gilt für die Restzeit des Runs, für
die eigene Arbeit und für das, was vor ihm liegt.

Die Laufzeitstichproben, der Run-Balken und die Anzeige abgeschlossener Runs gehören zu #181. Sie sind hier nicht
geändert.

## Nachweise

**PHPUnit:** `question_progress_test`
- 300 → wartend → angehalten → läuft, 340: `done` bleibt, nichts discarded;
- neue Generation per `sitting_recovery`: 300 discarded, 40 zählen;
- neuerer ungebundener Versuch: unknown, nicht die Bearbeitung;
- ungebunden mit zwei Versuchen: ambiguous;
- falsche und fehlende Bindung: Diagnose;
- feste Form 50 × 2500 mit allen Zuständen: 125.000, Summe geht auf.

**RED gegen 0.7.84:** derselbe Ablauf ergibt `done` 0 und `discarded` 300 bei wartend und angehalten. Beim neueren
ungebundenen Versuch ergibt er `done` 40 und `discarded` 300 (`nachweise/180-red-0784.txt`).
