# [BUG] Ein Fehler vor der ersten Frage hinterlässt einen dauerhaft `inprogress` stehenden Attempt

**Repository:** `ralferlebach/moodle-mod_adaptivequiz`
**Branch:** `ALiSe-v-1.2.0-legacy`
**Priorität:** Datenintegrität

## Befund

Wenn die Itemadministration bereits beim ersten Item einen Stoppgrund liefert,
bleibt ein Attempt-Datensatz zurück, der weder läuft noch beendet ist:

```text
uniqueid            = 0
attemptstate        = inprogress
attemptstopcriteria = ''
questionsattempted  = 0
standarderror       = 999
timefinished        = NULL
resultvalid         = 0
```

Auf einer Testinstanz mit automatisierten Läufen waren es 20 solcher Zeilen bei
167 Attempts insgesamt — sie entstehen nicht einzeln, sondern sammeln sich an,
weil jeder Wiederholungsversuch einen weiteren erzeugt.

## Ursache

`cat_session.php` legt den Attempt an, bevor die erste Frage feststeht, und
bricht danach ab:

```php
// classes/cat_session.php
if ($itemadministrationevaluation->item_administration_is_to_stop()) {
    $noquestionsfetchedforattempt = $uniqueid == 0;
    if ($noquestionsfetchedforattempt) {
        throw new moodle_exception('attemptnofirstquestion', 'adaptivequiz');
    }

    adaptivequiz_complete_attempt(...);
```

Der Kommentar an dieser Stelle benennt das Problem bereits richtig — ein leerer
Attempt soll nicht abgeschlossen werden. Nur wird er stattdessen gar nicht
behandelt: Die Ausnahme verlässt die Funktion, und die bereits geschriebene
Zeile bleibt unverändert stehen.

Der zweite Zweig behandelt den Normalfall vollständig (Abschluss, Stoppgrund,
Status). Für den ersten fehlt das Gegenstück.

## Warum das zählt

- Attempt- und Fortschrittszahlen werden falsch.
- Resume- und Retry-Pfade können einen technisch unbrauchbaren Attempt
  wiederfinden und fortsetzen wollen.
- Bei begrenzter Attemptzahl verbraucht ein nie begonnener Versuch das
  Kontingent.
- Aus dem Datensatz lässt sich weder Ursache noch Lebenszyklus ablesen: kein
  Stoppgrund, keine Endzeit, kein Status.

## Vorschlag

Zwei gangbare Wege; der erste ist der sauberere.

### A — Rollback

Der Attempt wird erst geschrieben, wenn die erste Frage feststeht, oder die
bereits geschriebene Zeile wird vor der Ausnahme wieder entfernt. Danach gibt es
keinen Zustand, den jemand interpretieren müsste.

### B — Ausdrücklicher Abschluss

Der Attempt wird geschlossen und als gescheitert gekennzeichnet, mit
Stoppgrund, Endzeit und `resultvalid = 0`:

```php
if ($noquestionsfetchedforattempt) {
    $adaptiveattempt->set_status($itemadministrationevaluation->stoppage_reason());
    // Beendet, nicht laufend: kein Resume-Pfad soll ihn wiederfinden.
    $adaptiveattempt->complete($context, 0.0, get_string('attemptnofirstquestion', 'adaptivequiz'), time());

    throw new moodle_exception('attemptnofirstquestion', 'adaptivequiz');
}
```

B hat den Vorteil, dass der gescheiterte Start sichtbar bleibt; A den, dass
nichts mitgezählt wird, was nie stattgefunden hat. Wo die Attemptzahl begrenzt
ist, spricht das für A.

## Akzeptanzkriterien

- [ ] Nach einem Fehlschlag vor der ersten Frage existiert kein Datensatz mit
      `uniqueid = 0` und `attemptstate = 'inprogress'`.
- [ ] Der Zustand ist aus dem Datensatz ablesbar (Variante B) oder es gibt
      keinen (Variante A).
- [ ] Ein Resume-Pfad findet einen solchen Attempt nicht wieder.
- [ ] Wiederholte Fehlversuche häufen keine Datensätze an.
- [ ] Ein Test hält den Fall fest.

## Abgrenzung

Nicht Teil dieses Issues ist die Frage, **warum** keine erste Frage geliefert
wurde. Das ist Sache der Konfiguration und der Teststrategie; hier geht es
ausschließlich darum, dass der Fehlschlag einen definierten Zustand hinterlässt.

## Umgehung im Labor

`local_catquizlab` entfernt diese Datensätze seit 0.6.6 für seine eigenen
simulierten Personen — sichtbar auf der Betriebsseite und automatisch im
`pipeline_tick`. Das ist eine Umgehung, keine Lösung: Sie räumt hinterher auf,
und sie greift nur für Attempts, die das Labor selbst erzeugt hat.
