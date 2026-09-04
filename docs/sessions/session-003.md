# Session 003 — Von der Guard-Path-Suite zum ersten Studienergebnis

Diese Sitzung setzt Session 002 fort. Ausgangspunkt war Release 0.2.6: eine
vollständig getestete Suite, deren engine-nahe Pfade ausnahmslos als Guard-Pfade
geprüft waren, weil keine CAT-Engine installiert war. Endpunkt ist Release
0.4.2: 90 durchgespielte Attempts, eine ausgewertete Robustheitsstudie und vier
per Test festgehaltene Engine-Defekte.

## Verlauf

### Issue #9, elf Teilbefunde (0.2.7 – 0.2.9)

Drei davon machten Ergebnisse ungültig statt sie zu stören:

- `definition_for()` las die Basisdefinition statt der Zelldefinition aus dem
  Manifest. Ein Sweep über vier Zellen hätte viermal dieselbe Bedingung
  ausgeführt, während Cellkey und Manifest vier verschiedene dokumentierten.
- `subscale_evaluator` klassifizierte die geschätzten Werte gegen die wahre
  globale Fähigkeit — die bewertete Ausgabe wurde teilweise aus der Antwort
  konstruiert, gegen die sie bewertet wurde.
- `metric_series()` poolte alle Runs eines Experiments. Die Streuung wurde groß,
  gerade wenn das Experiment funktionierte.

Dazu: Outcome-Pipeline persistiert (Stop-Erfolg, Konzentration, Laufzeit,
Multi-k), degeneriertes 2PL nicht mehr still deklariert, Identität über
`experimentkey` statt Anzeigename, Sweep-Faktoren für Budgets und SE-Fenster als
Paare, Lifecycle mit `ready`/`aggregating`/`cancelled`.

### Engine-Integration (0.2.11 – 0.2.13)

Die Engine installiert und die Kette zum ersten Mal wirklich gefahren. Fünf
Defekte, die ohne Engine grundsätzlich unsichtbar waren: eine korrekte Engine
galt als zu alt, `question_bank` fehlte im CLI, ein veralteter Cache ließ sechs
Items als zwei erscheinen, ein NOT-NULL-Feld der Host-Aktivität fehlte, und die
Provisionierung war nicht idempotent.

CI-Nachzug: Die Testjobs installieren die Engine jetzt selbst
(`.github/scripts/fetch-engine.sh`); die Lint-Jobs bleiben engine-frei.

### Der Weg zum ersten vollständigen Attempt (0.2.14 – 0.3.0)

Eine lange Kette von Befunden, deren gemeinsames Muster war, dass der Worker
etwas erraten sollte, das der Server bereits wusste: Benutzername, Passwort,
Question-ID aus dem DOM, Engine-Attempt-ID von der Abschlussseite. Dazu ein
leerer Attempt, der als Erfolg galt.

Die eigentliche Ursache des Ein-Item-Abbruchs war klein und wirkte weit: Die
Feedback-Farbschlüssel waren erfunden statt aus der Palette der Engine genommen.
Der Fehler entsteht in `update_attemptfeedback()` — **nachdem** die Engine die
nächste Frage bereits gewählt hat. Der Attempt endete also mit einer Frage in
der Hand, die er nicht anzeigen konnte, und jede Diagnose sah auf den Itempool,
der nie das Problem war.

Gefunden durch temporäre Instrumentierung der Preselect-Kette mit Zählung der
Kandidaten nach jedem Schritt — die Zahlen entschieden es in einem Lauf.

### Studienparameter und Auswertung (0.3.1 – 0.4.2)

- Trennschärfe als `Beta(3,4)` auf (0, 5], Modus exakt 2. Die zuvor verwendete
  Lognormal traf den Modus, aber nicht den Bereich: 9,4 % von 20.000 Ziehungen
  landeten auf exakt 5,0.
- Rateparameter `Beta(2,2)` auf (0; 0,5), Modus 0,25.
- Standardfehler aus der Testinformation der administrierten Items, weil die
  Engine `personparams.standarderror` leer lässt.
- Eine Fragenkategorie je Experiment.

## Ergebnis der Abschlussstudie

Drei Poolvarianten × fünf Replikationen × sechs Personen = 90 Attempts.

| Variante | n | Items | SE | Bias | 95 %-KI |
|---|---|---|---|---|---|
| ideal | 30 | 16,0 | 0,913 | +0,706 | [+0,256; +1,156] |
| calibrationerror | 30 | 16,0 | 0,937 | +0,512 | [+0,044; +0,980] |
| depleted | 30 | 11,2 | 1,071 | +0,659 | [+0,173; +1,145] |

Die Konfidenzintervalle überlappen fast vollständig: **kein nachweisbarer
Effekt der Störungen auf den Bias.** Der reale Effekt steht in anderen Spalten —
der verkleinerte Pool füllt den Test nicht (11,2 statt 16 Items) und bezahlt das
mit Präzision (SE 1,071 gegen 0,913).

Lokale Diagnostik über 180 Subskalen-Beobachtungen: Bias −0,030, RMSE 1,776,
1-SE-Abdeckung 52,2 %, 2-SE-Abdeckung 80,6 %.

## Stand der Engine-Defekte

Geprüft gegen `local_catquiz` **2026083025** (`main`):

| Punkt | Stand |
|---|---|
| `progressretention` / `progressretentiondays` | umgesetzt |
| catquiz#59 (`get_ability_range(array_key_first(...))`) | offen |
| `debuginfo.php` liest `lastquestion` ungeprüft | offen |
| `personparams.standarderror` | wird leer geschrieben |

`tests/engine_defects_test.php` hält den Stand fest. Jeder Pin schlägt fehl,
sobald der Punkt behoben ist — die Fehlermeldung sagt, was zu entfernen ist.

## Verifikationsstand

| Gate | Ergebnis |
|---|---|
| PHPUnit (Moodle 4.5, pgsql) | 423 Tests, 2752 Assertions |
| PHPUnit 11.5 (Ladeprüfung) | alle Testklassen laden |
| Behat (Chrome, inkl. Accessibility) | 27 Szenarien |
| Worker | Syntax, 11 Unit-Tests, Selbsttest |
| phpcs / PHPDoc | 0 Befunde |
| Ergebnisoberfläche | acht Reiter auf 90 Attempts, ohne Ausnahme |
| Engine-Kette | Provisionierung, Attempt, Trace, Auswertung, Export |

## Offen

- Zwei Upstream-Issues (`docs/design/`), catquiz#59 eingereicht.
- θ-Trajektorie nur mit `store_debug_info` und damit derzeit nur bei
  `$CFG->debug = 0`.
- Für publikationsreife Aussagen Replikationszahlen deutlich über fünf.
