# Sitzungsprotokolle

## Konvention: 1 Session = 1 Chat

Jede Chat-Session mit dem Assistenten entspricht **genau einem**
Sitzungsprotokoll `session-NNN.md`. Innerhalb einer Session wird dieses eine
Dokument fortgeschrieben (neue Phasen/Runden werden angehängt) — es wird **kein
neues** Protokoll pro Arbeitsschritt angelegt. Eine neue Nummer beginnt erst mit
einem neuen Chat.

Aufbau eines Protokolls:

- Kopf: Nummer, Titel, Datum, grobes Ziel der Session.
- Chronologische **Phasen** (Runden) innerhalb der Session, je mit Ergebnis.
- Abschluss: Verifikationsstand und „Next".

Feinkörnige Änderungshistorie gehört in `CHANGELOG.md`, der Arbeitsvorrat in
`docs/design/backlog.md`; das Sitzungsprotokoll hält Entscheidungen und
Begründungen der jeweiligen Chat-Session fest.
