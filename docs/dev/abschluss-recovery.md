# Abschlussvertrag, Execution-Identität und Recovery

Stand: CatQuizLab 0.7.85 (2026112701), Re-Audit 27.11.2026. Betrifft #172–#177 und das P0-Issue
„[ENGINE-LIFECYCLE]“.

## Execution-Identität und Fencing (#173)

Jeder Claim legt eine STARTED-Zeile in der Historie an. Ihre ID ist die Identität dieser Ausführung
(`local_catquizlab_attempt.executionid`, `local_catquizlab_attemptlog.executionid`). Sie ist eindeutig und wird nie
wiederverwendet. Das unterscheidet sie von `tries`: Ein Reset setzt `tries` zurück, auf CLARA Run 103 lautete die
Folge 2 → 3 → 2.

**Was die Identität trägt:**
- Der Worker bekommt sie mit dem Claim (`executionid`). Sie geht mit bind, heartbeat und report zurück
  (Worker-Contract 2) und steht im Request-Header `X-CatQuizLab-Attempt: sitting.try.executionid`.

**Wann ein Aufruf wirkt** (`lease_fence::check()`): Ein Aufruf wirkt nur, wenn die Bearbeitung unter ihrem Lock läuft,
derselbe Worker sie hält, die Execution-ID stimmt und die Lease nicht abgelaufen ist.

**Was mit allen anderen Aufrufen geschieht:** Fremde, ältere, abgelaufene und solche ohne Identität werden protokolliert
(`stale`) und nicht angewendet.

**Was nach einem abgewiesenen Report bleibt** (`recover_after_fenced_report()`): Das entscheidet der Server allein aus
seinem eigenen Zustand.
- **Abgelaufene Lease:** Die Bearbeitung wird zurückgegeben, wie es die Lease-Recovery auch tut.
- **Wieder in der Warteschlange, eigener Engine-Versuch complete:** Sie wird eingesammelt.
- **Idempotent:** Kommt derselbe Report noch einmal, ändert sich nichts.

## Abschlussvertrag (`completion_contract`)

**Wo er gilt:** Jeder Weg, der eine Bearbeitung beendet, endet im Collector. Der Collector fragt den Vertrag, bevor er
etwas speichert oder Personenparameter entfernt. Das gilt für:
- den Worker-Report,
- complete-before-start,
- den späten Report,
- die Reconciliation,
- die Lease-Recovery,
- TRACE_PENDING,
- das Resume.

**Was gespeichert wird:** Das Urteil steht in `completionjson` und in der Trace (`completion`). Es enthält:
- Host-Zustand, technischer Grund und Endcode,
- Form (geplant, gespielt, eindeutig, fremd, wiederholt, fehlend, unbeantwortet) und Trace,
- Klasse, Recovery,
- Quelle, Zeitpunkt, Regelversion.

| Klasse | Bedeutung | Recovery | In Auswertungen |
|---|---|---|---|
| regular | Design-Ende; bei fester Form die ganze Form, jedes Item einmal und beantwortet | none | ja, sofern die Gültigkeitsregeln es erlauben |
| incomplete | Ende vor dem, was das Design erlaubt (Form abgebrochen, Minimum nicht erreicht) | keep | nein (`completion_incomplete`) |
| technical | Zeitgrenze des Hosts, Engine-Fehler, von jemandem geschlossen | keep | nein (`completion_technical`) |
| unknown | nicht entscheidbar (kein bekannter Code, inkonsistente Form, Trace fehlt oder ist defekt) | decide | nein, nicht beurteilt (`completion_unknown`) |
| inprogress | Host-Versuch offen | continue | – |

**Ergänzende Regeln:**
- Ein Timeout ist nie ein Ergebnis, auch ohne Urteil und bei Bearbeitungen, die vor 0.7.85 eingesammelt wurden.
- Ein Zeitlimit ist nie Teil eines Lab-Designs, siehe `host-timeout.md`.
- Bearbeitungen ohne Urteil (vor 0.7.85) werden wie bisher nach den Gültigkeitsregeln beurteilt.

## Mehrdeutige Altbestände und Generationen

**Mehrdeutige Bearbeitungen:** Gemeint sind Bearbeitungen mit zwei oder mehr Engine-Versuchen der Person in der
Aktivität, von denen keiner gebunden ist.
- Es wird nichts automatisch ausgewählt: nicht nach ID, Datum, Zahl der Antworten oder `complete`.
- Beim Resume eines Experiments werden sie angehalten (`identity_mismatch`, `[identity:ambiguous-engine-attempt]`) und
  nicht wieder eingereiht.

**Entscheidungen** (`cli/sitting_recovery.php`): Ohne `--execute` liest das CLI nur. Jede Entscheidung wird in der
Historie festgehalten (`decided`), mit Begründung und Person, und ist idempotent. Bei einer laufenden Bearbeitung wird
nicht entschieden.

| Entscheidung | Wirkung |
|---|---|
| `--decision=bind --engineattemptid=N` | N ist der Versuch der Bearbeitung. Nur ein Versuch der Person in der Aktivität, nicht ausgemustert, und nur solange kein anderer gebunden ist. |
| `--decision=newgeneration` | Alle bisherigen Versuche werden ausgemustert (`local_catquizlab_retired`, mit Zustand und Zahl der Antworten zum Zeitpunkt). Die Bindung wird gelöst. Die nächste Ausführung beginnt einen neuen Versuch; die Vorgänger bleiben in der Engine. |
| `--decision=keep` | Das Ende bleibt, wie es ist; nichts wird erneut gespielt. |

Wieder eingereiht wird mit den üblichen Aktionen, etwa „Fehlgeschlagene erneut einreihen“.

## Historischer Audit (#174)

`cli/resume_integrity.php --runid=N [--json] [--mark]`, Regeln 2.

**Was er erfasst:** alle Bearbeitungen des Runs, unabhängig von `tries`, alle Engine-Versuche der Person und alle
Wiederaufnahmen in der Reihenfolge der Historie.

**Was er meldet:** Befunde je Bearbeitung, je Versuch und je Wiederaufnahme, jeweils mit Nenner.
- Was nicht belegbar ist, heißt `resume-unknown` oder `unknown`.
- Es gibt keine Kategorie „none“ und keine Entwarnung.

**Was `--mark` tut:** Es schreibt je Befund und Bearbeitung einmal eine Historienzeile (`audited`). Ergebnisse, Traces,
Status und Bindungen ändert es nicht.

## Zeitlimit des Hosts

Siehe `host-timeout.md`. Die Policy steht im Manifest (`hosttimelimit`). `cli/time_limits.php --runid=N` zeigt die
Fristen offener Versuche. Der Pipeline-Tick warnt im Run-Log (`time_limit_near`).

## Zustände in der Bearbeitungsliste (#175 AK05)

Die Liste der Bearbeitungen eines Runs (`runs.php`, Spalte Status, `data-state`) nennt den Zustand so, wie er für die
Recovery zählt (`attempt_scheduler::state_in_words()`, EN/DE). Der Status allein sagt in mehreren Fällen nur
„fehlgeschlagen“ oder „eingesammelt“.

| Zustand | Wann | Was zu tun ist |
|---|---|---|
| `held` – angehalten | Letztes Wort der Historie ist `held` (verletzte Invariante), oder `lasterror` beginnt mit `[identity:` | Ursache beheben, dann wieder einreihen oder mit `sitting_recovery` entscheiden |
| `exhausted` – endgültig fehlgeschlagen | fehlgeschlagen, Wiederholungen aufgebraucht, nicht angehalten | wieder einreihen, wenn ein neuer Versuch sinnvoll ist |
| `decided` – entschieden | letztes Wort ist eine Entscheidung (`decided`) | wieder einreihen, um wie entschieden zu spielen |
| `technical` / `incomplete` / `unknown` | eingesammelt, Abschlussvertrag nicht regulär | Diagnose, kein Ergebnis; siehe unten |
| sonst | Status wie bisher (wartet, läuft, eingesammelt, …) | – |

## Recovery-Leitfaden

1. **Bearbeitung angehalten mit `identity_mismatch`:**
   - Mit `cli/sitting_recovery.php --attemptid=N` die Kandidaten ansehen.
   - Dann binden, wenn der richtige Versuch belegbar ist, oder eine neue Generation starten.
   - Anschließend erneut einreihen.
2. **Abschluss `technical/host_timeout`:**
   - Die Trace ist Diagnose und kein Ergebnis.
   - Soll die Bearbeitung neu gespielt werden: `newgeneration`, dann erneut einreihen. Sonst `keep`.
3. **Abschluss `unknown`:** Vor einer Entscheidung die Historie und den Engine-Versuch prüfen. Das Urteil nennt den
   Grund (`why`).
4. **Run 103 auf CLARA:**
   - Zuerst nur lesen: `cli/resume_integrity.php --runid=103 --json` und sichern.
   - Markieren, binden oder neu generieren nur nach fachlicher Entscheidung.
   - Kein Reset und kein Löschen.
