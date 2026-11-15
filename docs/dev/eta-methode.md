# Zeitangaben im Fortschritt: eigene Restarbeit, Fertigstellung, Start, Ende (#125, #142, #160)

Code: `classes/local/eta.php` (Schätzung), `classes/local/run_figures.php` (eine Lesung aller Zahlen),
aufgerufen von `live_progress::snapshot()`: einmal je Poll-Zyklus (2 s) und beim Seitenaufbau, aus derselben Lesung
wie Fortschrittsbalken und Fragenzähler. Ausgeliefert über `local_catquizlab_live_status` (`progress.time`), angezeigt
im Fortschrittskasten (`data-region="catquizlab-eta"`) und in der Lauftabelle.

## Zwei Fragen, getrennt beantwortet (#160)

Bis 0.7.59 stand in der Spalte „Remaining“ eines Laufs die Restarbeit **aller Läufe mit kleinerer ID plus seiner
eigenen**, geteilt durch die Kapazität. So bekam ein Lauf bei 997/1000 die 2 h 35 min der beiden Läufe vor ihm
(Screenshot 14.11.2026, Läufe #84–#87). Die Reihenfolge war dazu falsch: Worker übernehmen nicht nach Lauf-ID.
Seit 0.7.60 sind es zwei Größen mit eigener Überschrift.

| Größe | Spalte | Woraus | Ändert sich durch andere Läufe? |
|---|---|---|---|
| **Eigene Restarbeit** | „Eigene Restarbeit“ | offene Testbearbeitungen und Fragen *dieses* Laufs × *seine* mittlere Dauer | nein |
| **Eigene Bearbeitungszeit** | darunter | eigene Restarbeit / Worker, die den Lauf gerade bearbeiten | nein (leer ohne Worker) |
| **Fertig bis (inkl. Warten)** | „Fertig bis“ | Warteschlange der Installation in Claim-Reihenfolge über die genutzte Kapazität | ja: Arbeit davor |

Oben im Kasten: **Verbleibend (inkl. Warten)** und **Voraussichtliches Ende (alle Läufe)** = bis der *letzte* Lauf
des Experiments fertig ist; dazu die eigene Restarbeit des ganzen Experiments.

## Eigene Restarbeit eines Laufs

- **Testbearbeitungen:** geplant − gesammelt − endgültig gescheitert. Gescheiterte zählen weder als erledigt noch als
  offen.
- **Fragen:** aus `question_progress` (#141), mit Art der Angabe:
  - genau (fester Test: der ganze Pool),
  - geschätzt (Mittel der gesammelten Testbearbeitungen),
  - höchstens (globales Maximum, solange nichts gesammelt ist),
  - unbekannt.

  Eine laufende Testbearbeitung zählt mit ihren schon gespeicherten Antworten, nie doppelt (die neueste
  Engine-Versuchszeile der Person).
- **Arbeitszeit eines Workers** = Einheiten × mittlere Dauer je Testbearbeitung.
  - **Einheiten:** wartende Testbearbeitungen ganz. Laufende nach Fragen anteilig (600 von 625 Fragen = 1/25), wo
    die Fragenzahl bekannt ist; sonst nach Laufzeit gegen die mittlere Dauer. Mindestens 10 %, nach Fragen mindestens
    eine Frage.
  - **Dauer:**
    1. die eigene des Laufs (≥ 3 beendete Ausführungen, `runtimems` der Historie);
    2. sonst die seiner Strategie *im selben Experiment*;
    3. sonst das Tempo der laufenden Testbearbeitungen dieses Laufs (Sekunden je Frage × Fragen je
       Testbearbeitung), wenn die Fragenzahl genau oder geschätzt ist;
    4. sonst die des Experiments, aber als Zeit je Frage aller seiner beendeten Testbearbeitungen × Fragen je
       Testbearbeitung dieses Laufs; ist die Fragenzahl nur eine Obergrenze, höchstens das, und höchstens die
       Dauer je Testbearbeitung der anderen;
    5. sonst das Tempo mit der Obergrenze.

    Bis zur Messreihe unten kam das Experimentmittel je Testbearbeitung vor dem eigenen Tempo. Ein Lauf mit FASTEST
    (20 Fragen) bekam so die Dauer der CLASSIC-Testbearbeitungen (36 bis 120 Fragen) und eine bis fünffach zu lange
    Prognose.
  - **Ab fünf beendeten:** Liegen mehr als fünf vor, ist das Mittel der letzten fünf die Schätzung, der Abstand zum
    Mittel aller bleibt als Unsicherheit (Drift, unten). Die ersten Testbearbeitungen eines Laufs, neben denen eines
    anderen Laufs gespielt, dauerten bis zu anderthalbmal so lange wie seine späteren.

    Die Pools sind je Experiment: Derselbe Lauf bekommt auf jeder Seite dieselbe Dauer.

## Fertigstellung: die Warteschlange durchgespielt

1. **Claim-Reihenfolge** wie `job_claim::claim_next()`: `nextruntime`, dann `timecreated`, dann `id`, über *alle*
   übernehmbaren Läufe der Installation (bereit oder laufend, weder pausiert noch angehalten), auch die anderer
   Experimente. Die wartenden Testbearbeitungen werden je Lauf und Fälligkeit gruppiert: Die frischen eines Laufs
   sind eine Gruppe, jeder Retry mit eigener Fälligkeit eine weitere.
   - Gruppen mit gleicher Fälligkeit ordnet die früheste Anlagezeit, dann die kleinste ID. Sind die Testbearbeitungen
     zweier Läufe verschränkt angelegt, ist das eine Näherung.
   - Eine Testbearbeitung mit abgelaufenem Lease gilt als nach dem Backoff (`RETRY_BACKOFF`) wieder eingereiht,
     also am Ende der Schlange.
2. **Kapazität** = Belegzeit im Messfenster / Fensterlänge, höchstens so viele Slots, wie Worker laufen (#160 AK07).
   - **Fenster:** max(10 min, 3 × mittlere Dauer), höchstens seit Beginn der Belegung im Fenster.
   - **Belegzeit:** Von einer Ausführung, die vor dem Fenster begann, zählt nur der Teil im Fenster. Laufende
     Testbearbeitungen zählen bis jetzt. Bis 0.7.59 zählte die ganze Dauer einer langen Testbearbeitung, die im
     Fenster endete. Das ergab mehr Slots, als Worker existierten.
   - **Ohne Messung:** Ist nichts gemessen, aber Testbearbeitungen laufen, sind diese die Kapazität.
3. **Durchspielen:** Erst die laufende Arbeit, sie belegt die Slots schon. Dann jede Gruppe in Claim-Reihenfolge:
   - Uhr = max(Uhr, Fälligkeit) + Einheiten × Dauer / Kapazität.
   - Ein Lauf ist fertig, wenn seine letzte Gruppe abgearbeitet ist, plus (1 − 1/Kapazität) einer Testbearbeitung:
     Die letzte wird übernommen, wenn die Schlange sie erreicht, und dauert dann eine ganze Testbearbeitung. Drei lange
     Testbearbeitungen auf vier Workern brauchen eine Testbearbeitung lang, nicht drei Viertel davon.
   - Ein Lauf nur mit laufenden Testbearbeitungen ist fertig, wenn die zuletzt begonnene es ist: mindestens ihr
     Anteil am Durchschnitt, mindestens mittlere Dauer − Zeit seit ihrem Beginn. Bis zur ersten Messreihe (siehe
     Kalibrierung) galt der Durchschnitt der laufenden; vier im Minutenabstand begonnene Testbearbeitungen sind aber
     nicht fertig, wenn ihr Durchschnitt es wäre. Die Prognose lag am Ende eines Laufs um die Hälfte zu früh.
   - Arbeit anderer Läufe liegt vor ihm dann nicht: Seine Prognose ruht nur auf seiner eigenen Dauer und ist
     `estimate`, sobald diese gemessen ist.
   - **Der letzte Lauf der Installation:** Gehört ihm die letzte Arbeit, leert sich die Installation mit seinem
     Ende. Ist sie durch die Prozessoren begrenzt, werden seine letzten Testbearbeitungen dann schneller, weil weniger
     sich die Prozessoren teilen; ist sie durch die Slots begrenzt, nicht. Die Prognose nimmt die Mitte zwischen
     „prozessorgebunden“ (die offene Arbeit über die Slots verteilt, ohne Schlussstück) und „slotgebunden“ (wie oben);
     die Spanne enthält beide. Gemessen auf diesem Rechner (2 CPUs, Moodle und vier Browser auf einer Maschine) lag
     das Ende des letzten Laufs vorher bis zur Hälfte zu spät.
4. **Spanne** = ± 1,96 Standardfehler der Arbeit bis zu diesem Lauf, mindestens ± 10 %, höchstens ± 100 %.
   - **Kleine Stichprobe:** Aus drei oder fünf Testbearbeitungen ist die Streuung selbst unsicher. Statt 1,96 gilt das
     95-%-Quantil der t-Verteilung mit n − 1 Freiheitsgraden (4,303 bei 3, 2,776 bei 5, 2,228 bei 11 beendeten); es
     wird in die Streuung eingerechnet (`eta::t975()`).
   - **Drift:** Die mittlere Dauer der letzten fünf beendeten Ausführungen (`run_figures::RECENT`) gegen die aller.
     Der Abstand geht quadratisch in den Standardfehler ein: Wird die Installation langsamer oder schneller, als es
     die ganze Stichprobe sagt, wird die Spanne breiter, statt falsch eng zu bleiben.
   - **Last:** Eine Testbearbeitung dauert so lange, wie die Installation sie lässt. Über die gesehene Streuung
     hinaus mindestens 30 % einer Testbearbeitung / √n (`eta::LOAD_SE`).
   - **Geliehen je Frage:** ± 35 % (`eta::BORROWED_SE`), bei einer bloßen Obergrenze ± 25 % der gekappten Dauer.
   - **Geliehene Dauer** (Experimentmittel): mindestens die Streuung einzelner Testbearbeitungen und 25 % des
     Mittels.
   - **Tempo:** Aus k Fragen ist es umso sicherer, je mehr es sind: relativer Standardfehler 0,5 · √(5 / k),
     mindestens 30 % (`eta::MIN_PACE_SE`). Die Engine schätzt nach jeder Antwort über alle bisherigen neu, spätere
     Fragen dauern länger als die ersten; gemessen lag das Tempo bei einem festen Test mit 120 Fragen, solange keine
     Testbearbeitung beendet war, 22 bis 51 % zu kurz. Bei einer bloßen Obergrenze der Fragen ± 50 %.
5. **Experiment** = sein spätester Lauf. **„Wartet hinter …“** nennt die Arbeit anderer Läufe vor der letzten
   Testbearbeitung des Laufs.

## Kalibrierung (Messreihe 15.11.2026)

`cli/smoke.php --strategies=classic,fastest --eta-probe=FILE [--watch-page=FILE]` auf diesem Rechner (2 CPUs, Moodle,
PostgreSQL und vier Worker-Browser auf einer Maschine), lokal, ohne andere Last. Alle 5 s ein Stand; am Ende jede
Prognose eines noch offenen Laufs gegen sein tatsächliches Ende. „In der Spanne“: |Prognose − tatsächlich| ≤ Spanne.
Auswertung je Phase: Start (nichts Eigenes beendet), Mitte, Ende (nur noch laufende Testbearbeitungen).

| Lauf | Code | Testbearbeitungen je Lauf | Prognosen | Median relativer Fehler | 90. Perzentil | in der Spanne |
|---|---|---|---|---|---|---|
| 7 | vorher | 3 | 68 | 0,48 | 2,18 | 96 % (Spannen meist ± 84–100 %) |
| 11 | vorher | 12 | 234 | 0,33 | 0,94 | 59 % |
| 14 | jetzt | 3 (wie im Worker-CI) | 63 | 0,34 | 0,97 | 100 % |
| 16 | jetzt, mit angemeldetem Browser daneben | 3 | 66 | 0,44 | 0,95 | 96 % |
| 15 | jetzt | 12 | 238 | 0,18 | 0,71 | 80 % |

Lauf 15 nach Phasen: Start 98 % in der Spanne (Median-Fehler 0,15, mittlere Spanne ± 34 %); Mitte, vorläufig (Tempo
oder geliehene Dauer), 100 %; Mitte mit eigener Dauer 44 % (Median-Fehler 0,33, Spanne ± 22 %); Ende 76 %
(Median-Fehler 0,09).

**Bekannte Grenze:** Die Verfehlungen der Mitte sind fast alle der FASTEST-Lauf, der nach dem CLASSIC-Lauf allein
ausläuft: Seine Prognose liegt dann bis zum Doppelten zu spät, nie zu früh. Seine ersten Testbearbeitungen liefen
neben denen des CLASSIC-Laufs und dauerten länger als die späteren; die Dauer einer Testbearbeitung hängt hier stark
davon ab, wie viel gleichzeitig läuft. Die Drift- und Lastkomponente und die Mitte für den letzten Lauf mildern das,
heben es nicht auf. Auf einer Installation, deren Worker die Prozessoren nicht mit Moodle teilen, ist der Effekt
kleiner zu erwarten; gemessen ist das nicht.

## Zuverlässigkeit (`reliability`)

| Wert | Wann | Anzeige |
|---|---|---|
| `none` | nichts geplant | „Nichts zu schätzen“, alle Werte „—“ |
| `calculating` | noch kein Start; keine Dauer (< 3 beendet, kein Tempo); Lauf nicht gestartet; Worker da, aber noch kein Durchsatz | „Wird berechnet …“ mit Grund |
| `provisional` | eigene Dauer fehlt (Experimentmittel oder Tempo) bei diesem Lauf oder bei Arbeit vor ihm | Schätzung, als vorläufig markiert |
| `estimate` | eigene oder Strategie-Dauer bei ihm und allem davor | „≈ …“ mit Spanne |
| `unreliable` | angehalten (Circuit Breaker/Fehler), pausiert, oder keine Kapazität (kein Worker, nichts läuft) | keine Prognose, Grund; zählt nicht von selbst herunter |
| `final` | nichts mehr offen | tatsächliches Ende, Rest 0 |

## Aufwand

Je Poll eine feste Zahl von Abfragen, unabhängig von der Zahl der Läufe (#160 AK12), alles `GROUP BY`:
- Testbearbeitungen je Lauf und Status;
- beendete Ausführungen je Lauf;
- die letzten fünf beendeten Ausführungen je Lauf (`ROW_NUMBER() OVER (PARTITION BY runid …)`, PostgreSQL und
  MariaDB/MySQL 8);
- erste Starts;
- Belegzeit im Fenster;
- laufende Testbearbeitungen mit ihren Starts (Summe und spätester);
- Gruppen der Warteschlange;
- Fragen über `question_progress` (zwei Abfragen plus Vorladen der Grenzen).

`eta_test::test_the_query_count_does_not_grow_with_runs` prüft 1, 10 und 40 Läufe.

## Aktualität

Jeder Snapshot trägt seinen Zeitpunkt („Stand …“). Schlägt ein Poll fehl, markiert die Seite die Angaben sofort als
möglicherweise veraltet (`eta:stale`), bevor nach drei Fehlern das Aktualisieren stoppt.

## Tests

- **`tests/eta_test.php`** (gestellte Uhr, echte Zeilen):
  - Screenshot #84–#88: Die alte Methode gibt #86 über 2 h; die neue gibt ihm 3 Testbearbeitungen, ≈ 75 Fragen und
    3 min Worker-Zeit und Fertigstellung in Minuten.
  - Umnummerieren und Hinzufügen anderer Läufe lässt die eigene Restarbeit unverändert.
  - Claim-Reihenfolge statt Lauf-ID; Retry mit Backoff zuletzt.
  - konkurrierendes Experiment;
  - lange Testbearbeitungen ohne Schein-Slots;
  - keine Kapazität ohne Countdown;
  - pausiert und angehalten;
  - Kalibrierung: t-Quantile gegen Tabellenwerte, Streuung aus drei Testbearbeitungen, Drift der letzten fünf;
    Ende der zuletzt begonnenen laufenden Testbearbeitung; nur laufende leihen nichts;
  - abgeschlossen; abgelaufenes Lease;
  - Abfragezahl 1/10/40 Läufe.
- **`tests/question_progress_test.php`:** 625 Fragen bei 0/1/100/624/625, 20 gegen 625, Retry, früher Stopp,
  unbekannt.
- **`cli/smoke.php --strategies=… --eta-probe=…`** (Worker-CI): mindestens zwei Läufe mit echten Workern.
  - Während des Laufs alle 5 s ein Snapshot, geprüft auf innere Widerspruchsfreiheit.
  - Am Ende die Prognosen gegen die tatsächlichen Enden: mittlerer absoluter Fehler, Median des relativen Fehlers,
    Anteil Enden in der Spanne.
- **`tests/playwright/progress_time.spec.js`:** die getrennten Spalten, die Hilfe zu den Zeitbegriffen, Tastatur,
  kleiner Bildschirm. Poll und Seite zeigen dieselben Werte.
