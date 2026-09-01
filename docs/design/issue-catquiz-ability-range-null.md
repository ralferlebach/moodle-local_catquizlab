# [BUG] `get_ability_range()` wird mit `null` aufgerufen, wenn noch keine Personenfähigkeiten vorliegen

## Kontext

Beim Start eines Attempts kann `feedbackgenerator::update_data()` eine
Skalenliste erhalten, die leer ist. Der Aufruf

```php
// local/catquiz/classes/teststrategy/feedbackgenerator.php:419
$abiltiyrange = $this->feedbackhelper->get_ability_range(array_key_first($catscales));
```

übergibt dann `null`, und

```php
// local/catquiz/classes/teststrategy/feedback_helper.php:483
public function get_ability_range($catscaleid): array {
    $cs = new catscale($catscaleid);
```

führt zu:

```text
TypeError: local_catquiz\catscale::__construct():
Argument #1 ($catscaleid) must be of type int, null given,
called in .../local/catquiz/classes/teststrategy/feedback_helper.php on line 484
```

Die Seite rendert dann eine Exception statt einer Frage.

## Ursache

`$newdata['catscales']` wird in `attemptfeedback.php` erst nach einer frühen
Rückgabe gesetzt:

```php
// local/catquiz/classes/output/attemptfeedback.php:366 ff.
if (!$personabilities) {
    return $newdata;          // 'catscales' wird nicht gesetzt
}
...
$catscales = catquiz::get_catscales([...]);
$newdata['catscales'] = $catscales;
```

Liegen für den Attempt noch keine Personenfähigkeiten vor, fehlt der Schlüssel
`catscales`. `feedbackgenerator.php:419` liest ihn dennoch und reicht das
Ergebnis von `array_key_first()` — also `null` — an eine Methode weiter, die
`int` verlangt.

Der Zustand „noch keine Fähigkeiten" ist im regulären Betrieb selten, weil die
Fähigkeiten beim Einstieg in den Test gesetzt werden (Peer-Mittelwert bzw.
Fallback). Er tritt jedoch zuverlässig auf, wenn ein Attempt direkt aufgerufen
wird, ohne dass dieser Einstieg durchlaufen wurde — etwa bei automatisierten
Testläufen. Unabhängig davon sollte eine Methode mit `int`-Signatur nicht mit
`null` aufgerufen werden können.

## Reproduktion

1. CAT-Test mit einer Skala und aktivierten Subskalen anlegen.
2. Für den Nutzer sicherstellen, dass in `local_catquiz_personparams` **keine**
   Zeile für den betreffenden Kontext existiert.
3. `mod/adaptivequiz/attempt.php?cmid=<cmid>` direkt aufrufen.

Erwartet: die erste Frage.
Beobachtet: `TypeError` wie oben, keine Frage.

## Wirkung: die eigentliche Fehlermeldung wird verdeckt

Das ist der schwerwiegendere Teil. Tritt der `TypeError` auf, ersetzt er eine
Meldung, die dem Betreiber tatsächlich weiterhilft. Derselbe Aufruf liefert mit
gesetztem `local_catquiz | store_debug_info`:

```text
Sorry, but couldn't define the first question to start the attempt,
the quiz is possibly misconfigured.
    mod_adaptivequiz\cat_session::run_item_administration_locked
    .../mod/adaptivequiz/classes/cat_session.php:126
```

Ohne die Einstellung erscheint stattdessen der `TypeError` aus
`catscale::__construct()`, und die Ursache — eine Konfiguration, die keine
erste Frage zulässt — ist nicht mehr erkennbar. Wer den Fehler sucht, landet im
Feedback-Pfad statt bei der Itemauswahl.

Die Absicherung der Methode behebt damit nicht nur einen Typfehler, sondern
stellt die Diagnosefähigkeit her: Die Fehlermeldung der Engine sollte nicht
davon abhängen, ob Debug-Informationen aktiviert sind.

## Ziel

Der Aufruf soll auch dann definiert enden, wenn noch keine Fähigkeiten
vorliegen. Es geht nicht darum, den Einstiegspfad zu ersetzen, sondern darum,
dass die Methode ihre eigene Vorbedingung prüft.

## Vorschlag

Zwei kleine Änderungen, unabhängig voneinander sinnvoll:

```php
// feedbackgenerator.php
$catscales = $newdata['catscales'] ?? [];
$abilityrange = $catscales === []
    ? $this->feedbackhelper->get_ability_range($primarycatscaleid)
    : $this->feedbackhelper->get_ability_range(array_key_first($catscales));
```

Die primäre Skala des Tests ist an dieser Stelle bekannt
(`$this->get_quiz_settings()->catquiz_catscales`) und ist auch die fachlich
richtige Referenz: Der Fähigkeitsbereich ist laut Kommentar im Code ohnehin für
alle Skalen desselben Wurzelbaums gleich.

Zusätzlich sollte die Signatur die Erwartung ausdrücken:

```php
// feedback_helper.php
public function get_ability_range(int $catscaleid): array {
```

Damit meldet ein fehlerhafter Aufruf die Stelle, an der er entsteht, statt eine
Ebene tiefer im Konstruktor von `catscale`.

## Akzeptanzkriterien

- [ ] Ein Attempt, für den noch keine Personenfähigkeiten vorliegen, zeigt die
      erste Frage statt einer Exception.
- [ ] `get_ability_range()` deklariert `int` und wird nirgends mit `null`
      aufgerufen.
- [ ] Der Fähigkeitsbereich entspricht bei leerer Skalenliste dem der primären
      Skala des Tests.
- [ ] Bestehendes Verhalten bei vorhandenen Fähigkeiten bleibt unverändert.
- [ ] PHPUnit: ein Test deckt den Fall „keine Personenfähigkeiten" ab.

## Abgrenzung

Nicht Teil dieses Issues:

- Änderung daran, wann und wie Personenfähigkeiten initial gesetzt werden.
- Änderung an `attemptfeedback::update_data()` über das Setzen von `catscales`
  hinaus.
- Änderungen an Teststrategien oder Itemauswahl.

## Technische Hinweise

```text
classes/teststrategy/feedbackgenerator.php          Zeile 419
classes/teststrategy/feedback_helper.php            Zeile 483
classes/output/attemptfeedback.php                  Zeile 366 ff.
```

Aufgefallen ist das bei automatisierten Testläufen mit
[`local_catquizlab`](https://github.com/ralferlebach/moodle-local_catquizlab),
das Attempts simulierter Personen direkt startet. Dort werden die
Erstfähigkeiten inzwischen selbst gesetzt; die Absicherung der Methode bleibt
davon unberührt sinnvoll.

## Definition of Done

- [ ] Die Methode ist gegen einen leeren Skalensatz abgesichert.
- [ ] Die Signatur verlangt `int`.
- [ ] Ein Test hält den Fall fest.
- [ ] Kein `TypeError` mehr auf dem beschriebenen Reproduktionsweg.
- [ ] Eine fehlgeschlagene Erstfragenauswahl meldet ihre eigene Ursache,
      unabhängig von `store_debug_info`.
