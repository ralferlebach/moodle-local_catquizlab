# Personenparameter der Engine bei Fehlschlag und Fortsetzung (#174, 0.7.84)

Leseanalyse der gepinnten Engine-Stände und die daraus folgende Entscheidung. Gelesen wurde der Code, nichts wurde
an der Engine geändert.

| Linie | Branch | Commit (Release-Pins 0.7.84) | Version | gelesen auch |
|---|---|---|---|---|
| Moodle 4.5 | `ALiSe-v-1.2.0-legacy` | `53b809e2fdecfece88a9eb202ef14648e42eb501` | 1.2.1, 2026112700 | `2b39e3c0` (Pins bis 0.7.83) |
| Moodle 5.x | `migration-zu-moodle-5.x` | `e7d07d785cfa846aa9b1d7794894598f1a58cb6b` | 1.3.0, 2026112701 | `6be68525` (Pins bis 0.7.83) |

**Gleichheit der Dateien:**
- `personability_loader.php` und `updatepersonability.php` sind in beiden Linien byte-gleich.
- `personparam_repository.php` unterscheidet sich nur in einem Kommentar.
- `attempt_finalizer.php` unterscheidet sich nur im Aufruf `progress::get_cat_attempt_id`.

**Änderungen zwischen alten und neuen Pins, so weit sie hier zählen:**
- Messpunkte (`timeline::span`).
- `debug_info` wird erst am Ende geschrieben.
- Der Fortschritt besteht aus Kopf und Ereignissen (Engine-Issue #177, gelesen über `progress::stored_structure()`).
- `updatepersonability` setzt die laufende Schätzung einer Subskala auch im Kontext des Schritts. Der Kommentar dort
  bestätigt: „The next step read the new ability anyway, from the person parameters below.“
- Das Fortsetzen aus den eigenen Zwischenschätzungen ist unverändert.

## Was die Engine in `local_catquiz_personparams` hält, und wer es liest

**Zwischenschätzung eines laufenden Versuchs.**
- Geschrieben wird sie nach jeder Antwort von
  `updatepersonability::update_person_param()`, und zwar über `personparam_repository::save_provisional()` mit
  `validationstatus = provisional`, `isvalid = 0` und `attemptid` = CAT-Versuch.
- Gelesen wird sie nur vom selben Versuch: `personability_loader::load_saved_personparams()` ruft
  `get_provisional(laufender CAT-Versuch, Nutzer, Kontext, Skalen)` auf (Engine-Issue #164).

**Ergebnis eines beendeten Versuchs.**
- Geschrieben wird es vom Finalisierer (`attempt_finalizer`), der danach die Zwischenschätzungen mit
  `discard_provisional()` verwirft.
- Es ist zulässig im Sinne von `personparam_repository::admissible_sql()`: gültig oder nur zur Anzeige, und
  Referenzwert.
- Gelesen wird es auf zwei Wegen:
  - als Startwert des nächsten Versuchs derselben Person (`get_latest_valid_by_scale()`);
  - als Population für den Prior anderer Personen (`catquiz::get_person_abilities()`).

## Die Reihenfolge beim Laden einer Fähigkeit

So geht `personability_loader::load_saved_personparams()` vor:

1. Die eigene Zwischenschätzung des laufenden Versuchs.
2. Sonst der letzte zulässige Wert der Person (Übertrag).
3. Sonst der neueste zulässige Wert aus `get_person_abilities`.
4. Sonst die Standardfähigkeit 0.

`updatepersonability` schätzt danach aus allen Antworten des Versuchs neu (`catcalc::estimate_person_ability`):

- **Startwert:** die geladene Fähigkeit.
- **Prior-Mittel (`get_initial_ability()`):**
  - die geladene Fähigkeit, sobald der Versuch genug eigene Antworten hat;
  - sonst das Mittel der zulässigen Werte im Kontext, wenn es mindestens `NUM_ESTIMATION_THRESHOLD` = 50 sind;
  - sonst 0.
- **Prior-Streuung (`set_initial_standarderror()`):** entsprechend.

## Was CatQuizLab bis 0.7.83 tat

`job_complete` rief `user_provisioner::forget_engine_person_params()` auf:

- **nach jedem gemeldeten Fehlschlag:** nach `retry_or_fail()`, ohne dessen Ergebnis zu prüfen;
- **nach jedem beendeten Bericht:** nach `collect_or_hold()`, auch wenn die Bearbeitung nur auf ihren Trace wartete.

Gelöscht wurden alle Zeilen der Person in den Kontexten des Laufs, die Zwischenschätzungen eingeschlossen.

Ein neuer Versuch derselben Bearbeitung setzt aber denselben Engine-Versuch fort (die Aktivität bietet „Fortsetzen“).
Nach der Löschung fand der Lader keine eigene Zwischenschätzung mehr. Eine simulierte Person hat außer diesem
keinen Versuch, also auch keinen Übertrag. Damit begann der erste Schritt nach der Fortsetzung mit Fähigkeit 0 als
Startwert und als Prior-Mittel statt mit der letzten eigenen Schätzung. Jeder weitere Schritt baut auf diesem auf.

- **Klassischer Test:** Die Itemfolge ist davon unberührt, weil die Strategie alle Items der Reihe nach spielt. Der
  Schätzpfad θ/SE ist es nicht.
- **Adaptive Strategien:** Ab diesem Schritt kann sich auch die Itemauswahl ändern.

Ob und wie stark sich ein Endergebnis verschoben hat, lässt sich aus den Tabellen nicht mehr ablesen, weil die
gelöschten Zeilen fehlen. Belegt ist das Risiko auf Code-Ebene, kein Schaden in einem bestimmten Lauf.

Zweiter Befund: Im TRACE_PENDING-Pfad löschte `job_complete` die Zeilen vor der Collection.
`attempt_collector::read_scale_standarderrors()` und `final_standarderror()` lesen die Standardfehler aber genau
dort.

## Entscheidung (0.7.84)

- **Fehlschlag:** `job_complete` löscht nichts mehr. Das gilt für alle Ausgänge:
  - **erneuter Versuch (QUEUED):** Er setzt mit den eigenen Zwischenschätzungen fort.
  - **endgültig fehlgeschlagen (FAILED) oder angehalten (#175):** Eine Person kann die Bearbeitung zurückgeben
    (`run_lifecycle::requeue_failed()`, Wiederaufnahme nach dem Breaker).
  - **abgebrochen (CANCELLED):** Die Wiederaufnahme des Experiments stellt die Bearbeitung wieder in die
    Warteschlange (`experiment_resume`).
- **Beendet:** `job_complete` löscht ebenfalls nichts. Gelöscht wird an genau einer Stelle, wie schon zuvor: in
  `attempt_collector::collect()`, nachdem Trace und Status COLLECTED in einem Update gespeichert sind. Wartet die
  Bearbeitung auf ihren Trace (TRACE_PENDING), bleiben die Zeilen erhalten, bis die Collection gelingt. Ein zweiter
  Aufruf löscht nichts mehr (`delete_records_select` mit derselben Bedingung), ist also idempotent.
- **Unabhängigkeit der Personen:** Sie bleibt gewahrt, und das ohne Löschung.
  - Zwischenschätzungen sind nach `admissible_sql()` nicht zulässig. Sie fließen weder in den Prior anderer
    Personen noch in deren Übertrag ein. Der Test `retry_integrity_test` prüft das gegen die installierte Engine.
  - Zulässig werden Werte erst durch den Finalisierer. Sie werden gelöscht, sobald die Bearbeitung eingesammelt
    ist, wie bisher. Die Zahl der gleichzeitig zulässigen Werte im Kontext eines Laufs bleibt damit durch die Zahl
    der Bearbeitungen begrenzt, die gerade zwischen Engine-Ende und Collection stehen.
- **Kontexte:** Die Bereinigung beim Neuaufsetzen eines Laufs (`remove_seeded_parameters`) und beim Löschen
  (`purger`) ist unverändert. Sie betrifft nur die Kontexte dieses Laufs.

## Historische Daten

`cli/resume_integrity.php --runid=N` listet die gefährdeten Bearbeitungen eines Laufs, nur lesend:

- **at-risk:** mit mehr als einer Ausführung, nach einem Fehlschlag fortgesetzt, und dieser Fehlschlag liegt vor
  dem ersten Upgrade auf 2026112700 (laut `upgrade_log`);
- **after-fix:** der Fehlschlag liegt nach diesem Upgrade, es wurde nichts gelöscht;
- **new-attempt:** Der Engine-Versuch wurde erst nach der Fortsetzung angelegt.
- **no-engine-attempt:** Die Bearbeitung hat keinen Engine-Versuch, weil die Fehlschläge vor seinem Start lagen
  (Anmeldung, Start). Es wurde nichts fortgesetzt.

Mit `--mark` bekommt jede gefährdete Bearbeitung genau eine Historienzeile (`audited`, Grund
`resume_after_param_deletion`). Ergebnis, Trace und Status bleiben unverändert. Die Zeile erscheint in der Historie,
in den Logs und im Export auf Ausführungsebene.

Ob die gekennzeichneten Bearbeitungen wiederholt werden, ist eine fachliche Entscheidung und wird nicht automatisch
getroffen.
