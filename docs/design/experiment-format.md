# Deklaratives Experimentformat (E1.1)

Ein Experiment wird als JSON beschrieben und von
`local_catquizlab\local\experiment_definition` geparst, validiert und mit
Defaults ergänzt, bevor die Sweep-Expansion (E1.2) daraus konkrete Runs macht.
Der Validator meldet alle Probleme gesammelt (nicht nur das erste) und hat
keine Seiteneffekte (keine DB-Schreibzugriffe, keine Provisionierung).

## Felder

| Pfad | Pflicht | Werte / Regel |
| --- | --- | --- |
| `name` | ja | nicht-leerer String |
| `tier` | ja | `baseline` \| `main` \| `robustness` \| `operational` |
| `model` | ja | `raschbirnbaum` \| `rasch` \| `2pl` \| `3pl` |
| `strategy` | ja | `fastest` \| `balanced` \| `allsubs` \| `lowestsub` \| `highestsub` \| `pilot` \| `classic` \| `relsubs` |
| `replications` | ja | Ganzzahl ≥ 1 |
| `seed` | ja | Ganzzahl |
| `pool.variant` | ja | `ideal` \| `shifted` \| `stretched` \| `gappy` \| `calibrationerror` \| `taggingerror` \| `depleted` \| `combined` |
| `pool.scales.{categories,subcategories,itemspersubscale}` | ja | Ganzzahl ≥ 1 |
| `pool.questiontemplate.type` | ja | nicht-leerer String (Fragetyp); `blanks` frei (Template-Platzhalter) |
| `pool.itemnaming.pattern` | ja | nicht-leerer String (Namensmuster mit Platzhaltern) |
| `persons.stratum` | ja | `conforming` \| `categoryvariation` \| `subscalevariation` \| `chaotic` |
| `persons.count` | ja | Ganzzahl ≥ 1 |
| `persons.naming.pattern` | ja | nicht-leerer String (Namensmuster) |
| `budgets.{minitems,maxitems}` | ja | Ganzzahl ≥ 1, `minitems` ≤ `maxitems` |
| `budgets.setarget` | (default) | Zahl (SE-Ziel), Default 0.35 |
| `timing.{spacingseconds,faildelay}` | (default) | Ganzzahl; Task-Timing |
| `courses` | ja | nicht-leere Liste; je Eintrag vorhandenen Kurs referenzieren oder neu anlegen |
| `tests` | ja | nicht-leere Liste; je Eintrag CAT-Test (adaptivequiz) referenzieren oder neu anlegen |

Die Felder setzen die Anforderungen aus Architektur 2.6 um: Item-Varianten
über `pool.variant` + `pool.scales` (Items/Skalen, nicht Kontexte, 2.6.A),
Personen mit Anzahl und Namensregel (eigene Nutzer, 2.6.B/D), `courses`/`tests`
je Lauf spezifizierbar (2.6.C), Namensmuster und Fragen-Templates (2.6.D).

## Beispiel

`experiment_definition::example_baseline()` liefert eine gültige Baseline-
Definition (Ideal-Pool 10×10×25, klassische Strategie, 50 Personen, ein Kurs,
ein CAT-Test) als Vorlage und Testfixture.
