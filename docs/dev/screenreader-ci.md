# Screenreader in der CI (#122 DoD 3, #143 AK7, #144 AK9; seit 0.7.65 #153 AK8, #159 DoD5)

Seit 0.7.62. Code: `tests/playwright/screenreader_snapshot.spec.js` (Linux, Moodle), `tests/screenreader/`
(Windows/macOS, Screenreader), Jobs `screen-reader` (NVDA) und `screen-reader-voiceover` in
`.github/workflows/ui-e2e.yml`.

## Was geprüft wird

Zwei Stellen der Ergebnisseite mit einem **echten Screenreader**:

| Screenreader | Betriebssystem (Runner) | Browser |
|---|---|---|
| NVDA (von Guidepup gepinnte Fassung, derzeit 2026.2) | Windows (`windows-2025`) | Chromium |
| VoiceOver | macOS (`macos-15`) | WebKit |

Geprüft wird je Moodle-Stack des Interface-Laufs (4.5, 5.2, 5.3) und je Screenreader, also sechsmal:

- **NVDA:** drei Jobs, einer je Stack.
- **VoiceOver (seit 0.7.71):** ein macOS-Job, der die drei Stacks nacheinander prüft, je mit eigenem Snapshot, eigenem
  Bericht und eigenem Artefakt (`catquizlab-screenreader-voiceover-<stack>`). Er schlägt fehl, wenn ein Stack
  fehlschlägt, und zwar erst nach allen dreien.

Warum ein Job: Drei macOS-Jobs zugleich verloren auf fd478bd (zweimal) und 995b4a7 je einen an GitHubs Kapazität. Die
Meldung war „The job was not acquired by Runner of type hosted even after multiple attempts“, und kein Schritt lief.
Die beiden anderen bekamen ihre Maschine nacheinander. Ein Job braucht nur eine Maschine.

Der Screenreader läuft, die Tastatureingaben gehen über ihn an den Browser (Guidepup), und der Test hält fest, was er
dabei sagt.

**Filterleiste** (sechs Faktor-Menüs, „Apply“, „Reset filter“): Der Test geht die Leiste mit der Tastatur durch und
fragt nach jeder Taste die Seite, welches Element den Fokus hat. Für jedes Element muss das Gesagte enthalten:

- den **Namen** (das Label, z. B. „CAT strategy“),
- die **Rolle** (NVDA: „combo box“, „button“, „link“; VoiceOver: „pop-up button“, „button“, „link“),
- bei den Menüs den **angezeigten Wert** (z. B. „Any strategy“).

Außerdem wird jedes Element per Tastatur erreicht, in der Reihenfolge der Leiste. VoiceOver läuft erst mit Tab,
dann mit Option+Tab: WebKit erreicht Links und Schaltflächen mit Tab nur bei eingeschalteter Tastaturnavigation von
macOS, mit Option+Tab immer. Mit welcher Taste ein Element erreicht wurde, steht im Protokoll.

**Streudiagramm** (Modusvergleich aus `cli/seed_modes.php`: zehn Punkte auf zwei Markierungen zu je fünf): Tab
erreicht die erste Markierung des Diagramms; der Screenreader muss sie als Grafik ansagen, mit Anzahl, Lage und
den Twins („5 points at …, Twin …“). Dann geht der Screenreader mit seiner eigenen Navigation zur nächsten Grafik
(NVDA: G, sonst Pfeil runter; VoiceOver: VO+Rechts, sonst VO+Cmd+G) und muss die zweite Markierung ansagen. Die
Pfeiltasten des Diagramms selbst (`amd/src/plot_points.js`) sind für Sehende mit Tastatur da: Ein Screenreader im
Lesemodus behält die Pfeiltasten für sich und navigiert mit eigenen Befehlen — so, wie er es auch tut.

**Seit 0.7.65 drei weitere Gruppen**, gleich durchlaufen wie die Filterleiste (Name, Rolle, angezeigter Wert, Reihenfolge,
Erreichbarkeit per Tastatur):

- **Bibliothek: Suche und Sortierung** (`[data-region="catquizlab-library-search"]`, #153 AK8): Suchfeld (Rolle
  „searchbox“), Sortierung (Auswahlliste mit dem gewählten Wert), „Anwenden“.
- **Experimentauswahl** (`[data-region="catquizlab-experiment-selector"]`, #153 AK8): Auswahlliste mit dem gewählten
  Experiment, „Übernehmen“. Die Auswahl verließ bis 0.7.64 die Seite bei jeder Änderung ihres Werts; unter Windows ändern
  die Pfeiltasten einer geschlossenen Auswahlliste den Wert bei jedem Druck, wer mit Tastatur oder Screenreader wählte,
  landete beim ersten Druck auf dem nächsten Experiment (WCAG 3.2.2). Jetzt wählen die Pfeiltasten nur; die
  Eingabetaste oder „Übernehmen“ zeigt die Wahl, eine Wahl mit der Maus wie bisher sofort. Ein unsichtbarer Hinweis am
  Feld sagt das (`aria-describedby`). Tastatur und 320 px ohne Screenreader: `tests/playwright/order_keyboard.spec.js`.
- **Experiment-Editor: Itemmodell** (`#id_modelheader`, #159 DoD5): Modell, Verteilung der Diskrimination, die drei
  gekoppelten Log-Normal-Felder (μlog, σlog, E(a)) mit ihren Werten, die Hilfe-Schaltflächen, Kategorien. Die
  Aufklappschaltfläche des Abschnitts zählt nicht mit (sie steht vor dem ersten Feld).

Die Aufzeichnung (`results-pages.json`, `groups`) hält je Gruppe URL, Selektor und die Bedienelemente; das Protokoll je
Gruppe: `artifacts/<reader>-library.json`, `-selector.json`, `-lognormal.json`.

Vor den Screenreader-Tests prüft ein Test im selben Job ohne Screenreader, dass die Seiten Namen, Rollen und Werte
liefern und im Diagramm Tab und Pfeiltasten wirken (Playwright). Scheitert dieser, liegt der Fehler an der Seite,
nicht am Screenreader.

## Warum eine Aufzeichnung

NVDA gibt es nur unter Windows, VoiceOver nur unter macOS; Moodle läuft im Interface-Lauf unter Linux. Der
Interface-Lauf zeichnet deshalb nach dem Seeden die beiden Ergebnisseiten mit allem auf, was der Browser für sie
geholt hat — HTML, Stylesheets, Moodles Skripte, Moodles eigene Anfragen — als HAR-Datei (`results.har`), dazu die
Liste dessen, was angesagt werden muss (`results-pages.json`). Beides geht als Artefakt
`screenreader-snapshot-<stack>` an die Screenreader-Jobs. Dort beantwortet Playwright jede Anfrage an die
aufgezeichnete Seite aus der Datei (`routeFromHAR`, nicht Aufgezeichnetes wird abgebrochen); die Seiten laufen also
mit ihren Skripten, ohne dass ein Moodle dahinter steht.

Die Anmeldung geschieht in einem eigenen Browserkontext; die Aufzeichnung enthält kein Passwort, wohl aber das
Sitzungscookie der kurzlebigen CI-Installation.

**Grenze:** Abgesendet wird unter dem Screenreader nichts (die Antwort wäre nicht aufgezeichnet). Den Filterwechsel
selbst prüft `filters.spec.js` im Interface-Lauf, ohne Screenreader.

## Nicht abgedeckt: JAWS

JAWS hat keine Automatisierungsschnittstelle, die Guidepup (oder ein anderes freies Werkzeug) steuern kann, und braucht
eine Lizenz. Es wird nicht automatisiert geprüft.

## Nachweis

Je Job das Artefakt `catquizlab-screenreader-<reader>-<stack>`:

- `artifacts/<reader>-filterbar.json`: je Bedienelement Name, Rolle, Wert, die Taste, mit der es erreicht wurde, und
  was der Screenreader gesagt hat; dazu das vollständige Sprachprotokoll.
- `artifacts/<reader>-plot.json`: was beim Erreichen der ersten und der zweiten Markierung gesagt wurde, mit welcher
  Taste bzw. welchem Befehl; das Sprachprotokoll.
- `artifacts/results.json`, `report/` (HTML), `test-results/` (Video und Trace der Browserseite).

Beides auch bei Fehlschlag. Ein Versuch wird einmal wiederholt (langsamer Start des Screenreaders); der Bericht weist
einen so bestandenen Test als „flaky“ aus, und das Protokoll jedes Versuchs bleibt erhalten (`-retry1`).

## Versionen

`tests/screenreader/package.json` und `package-lock.json` pinnen exakt: `@guidepup/guidepup` 0.38.0,
`@guidepup/playwright` 0.20.0, `@guidepup/setup` 0.29.3, `@playwright/test` 1.64.1. Die NVDA-Fassung und die
VoiceOver-Einstellungen kommen über `guidepup install` aus dem Manifest von `@guidepup/guidepup` (mit sha256).

## Lokal

Nur auf Windows (NVDA) oder macOS (VoiceOver), nach `npx guidepup setup` und `npx guidepup install`:

```
# Aufzeichnung erzeugen (Linux-Moodle mit cli/seed_results.php):
cd tests/playwright && PLOTS_EXPERIMENTID=<id> npx playwright test screenreader_snapshot.spec.js
# artifacts/screenreader/* auf dem Windows-/Mac-Rechner nach tests/screenreader/snapshot/ kopieren, dann:
cd tests/screenreader && npm ci && SR=nvda npx playwright test      # bzw. SR=voiceover
```
