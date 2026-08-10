# Session 005 — CI-Fails fixen + E1.1 (deklaratives Experimentformat)

## CI-Analyse (logs_85055861381)
- **Ursache aller PHPUnit-/Behat-Fails:** Beim Plugin-Install meldete XMLDB
  „CHAR NOT NULL column (...) with '' (empty string) as DEFAULT value. This
  type of columns must have one meaningful DEFAULT declared or none (NULL)."
  Diese Debugging-Ausgabe lässt den Install-Schritt in moodle-plugin-ci
  fehlschlagen — die Tests liefen dadurch gar nicht erst an. Betroffen:
  run.cellkey, person.stratum, transfer.remotehost, transfer.payloadhash.
- **PHP Mess Detector:** ungenutzte Variable `$params` in
  `oracle_answer::execute` (nicht fatal wegen `|| true`, dennoch gefixt).
- Strukturprüfung, PHPDoc, Savepoints, Grunt/Gherkin: grün.

## Fixes
- `DEFAULT=""` aus den vier CHAR-NOT-NULL-Feldern entfernt (NOT NULL ohne
  Default = empfohlenes Moodle-Muster; Werte werden beim Insert stets gesetzt).
  Bestandsinstallationen unberührt (Spalten existierten bereits).
- `oracle_answer`: `$params` per `unset()` wie die Geschwister-Stubs.

## E1.1 — deklaratives Experimentformat
- `classes/local/experiment_definition.php`: Parsen (Array/JSON), Validieren
  (Struktur, Enums, Bereiche, Anforderungen 2.6: Pool-Variante über Skalen,
  Personenzahl+Namensregel, Fragen-Template, spezifizierbare Kurse/Tests),
  Defaults, Sammel-Fehlermeldungen, keine Seiteneffekte. `example_baseline()`
  als Vorlage/Fixture.
- Tests `experiment_definition_test.php`: gültige Baseline, JSON-Roundtrip,
  Garbage-Ablehnung, ein Testfall je Defekt (DataProvider), Defaults.
- Doku `docs/design/experiment-format.md`; Backlog E1.1 = ✅.
- Lokal per CLI-Harness verifiziert (Baseline valid, jeder Defekt genau ein
  Fehler, Defaults, Roundtrip, Garbage) — PHPUnit selbst läuft in CI.

## Version
2026081001 → **2026081002**, Release **0.1.3** (Nummer und Name hochgezogen).
Kein neuer Upgrade-Schritt: Schema-Fix betrifft nur Install-Metadaten, E1.1
ist reiner Code.

## Verifikation
PHPCS 0/0 über alle PHP-Dateien; install.xml gültig, kein `DEFAULT=""` mehr;
YAML/Worker-JS ok; höchster Savepoint (2026081001) ≤ Version (2026081002).

## Next
E1.2 Sweep-Expansion (Definition → konkrete Runs, Ausschlussregeln,
Fraktionierung, Kapazitätsschätzung) und E1.3 Run-Tabelle in der Verwaltungs-
seite; danach E2.1 Pool-Generator (Templates→Items, Skalenbaum).
