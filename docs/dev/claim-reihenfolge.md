# Claim-Reihenfolge: eine Regel für Warteschlange und Worker (#163, 0.7.65)

Code: `classes/local/execution_queue.php` (`holder()`, `next_holder()`, `take_turn()`, `adopt()`, `sittings_done()`,
`take_front()`/`release_front()`), `classes/external/job_claim.php` (`claim_next()`, `next_of()`, `next_due()`),
`attempt_scheduler::queue_breakdown()` (`waitingturn`, `holder`), `worker_launcher::launch_pool()`,
`run_figures::read()`/`eta::forecast()` (Rang in der Reihe), `experiment_status`, `status_report::run()`,
`situation`, `cli/smoke.php`, `cli/claim_race.php`. Tests: `tests/claim_order_test.php`.

## Befund (CLARA, 15.11.2026)

- Die Ausführungswarteschlange (`execqueue`) hielt Experiment #21 seit dem 14.11. 19:50 UTC als `running`. Seine Runs
  #96–#101 standen READY mit je 50 wartenden Testbearbeitungen; keine davon war je vergeben worden.
- Alle elf Worker spielten Run #88 von Experiment #19.
- Die ersten 50 Claim-Kandidaten: 40 aus #88, 10 aus #84, keiner aus #21.

Ursache:
- `execution_queue::advance()` hielt die Reihe für #21 und versprach „ein Experiment nach dem anderen“.
- `job_claim::claim_next()` las dagegen alle READY/RUNNING-Runs der Installation, sortiert nach
  `nextruntime, timecreated, id`. Eine nie gestartete Testbearbeitung hat `nextruntime = 0`. Ältere Bestände gingen
  deshalb jedem neueren Experiment und jedem fälligen Retry vor, solange es sie gab.
- Die Reihe war eine Aussage, die nichts durchsetzte.

`tests/claim_order_test.php::test_claims_go_to_the_holder_of_the_line_and_never_to_older_runs_outside_it` baut den
Stand im Kleinen nach:
- #19 mit #84, #86, #87 und #88, davon 11 Testbearbeitungen von #88 in Arbeit, mit Lease;
- #21 an der Spitze, mit 6 × 5 unberührten Testbearbeitungen.

Der Test liest zuerst die alte Abfrage: Ihre ersten 50 enthalten keinen Run von #21. Danach prüft er die neue Regel.

## Die Regel

1. **Ein Halter.**
   - Der Halter ist das Experiment des `running`-Eintrags der Reihe (`holder()`).
   - `job_claim` gibt nur dessen Testbearbeitungen aus. Kein Claim überspringt den Halter zugunsten eines anderen
     Experiments.
2. **Außerhalb gestartet heißt: hinten eingereiht.**
   - Runs, die außerhalb der Reihe gestartet wurden, landen am Ende der Reihe (`adopt()`, Grund `adopted`). Das sind:
     - ein einzelner Run auf der Run-Seite;
     - „Läufe starten“;
     - „Anlegen und starten“;
     - Bestände von vor 0.7.65 (auf CLARA #19).
   - Reihenfolge untereinander: die früheste wartende Testbearbeitung zuerst.
   - Nur Testbearbeitungen, die ein Worker nehmen könnte, zählen: Run READY/RUNNING und nicht pausiert, weder von Hand
     noch vom Schutzschalter. Ein Experiment, dessen Arbeit nur in pausierten Runs liegt, bekommt seinen Platz, sobald
     ein Run weiterläuft; es kann die Reihe nicht für eine Pause halten, die vor seinem Zug gesetzt wurde.
   - Beim Start sagt die Meldung, wer die Reihe hält (`queue:startedoutside`).
3. **Fertig heißt: alles vergeben, nichts in Arbeit.**
   - Hat der Halter keine vergebbare Testbearbeitung mehr und keine in Arbeit, verlässt er die Reihe
     (`sittings_done()`, Grund `sittings-done`). Ein blockiertes Experiment geht mit Grund `blocked`.
   - Was danach noch folgt, braucht keinen Worker: Einsammeln, Aggregation, eine Person für einen fehlgeschlagenen Run.
   - Ein Run, den eine Person pausiert hat, hält die Reihe. Das ist eine Entscheidung, keine Störung.
   - Ein Run, den der Schutzschalter hält, hält sie nicht: Er wartet auf eine Person, und alles dahinter würde mitwarten.
   - Ein blockiertes Experiment spielt seine Runs, die noch Arbeit ausgeben, zu Ende und geht dann. So war es auch
     vor 0.7.65; neu ist, dass es dabei die Reihe hält statt neben dem nächsten zu laufen.
4. **Die Reihe rückt mit dem Claim weiter, nicht erst mit dem Takt.** Findet ein Claim beim Halter nichts, ruft er
   `take_turn()` auf.
   - Das schließt einen fertigen Halter und reiht Außenstehende ein.
   - Es befördert den vorderen Eintrag, wenn dessen Runs schon bereit sind.
   - Vorbereiten (#151) bleibt Sache des Takts: Ein Claim bereitet nichts vor und überspringt keinen Eintrag, der noch
     vorbereitet werden muss.
5. **Spitze für Werkzeuge, offen und befristet.**
   - `cli/smoke.php` und `cli/claim_race.php` müssen auf einer beschäftigten Installation sofort spielen. Sie nehmen
     dafür die Spitze (`take_front()`).
   - Der Grund `front:<bis>:<wer>` steht in der Reihe, Liste und Log sagen es.
   - Der verdrängte Halter wartet vorn (Grund `preempted`) und geht danach weiter. Seine Testbearbeitungen in Arbeit
     bleiben unberührt.
   - Am Ende gibt das Werkzeug die Spitze zurück (`release_front()`, auch beim Abbruch über einen Shutdown-Handler).
   - Läuft die Frist ab, etwa weil das Werkzeug mit `kill -9` beendet wurde, gilt der Eintrag nicht mehr als Halter.
     Der nächste Schritt schließt ihn (`front expired`), und hat das Experiment noch Arbeit, reiht die Reihe es hinten
     ein.
   - Nimmt ein zweites Werkzeug die Spitze, während das erste sie hält, bleibt die Spitze des ersten erhalten (Grund
     `preempted: <zweites> | front:<bis>:<erstes>`). Gibt das zweite sie zurück, hält das erste wieder die Spitze, mit
     seiner ursprünglichen Frist. Gibt das erste sie zurück, solange es verdrängt wartet, wird sein Eintrag dort
     geschlossen. Läuft seine Frist während des Wartens ab, wird er geschlossen, sobald er vorn ist (`front expired`).
     Test: `test_a_front_displaced_by_another_tool_keeps_its_time_limit`.
   - Bis 0.7.64 stellte der Smoke-Test stattdessen alle fremden Testbearbeitungen eine Stunde zurück und gab ihnen
     danach ihre Zeit wieder (#120). Das ist entfallen. `queue_deferral::restore()` gibt nur noch Reste eines alten,
     abgebrochenen Laufs zurück.

## Reihenfolge innerhalb des Halters (AK4)

- **Platz** = Anlage der Testbearbeitung (`timecreated`, dann `id`).
- **`nextruntime`** entscheidet nur, ab wann eine Testbearbeitung vergeben werden darf.
- Ein Retry behält damit seinen Platz: Er wird nicht vor Ende seines Backoffs vergeben, danach aber vor allem, was
  später eingereiht wurde.
- Bis 0.7.64 ging jeder Retry hinter alle nie gestarteten Testbearbeitungen (`nextruntime = 0` zuerst). Ein Run konnte
  so Stunden nach seinen Geschwistern mit einem einzelnen Nachzügler enden.
- Eine Testbearbeitung, deren Person gerade woanders spielt, wird übersprungen; das ist unverändert seit 0.7.27.

## Suche ohne Schein-Leere (AK9)

`next_of()` liest zuerst die Runs des Halters, die Arbeit ausgeben (READY/RUNNING), und fragt dann nur deren
Testbearbeitungen ab, 50 je Seite.
- Gehört eine ganze Seite pausierten Runs, sucht die nächste Seite ohne diese Runs. Die Suche endet mit einer
  Testbearbeitung oder wenn alle Runs angesehen sind.
- Bis 0.7.64 füllten 50 Testbearbeitungen pausierter Runs das Fenster, und der Worker meldete eine leere Warteschlange.
  Test: `test_sittings_of_a_paused_run_do_not_hide_the_others`.

## Ein Worker, der nichts bekommt

`next_due()` zählt nur Testbearbeitungen des Halters und keine pausierten Runs: Darauf zu warten hieße, jede Sekunde
erneut zu fragen. Die Antworten:
- **Retry im Backoff:** die Sekunden bis dahin (wie seit dem Audit vom 11.11.).
- **Nichts mehr eingereiht, aber Testbearbeitungen in Arbeit:** `IN_PLAY_WAIT` = 20 s. Sind die letzten fertig, rückt
  die Reihe weiter, und der wartende Worker bekommt das nächste Experiment, statt gegangen zu sein.
- **Sonst:** 0, der Worker geht. Der Takt startet Worker neu, sobald der Halter Arbeit hat.

## Zähler, Status, Prognose: dieselbe Regel (AK2, AK7)

- **`queue_breakdown()`**
  - `claimable` sind nur die Testbearbeitungen des Halters, oder die des Experiments, das ein Claim jetzt zum Halter
    machen würde (`next_holder()`).
  - Neu ist `waitingturn`: Testbearbeitungen, die vergebbar wären, aber auf die Reihe ihres Experiments warten.
  - Der Launcher startet keine Worker für `waitingturn`. Gibt es nur solche, rückt er zuerst die Reihe
    (`take_turn()`).
- **Phase.** Ein Experiment hinter dem Halter heißt „Wartet, bis es an der Reihe ist“ (`queued`), auch wenn es schon
  gespielt hat oder noch Testbearbeitungen in Arbeit sind. Der Grund nennt den Platz und den Halter. „Sammelt“ würde
  das Gegenteil sagen.
- **Run-Karten:** „Wartet, bis sein Experiment an der Reihe ist“ bzw. „Spielt zu Ende, was läuft; wartet dann …“. Die
  Run-Karten kommen ohne Abfrage je Run aus (#160 AK12).
- **Lage:** „n Testbearbeitung(en) warten, bis ihr Experiment an der Reihe ist“ statt „nichts los“.
- **Leerlauf-Gründe des Takts:** `waitingturn`.
- **Reihe auf der Fortschrittsseite:** Jeder Eintrag sagt, wie er dorthin kam: aufgenommen, verdrängt, Spitze bis …
- **Prognose** (`run_figures`, `eta`): Die Warteschlange wird in Reihen-Reihenfolge durchgespielt.
  - Erst der Halter, mit Retries an ihrem Platz, sobald fällig.
  - Dann jedes Experiment dahinter, erst wenn das vorige nichts mehr auf den Slots hat.
  - Damit folgen die Fertig-Zeiten von #160/#158 der tatsächlich geltenden Policy (#160 AK04).

## Kosten und Indizes (AK9, DoD6)

Pro Claim mit Halter:
- eine Abfrage der Runs des Halters (Index `experimentid-status`);
- je Seite eine Abfrage seiner Testbearbeitungen (Index `runid-status`), sortiert über höchstens die eingereihten
  Testbearbeitungen dieser Runs.

`take_turn()` läuft nur, wenn der Halter nichts hergibt. Es kostet eine gruppierte Abfrage über die eingereihten
Testbearbeitungen (Index `status-runid`) und `experiment_runner::state()` je betroffenem Experiment.

Messung unter CLARA-ähnlichem Altbestand: siehe Abschnitt Messung (`cli/claim_bench.php`).

## Grenzen

- **Kein Mehr-Experiment-Betrieb.** Mehrere Experimente gleichzeitig fair zu bedienen, gibt es nicht. Das Issue
  verlangt so etwas nur als ausdrücklich freizugebende Option; beschlossen ist sie nicht.
- **Wechsel am Ende eines Experiments.** Die Reihe rückt erst, wenn die letzte Testbearbeitung des Halters fertig ist.
  Bei langen Testbearbeitungen stehen Slots so lange leer, das ist der Preis von „eins nach dem anderen“. Die Prognose
  rechnet diese Lücke ein.
- **Laufende Testbearbeitungen.** Was beim Update auf 0.7.65 schon gespielt wird (auf CLARA die 11 Testbearbeitungen
  von #88), läuft zu Ende. Eine Testbearbeitung, deren Lease abläuft, kommt in die Warteschlange zurück und wartet dann
  wie alle anderen ihres Experiments.
