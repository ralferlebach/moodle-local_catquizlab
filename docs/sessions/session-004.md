# Session 004 — Anforderungen ins Lasten-/Pflichtenheft + Schema-Korrektur

## Anlass
Vier Präzisierungen zur Provisionierung sollen normativ ins Anforderungs-
dokument. Eine davon korrigiert eine Design-Annahme des Fundaments.

## Aufgenommene Anforderungen (Architektur 2.6, normativ)
- **A. Items/Skalen statt Kontexte:** Unterschiedliche Item-Parametrisierungen
  werden als physisch verschiedene Fragen mit eigenen Parametern realisiert,
  organisiert über Item-Skalen (catscales). CAT-Kontexte sind dafür
  ausdrücklich ungeeignet und werden NICHT als Variantenträger genutzt (sie
  modellieren Kalibrier-Scopes derselben Items; das würde Ground Truth,
  Tagging, Exposition und Depletion vermischen). Ein Lauf = ein Arbeits-
  Kontext; Varianten unterscheiden sich durch Items und Skalen.
- **B. Personen = verschiedene Nutzer:** jede Person ein eigener Moodle-User,
  Ground Truth im Lab-Store über person.moodleuserid verknüpft.
- **C. Kurse/CAT-Tests je Lauf spezifizierbar; Personen kursweise einschreiben:**
  Referenzieren oder Neuanlage; Enrol vor Attempt-Planung.
- **D. Systematische Namen + Fragen-Templates:** Personen-/Item-Namen nach
  spezifizierbaren Regeln (seed-stabil, kollisionsfrei); Fragen als Templates
  mit Blanks, regelbasiert zu konkreten Items instanziiert.

## Umgesetzt
- architektur.md → Rev. 2.1: neuer Abschnitt 2.6 (A–D normativ), Inline-
  Korrektur im Provisionierungsteil (Item-Pool, Kurs/Aktivität, Simulanten).
- backlog.md: E1.1 (Kurs-/Test-Spezifikation, Namensregeln, Templates),
  E2.1/E2.2 (Items+Skalen statt Kontext; Templates; Namen; Depletion =
  Items entfernen), E2.3 (eigener Nutzer je Person, Namensregeln),
  E2.4 (spezifizierbare Kurse/Tests + Einschreibung).
- Schema-Korrektur: `local_catquizlab_pool.contextid` entfällt; neu
  `scaleid` + `questioncategoryid`. db/upgrade.php mit Feldänderungen und
  Savepoint 2026081001; Generator angepasst.
- Versionsbump 2026080900→**2026081001**, Release **0.1.2** (fasst auch den
  UI-Einstieg aus der Vorsession, der ohne Bump lief). Bump hier technisch
  nötig wegen Schemaänderung auf bereits installiertem Testsystem.

## Verifikation
PHPCS 0/0 über alle PHP-Dateien; install.xml gültig; Savepoint == Version;
kein `contextid` mehr in Schema/Generator.

## Next
E1.1 deklaratives Format inkl. Kurs/Test-Spec, Namensregeln, Template-Schema;
dann E2.1 Pool-Generator (Templates→Items, Skalenbaum) und E2.3/E2.4.
