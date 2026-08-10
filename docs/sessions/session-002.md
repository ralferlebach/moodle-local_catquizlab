# Session 002 — Runde E0: Plugin-Fundament abgeschlossen

## Goal
Erste Runde nach dem installierbaren Stub: Epic E0 (Plugin-Fundament)
vollständig abarbeiten — Datenmodell (0.2), Webservices (0.3), Run-Manifest
(0.4). Weiterhin ohne Engine-Abhängigkeit und CI-grün.

## Fixed decisions
- **Versionsbump erforderlich** (2026080900 → 2026081000, Release 0.1.1):
  Schema + Services ändern sich und das Plugin ist im Testsystem bereits
  installiert. Dazu `db/upgrade.php` mit Savepoint, damit bestehende
  Installationen migrieren. (Bewusster Unterschied zur Rename-Runde, die
  ohne Bump lief, weil rein kosmetisch und vor Erstinstallation gedacht.)
- **8 Tabellen**: experiment (bestand), run, pool, person, attempt, result,
  exportlog, transfer. Alle Namen ≤ 27 Zeichen. `person.moodleuserid` als
  einfacher int (keine user-FK), da Lebenszyklus der Wegwerf-Testnutzer der
  Provisionierung gehört — hält zugleich die Privacy-Lage einfach.
- **upgrade.php lädt Tabellen aus der eigenen install.xml** (`xmldb_file` →
  `getStructure()->getTable()`), keine duplizierten Definitionen, die
  auseinanderlaufen könnten.
- **5 Webservices**, gruppiert in zwei vordefinierte, deaktivierte,
  nutzerbeschränkte Services (worker, hub) nach Vorbild des Hub/Node-Musters
  der Engine. Stubs: authentifizieren, validieren, wohlgeformte „not ready"-
  Antwort; `hub_submit_run` prüft bereits den SHA-256-Hash echt.
- **Privacy** bleibt Null-Provider, da keine Zeilen geschrieben werden; Text
  angepasst, Umstellung auf Voll-Provider bleibt an E2.3 getriggert.

## Result
E0 vollständig. PHPCS (Moodle-Standard) 0 Fehler/0 Warnungen über alle
PHP-Dateien; alle Syntax- und XML-Prüfungen grün. Tests erweitert
(stub_test, external_test, manifest_test), Generator um run/pool/person
ergänzt. Backlog- und CHANGELOG-Einträge gesetzt.

## Verifikation offen im Container
PHPUnit/Behat laufen erst in CI (kein Moodle im Container). CI-Matrix und
`moodle-plugin-ci validate`/`savepoints` decken Schema, Services und
Upgrade-Savepoint ab.

## Next session (Beginn E1 / Vorbereitung M1)
- E1.1 deklaratives Experimentformat + Validierung, E1.2 Sweep-Expansion.
- E3.1 Adhoc-Task „schedule_attempt", der einen `attempt`-Datensatz anlegt
  und (Variante exec) den Worker startet; Worker-Login-Konzept (offener
  Punkt 6 der Architektur) entscheiden.
