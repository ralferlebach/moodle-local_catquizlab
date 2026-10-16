# Antwortübermittlung: Klassen und Abgleich (#134)

## Was je Antwort festgehalten wird

`navigation.js::clickAndSettle()` hält für jeden Klick auf „Antwort absenden“ die
Hauptframe-Kette fest: Methode, HTTP-Status, Ziel jeder Umleitung (`Location`),
Endadresse, bei abgebrochener Verbindung den Grund des Browsers
(`net::ERR_…`). Dazu kommen Usage, Slot und die bis dahin bestätigten Antworten
sowie die Dauer. Gespeichert wird das im Browserprotokoll der Ausführung
(`browser.submits`: Zähler je Klasse, die letzten fünf vollständig) und in den
Artefakten (`events.json`: alle).

## Klassen

| Klasse | Bedeutung | Erfolg? | Reason-Code |
|---|---|---|---|
| `ok` | 200, danach Frage oder Abschlussseite | ja | – |
| `redirect` | 3xx auf `attempt.php`, `attemptfinished.php` oder `review.php`, danach Frage oder Abschlussseite | ja | – |
| `unexpected_redirect` | 3xx anderswohin (z. B. `view.php`, Login) | nein, unsicher | `submit_unexpected_redirect` |
| `http_404` | irgendein Glied der Kette 404 | nein, unsicher | `submit_http_404` |
| `http_4xx` | anderer 4xx | nein, unsicher | `submit_http_4xx` |
| `http_5xx` | 5xx | nein, unsicher | `submit_http_5xx` |
| `connect_timeout` | Verbindung: `ERR_…TIMED_OUT` | nein, unsicher | `submit_connect_timeout` |
| `connect_failed` | Verbindung abgebrochen/abgelehnt/leer | nein, unsicher | `submit_connect_failed` |
| `cdp_timeout` | keine lesbare Antwort, bevor das Warten des Browsers ablief | nein, unsicher | `submit_cdp_timeout` |
| `moodle_error_page` | Status 200 mit Moodle-Fehlerseite | nein | `submit_moodle_error_page` (bzw. `engine_exception`, wenn der Server-Replay die Ausnahme findet) |
| `no_state` | Antwort kam, ist aber weder Frage, Abschluss- noch Fehlerseite | nein, unsicher | `submit_no_state` |

Der HTTP-Status geht der Seite vor: Ein 404 oder 500 mit Moodles Fehlerseite ist
ein 404 oder 500, keine „Fehlerseite“ (AK 3).

## Abgleich vor dem Weitermachen

Bei einer unsicheren Klasse fragt der Worker den Server
(`local_catquizlab_sitting_state`: Versuch vorhanden, Zustand, beantwortete und
gestellte Fragen) und vergleicht mit seinen bestätigten Antworten:

| Server | Urteil | Weiter |
|---|---|---|
| eine Antwort mehr als bestätigt | `stored` – Antwort gespeichert, nur die Antwortseite verloren | zählen, über die Aktivität zur nächsten Frage |
| gleich viele | `notstored` – nicht angekommen | über die Aktivität zurück zur selben Frage, neu beantworten |
| Versuch abgeschlossen | `finished` | als beendet melden |
| kein Versuch / andere Zahl | `missing` / `mismatch` | Ausführung endet mit `submit_state_mismatch` |

Höchstens drei Abgleiche je Ausführung. Führt der Weg auf die Login-Seite, wird
das festgehalten und neu angemeldet. Jeder Abgleich, der weitergeführt hat,
steht als eigener Eintrag „fortgesetzt“ mit Reason-Code in der Versuchshistorie,
den Logs (Warnung), dem Grund-Filter der Laufseite und dem Export „Ausführungen“.

## Nachweis

- `worker/test/submit.test.js`: Klassifikation und Abgleich als Unit-Tests; im
  Browser gegen einen Server, der 200, 303 zur Frage, 303 zum Abschluss, 303
  anderswohin, 404, 500, 200-Fehlerseite, abgebrochene Verbindung und eine
  ausbleibende Antwort liefert – jede Klasse richtig erkannt.
- `cli/smoke.php --expect-recovered=2` mit `CATQUIZLAB_FAULTS=lostresponse@5,lostrequest@9`
  (Worker-E2E): echte Moodle-Bearbeitung, verlorene Antwortseite → `stored`,
  verlorene Anfrage → `notstored`; geprüft wird, dass jede Frage genau einmal
  gestellt und beantwortet wurde (36 Fragen, 36 verschiedene, je 2 Schritte).

## Offen

Kausalrekonstruktion repräsentativer 404/303 aus Lauf 51 (DoD 2) braucht die
Apache-Logs und `requests/*.jsonl` des Zielsystems; mit 0.7.38 trägt jeder
fehlgeschlagene Antwort-POST seine Klasse, seine Kette und das Serverurteil.
