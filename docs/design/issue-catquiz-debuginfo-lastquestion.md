# [BUG] Ungeprüfter Zugriff auf `lastquestion` bricht bei Developer-Debugging den Attempt ab

## Kontext

**Umfang zuerst:** Auf einer Instanz mit `$CFG->debug = DEBUG_DEVELOPER` lässt
sich mit gesetztem `local_catquiz | store_debug_info` kein Attempt starten. Auf
einer Instanz mit normaler Debug-Stufe tritt der Fehler **nicht** auf — dort
läuft derselbe Test durch und `debug_info` wird korrekt befüllt. Beides
nachgemessen, mit sonst identischem Zustand.

Betroffen ist damit die Entwicklungs- und Testsituation, nicht der
Produktivbetrieb. Der ungeprüfte Zugriff bleibt trotzdem einer, und er trifft
genau die Umgebung, in der man ihn am wenigsten gebrauchen kann.

Bei Developer-Debugging erscheint statt der ersten Frage:

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

Bei normaler Debug-Stufe ist das eine Notice: `(array) null` ergibt ein leeres
Array und der Ablauf geht weiter. Bei Developer-Debugging wandelt Moodles
Fehlerbehandlung die Notice in eine Ausnahme um; sie wird in
`strategy::return_next_testitem()` gefangen und in einen allgemeinen Fehler
übersetzt. Nach außen sieht das aus, als habe die
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

1. `$CFG->debug = DEBUG_DEVELOPER` setzen.
2. `local_catquiz | store_debug_info` aktivieren.
3. Caches leeren.
4. Einen CAT-Test mit gültigem Itempool aufrufen und einen Attempt starten.

Erwartet: die erste Frage.
Beobachtet: `couldn't define the first question`.

Gegenprobe, beide nachgemessen: mit `store_debug_info = 0` startet derselbe
Attempt bei Developer-Debugging normal, und mit `$CFG->debug = 0` startet er
auch bei aktivierter Debug-Speicherung — dann werden 17 Schritte in
`debug_info` geschrieben.

## Ziel

Die Debug-Einstellung soll Debug-Informationen sammeln, nicht den Testablauf
verändern — und schon gar nicht abhängig davon, wie die Instanz ihre
Fehlerbehandlung konfiguriert hat. Ein fehlendes Feld in einer
Diagnosestruktur darf keinen Attempt verhindern.

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

- [ ] Mit gesetztem `store_debug_info` startet ein Attempt auch bei
      `DEBUG_DEVELOPER` und zeigt die erste Frage.
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
