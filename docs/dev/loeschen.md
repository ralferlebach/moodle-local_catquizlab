# Löschen und die CAT-Engine (#145, #161)

Seit 0.7.63. Code: `classes/local/purger.php`, `classes/local/engine_footprint.php`, `classes/local/engine_inventory.php`,
`classes/local/deletion_log.php`, `classes/local/purge_incomplete.php`, `classes/task/delete_experiment.php`;
Kommandozeile `cli/engine_inventory.php`, `cli/deletion_report.php`, `cli/deep_delete_check.php`.

## Was einem Lauf gehört: der Fußabdruck

`engine_footprint::of_run()` bestimmt, was in der Engine (local_catquiz), in ihrer Host-Aktivität (mod_adaptivequiz)
und in Moodle **allein diesem Lauf** gehört. Die Zuordnung läuft nie über eine Nummer allein, sondern über die eigenen
Aufzeichnungen des Labs:

| Was | Gefunden über |
|---|---|
| Aktivität (`course_modules`, `adaptivequiz`), ihre Versuche (`adaptivequiz_attempt`) und deren Fragenverwendungen (`question_usages`) | `testcmid` des Laufs |
| CAT-Versuche, Fortschritt, Fähigkeiten, Engine-Traces (`local_catquiz_attempts`, `_progress`, `_personparams`, `_trace`) | die simulierten Konten des Laufs (Benutzername mit `_r<Lauf>_`; ein gelöschtes Konto über die Personenzeile des Laufs), CAT-Versuche zusätzlich über die Aktivität |
| Engine-Kontext, Skalen, Items, Itemparameter, Testeinstellungen (`_catcontext`, `_catscales`, `_items`, `_itemparams`, `_tests`) | Skalenzuordnung des Laufs (`local_catquizlab_scalemap`), Testeinstellungen über die Aktivität |
| Fragen | Itemaufzeichnung des Laufs (`local_catquizlab_item`) |

**Gefährdet** sind Zeilen **anderer**, die eine Nummer mit dem Lauf teilen. Es gibt zwei ID-Räume:
`adaptivequiz_attempt.id` (Versuch der Aktivität) und `local_catquiz_attempts.id` (CAT-Versuch). Gefährdet sind
deshalb:
- ein CAT-Versuch, Fortschritt oder eine Fähigkeit eines anderen, dessen Versuchsnummer einer Versuchsnummer des Laufs
  gleicht, in einem der beiden Räume;
- ein Aktivitätsversuch eines anderen mit der Nummer eines CAT-Versuchs des Laufs.

Eine Löschung, die die eine ID für die andere nimmt, trifft genau diese Zeilen.

## Tiefes Löschen

Reihenfolge je Lauf (`purger::delete_run(..., deep: true)`):
1. **Fußabdruck vorher** festhalten (Datensatz `engine-before`).
2. **Aktivität löschen** mit Moodles eigener Modullöschung und den Callbacks der Aktivität und des CAT-Modells
   (`core_compat::delete_module()`: auf 4.5 `course_delete_module()`, ab 5.2 die Formataktion
   `formatactions::cm()->delete()`, weil `course_delete_module()` dort veraltet ist, MDL-86856).
   **Scheitert das**, wird **nichts weiter** gelöscht: kein Itemparameter, keine Skala, keine Frage, keine Lab-Zeile.
   Der Lauf bleibt mit allem, was zu seinen Engine-Zeilen führt (`purge_incomplete`); die Aufgabe wird wiederholt.
   Bis 0.7.62 zählte das als „adaptiver Test, der nicht gelöscht werden konnte“, und der Rest wurde trotzdem
   gelöscht.
   Danach die leeren Fragenverwendungen der Versuche der Aktivität (Versuche ohne erste Frage): Moodles
   Frage-Engine löscht eine Verwendung auf MySQL/MariaDB nur zusammen mit ihren Frageversuchen (inner join in
   `question_engine_data_mapper::delete_usage_records_for_mysql()`), auf PostgreSQL auch ohne. Gefunden am
   MariaDB-Mirror.
3. **Engine-Items:** Itemparameter, Items, Skalen des Laufs, dann seine Fragen. Die Itemparameter kommen zuerst, denn
   das Löschen einer Frage lässt die Engine ihr Item fallen und dessen Parameter liegen. Bis 0.7.62 blieben so alle
   Itemparameter zurück.
4. **Fähigkeiten** der eigenen simulierten Konten, die noch da sind.
5. **Engine-Kontexte** des Laufs, sobald keine Skala mehr darin ist. Bis 0.7.62 blieben sie zurück.
6. **Simulierte Konten.**
7. **Prüfung** (`engine_footprint::check()`, Datensatz `engine-after`): Nichts vom Fußabdruck darf übrig sein, und
   **jede** gefährdete Zeile anderer muss noch da sein. Sonst `purge_incomplete`: Lauf, Lab-Zeilen und Experiment
   bleiben; die Meldung nennt Tabellen und IDs; die Aufgabe wird wiederholt.
8. Erst dann die Lab-Zeilen des Laufs; nach dem letzten Lauf die Prüfung der Lab-Tabellen (#145) und das Experiment
   selbst (Datensatz `finished`).

Ein **Zurücksetzen** (`run_lifecycle::reset()`) nimmt die Schritte 1–5 und 7 genauso. Scheitert es, bleiben die
Lab-Zeilen, und das Ergebnis heißt `engine-incomplete` mit Eintrag im Laufprotokoll. Die simulierten Konten behält es,
wie bisher.

Das **Aufräumen eines Laufs** (`run_cleanup::cleanup()`) nimmt dieselben Schritte und dieselbe Prüfung, die
Engine **vor** den Lab-Zeilen; scheitert es, wirft es `purge_incomplete`, und die Lab-Zeilen bleiben. Bis 0.7.62 löschte
es die Lab-Zeilen zuerst, fing eine scheiternde Aktivität ab und ließ Fragen und Fähigkeiten liegen.

Wird die **Aktivität vorher regulär gelöscht** (im Kurs, mit dem Kurs), nehmen ihre eigenen Callbacks ihre Versuche.
Das tiefe Löschen des Labs findet danach den Rest und endet im selben Zustand. Ein **laufendes** Experiment wird vor dem
Löschen angehalten (`experiment_stop`, unter der Sperre der Zuteilung): Läufe abgebrochen, Worker zum Anhalten
aufgefordert, offene Sittings geschlossen, wartende Aufgaben entfernt; dann erst wird die Löschaufgabe eingereiht.

**Nicht tiefes Löschen** löscht nichts in der Engine oder der Aktivität. Vorschau und Rückmeldung sagen, was bleibt
(„Kein tiefes Löschen: bleibt in der CAT-Engine und der Aktivität: …“).

## Was die Vorschau zeigt

Beim tiefen Löschen die Zahlen des Fußabdrucks aller Läufe: Aktivitäten, deren Versuche, Fragenverwendungen,
CAT-Versuche, Fortschritt, Fähigkeiten, Engine-Traces, Testeinstellungen, Kontexte, Skalen, Items, Itemparameter und
Fragen. Dazu, wie viele Zeilen anderer eine Nummer teilen; sie werden nicht gelöscht, aber danach geprüft. Mit einem
CAT-Modell vor Version 2026101701 kommt eine Warnung (siehe unten).

## Das CAT-Modell: Abhängigkeit statt Reparatur (#161 AK7)

Der Lösch-Callback des CAT-Modells (`adaptivequizcatmodel_catquiz`, dort #11) löschte bis Version 2026100906
(`instance_actions_handler::delete_instance_callback()`) `local_catquiz_progress` über
`local_catquiz_attempts.attemptid`, also die Versuchsnummern der Aktivität. Die gepinnten local_catquiz beider Linien
schreiben dort aber die Nummer des CAT-Versuchs hinein. Die Folge: Der eigene Fortschritt blieb, und fremder mit
derselben Nummer ging. Ab 2026101701 (beide Linien) löscht das CAT-Modell über den CAT-Versuch
(`classes/local/attempt_cleanup.php`, local_catquiz #95).

CatQuizLab repariert den Callback nicht und räumt fremde oder alte Zeilen nicht global auf. Stattdessen:
- **CI:** `engine-pins.env` pinnte das CAT-Modell seit 0.7.63 auf die korrigierten
  Stände `a7ba075` (ALiSe-v-1.2.0-legacy, 2026101801) und `15d4c55` (v-3.0, 2026102800); seit 0.7.73 auf neuere Heads
  derselben Zweige (`fee42fd`, `9a07cca`), die alten Stände stehen in `engine-pins-previous.env`. Ihre Abhängigkeiten sind
  unverändert, und jede local_catquiz-Klasse, die sie benutzen, ist in den gepinnten Engines vorhanden.
- **Auf einer Installation** mit älterem CAT-Modell warnt die Vorschau des tiefen Löschens. Die Prüfung in Schritt 7
  hält das Löschen an, bevor es „gelöscht“ meldet.
- `local_catquizlab` verlangt in `version.php` weiterhin die alten Mindestversionen. Eine höhere Mindestversion des
  CAT-Modells würde das Update des Labs auf einer Installation verhindern, die das CAT-Modell noch nicht aktualisiert
  hat. Die Prüfung schützt dort.

## Nachweis und Protokoll (#145 AK6)

`moodledata/local_catquizlab/deletions/experiment-<id>.jsonl`, eine Zeile je Ereignis, mit Zeit und Korrelations-ID:

| Ereignis | Inhalt |
|---|---|
| `queued` | bestätigtes POST: tief oder nicht, wer |
| `started` | die Hintergrundaufgabe beginnt (Task-ID) |
| `engine-before` | je Lauf: Zahlen des Fußabdrucks und der gefährdeten Zeilen |
| `engine-after` | je Lauf: Prüfung bestanden, was übrig, was verloren |
| `failed` | Fehler, Lauf; die Aufgabe wird wiederholt |
| `finished` | was in den Lab-Tabellen übrig ist (muss nichts sein) und was gelöscht wurde |

Die Datei überlebt das Experiment. `php local/catquizlab/cli/deletion_report.php --experimentid=N [--json]` zeigt sie;
mit `--expect-deleted` ist es eine Prüfung. Exit 0 nur, wenn:
- das Löschen mit `finished` endet, ohne Rest;
- jede Engine-Prüfung bestanden ist;
- das Experiment weg ist.

Die Bibliothek zeigt ein Experiment im Löschen mit dem letzten Fehler (`deleteerror`), bis eine Wiederholung durchläuft.

## Inventur für den Betrieb (#161 AK6)

`php local/catquizlab/cli/engine_inventory.php --orphans [--json]` **liest nur**. Es zählt, je mit ältestem und
neuestem Zeitpunkt und Komponente:
- Fortschritt ohne CAT-Versuch, davon die mit der Nummer eines Aktivitätsversuchs;
- CAT-Versuche ohne Aktivitätsversuch;
- Fähigkeiten ohne CAT-Versuch oder ohne (lebendes) Konto;
- Itemparameter ohne Item, Items ohne Skala;
- Aktivitätsversuche ohne Aktivität.

„Von Lab-Läufen“ zählt nur, was die Aufzeichnungen des Labs einem **bestehenden** Lauf zuordnen; einem gelöschten Lauf
wird nichts zugeschrieben. `--runid=N` zeigt den Fußabdruck eines Laufs, `--runs` den aller Läufe in Zahlen. Ob und
wie Altlasten entfernt werden, entscheidet der Betrieb, nicht das Lab.

## Tests

- **`engine_footprint_test`** (PHPUnit, echte Engine-Tabellen und Aktivität): Lauf A und Aktivität B mit über Kreuz
  gleichen Nummern.
  - Fußabdruck und gefährdete Zeilen;
  - tiefes Löschen über die Hintergrundaufgabe: nichts von A übrig, B unverändert, keine neue verwaiste Zeile,
    vollständiger Datensatz;
  - ein Callback, der B's Fortschritt nimmt: angehalten, Lauf bleibt;
  - scheiternde Aktivität: Lauf bleibt, die Wiederholung vollendet, eine weitere findet nichts;
  - die Aufgabe, wie Cron sie ausführt (`\core\cron::run_adhoc_task()`): sie scheitert, Moodle behält sie mit
    Fehlverzögerung, die Liste zeigt den Fehler; die Wiederholung durch Cron vollendet sie (#145 DoD2);
  - Zurücksetzen und Aufräumen mit scheiternder Aktivität: nichts gelöscht; Aufräumen danach wie Löschen,
    wiederholt ohne Wirkung;
  - Aktivität vorher regulär im Kurs gelöscht: tiefes Löschen endet gleich;
  - nicht tief: nichts in der Engine, Vorschau sagt es;
  - Zurücksetzen wie Löschen;
  - Inventur liest nur.
- **Worker-E2E**, alle Stacks, Schritt „Issue 161 — deep deletion against the real engine“: das gerade gespielte
  Experiment, gelöscht wie auf einer Installation (Bestätigung, Hintergrundaufgabe, echte Modullöschung),
  vorher und nachher geprüft (`cli/deep_delete_check.php`, `cli/deletion_report.php --expect-deleted`).
- **Interface-E2E**, alle Stacks, letzter Schritt „Issue 161 — the played experiment deleted deeply through the
  interface“ (`tests/playwright/deep_delete.spec.js`, nur mit `CATQUIZLAB_DEEP_DELETE`): das Experiment, das
  `experiment.spec.js` über die Oberfläche angelegt und gespielt hat, im Browser tief gelöscht (Vorschau, Name,
  Schaltfläche). Cron führt die Aufgabe aus; der Test wartet auf den Löschdatensatz, er schläft nicht. Vorher und
  nachher wie oben geprüft. Ausgaben in `artifacts-deep-delete/`.
- **Playwright** `library.spec.js`: Verschwinden aus der Liste zählt nur mit vollständigem Löschdatensatz (#145 AK1).
