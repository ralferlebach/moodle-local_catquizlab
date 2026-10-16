# Ergebnisse: Analysebasis, leere Zustände, Download-Menü (#130)

Code: `classes/output/results_page.php` (`render_provenance($experimentid)`,
`basis_warnings`, `basis_details`, `empty_kind`, `render_no_data`),
`classes/output/scatter_chart.php` (`download_menu`), `results.php`.

## Reihenfolge der Seite

Titel → Einleitung → Filter → Untertabs → **Analysebasis** → Inhalt des Tabs.
Vorher standen die große Vollständigkeitsmeldung und der Paket-Knopf über dem
Seitentitel.

## Analysebasis

Sichtbar:
1. Populationsprobleme (unverändert, #117);
2. kurze Warnungen: **unvollständiges Design** (`basis:incomplete`: gesammelt /
   geplant / endgültig gescheitert, Link zu den offenen Läufen),
   **fehlendes Engine-Urteil** (`basis:verdictmissing`, nur bei Engine-Regel),
   Designverluste (#115) kompakt;
3. eine Zeile Grundlage (#127: Bearbeitungen, Personen, Läufe, Replikationen,
   Zellen, Unsicherheit) — `data-region="catquizlab-basis-line"`;
4. Validitätsquelle und -auswahl mit Umschaltern (`catquizlab-coverage`).

Eingeklappt („Analysebasis ▸“, `catquizlab-analysis-basis`): Population,
Trichter Plan → gültig (#115), simulierte Fähigkeiten, Stoppregeln je Strategie,
Vollständigkeit, Reproduktionspaket (POST).

## Leere Zustände (`empty_kind()`)

| Art | Wann | Wo gesagt |
|---|---|---|
| `nomatch` | gewählte Faktoren in keinem Lauf | Basis (#122) |
| `noruns` | keine Läufe in der Auswahl | Basis + Tab |
| `notstarted` | Läufe da, Phase geplant/vorbereitet/startbereit/eingereiht | Basis + Tab |
| `nocollected` | gestartet, nichts gesammelt | Basis + Tab |
| `allexcluded` | gesammelt, keine gültig (mit Gründen) | Basis (`data-empty="allexcluded"`), Tab schweigt |
| `noverdict` | Engine-Regel, kein Engine-Urteil | Basis (`data-empty="noverdict"`) mit Umschalter auf einheitliche Regel |
| `noinvalid` | „nur ungültige“, keine ungültig | Basis |

„No results yet — Nothing has been collected“ erschien vorher im Übersichtstab
auch dann, wenn die Basis „250 gesammelt, keine gültig“ sagte (AK 1).

## Download-Menü

Jede Abbildung: ein `<details>`-Menü „Herunterladen ▾“ mit SVG, PNG, PDF, CSV
(ohne Script, per Tastatur bedienbar); die Plot-Einstellungen (JSON) daneben.
Der Export-Tab bietet die Dateien der aktuellen Auswahl.

## Tests

`tests/results_empty_states_test.php` (alle leeren Zustände, Basis kompakt und
vollständig, Menü); Playwright `tests/playwright/results_ux.spec.js` (keine
„nichts gesammelt“-Meldung bei gesammelten ungültigen, Reihenfolge Titel/Paket,
Basis per Tastatur, Filter- und Validitätswechsel, Export folgt der Auswahl,
Menü per Tastatur); `plots.spec.js`/`downloads.spec.js` angepasst.
Daten: `cli/seed_results.php --invalid --label=INVALID`.
