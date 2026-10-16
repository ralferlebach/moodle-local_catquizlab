# Logs: Schnellfilter, Deep-Links, Ausgaben, Redaktion (#131)

Code: `logs.php`, `classes/local/log_view.php` (`redact`, `split_detail`,
`export_metadata`), `classes/local/status_report.php` (`held_card`),
`classes/local/recovery_advisor.php`.

## Bedienung

- **Schnellfilter** über dem Formular (GET-Links, Experiment/Lauf/Zeitraum
  bleiben): Fehler, Warnungen (Warnung oder schlimmer), Lauf-Lebenszyklus,
  Worker, Testbearbeitungen, Filter zurücksetzen. Der aktive ist markiert
  (`aria-current`).
- **Erweiterte Filter** (Zeitraum, Aktion, Korrelation, Worker, Task, Lauf-
  versuch, Bearbeitung, Schwere, Reihenfolge) eingeklappt, offen sobald einer
  gesetzt ist. Experiment und Benutzer werden als versteckte Felder mitgeführt —
  vorher gingen sie beim Anwenden verloren.
- Fehler- und Warnzeilen zeigen den kurzen Teil; der technische Teil (JSON,
  Stacktrace) ist in derselben Zeile aufklappbar („Details“). Kopieren kopiert
  den ganzen Text ohne die Aufklapp-Beschriftung.
- Zeitzone über dem Log genannt und in beiden Downloads (`timezone`).
- **Leere Zustände**: nichts protokolliert (`nothing`), kein Treffer für die
  Filter (`nomatch`, mit „Filter zurücksetzen“), nichts im Zeitfenster
  (`window`, mit „alles anzeigen“ und letztem Eintrag).

## Ausgaben

| Ausgabe | Inhalt |
|---|---|
| Kopieren | die angezeigten Zeilen dieser Seite |
| Download TXT / JSON | diese Auswahl, bis 5000 Zeilen, älteste zuerst; Kopf/Metadaten mit Filter, Zeitraum, Zeitzone, Reihenfolge, Grenze, gekürzt/teilweise gelesen |

Die Zeile unter den Knöpfen sagt das auf der Seite.

## Redaktion

`log_view::redact()` wird in `window()` auf jede Zeile angewandt — also auf
Seite, Kopie, TXT und JSON gleichermaßen: `wstoken|token|sesskey|password|
passwd|secret|apikey|api_key|privatetoken=…`, dieselben Schlüssel in JSON,
`MoodleSession…=…`, `Authorization: Bearer|Basic …`, `Cookie: …` →
`[redacted]`.

## Deep-Links

Vom Fortschritt: ein vom Circuit Breaker angehaltener Lauf (seit 0.7.38
RUNNING + pausiert) zeigt jetzt seine Karte mit Ursache, „Fehler und Log
ansehen“ (`logs.php?runid=…&severity=warning&hours=0`) und „Fehler löschen und
fortsetzen“ — vorher „wartet auf Worker“ ohne Weg zur Ursache. Ebenso die
Wiederherstellung; Bearbeitungen verlinken weiter mit `attemptid` bzw.
`correlationid`.

## Tests

`tests/log_view_redaction_test.php`; Playwright `tests/playwright/logs_ux.spec.js`
(Schnellfilter, erweiterte Filter ohne Verlust, Tastatur, leere Zustände,
Deep-Link aus dem Fortschritt, TXT/JSON = angezeigte Auswahl), `logs.spec.js`
angepasst.
