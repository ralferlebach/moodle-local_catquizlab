# Zeitangaben im Fortschritt: Start, verstrichen, Ende, Rest (#125)

Code: `classes/local/eta.php`, aufgerufen von `live_progress::snapshot()` — also
einmal je Poll-Zyklus (2 s) und beim Seitenaufbau, mit denselben Zählungen wie
der Fortschrittsbalken. Ausgeliefert über `local_catquizlab_live_status`
(`progress.time`), angezeigt im Fortschrittskasten (`data-region="catquizlab-eta"`).

## Die vier Werte

| Wert | Quelle | Verhalten |
|---|---|---|
| Started | früheste `started`-Zeile der Ausführungshistorie (`local_catquizlab_attemptlog`) über alle Läufe | persistiert; springt bei Reload/Poll nicht. Vorher: „Noch nicht — Start angefordert …“ (`run_log` `start_requested`) |
| Elapsed | jetzt − Start; nach Abschluss: Ende − Start | |
| Estimated finish | jetzt + Rest; nach Abschluss: letzte `collected`/`failed`-Zeile („Beendet …“) | |
| Remaining | Restarbeit / Kapazität, mit Spanne | gerundet: < 2 min Sekunden, < 2 h Minuten, sonst 5 min |

Zeiten in der Zeitzone des Betrachters, Zone in Klammern.

## Methode

1. **Restarbeit je Lauf** = (offene Bearbeitungen − laufende + ½ · laufende) ×
   mittlere Dauer der beendeten Ausführungen *dieses* Laufs (`runtimems` der
   Historie). Strategien unterscheiden sich stark, darum je Lauf.
   - Lauf noch ohne ≥ 3 beendete Ausführungen: Mittel seiner Strategie aus
     den anderen Läufen; sonst Mittel des Experiments → **vorläufig**.
2. **Kapazität** = Summe der Laufzeiten aller im Fenster beendeten Ausführungen
   / Fensterlänge (Fenster = max(10 min, 3 × mittlere Dauer), höchstens seit
   Start). Das ist die tatsächlich erreichte Parallelität — inklusive
   Startstaffel, Pausen zwischen Claims und Workern, die andere Experimente
   bedienen. Nichts beendet, aber Bearbeitungen laufen (Bearbeitungen länger
   als das Fenster): Kapazität = Zahl laufender Bearbeitungen.
3. **Rest** = Σ Restarbeit / Kapazität. Läufe in Warteschlangenreihenfolge
   (`job_claim`: älteste zuerst); das Ende eines Laufs = kumulierte Restarbeit
   bis einschließlich dieses Laufs / Kapazität.
4. **Spanne** = ± 1,96 · Standardfehler der Restarbeit (aus Streuung der
   Dauern je Lauf), mindestens ± 10 %.

## Zuverlässigkeit (`reliability`)

| Wert | Wann | Anzeige |
|---|---|---|
| `none` | nichts geplant | „Nichts zu schätzen“, alle Werte „—“ |
| `calculating` | noch kein Start oder < 3 beendete Ausführungen | „Wird berechnet …“ mit Grund |
| `provisional` | mindestens ein offener Lauf ruht auf dem Experimentmittel | Schätzung, als vorläufig markiert |
| `estimate` | alle offenen Läufe mit eigener bzw. Strategie-Dauer | „≈ …“ mit Spanne |
| `unreliable` | angehalten (Circuit Breaker/Fehler), pausiert, oder keine Kapazität (nichts beendet, nichts läuft) | keine laufende Prognose, Grund |
| `final` | nichts mehr offen | tatsächliches Ende, „Nichts mehr offen“ |

## Aktualität

Jeder Snapshot trägt seinen Zeitpunkt („Stand …“). Schlägt ein Poll fehl,
markiert die Seite die Angaben sofort als möglicherweise veraltet
(`eta:stale`), bevor nach drei Fehlern das Aktualisieren stoppt.

## Tests

- `tests/eta_test.php` (fake clock): vor dem Start, Kalibrierung, Läufe mit
  unterschiedlichen Dauern, Warteschlangenreihenfolge und vorläufige Schätzung,
  wechselnder Durchsatz (Start bleibt), blockiert/pausiert/ohne Kapazität,
  abgeschlossen (Ende unabhängig vom Betrachtungszeitpunkt), Snapshot.
- `tests/playwright/progress_time.spec.js`: laufend, anlaufend, blockiert,
  abgeschlossen, ohne Daten, kleiner Bildschirm; Daten aus
  `cli/seed_progress.php --state=…` (der Spec legt sie selbst an).
