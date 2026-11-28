# Host-Timeout: Analyse der gepinnten Engine und Entscheidung

P0-Issue „[ENGINE-LIFECYCLE] Technische Finalisierung vom fachlich erfolgreichen Testabschluss trennen“, DoD01.
Stand: CatQuizLab 0.7.85 (2026112701), Re-Audit 27.11.2026.

## Gepinnte Versionen

| Komponente | Zweig | Commit | Version |
|---|---|---|---|
| local_catquiz (Moodle 4.5) | ALiSe-v-1.2.0-legacy | b59875fa → 78043b8f | 2026112702 → 2026112703 |
| local_catquiz (Moodle 5.x) | migration-zu-moodle-5.x | 4ffe20a2 → 284b538c | 2026112703 → 2026112704 |
| mod_adaptivequiz (Moodle 4.5) | ALiSe-v-1.2.0-legacy | 5a037404 → f4ed4bef | 2026112300 → 2026112700 |
| mod_adaptivequiz (Moodle 5.x) | v-3.0 | f587a1ee → 772fe171 | 2026112301 → 2026112701 |

Die Analyse unten gilt für die ersten Pins. Den Nachtrag zu den Pins vom Abend (`expiry_policy`) enthält der letzte
Abschnitt.

Beide local_catquiz-Linien haben die Task in derselben Form. Zwischen b59875fa und 4ffe20a2 unterscheidet sich
`cancel_expired_attempts.php` nur um ein Argument beim Abschluss: die Standardabweichung für die Altsignatur von
`adaptivequiz_complete_attempt()`.

## Was den Versuch beendet

**Task:** `local_catquiz\task\cancel_expired_attempts`

- **Registrierung:** `db/tasks.php` von local_catquiz, Zeitplan `*/5 * * * *`.
- **Was sie liest:** jedes offene Attempt (`attemptstate = inprogress`) einer Adaptive-Quiz-Aktivität mit
  `catmodel = 'catquiz'`.

**Frist** (`exceeds_maxtime()`, Zeile 213 ff.):

- **Bezugspunkt:** `now − adaptivequiz_attempt.timecreated`, also der Start des Versuchs. Letzte Aktivität,
  Inaktivität und Pausen des Labors spielen keine Rolle.
- **Test mit eigenem Versuchslimit:** Dieses Limit gilt, mit dem Puffer `BUFFER_TIME_FACTOR = 1.5`. Ein eigenes Limit
  von 0 heißt „kein Limit“.
  - Gelesen wird über `catquiz_handler::time_limits()`, Zeile 1280 ff.
  - Liegt das Limit unter dem Site-Default, gewinnt es. Das ist der Fix für mod_adaptivequiz #5.
- **Test ohne eigenes Limit:** Es gilt der Site-Default `maximum_attempt_duration_hours`.
  - Einstellung: `settings.php`, Zeile 213, Vorgabe 24.
  - Der Wert 0 heißt „kein Limit“.
  - Gelesen wird mit `intval(get_config(...) ?? 24)`. Ist die Einstellung nicht gesetzt, liefert `get_config` `false`,
    und das wird zu 0, also „kein Limit“. Der Fallback `?? 24` greift dort nie.
- **Unsere Tests:** Ein Test mit ausgeschaltetem Zeitlimit (`catquiz_includetimelimit = 0`) hat kein eigenes Limit,
  `time_limits()` liefert dafür `null`. So legt `test_provisioner` jeden Test des Labors an (Zeile 197). Für Tests des
  Labors gilt also immer der Site-Default, auf CLARA 24 h.

**Abschluss** (`complete()`):

- `adaptivequiz_complete_attempt_by_id(..., 'timelimit')` setzt den Host-Stopcode `timelimit` und den Stoptext
  `attemptclosedbytimelimit` („Versuch wurde wegen Zeitüberschreitung automatisch beendet“).
- `catquiz::set_final_attempt_status(..., status::CLOSED_BY_TIMELIMIT)` setzt den Engine-Status 9.
- Das ist derselbe Abschlussweg wie bei jedem anderen Ende. Die Engine finalisiert den Versuch, also Ergebniszeile und
  Personenparameter.

**Host-eigener Zeitlimitpfad:** mod_adaptivequiz kennt dazu `resume_state::classify()` und
`resume_actions::end_expired()`.

- Dieser Pfad greift nur, wenn der Test ein eigenes Versuchslimit hat (`stop_reason::time_limit()` →
  `attempt_time_limit()`).
- Für die Tests des Labors ist das nicht der Fall. Ihr einziger Zeitabschluss ist die Task oben.

## Befund CLARA Run 103

Die 50 Versuche der Generation A wurden gestartet am 16.11. und beendet nach 24,008 bis 24,082 h. Das passt genau zu
einer Frist von 24 h ab Start, geprüft alle 5 Minuten (bis zu 5 Minuten Verzug durch den Takt plus Laufzeit der Task).
Die Stopgründe lauten „Versuch wurde wegen Zeitüberschreitung automatisch beendet“. Ein inaktivitätsbezogener Grenzwert
ist durch den Code ausgeschlossen.

## Konfigurierbarkeit und Entscheidung

**Was die gepinnte Engine anbietet:**

- **Site-weit** (`maximum_attempt_duration_hours`): Ändern hieße, das Timeout aller CATquiz-Tests der Installation zu
  ändern, nicht nur der des Labors. Das ist ausgeschlossen: keine globale Deaktivierung als Workaround.
- **Je Test** (`catquiz_includetimelimit = 1` mit eigenem Versuchslimit):
  - Das ginge, schaltet aber zugleich das Zeitlimit des Tests für die Person ein. Der Host zeigt es dann an, und es
    gilt auch bei `resume_state::classify()`.
  - Damit würde die gespielte Testform des Labors verändert, also das Forschungsdesign. Das ist ausgeschlossen.
  - Ein „kein Limit für diesen Test“ ist in der gepinnten Engine nicht ausdrückbar. Ein ausgeschaltetes Limit fällt auf
    den Site-Default zurück.

**Entscheidung für 0.7.85:**

1. **Kein Eingriff in die Policy.** Jeder Zeitabschluss eines Lab-Versuchs ist ein technischer Abschluss und kein Ende
   des Designs, denn die Designs des Labors setzen kein Zeitlimit.
   - `completion_contract` klassifiziert ihn als `technical/host_timeout`, die Recovery ist `keep`.
   - `result_validity` nimmt ihn nie als Ergebnis, auch wenn die Form vollständig gezählt wurde (ausdrückliche Policy).
   - Die Trace bleibt als Diagnose erhalten.
2. **Kein automatischer Neustart.**
   - Ein Resume sammelt den Versuch ein und startet keinen neuen (`host_timeout_test`).
   - Eine neue Generation gibt es nur durch eine ausdrückliche Entscheidung (`cli/sitting_recovery.php
     --decision=newgeneration`); die Vorgänger bleiben erhalten.
3. **Policy im Manifest** (`host_time_limit::record()`): bei Provisionierung und bei Resume, jeweils mit Quelle, Frist,
   Bezug, Task und Zeitplan.
   - `cli/time_limits.php --runid=N` zeigt je offenem Versuch die Frist oder „nicht bekannt“.
   - Der Pipeline-Tick warnt höchstens stündlich je Run im Run-Log (`time_limit_near`), wenn offene Versuche weniger als
     2 h vor der Frist stehen oder sie überschritten haben.
   - Die Pause des Labors hält die Frist nicht an. Das steht so in Manifest und CLI.
4. **Upstream:** Ein ausdrückliches „kein Limit“ je Test, ohne das Zeitlimit für die Person einzuschalten, braucht
   local_catquiz selbst. Der Issue-Entwurf liegt in den Nachweisen (`engine/issue-entwurf-zeitlimit-je-test.md`), für
   beide Linien. Hier wird nichts gepatcht.

**Folge für Langläufe:** Eine CLASSIC-Bearbeitung mit 2500 Items muss auf CLARA innerhalb von 24 h ab dem Start ihres
Engine-Versuchs beendet sein.

- Mit den gemessenen Latenzen ist das bei 1 Worker unkritisch (etwa 37 min Serverzeit).
- Bei 20 parallelen Workern ebenfalls (etwa 3 h).
- Kritisch sind lange Pausen eines Experiments mit offenen Versuchen. Die Warnung im Run-Log und das CLI zeigen sie
  rechtzeitig.


## Nachtrag 27.11.2026 abends: Policy der Engine je Test (local_catquiz #178, 78043b8f / 284b538c)

Die Engine bietet jetzt `local_catquiz\local\attempt\expiry_policy` an (`doc/issue-178-ablaufpolicy.md` der
Engine). Die Reihenfolge der Regeln:

1. **Policy des Tests `catquiz_expirypolicy`:**
   - `none`: Der Task schließt den Versuch nie.
   - Sekunden > 0: genau diese Grenze.
   - Es gibt kein Formularfeld, und der Person wird kein Zeitlimit angezeigt.
2. **Zeitlimit des Tests:** Limit × 1,5.
3. **Standard der Site:**
   - 0 heißt keine Grenze.
   - Ist er nicht gesetzt, gelten jetzt 24 h; vorher wurde aus `intval(false)` 0, also keine Grenze.

**Abschluss unter der Sperre:** Der Task schließt unter der Sperre der Itemadministration. Den Zeitlimit-Status
bekommt nur, was er selbst geschlossen hat.

**Was das Lab tut** (`host_time_limit::keep_open()`):
- **Wann:** bei der Provisionierung und bei jedem Resume (`record()`).
- **Was:** Es setzt für den Test seines Runs die Policy `none`. Das ist genau der Wunsch des Issue-Entwurfs aus den
  Nachweisen, den die Engine inzwischen umgesetzt hat.
- **Was es nicht ändert:**
  - Die Designs bleiben ohne Zeitlimit.
  - Die Person sieht kein Limit.
  - Andere Tests der Site behalten ihre Regeln.
  - Es gibt keine globale Abschaltung.
- **Lesen der Policy:** `policy()` liest sie dann über die Engine selbst (`enginesource`, z. B. `test-policy-none`).
- **Manifest:** Es hält zusätzlich fest, ob und wie viele Settings-Zeilen gesetzt wurden (`keptopen`).
- **Pins ohne die Klasse:** Ohne `expiry_policy` wird nichts geschrieben, und es gelten die Regeln oben.

**Was die Policy nicht ändert:**
- Schließt ein Host einen Lab-Versuch trotzdem durch Zeitablauf, etwa einen Run, der vor 0.7.85 provisioniert und
  noch nicht wieder aufgenommen wurde, bleibt es beim Abschlussvertrag: `technical/host_timeout`, kein Ergebnis, kein
  automatischer Neustart.
- Für Run 103 auf CLARA heißt das: Die geschlossenen Versuche bleiben geschlossen.
- Ein Resume setzt die Policy für die offenen Versuche des Runs und verhindert damit weitere Zeitabschlüsse.

**Test:** `host_time_limit_test`
- liest alle Quellen über die Engine;
- `keep_open()` setzt `none`, und die Site-Einstellung bleibt unberührt;
- ist der Standard der Site nicht gesetzt, gilt 24 h;
- bei der Provisionierung wird der Test offen gehalten, und es gibt keine Warnung.
