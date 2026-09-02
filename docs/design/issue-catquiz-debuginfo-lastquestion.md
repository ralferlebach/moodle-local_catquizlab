# [BUG] `store_debug_info` verhindert den Start jedes Attempts: ungeprüfter Zugriff auf `lastquestion`

## Kontext

Ist `local_catquiz | store_debug_info` gesetzt, lässt sich kein Attempt mehr
starten. Die erste Frage erscheint nicht, stattdessen:

```text
Sorry, but couldn't define the first question to start the attempt,
the quiz is possibly misconfigured.
    mod_adaptivequiz\cat_session::run_item_administration_locked
    .../mod/adaptivequiz/classes/cat_session.php:126
```

Dieselbe Konfiguration, dieselben Items, derselbe Nutzer, mit
`store_debug_info = 0`: Der Test läuft normal durch (in unserem Fall 16 Items,
Fähigkeitsschätzung nahe am erwarteten Wert).

Damit ist auch `local_catquiz_attempts.debug_info` faktisch nicht nutzbar: Das
Feld wird nur bei gesetzter Einstellung geschrieben
(`catquiz.php:1870`), und genau dann kommt kein Attempt zustande.

## Ursache

`feedbackgenerator/debuginfo.php` greift beim Aufbau der Debug-Zeile ungeprüft
auf `lastquestion` zu:

```php
// classes/teststrategy/feedbackgenerator/debuginfo.php:347
'lastquestion' => (array) $newdata['lastquestion'],
```

Beim **ersten** Frageaufruf eines Attempts gibt es noch keine letzte Frage, der
Schlüssel fehlt, und der Zugriff wirft:

```text
Undefined array key "lastquestion"
    .../classes/teststrategy/feedbackgenerator/debuginfo.php:347
```

Die Ausnahme wird in `strategy::return_next_testitem()` gefangen und in einen
allgemeinen Fehler übersetzt. Nach außen sieht das aus, als habe die
Itemauswahl keine Frage gefunden — deshalb die irreführende Meldung über eine
vermeintlich falsche Testkonfiguration.

Auffällig ist, dass die unmittelbar benachbarten Felder derselben Struktur
sämtlich abgesichert sind:

```php
'lastresponse'    => isset($lastresponse) ? $lastresponse['fraction'] : self::NA,
'state'           => isset($lastresponse['state']) ? $lastresponse['state'] : self::NA,
'rightanswer'     => isset($lastresponse['rightanswer']) ? ... : self::NA,
'responsesummary' => isset($lastresponse['responsesummary']) ? ... : self::NA,
```

Nur `lastquestion` und `lastmiddleware` sind es nicht.

Hinzu kommt, dass `lastquestion` an anderer Stelle aus den Attemptdaten
ausdrücklich entfernt wird:

```php
// classes/catquiz.php:1874 ff.
$excluded = ['person_ability', 'installed_models', 'lastquestion', ...];
```

Der Schlüssel fehlt also nicht nur beim ersten Aufruf, sondern systematisch.

## Reproduktion

1. `local_catquiz | store_debug_info` aktivieren.
2. Caches leeren.
3. Einen CAT-Test mit gültigem Itempool aufrufen und einen Attempt starten.

Erwartet: die erste Frage.
Beobachtet: `couldn't define the first question`.

Mit `store_debug_info = 0` und sonst identischem Zustand startet derselbe
Attempt normal.

## Ziel

Die Debug-Einstellung soll Debug-Informationen sammeln, nicht den Testablauf
verändern. Ein fehlendes Feld in einer Diagnosestruktur darf keinen Attempt
verhindern.

## Vorschlag

```php
'lastquestion'   => (array) ($newdata['lastquestion'] ?? []),
'lastmiddleware' => $newdata['lastmiddleware'] ?? self::NA,
```

Damit verhält sich das Feld wie seine Nachbarn.

Weitergehend wäre zu erwägen, den Aufbau der Debug-Zeile insgesamt gegen
Ausnahmen abzuschirmen: Eine Diagnosefunktion, die den Vorgang abbrechen kann,
den sie beobachten soll, verliert ihren Zweck genau dann, wenn er gebraucht
wird.

## Akzeptanzkriterien

- [ ] Mit gesetztem `store_debug_info` startet ein Attempt und zeigt die erste
      Frage.
- [ ] `local_catquiz_attempts.debug_info` enthält danach die Schrittdaten.
- [ ] Ein fehlendes `lastquestion` führt zu einem neutralen Eintrag, nicht zu
      einer Ausnahme.
- [ ] Verhalten bei ausgeschalteter Einstellung unverändert.
- [ ] PHPUnit: ein Test deckt den ersten Frageaufruf mit aktivierter
      Debug-Speicherung ab.

## Abgrenzung

Nicht Teil dieses Issues:

- Inhalt oder Format der Debug-Informationen.
- Aufbewahrung und Löschung der Debug-Daten.
- Die Fehlermeldung von `mod_adaptivequiz`, die den eigentlichen Grund
  verdeckt — sie ist eine Folge, keine Ursache.

## Verwandt

Dieselbe Bauform wie
[#59](https://github.com/ralferlebach/moodle-local_catquiz/issues/59): eine
Ausnahme im Feedback-Pfad bricht die Fragenauswahl ab, und die sichtbare
Meldung zeigt auf die falsche Stelle. Aufgefallen bei automatisierten
Testläufen mit
[`local_catquizlab`](https://github.com/ralferlebach/moodle-local_catquizlab).

## Definition of Done

- [ ] Kein Zugriff auf `lastquestion` ohne Existenzprüfung.
- [ ] Ein Attempt startet mit aktivierter Debug-Speicherung.
- [ ] `debug_info` wird tatsächlich befüllt.
- [ ] Ein Test hält den Fall fest.
