# Wessen Engine-Fortschritt eine Bearbeitung trägt (#168, 0.7.77; Betreiber-Inventur 0.7.78)

Bis 0.7.74 konnte der Sammler einer Bearbeitung den Engine-Fortschritt einer anderen Person speichern
(`attempt_collector::read_progress()`, behoben in 0.7.75). Betroffen sind daraus abgeleitete Teile des Traces:
gespielte Fragen, Skalenzustände, Antworten, Fähigkeitspfad, Schritt-Export, Testverlauf. Die Ergebnisse der Engine
selbst (Items, SE, Fähigkeit, Ende) waren die eigenen.

## Regeln (Version 1, `progress_provenance::RULES`)

Ein gespeicherter Trace hat zwei unabhängige Aufzeichnungen dessen, was gefragt wurde: `items` (aus der eigenen
Fragenverwendung der Bearbeitung, Moodles Aufzeichnung) und `progress.playedquestions` (der Engine-Fortschritt).

| Urteil | Bedeutung |
|---|---|
| `verified` | gespielte Fragen = Items, in derselben Reihenfolge |
| `mismatch` | keine gemeinsame Frage, oder die meisten verschieden: der Fortschritt einer anderen Bearbeitung |
| `suspect` | dieselben Fragen in anderer Reihenfolge, oder eine Liste Teil der anderen und überwiegend gemeinsam |
| `notreconstructable` | Fortschritt ohne Items zum Vergleich, oder Trace auf seinen Digest reduziert |
| `notassessed` | kein Fortschritt gespeichert, oder kein Trace |

## Inventur — nur lesend: `cli/progress_provenance.php`

    php local/catquizlab/cli/progress_provenance.php            # alle Läufe
    php local/catquizlab/cli/progress_provenance.php --runs=12,13 --json

- Zählt je Urteil, nennt die Ids aller `mismatch`, `suspect`, `notreconstructable`; getrennt die Bearbeitungen, die
  zuletzt vor der Installation von 0.7.75 geändert wurden (`upgrade_log`, Version 2026112100).
- Nur Ids und Zahlen; keine Frage, Antwort oder Schätzung.
- Fingerabdruck der Bearbeitungstabelle und der Entscheidungstabelle vor und nach dem Lesen; geändert → Exit 3.
- Exit 1, solange `mismatch`/`suspect` gefunden und nicht ausgeschlossen sind; sonst 0.
- Alle Datenbanktypen: nur Moodles DML, kein datenbankspezifisches SQL.

## Entscheidungen — je eine neue Zeile in `local_catquizlab_provenance`, nie eine geänderte

| Aufruf | Wirkung |
|---|---|
| `--record=IDS` | Urteil festgehalten, sonst nichts |
| `--exclude=IDS` | aus jeder Analyse, jedem Export, jeder Zählung genommen (nur `mismatch`, `suspect`, `notreconstructable`); die Population nennt sie („Ausgelassen: Engine-Fortschritt einer anderen Bearbeitung“) |
| `--include=IDS` | wieder aufgenommen (neue Zeile `recorded`) |
| `--rederive=IDS` | Fortschritt durch den eigenen ersetzt — nur wenn die Engine ihn noch hat und er nachweislich der eigene ist (gespielte Fragen = Items); der bisherige Trace samt SHA-256 in der Zeile |
| `--revert=IDS` | letzte Neuableitung rückgängig, der aufbewahrte Trace Byte für Byte zurück |

Maßgeblich ist je Bearbeitung die jüngste Zeile. Neu abgeleitet wird nur der Fortschritt und was aus ihm stammt (der
Fähigkeitspfad, wo er aus dem Fortschritt kam; die Schrittzahl); der Trace erhält `provenance: {rederived, rules}`.

## Ausgabeprüfung (#136 AK5)

`output_agreement::check()` (von `smoke.php` nach jedem Lauf aufgerufen) prüft je Bearbeitung des Filters „all“ das
Urteil und meldet `mismatch`/`suspect` sowie denselben Fortschritt in mehreren Bearbeitungen mit verschiedenen Items
(„carry the engine progress of sitting(s) …“).

## Entwicklungsinstanz (21.11.2026)

18 603 gespeicherte Bearbeitungen: 3 `mismatch` (8158, 8160, 8161; Experiment 149, alle vor 0.7.75), 17 394 `verified`,
1 206 `notassessed`. Die Engine hatte für alle drei noch den eigenen Fortschritt; neu abgeleitet, danach 0 Befunde, die
Ausgabeprüfung von Experiment 149 ohne Abweichung (vorher 13).

## Betreiber-Inventur auf CLARA und anderen Installationen (AK4; 0.7.78)

Ein einziger Befehl je Installation, nur lesend, ab Plugin-Version 0.7.77 (mit `site` und `exposure` ab 0.7.78):

    sudo -u www-data php local/catquizlab/cli/progress_provenance.php --json > provenienz-$(hostname)-$(date -u +%Y%m%dT%H%M%SZ).json

- **Was er liefert:** die Installation (wwwroot, Datenbanktyp, Plugin- und Engine-Version, Lesezeitpunkt UTC);
  Gesamtzahl; je Urteil (`verified`, `mismatch`, `suspect`, `notreconstructable`, `notassessed`) die Zahl und die
  Ids der Befunde; den Anteil, der vor 0.7.75 zuletzt geändert wurde; den Fingerabdruck vor und nach dem Lesen.
- **Ob Befunde hinausgingen** (`exposure`): Er zählt je Experiment die vom Plugin festgehaltenen Exporte
  (`local_catquizlab_exportlog`) und Forschungspakete und davon die, die nach dem Sammeln einer befundeten Bearbeitung
  entstanden — die tragen sie. Downloads direkt von der Ergebnisseite (CSV, JSON, Schritte) hält das Plugin nicht
  fest; der Bericht sagt das, statt „keine“ zu melden.
- **Exit-Status:** 0 bedeutet: nichts gefunden, oder alles Gefundene ist ausgeschlossen. 1 bedeutet: `mismatch` oder
  `suspect` gefunden und nicht ausgeschlossen. 3 bedeutet: Die Datenbank änderte sich während des Lesens (z. B. liefen
  Worker) — dann erneut lesen, wenn nichts läuft.
- **Ablage:** Die Datei enthält Ids und Zahlen, keine Antworten und keine Schätzungen. Sie gehört trotzdem an einen
  vertraulichen Ablageort. Ins Issue kommen nur die Zahlen je Installation.
- **Was nicht dazu gehört:** `--exclude` und `--rederive` sind Korrekturen, keine Inventur. Sie werden nur nach
  gesonderter Entscheidung je Installation ausgeführt.
- **Lesart:** `notassessed` heißt: ohne gespeicherten Fortschritt nicht prüfbar. Das zählt weder als `verified` noch
  als Beweis einer falschen Zuordnung.

**Wird die Betreiberprüfung erlassen**, ist das ein **Risikoverzicht**, keine technische Abnahme. Formulierungsvorlage
fürs Issue: „Auf die Provenienz-Inventur der Installation(en) … wird verzichtet. Damit ist weder die Unversehrtheit
noch eine Beschädigung der dort vor 0.7.75 gesammelten Bearbeitungen festgestellt. Ergebnisse aus diesem Bestand
gelten bis zu einer Inventur als ungeprüft.“
