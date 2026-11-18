# CLARA, 15.11.2026: was zu welchem Issue gehört (#110 DoD-CLARA-04)

Ein Lauf auf CLARA zeigte vier Dinge zugleich: Antwort-POSTs von rund 60 s, Zeitüberschreitungen des Browsers
(`submit_cdp_timeout`), einen Lastschutz bei 12 statt 25 Workern und Läufe, die als „running“ galten, aber nicht
spielten. Sie haben verschiedene Ursachen und werden in verschiedenen Issues behoben. Diese Seite trennt sie.

| Issue | Gegenstand | Ursache liegt | Hier belegt durch |
|---|---|---|---|
| CatQuiz #27 | `strategy:select_question` 56–65 s bei 2496–2500 Kandidaten, `pool:cache_lookup` ~12 ms | in der Engine (local_catquiz), Auswahl der nächsten Frage | nichts zu beheben im Plugin; die Zeit steht im Trace der Engine und in der Request-Zeile (`durationms`) |
| #110 | was ein langer oder fehlgeschlagener POST beim Worker anrichtet: Diagnose, Korrelation, Abgleich, keine doppelte Antwort | im Worker (Umgang mit Zeitüberschreitung) und in der Diagnose | `docs/dev/antwortuebermittlung.md`; `worker/test/submit.test.js`; Worker-E2E „Issues 134/155/110“ (verlorene Antwort, verlorene Anfrage, Antwort nach Ablauf der Wartezeit; je Sitzung genau ein Abgleich je Fehler, jede Frage genau einmal) |
| #162 | Lastschutz: 25 eingestellt, 12 wirksam; kontrollierte Freigabe durch eine Person | Folge der Fehler (der Schutz halbiert nach Fehlern), nicht ihre Ursache | `tests/loadguard_release_test.php` (PHPUnit); die Freigabe im echten Betrieb nur auf CLARA |
| #163 | Warteschlange: Claims nur für den Halter der Reihe, Status „running“ nur, wenn gespielt wird | in der Reihenfolge der Claims | `docs/dev/claim-reihenfolge.md`; Worker-E2E „the holder first, the run started outside after it, nothing played beside the line“; Claims-Schritt (11 und 25 Claimer, 0 doppelt) |
| #164 | ob Request-Tracing und Debuglevel unter Last selbst Zeit kosten | Messfrage, kein Fehler | `docs/dev/tracing-messung.md`, `cli/trace_matrix.sh`; die Messung auf CLARA steht aus |

## Was nicht vermischt wird

- **Ein langer POST ist kein Fehler des Workers.** Er wartet 30 s auf die Antwortseite. Kommt sie später, fragt er
  den Server, ob die Antwort gespeichert ist, und macht an der richtigen Stelle weiter. Die 60 s selbst verkürzt nur
  die Engine (CatQuiz #27).
- **Der Lastschutz ist keine Ursache der Zeitüberschreitungen.** Er reagiert auf sie. Ihn abzuschalten beseitigt
  keinen langen POST (#162 verlangt eine Freigabe durch eine Person, nicht das Abschalten).
- **Die Diagnose ist nicht als Ursache behauptet.** Ob Tracing messbar kostet, beantwortet die Messung von #164,
  nicht eine Vermutung.
- **Reihenfolge und Last sind getrennt.** Dass ein Lauf nicht spielte, lag an der Vergabe (#163), nicht an der
  Kapazität.

## Was nur auf CLARA zu belegen ist

- die Rekonstruktion der Fälle aus Lauf 51 mit Apache-Logs, PHP-Fehlerlog und `requests/*.jsonl` (#110 AK-CLARA-01,
  Ursache client- oder serverseitig);
- die Lastmatrix (1 Worker / normale Parallelität, classic / allsubs / kurzer Modus) mit Server- und DB-Grenzen;
- die Freigabe 25 → 12 → 25 mit echtem Betrieb (#162);
- die 2×2-Messung Tracing × Debuglevel (#164).

Wo die Spuren dafür liegen, steht in `docs/dev/antwortuebermittlung.md`, Abschnitt „Ein HTTP 500 finden“.
