# [IMPROVEMENT] Aufbewahrung und Verlaufstiefe des Attempt-Fortschritts steuerbar machen (`local_catquiz_progress`)

## Kontext

`local_catquiz_progress` hält den Zustand, der nötig ist, um einen laufenden
Attempt fortzusetzen: gespielte Fragen, aktive, verworfene und gesperrte
Skalen, Antworten, Fähigkeiten und die Fähigkeiten vor dem Attempt. Der
Feldkommentar beschreibt die Tabelle als „all data needed to continue the
attempt“, also als Arbeitsspeicher eines laufenden Versuchs.

Tatsächlich verhält sie sich heute anders. Eine Prüfung des aktuellen Codes
ergibt:

- `progress::delete(int $attemptid)` existiert und löscht Cache **und**
  Datenbankzeile, wird im Produktionspfad aber **nirgends aufgerufen**.
- Die einzige tatsächliche Löschung erfolgt in
  `adaptivequizcatmodel_catquiz\local\catmodel\instance\instance_actions_handler`,
  wenn die **Aktivitätsinstanz** gelöscht wird.
- `attempt_finalizer` liest die Zeile beim Abschluss noch (für
  `get_preattempt_abilities()`), löscht sie aber nicht.

Damit bleibt pro Attempt dauerhaft ein JSON-Blob mit personenbezogenen
Antwortdaten liegen, ohne dass es dafür eine bewusste Entscheidung,
Konfiguration oder Aufbewahrungsfrist gäbe.

Gleichzeitig ist der Blob für eine Verlaufsauswertung **zu dünn**:

```php
// progress::update_ability()
if (!isset($this->abilities[$catscaleid])) {
    $this->abilities[$catscaleid] = [];
}
$this->abilities[$catscaleid] = $ability;
```

Die leere Array-Initialisierung ist wirkungslos: Der Wert wird sofort mit einem
Skalar überschrieben. `abilities` enthält also je Skala nur den **letzten**
Schätzwert, keine Trajektorie. Ein schrittweiser Fähigkeitsverlauf lässt sich
aus `local_catquiz_progress` folglich nicht rekonstruieren.

Der Verlauf existiert stattdessen in `debug_info` auf
`local_catquiz_attempts` — als Liste von Schritt-Snapshots mit
`personabilities` — aber nur, wenn die Einstellung
`local_catquiz | store_debug_info` gesetzt ist. Ist sie es nicht, ist der
Verlauf eines Attempts nachträglich nicht mehr herstellbar.

Es liegen damit zwei gegenläufige Probleme vor: Es wird dauerhaft aufbewahrt,
was als flüchtig gedacht war, und es fehlt genau das, was man für eine
Auswertung dauerhaft bräuchte.

## Ist-Zustand

- Die Progress-Zeile überlebt den Attempt und wird erst mit der Aktivität
  gelöscht.
- Es gibt keine Einstellung, die diese Aufbewahrung steuert.
- Es gibt keinen Cleanup-Task; `classes/task/` enthält nur
  `cancel_expired_attempts`, `adhoc_calculation`,
  `adhoc_recalculate_cat_model_params` und
  `recalculate_cat_model_params`.
- `progress::delete()` ist toter Code.
- `abilities` speichert je Skala einen Skalar, keinen Verlauf.
- Der schrittweise Verlauf hängt allein an `store_debug_info`, einer globalen
  An-/Aus-Einstellung ohne Abstufung und ohne Aufbewahrungsgrenze.
- `privacy/provider.php` deklariert die Tabelle korrekt, was das Problem
  dokumentiert, aber nicht löst: Die Daten sind exportierbar und löschbar,
  werden aber nie von selbst weniger.
- Für Betreiber ist dadurch nicht steuerbar, ob eine Instanz datensparsam
  arbeitet oder Verläufe für Auswertung und Qualitätssicherung vorhält.

## Ziel

Die Aufbewahrung des Attempt-Fortschritts und die Tiefe der Verlaufsdaten
sollen eine **bewusste, dokumentierte Entscheidung** sein — konfigurierbar
global und je Aktivität, mit einem datensparsamen Standard.

Die Lösung soll:

1. eine Einstellung einführen, die zwischen mindestens „datensparsam“ und
   „Verlauf protokollieren“ unterscheidet,
2. diese Einstellung global (Plugin-Setting) und je CAT-Test überschreibbar
   anbieten, wobei die globale Einstellung eine Obergrenze setzen kann,
3. im datensparsamen Modus die Progress-Zeile nach Abschluss des Attempts
   tatsächlich löschen — dafür ist `progress::delete()` bereits vorhanden,
4. im Protokollmodus einen **echten Verlauf** speichern, nicht nur den
   Endzustand, sodass die θ-Trajektorie ohne `store_debug_info` verfügbar ist,
5. eine Aufbewahrungsfrist anbieten, nach der protokollierte Verläufe von einem
   geplanten Task entfernt werden,
6. bestehende Instanzen nicht rückwirkend verändern, ohne dass jemand das
   ausgelöst hat.

## Vorschlag

### Einstellung

```
local_catquiz | progressretention
    minimal   (Standard)  Progress wird nach Abschluss des Attempts gelöscht.
    keep                  Endzustand bleibt erhalten (heutiges Verhalten).
    trace                 Vollständiger Schrittverlauf wird protokolliert.

local_catquiz | progressretentiondays
    0 = unbegrenzt, sonst Tage bis zur automatischen Löschung.
```

Je CAT-Test dieselbe Auswahl plus „Standard der Website übernehmen“. Die globale
Einstellung sollte begrenzen dürfen, was eine Aktivität wählen kann: Auf einer
datenschutzstreng konfigurierten Instanz darf eine einzelne Aktivität nicht
mehr protokollieren, als die Website erlaubt.

### Verlauf statt Endzustand

Im Modus `trace` sollte `update_ability()` anhängen statt zu überschreiben — die
vorhandene Array-Initialisierung deutet darauf hin, dass das ursprünglich so
gedacht war:

```php
$this->abilities[$catscaleid][] = [
    'step'    => $this->get_step(),
    'ability' => $ability,
];
```

Zu klären ist, ob das im selben JSON geschieht (einfach, aber wachsende Zeile)
oder in einer eigenen Schritt-Tabelle (sauberer auswertbar, aber neues Schema).
Bei langen Tests mit vielen Skalen wächst der JSON-Blob sonst spürbar.

## Akzeptanzkriterien

### Einstellung

- [ ] Es existiert eine globale Einstellung für die Aufbewahrung des
      Attempt-Fortschritts mit mindestens drei Stufen.
- [ ] Der Standard ist datensparsam, nicht das heutige Verhalten.
- [ ] Die Einstellung ist je CAT-Test überschreibbar.
- [ ] Die globale Einstellung kann als Obergrenze wirken; eine Aktivität kann
      nicht mehr protokollieren, als die Website erlaubt.
- [ ] Die Beschreibung nennt, welche personenbezogenen Daten in welcher Stufe
      aufbewahrt werden.

### Löschung

- [ ] Im datensparsamen Modus ist die Progress-Zeile nach Abschluss des
      Attempts nicht mehr vorhanden.
- [ ] Die Löschung erfolgt erst, nachdem `attempt_finalizer` die
      Pre-Attempt-Fähigkeiten ausgewertet hat.
- [ ] `progress::delete()` wird verwendet und ist damit kein toter Code mehr.
- [ ] Ein geplanter Task entfernt protokollierte Verläufe nach Ablauf der
      Aufbewahrungsfrist.
- [ ] Der Task ist idempotent und arbeitet in Stapeln, sodass er auf großen
      Instanzen nicht in ein Timeout läuft.

### Verlauf

- [ ] Im Modus `trace` ist der schrittweise Fähigkeitsverlauf je Skala
      abrufbar, nicht nur der Endwert.
- [ ] Der Verlauf ist unabhängig von `store_debug_info` verfügbar.
- [ ] Der Verlauf enthält je Schritt mindestens Schrittnummer, Skala und
      Fähigkeitsschätzung.
- [ ] Die Speichergröße je Attempt bleibt bei 100 Items und 100 Skalen
      vertretbar; andernfalls wird eine eigene Schritt-Tabelle verwendet.

### Rückwärtskompatibilität

- [ ] Bestehende Progress-Zeilen werden durch das Upgrade nicht gelöscht.
- [ ] Laufende Attempts werden durch die Umstellung nicht unterbrochen.
- [ ] Ein Attempt, dessen Progress-Zeile fehlt, führt an keiner Stelle zu einem
      Fatal — insbesondere nicht in `attempt_finalizer` und in
      `export_debuginfo_pdf.php`.
- [ ] Die Privacy-Deklaration wird an die neuen Stufen angepasst.

### Tests

- [ ] PHPUnit: im datensparsamen Modus existiert nach Abschluss keine
      Progress-Zeile mehr.
- [ ] PHPUnit: im Modus `keep` bleibt der Endzustand erhalten.
- [ ] PHPUnit: im Modus `trace` enthält der Verlauf einen Eintrag je Schritt.
- [ ] PHPUnit: die Aktivitätseinstellung kann die globale Obergrenze nicht
      überschreiten.
- [ ] PHPUnit: der Cleanup-Task entfernt genau die Zeilen jenseits der Frist.
- [ ] PHPUnit: `attempt_finalizer` funktioniert ohne Progress-Zeile.
- [ ] Behat: die Einstellung ist im CAT-Test-Formular sichtbar und
      speicherbar.

## Abgrenzung (nicht Teil dieses Issues)

- Keine Änderung der CAT-Algorithmen oder der Itemauswahl.
- Keine Änderung an `store_debug_info` selbst; die Einstellung bleibt bestehen
  und behält ihren Zweck (Debug-Ausgabe im Feedback).
- Keine neue Auswertungsoberfläche für Verläufe.
- Keine Änderung am Export der Debug-Informationen.
- Keine rückwirkende Bereinigung vorhandener Daten ohne ausdrückliche Aktion.

## Technische Hinweise

Betroffene Stellen:

```
classes/teststrategy/progress.php            save(), delete(), update_ability()
classes/local/attempt/attempt_finalizer.php  liest die Zeile beim Abschluss
classes/privacy/provider.php                 Deklaration der Tabelle
db/install.xml, db/upgrade.php               ggf. Schritt-Tabelle
settings.php                                 neue Einstellungen
classes/task/                                neuer Cleanup-Task
export_debuginfo_pdf.php                     muss ohne Zeile auskommen
```

Die Löschung der Aktivitätsinstanz erfolgt heute im Subplugin
`adaptivequizcatmodel_catquiz`
(`instance_actions_handler`) und sollte davon unberührt bleiben.

Zu klären ist die Abgrenzung zu `store_debug_info`. Beide Einstellungen
betreffen Verlaufsdaten, verfolgen aber verschiedene Zwecke: `store_debug_info`
erzeugt eine Debug-Ausgabe für das Feedback, die vorgeschlagene Einstellung
regelt Aufbewahrung und Tiefe der Prozessdaten. Möglicherweise ist es sinnvoll,
`store_debug_info` künftig als Anzeigeoption zu behandeln und die Datenhaltung
allein über die neue Einstellung zu steuern; das wäre aber eine
Verhaltensänderung und sollte bewusst entschieden werden.

## Motivation aus der Praxis

Aufgefallen ist das bei der Arbeit an der Experimentsuite `local_catquizlab`,
die Testverläufe auswertet. Dort war zunächst angenommen worden, die
Progress-Zeile werde nach dem Attempt gelöscht — die Suite archiviert sie
deshalb beim Einsammeln der Traces selbst. Die Prüfung des Engine-Codes ergab
das Gegenteil: Sie bleibt liegen, enthält aber nicht den Verlauf, den eine
Auswertung braucht. Beide Befunde sprechen für dieselbe Lösung: Aufbewahrung
und Tiefe sollten konfigurierbar sein statt implizit.

## Abhängigkeiten / Related

- `local_catquiz` (`progress`, `attempt_finalizer`, Privacy-Provider)
- `adaptivequizcatmodel_catquiz` (`instance_actions_handler`)
- `mod_adaptivequiz` (Attempt-Lebenszyklus)
- `store_debug_info` und der Attempt-Debug-Output
- `local_catquizlab` (Auswertung der Testverläufe)
