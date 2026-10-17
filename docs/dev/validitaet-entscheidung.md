# Validität: welche Regel entscheidet, und was „kein Urteil“ heißt

Stand: 0.7.41 (Re-Audit vom 16.10.2026, AK 7).

## Entscheidung

Festgelegt vom Projektverantwortlichen am 07.10.2026, hier festgehalten, ohne
den Default zu ändern:

- **Standard ist die einheitliche Regel** (`validityrule=uniform`). Sie wendet
  die Definitionen der Engine (local_catquiz) auf jede Strategie gleich an:
  Ende nach dem Minimum, Mindestzahl Fragen (Test und Skala), Anteil richtiger
  Antworten 0 < f < 1 (#140), maximaler Standardfehler. Die Definitionen kommen
  aus der Engine, nicht aus catquizlab.
- **Das eigene Urteil der Engine steht wörtlich daneben**
  (`validityrule=engine`). Es erscheint als Spalte (`enginevalid`,
  `engineverdict`) und als Filter. Es kann als entscheidende Regel gewählt
  werden.
- `filter_semin` der Engine entscheidet, wann ein Test endet, nicht ob sein
  Ergebnis gilt. Es gehört nicht zu den Gültigkeitsregeln.
- Ergebnisse und Plots zeigen nur gültige Messungen.

Ein Wechsel des Defaults ist eine fachliche Entscheidung und wird nicht still
vorgenommen.

## Drei Zustände, nicht zwei

Jede Testbearbeitung ist unter der gewählten Regel genau eines:

| Zustand | Engine-Regel | Einheitliche Regel |
|---|---|---|
| `valid` | Engine sagt gültig | keine Regel verletzt |
| `invalid` | Engine sagt ungültig | mindestens eine Regel verletzt |
| `unknown` | Engine gibt **kein** Urteil | es fehlen nur Daten (`no_engine_data`, `trace_incomplete`) |

`unknown` zählt nie als `invalid`:
- **Zählungen:** `validity_counts()` führt `valid`, `invalid` und `unknown`
  getrennt, dazu `unknownreasons`. Ungültigkeitsgründe werden nur für
  `invalid` gezählt.
- **Filter:** `validity=invalid` zeigt nur ungültige, `validity=unknown` nur
  solche ohne Urteil. Der Schalter „nur ohne Urteil“ steht neben „gültig“,
  „ungültig“ und „alle“.
- **Abdeckung, Trichter, Plot-Hinweise:** Sie nennen „nicht beurteilbar“
  getrennt, mit Ursache.
- **Skalenebene:** Hat die Engine zu einer Skala kein Urteil, zählt das als
  `engine_unknown`, nicht als „ungültig“.
- **Export (Ebene Testbearbeitung, angehängt):** `validitystate`
  (valid/invalid/unknown unter der gewählten Regel), `engineverdict`
  (valid/invalid/unknown) und `engineunknownreason`. `valid` bleibt 0/1 für
  „geht in die Zahlen ein“.

## Warum es kein Urteil gibt

Die Ursache wird beim Einsammeln mit der Bearbeitung gespeichert
(`enginevalidityunknown` im Trace). Ältere Bearbeitungen erhalten sie beim
ersten Lesen. Geraten wird nichts:

| Code | Bedeutung |
|---|---|
| `engine_unavailable` | Die installierte Engine hat keine Gültigkeitsprüfung. |
| `no_engine_attempt` | Zur Testbearbeitung gibt es keinen Versuch in der Aktivität. |
| `no_cat_attempt` | Zum Versuch der Aktivität gibt es keinen CAT-Versuch (Zuordnung). |
| `no_progress` | Der Fortschritt des CAT-Versuchs fehlt oder ist nicht lesbar. |
| `no_scale_result` | Die Prüfung der Engine lieferte kein Skalenergebnis. |
| `validator_exception` | Die Prüfung der Engine brach mit einer Ausnahme ab; Klasse und Meldung stehen im Trace (`enginevalidity.engine.exception`). |
| `not_recorded` | Die Bearbeitung wurde vor 0.7.41 gesammelt, die Ursache ist nicht erfasst. |

Ein Catch-all, das die Ursache verschluckt, gibt es nicht mehr.

## Klassischer Test (Hinweis zur Engine ab 2026100915)

Seit local_catquiz #135 zeigt die Engine beim klassischen Test die Mindestzahl
je Skala und den maximalen Standardfehler. Beide sind dort reine
**Berichtsschwellen**: Sie entscheiden, was als gültig berichtet wird, und
kürzen den Test nicht. catquizlab gibt dem klassischen Test, wie bisher,
neutrale Werte: Mindestzahl je Skala 0, maximaler SE 1,0. Seine Gültigkeit
prüft deshalb gemessen, Anteil 0 < f < 1 und SE ≤ 1,0, in beiden Regeln
gleich.

Offen ist eine fachliche Frage: Soll der klassische Test stattdessen die
Schwellen des Experiments erhalten, zum Beispiel dieselbe Mindestzahl je Skala
und denselben maximalen SE wie die adaptiven Strategien? Das ist eine
Entscheidung, die noch zu treffen ist.
