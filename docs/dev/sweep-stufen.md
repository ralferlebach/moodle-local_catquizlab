# Variierte Faktoren als Stufen (#126)

Code: `classes/form/experiment_form.php` (`parse_levels`, `LEVEL_FIELDS`,
Abschnitt „Weitere Faktoren variieren (optional)“, `definition_after_data`),
`amd/src/levels.js`.

## Befund

Die vier Sweep-Textareas (globales Budget, Subskalen-Budget, SE-Fenster,
Störungsstärke) standen leer und kaum erklärt im Normalmodus.
`parse_pairs()`/`parse_strengths()` übergingen unlesbare nichtleere Zeilen
stillschweigend („40-15“ verschwand; das Experiment lief ohne diese Stufe).

## Jetzt

- Eigener, eingeklappter Abschnitt mit Erklärung; offen, sobald ein Faktor
  Stufen hat. Leer = „Nicht variiert — der Einzelwert oben gilt“ (je Feld).
- Im Browser ersetzt ein Editor die Textarea: eine Zeile je Stufe (min/max bzw.
  Art + Wert), „Stufe hinzufügen“/„Stufe entfernen“, Prüfung je Zeile mit
  Meldung und `aria-invalid`. Die Textarea bleibt der gesendete Wert (gleiches
  Zeilenformat) — Speichern, Neuladen, Kopieren verlustfrei; ohne Script bleibt
  die Textarea.
- Gemeinsame Prüfung: Format als `regex`-Regel (Browser und Server),
  inhaltliche Regeln in `parse_levels()` (Server) und gespiegelt in
  `levels.js`: ganze Zahlen bei Budgets, min ≤ max, mindestens 1 Item,
  SE > 0, Anteil 0 < x ≤ 100 %, bekannte Schlüssel (fraction, shift, factor,
  sd), keine doppelten Stufen. Fehler mit Zeilennummer am Feld.
- Variiert ein Faktor, wird sein Einzelwert oben ausgeblendet (`hideIf`), und
  der Zustand sagt „Variiert: n Stufe(n); der Einzelwert oben wird nicht
  verwendet“.

## Vorschau

Die Sweep-Vorschau (Zellen, Läufe, Bearbeitungen, Budgets je Zelle) rechnet
aus der gespeicherten Definition; der Test prüft Vorschau = angelegte Läufe.
Eine Live-Vorschau vor dem Speichern ist nicht Teil dieser Lieferung.

## Tests

`tests/sweep_levels_test.php` (Budgets, SE, Stärken, Formular lehnt mit Zeile
ab, Speichern/Neuladen verlustfrei, Vorschau = Läufe);
`tests/playwright/sweep_levels.spec.js` (eingeklappt ohne leere Textareas,
Stufen per Tastatur, Meldung je Zeile, Einzelwert ausgeblendet, Server lehnt
unlesbare Stufe ab, Stufen nach Speichern wieder als Zeilen).
