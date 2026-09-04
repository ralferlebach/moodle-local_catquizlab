# [BUG] Ungeprüfter Zugriff auf `lastquestion` bricht Attempts bei `DEBUG_DEVELOPER` ab

## Problem

`feedbackgenerator/debuginfo.php:347` greift ungeprüft auf `lastquestion` zu:

```php
'lastquestion' => (array) $newdata['lastquestion'],
```

Beim ersten Frageaufruf eines Attempts existiert der Schlüssel nicht. In
`catquiz.php:1874` wird `lastquestion` zudem ausdrücklich aus den Attemptdaten
entfernt, fehlt also systematisch.

Auf einer normalen Instanz ist das folgenlos: `(array) null` ergibt `[]`. Auf
einer Instanz mit `$CFG->debug = DEBUG_DEVELOPER` macht Moodles
Fehlerbehandlung aus der Notice eine Exception. Sie wird in
`strategy::return_next_testitem()` gefangen und in einen allgemeinen Fehler
übersetzt, sodass der Attempt abbricht mit:

```text
Sorry, but couldn't define the first question to start the attempt,
the quiz is possibly misconfigured.
```

Die Meldung zeigt auf die Konfiguration statt auf die Diagnosefunktion.

Betroffen ist ausgerechnet die Entwicklungs- und Testumgebung, dort nur bei
aktiviertem `local_catquiz | store_debug_info`. Da `local_catquiz_attempts.debug_info`
ausschließlich bei gesetzter Einstellung geschrieben wird, sind
Debug-Informationen bei Developer-Debugging praktisch nicht zu bekommen.

Auffällig: Die Nachbarfelder derselben Struktur (`lastresponse`, `state`,
`rightanswer`, `responsesummary`) sind alle mit `isset()` abgesichert, nur
`lastquestion` und `lastmiddleware` nicht.

## Reproduktion

1. `$CFG->debug = DEBUG_DEVELOPER`
2. `local_catquiz | store_debug_info = 1`
3. Caches leeren, CAT-Test mit gültigem Itempool starten

Erwartet: erste Frage. Beobachtet: `couldn't define the first question`.

Gegenproben, beide nachgemessen: mit `store_debug_info = 0` startet derselbe
Attempt normal; mit `$CFG->debug = 0` startet er auch bei aktivierter
Debug-Speicherung und schreibt 17 Schritte nach `debug_info`.

## Vorschlag

```php
'lastquestion'   => (array) ($newdata['lastquestion'] ?? []),
'lastmiddleware' => $newdata['lastmiddleware'] ?? self::NA,
```

Damit verhalten sich die beiden Felder wie ihre Nachbarn.

## Akzeptanzkriterien

- [ ] Mit `DEBUG_DEVELOPER` und aktiviertem `store_debug_info` startet ein
      Attempt und zeigt die erste Frage.
- [ ] `local_catquiz_attempts.debug_info` wird dabei befüllt.
- [ ] Verhalten bei normaler Debug-Stufe unverändert.

## Verwandt

Gleiche Bauform wie [#59](https://github.com/ralferlebach/moodle-local_catquiz/issues/59):
eine Ausnahme im Feedback-Pfad bricht die Fragenauswahl ab, und die sichtbare
Meldung zeigt auf die falsche Stelle. Aufgefallen bei automatisierten
Testläufen mit `local_catquizlab`.
