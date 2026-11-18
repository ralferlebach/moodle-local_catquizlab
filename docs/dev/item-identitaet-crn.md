# Gleiche Items, gleiche Antworten: Item-Identität und Common Random Numbers (#154, #155)

Code: `classes/local/item_identity.php` (Schlüssel, Fingerprints, Vergleich), `classes/local/materialiser.php`
(erzeugt und speichert sie), `classes/local/twin_response.php` (die Antwort eines Twins),
`classes/local/seed_domains.php` (`response_crn()`, `rng_schema()`), `classes/local/response_oracle.php`
(`explain_item()`), `classes/local/results_export.php` (Schrittexport), `classes/local/export_dataset.php`
(Itemtabelle), `classes/local/reproducibility.php` (Paket). Seit 0.7.61 (2026111500).

Ein Strategievergleich ist nur dann ein Vergleich der Strategien, wenn in jeder Strategiezelle derselbe Twin
denselben Fragen gegenübersteht und auf dieselbe Frage gleich antwortet. Was die Strategie entscheidet — welche
Fragen, in welcher Reihenfolge, wie viele —, ist dann der einzige Unterschied. Eine gleiche **adaptive
Fragenfolge** folgt daraus nicht und wird nirgends behauptet: Die Strategien sollen ja verschieden auswählen.

## 1. Item-Identität (#154)

### Schlüssel

Jedes erzeugte Item bekommt einen kanonischen Schlüssel, unabhängig von Lauf, Moodle-Frage-ID und Datenbank:

```
itemkey = r<Replikation, 3-stellig>-<Poolbedingung/Modell>-c<Kategorie>-s<Subskala>-i<Index im Blueprint, 4-stellig>
          z. B. r001-2pl-c01-s02-i0017
```

Kategorie und Subskala sind die **wahre** Platzierung aus dem Blueprint. Moodle-IDs (`questionid`, Skalen-IDs) bleiben
getrennt in `local_catquizlab_item.questionid`; sie sind in jedem Lauf andere.

### Zwei Fingerprints je Item, einer je Pool

| Feld | Worüber (sha256) | Zweck |
|---|---|---|
| `contentfp` | `itemkey`, Itemname, Modell, wahre Parameter (b, a, c, Stufen), Kategorienzahl, wahre Kategorie/Subskala | Das Item, wie der Blueprint es erzeugt hat |
| `fingerprint` | `contentfp` + gespeicherte Schwierigkeit, zugewiesene Platzierung, Fehlkalibrierungs-/Fehlzuordnungsflag, Fragename, Fragetext, Antwortoptionen mit Bewertung | Das Item, wie es realisiert wurde: was die Engine und die Person sehen |
| Pool (`local_catquizlab_pool.fingerprint`) | sortierte Zeilen `itemkey fingerprint` | Der ganze realisierte Pool eines Laufs |

Zahlen gehen mit 5 Nachkommastellen ein. Der Fragetext nennt die Skala mit ihrem Blueprint-Label (`K1.2`) und das Item
mit seinem `itemkey`, nicht mit dem Namen der Engine-Skala, der den Lauf enthält; er wird in der Sprache der Site
gerendert, egal wer die Einrichtung auslöst. Eigene Vorlagen können `{scalename}` weiter benutzen — dann
unterscheidet sich der Text je Lauf, und der Vergleich meldet das zu Recht.

### Vergleich

`item_identity::compare($experimentid)` gruppiert die Läufe nach dem, wovon ihr Pool abhängen darf: Replikation,
Modell, Poolvariante und deren Rezept. Die Strategie gehört nicht dazu.

- **Innerhalb einer Gruppe** müssen alle Pool-Fingerprints gleich sein. Ein abweichender Lauf ist ein Defekt und wird
  mit den abweichenden, fehlenden und überzähligen Schlüsseln gemeldet (je höchstens 20, Anzahl vollständig).
  Doppelte Schlüssel in einem Lauf ebenso.
- **Zwischen Gruppen** sind Unterschiede das Design, keine Fehler. Ausgewiesen werden die Faktoren, die variieren,
  und je Replikation und Modell die Kontraste der Poolvarianten gegen den idealen Pool, Item für Item: gleich,
  Inhalt geändert, nur Realisierung geändert (z. B. gespeicherte Schwierigkeit, Zuordnung), entfernt, hinzugefügt.
  Gruppen verschiedener Replikationen oder Modelle teilen absichtlich keine Items und werden nicht verglichen.
- **Läufe vor 0.7.61** haben keine Schlüssel. Sie werden als „ohne Item-Identität“ genannt und nicht nachträglich
  verschlüsselt.

Angezeigt auf der Ergebnisseite: eine Zeile mit dem Ergebnis, bei Defekten ein Hinweis in der Analysebasis. Im Paket
(`reproducibility::package()`): je Lauf `itempool` (Schema, Fingerprint), auf Experimentebene `itemidentity` (der
ganze Vergleich). In der Itemtabelle des Datenpakets (`export_dataset`): `replication`, `poolvariant`, `itemkey`,
`contentfp`, `fingerprint` neben `runid` und `questionid` — der Join `runid+questionid → itemkey` geht ohne Datenbank.

### Determinismus

Der Plan eines Pools hängt nur von Definition, Master-Seed, Replikation, Modell und Variante ab. Reihenfolge der
Einrichtung, ein zweites Einrichten desselben Laufs (Reset), parallele Worker und Datenbank-IDs ändern weder Schlüssel
noch Fingerprints. Moodle-Backup/-Restore der Labordaten gibt es nicht; ein wiederhergestellter Lauf wird neu
eingerichtet und bekommt denselben Pool.

## 2. Gemeinsame Zufallszahlen (#155)

### Der Vertrag

Eine Antwort eines Twins ist eine reine Funktion. Unter **RNG-Schema 2** (Läufe ab 0.7.61):

```
seed = derive(master, 'response-crn', {rep: Replikation, twin: twinid, item: itemkey, presentation: 1})
u    = mt_rand() / (mt_getrandmax() + 1)   nach mt_srand(seed)        ∈ [0, 1)
θ    = wahre Fähigkeit des Twins auf der WAHREN Subskala des Items (Profil, ggf. mit Devianz)
P    = Kategorienwahrscheinlichkeiten aus θ und den WAHREN Itemparametern (a, b, c bzw. Stufen)

dichotom:  richtig (Kategorie 1), wenn u < P(1)
polytom:   kleinste Kategorie k mit u < P(0) + … + P(k)
```

Nicht im Seed: Lauf-ID, Personen-ID, Frage-ID, Strategie, Worker, Uhrzeit, Reihenfolge des Aufrufs.

- **Gleiche Bedingungen** (gleiche Replikation, gleicher Twin, gleiches Item, gleiche wahre Fähigkeit und Parameter):
  gleiches u, gleiches P, also gleiche Kategorie und gleiche Score-Fraction — in jeder Strategiezelle.
- **Andere Bedingungen** (anderes Stratum, andere Devianzstärke, verschobene oder fehlkalibrierte wahre Parameter):
  **dasselbe u**, aber ein anderes P. Die Antworten unterscheiden sich genau so weit, wie die Bedingung es verlangt;
  es werden keine gespeicherten Antworten kopiert. Gleichheit wird dort nicht gefordert.
- **Gespeicherte vs. wahre Parameter:** Die Antwort kommt aus den wahren Parametern und der wahren Platzierung. Was
  der Engine (falsch) gesagt wurde, ändert die Antwort nicht — dieser Unterschied ist die getestete Bedingung.

### Wiederholungen, Retry, Replay

- Innerhalb einer Testbearbeitung stellt die Engine kein Item zweimal (`sitting_integrity` prüft: dargeboten =
  verschieden). Würde dasselbe Item demselben Twin in derselben Replikation erneut gestellt, gälte
  `presentation = 1` weiter: **dieselbe Antwort**. Das ist die dokumentierte Regel, keine stille Abhängigkeit.
- Ein Retry des Workers, eine verlorene Antwort oder Anfrage (#134), ein Neustart, ein Reset des Laufs: Es wird
  nichts neu gezogen, die Funktion liefert dasselbe.

### Altbestand: Schema 1

Läufe vor 0.7.61 haben im Manifest keine Domäne `response-crn`. `seed_domains::rng_schema()` liefert für sie 1, und
ihre Antworten werden weiter so gezogen wie damals: `seed_domains::response(master, runid, personid, questionid)`.
Sie werden nicht still umgedeutet. Ein Item ohne `itemkey` in einem neuen Lauf fällt ebenfalls auf Schema 1 zurück,
nie auf einen erfundenen Schlüssel.

Das Paket nennt je Lauf `rng.schema`, die Antwortdomäne und den Domänen-Seed (`seedfingerprint`).

### Schrittexport

Jede Zeile (`results_export::LEVEL_STEP`) trägt:

| Spalte | Inhalt |
|---|---|
| `replication`, `twinid`, `itemkey`, `runid`, `questionid` | Der Paarschlüssel und die echte gespielte Frage |
| `rngschema` | 1 oder 2 |
| `score` | Was die Engine aufgezeichnet hat |
| `rngseed` | Der Seed der Antwort |
| `uniform` | Die gemeinsame Zufallszahl u (9 Nachkommastellen) |
| `trueability` | θ auf der wahren Subskala des Items |
| `trueprobs` | `P(0);P(1);…` (6 Nachkommastellen) |
| `responsecategory` | Gezogene Kategorie (dichotom 0/1) |
| `oraclefraction` | Score-Fraction der gezogenen Kategorie |

Die letzten sechs werden mit derselben Funktion neu berechnet, die die Antwort gezogen hat
(`twin_response::draw()`). Sie sind nur für Läufe unter Schema 2 gefüllt; Schema-1-Läufe bleiben leer, weil ihre
damaligen Antworten nicht nachträglich rekonstruiert werden. Damit lässt sich ohne Plugin prüfen:

- **Paar:** Gleiche `replication`, `twinid`, `itemkey` → gleiche `rngseed`, `uniform`, `responsecategory`, `score`, unter
  verschiedenen `questionid`.
- **Regel:** `responsecategory` folgt aus `uniform` und `trueprobs` nach der Regel oben.
- **Treue:** `score` = `oraclefraction`: Die Engine hat aufgezeichnet, was gezogen wurde.
- **Erlaubte Abweichung:** Zwischen Bedingungen gleiches `uniform`, andere `trueprobs`.

### Nachweis im Worker-E2E

`cli/smoke.php` mit mehreren Strategien prüft am Ende, auf der echten Einrichtung durch die Worker:

1. **Item-Identität:** Jede Gruppe von Läufen, die einen Pool teilen müssen, hat denselben Pool-Fingerprint, alle
   Items haben Schlüssel.
2. **Paarvergleich** aus dem Schrittexport, wie ein Analyst ihn liest: Kein Twin beantwortet dasselbe Item in zwei
   Läufen verschieden. Jede aufgezeichnete Antwort ist die gezogene, auch nach verlorener Antwort oder Anfrage.
   Mindestens ein geteiltes Item, sonst ist nichts verglichen. Alle Läufe unter Schema 2.

`--pairs=FILE` schreibt die Schritte mit allen Spalten oben als CSV. Im Workflow `worker-e2e.yml`: der Schritt
„Issues 141/142/160“ (klassisch und fastest, drei Twins, 60+ Fragen) und der Schritt „Issues 134/155/110“ (dieselben zwei
Strategien, verlorene Antwort bei POST 5, verlorene Anfrage bei POST 9 in jeder Testbearbeitung). Beide CSV liegen in
den Belegen (`/tmp/pairs.csv`, `/tmp/pairs-recovery.csv`).

## Grenzen

- Die Gleichheit gilt **je Replikation**. Zwischen Replikationen sind es andere Twins und andere Items, mit eigenen
  Seeds.
- Gleiche Antworten heißen nicht gleiche Testverläufe: Welche Items geteilt werden, entscheiden die Strategien. Ein
  Paarvergleich vergleicht nur die Items, die beide Läufe demselben Twin gestellt haben.
- Eigene Fragevorlagen mit laufbezogenem Inhalt (`{scalename}`) machen die Pools verschieden. Das ist dann wirklich so
  und wird gemeldet.
