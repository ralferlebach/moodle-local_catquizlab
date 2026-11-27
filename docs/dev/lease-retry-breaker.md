# Lease, Wiederholung, Circuit Breaker (#133)

## Befund zu Lauf 51

Die Lease einer Testbearbeitung wurde beim Abholen für 300 s gesetzt und nie
verlängert – der Heartbeat (alle 20 s) aktualisierte nur den Worker. Dauerte
eine Bearbeitung unter Last länger, lief die Lease ab, während der Worker noch
antwortete: `reclaim_stale()` stellte sie zurück, die Person wurde wieder
abholbar (die Abholung schließt Personen nur mit *gültiger* Lease aus), und ein
zweiter Worker meldete sich als dieselbe Person im selben Versuch an. Daher die
303 `lease_expired`-Rückstellungen in Lauf 51 und plausibel ein Teil der
404/303-Antwort-POSTs aus #134 (zwei Browser in einem Versuch).

## Jetzt (0.7.38)

| Fall | Verhalten |
|---|---|
| Worker spielt | Jeder Heartbeat mit Bearbeitung verlängert deren Lease – nur, solange dieser Worker sie hält (`attempt_scheduler::renew_lease`). |
| Lease verloren | Der Heartbeat meldet `leaselost`; der Worker antwortet nicht mehr und endet mit `[lease:lost]` (Code `lease_lost`). |
| Lease abgelaufen | Vor dem Zurückstellen fragt der Server die Engine: abgeschlossener Versuch → eingesammelt, nicht neu gespielt; sonst zurückgestellt, mit Worker, Engine-Zustand, beantworteten/gestellten Fragen im Verlauf. |
| Späte Meldung | Meldung eines Workers, der die Bearbeitung nicht mehr hält: als „verspätete Meldung, nicht übernommen“ im Verlauf, Zustand unverändert. Ausnahme: abgeschlossener Engine-Versuch einer wartenden Bearbeitung → als Ergebnis übernommen. |
| Lease-Messung | Jede Meldung trägt `lease.heldseconds` (seit Abholung) und `lease.leftseconds`. |
| Circuit Breaker | Pausiert den Lauf (Status bleibt, nicht `failed`), Grund `circuit_breaker:<Code>` nach dem häufigsten Reason-Code der Serie; Kategorie Kapazität/deterministisch im Lauf-Log. |
| Fortsetzen | Idempotent; mit Readiness-Prüfung des bereitgestellten Tests; fehlgeschlagene Bearbeitungen zurück in die Warteschlange mit je einer Verlaufszeile; Fehlerverlauf bleibt. Gilt für „Fortsetzen“ wie für „Zurücksetzen und weiter“. |
| Anzeige | Warteschlange: abholbar, in Arbeit, gesammelt, endgültig fehlgeschlagen, wiederholbar (wartend), zurückgehalten – mit Grund je Lauf. Seit 0.7.64 auch „nicht abholbar, weil der Lauf keine Arbeit ausgibt“ mit Zustand und Fehlergrund je Lauf. Laufstatus „Pausiert durch den Circuit Breaker: <Grund>“, „Beendet, N endgültig fehlgeschlagen“. |
| Audit | Pause/Fortsetzen als `run_autopaused`/`run_resumed` mit Grund, Zählern, Auslöser im Lauf-Log; Statuswechsel wie bisher; Bearbeitungsverlauf append-only. |

## Migration

Upgrade 2026101301: Läufe im Status `failed`, deren letzter Statuswechsel
`circuit_breaker_tripped` war, gehen in ihren vorherigen Status zurück
(bereit/laufend) und werden pausiert, mit dem Grund, den der Breaker heute
nennen würde (`circuit_breaker::migrate_failed_held`). Gelöscht oder
zurückgesetzt wird nichts; fehlgeschlagene Bearbeitungen bleiben es bis zum
Fortsetzen.

## Tests

`tests/run_recovery_test.php`: Lauf 51 als Regression (210 wartend + 40
fehlgeschlagen, als `failed` gehalten → migriert, pausiert, fortgesetzt, Verlauf
erhalten, zweites Fortsetzen ohne Wirkung), Pause nach Ursache,
Lease-Verlängerung und -Verlust über den Heartbeat, Rückstellung mit
Serverzustand, späte Meldung. `tests/external_test.php`: zehn Fehlschläge über
`job_complete` pausieren den Lauf.

## Angehalten statt wiederholt (0.7.84, #175)

Ein Worker, der eine Invariante verletzt findet, meldet den Grund als Code (`job_complete`, Parameter `reason`).
Gemeint sind drei Fälle:
- eine Antwort über den Pool des Laufs hinaus (`budget_violation`);
- kein Fortschritt, den der Server bestätigt (`no_progress`);
- ein Engine-Versuch, der nicht der der Bearbeitung ist (`identity_mismatch`).

**Reihenfolge:** Die Historie schreibt den Code, bevor die Wiederholungsentscheidung und der Breaker ihn lesen.

**Folgen für die Bearbeitung:**
- Sie wird sofort fehlgeschlagen und *angehalten* (Zeile `held`). Sie wird nicht wiederholt.
- Engine-Versuch und Personenparameter bleiben, wie sie sind (#174).
- Eine Person gibt sie mit „Fehlgeschlagene erneut einreihen“ oder der Wiederaufnahme nach dem Breaker zurück.

**Folgen für den Lauf:**
- Drei angehaltene Bearbeitungen in Folge mit derselben Ursache halten ihn an (`HOLD_THRESHOLD`).
- Eine verlorene Lease, ein Stopp durch eine Person und die eigene Pause des Breakers zählen weder für noch gegen die
  Serie.

`state_unavailable` (der Server war nicht zu befragen) gilt als vorübergehend und wird wie bisher mit Backoff
wiederholt.

