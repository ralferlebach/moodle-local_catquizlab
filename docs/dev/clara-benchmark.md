# CLARA-ähnlicher Benchmark (#110, 0.7.78; Nachbesserungen 0.7.79; fail closed seit 0.7.80, #170)

Der Vorfall auf CLARA am 15.11.2026: HIGHESTSUB-Run #87 mit einem Pool von etwa 2 500 Items. Der Selektor der damaligen
Engine brauchte rund eine Minute pro Frage (`strategy:select_question` 56–65 s). Die Browser liefen beim Absenden in
ihre 60 s (`submit_cdp_timeout` bei Frage 5/6). Der Lastschutz drosselte von 25 auf 12 Worker. Der Benchmark stellt
diesen Lauf in der CI nach, vorher und nachher, auf denselben Seeds.

## Aufbau (Workflow `acceptance.yml`, Jobs `clara-bench` und `clara-report`)

| Zweig | Engine-Satz | Worker | Personen je Worker | Zusatzschritte | gegated |
|---|---|---|---|---|---|
| after | Release (`engine-pins.env`) | 1, 5, 12, 25 | 2 | Query-Log-, Fehler- und Wiederaufnahme-Schritt (je 5 Worker) | ja: jede Stufe PASS, 25 Worker getragen |
| before | Vorgänger (`engine-pins-previous.env`) | 1, 25 | 1 | – | nein: zeigt, wie der Vorfall aussah |

Gemeinsame Bedingungen:
- Moodle 4.5 mit PostgreSQL 16, Apache (event) vor PHP-FPM (64 Prozesse, `memory_limit` 512M, `max_execution_time` 300).
- HIGHESTSUB mit `POOL=2500`; smoke.php legt 2 502 Items über 3 Subskalen an. Mindestens 20, höchstens 25 Fragen.
- Engine-Tracing an.

Pro Schritt schreibt `load_matrix.sh`:

| Datei | Inhalt |
|---|---|
| `*-smoke.json` | Abrechnung aller Ausführungen und Bearbeitungen (seit 0.7.77) |
| `*-requests.json` | jede Anfrage: Status, 5xx, Fatals, Exceptions, Peak, `memory_limit` |
| `*-traces.json` | Selektor (`strategy`), Engine-Arten, Zerlegung: Server/DB/Sperre/Client, Trace-INSERT im Query-Log-Schritt |
| `*-db.json` | Datenbank: Grenzen, Zähler vorher/nachher (Deadlocks, Rollbacks), DML-Exceptions der Anfragen nach Klasse |
| `*-cdp.json` | jede unsichere Abgabe mit Lebenslauf und Seite (siehe unten) |
| `*-samples.jsonl` | alle 30 s: CPU, Speicher, Apache, DB-Verbindungen; dazu Worker live/spielend, wirksame Grenze, Safety-Cap, gedrosselt |
| `matrix.jsonl` | je Schritt eine Zeile mit allen Kennzahlen und UTC-Fenster |

`server-limits.sh` hält die Grenzen fest, die gelten:
- PHP, über den Webserver selbst gefragt: `memory_limit`, `max_execution_time` u. a.;
- PHP-FPM: `pm`, `pm.max_children`, `request_terminate_timeout`;
- Apache: `Timeout`, `ProxyTimeout`, `KeepAliveTimeout`, `MaxRequestWorkers`;
- Gateway: keins;
- Datenbank: `max_connections`, Timeouts.

`host.json` hält Commit, Lauf, Runner, CPUs, CPU-Modell, Speicher, Zweig und Engine-Satz fest (seit 0.7.80).

Jeder Schritt beginnt mit gelöstem Lastschutz. Hat ein früherer Schritt gedrosselt, wartet `load_matrix.sh` bis zu
`GUARDWAIT` Sekunden darauf und sagt das. Lokal hatte ein vorangegangener Fehler-Schritt den nächsten Lauf mit 5 Workern
auf 1–2 gehalten. Der Query-Log-Schritt läuft deshalb vor dem Fehler-Schritt.

## Lebenslauf einer unsicheren Abgabe (AK-CLARA-01; `cdp_timeline`)

Der Worker hält seit 0.7.78 für jede Abgabe, die er abstimmen muss, drei Zeitpunkte fest (UTC, Millisekunden):
- wann der POST begann,
- wie lange der Browser wartete,
- wann der Server gefragt wurde.

Daneben stehen die Anfragezeile des Servers für diesen POST (dieselbe Bearbeitung und Ausführung) und der
Engine-Trace der Anfrage. Die Seite ergibt sich aus diesen Zeiten:

| Ursache | Seite | woran zu erkennen |
|---|---|---|
| `request_not_received` | Client/Netz | keine Anfragezeile: der Request erreichte PHP nie |
| `response_not_delivered` | Client | der Server war fertig, bevor der Browser aufgab (1 s Spielraum für PHPs Shutdown) |
| `server_slow` | Server | der Server lief noch, als der Browser aufgab |
| `server_error` | Server | der Server schickte einen Fehlerstatus |
| `unattributed` | – | Zeiten fehlen: das wird gesagt, nicht geraten |

Im Fehler-Schritt muss genau herauskommen, was eingespeist wurde: je Bearbeitung ein `request_not_received` (verlorene
Anfrage) und zwei `response_not_delivered` (verlorene und zurückgehaltene Antwort). Jede andere Zahl und jede
`unattributed`-Abgabe lässt den Schritt scheitern.

## Wiederaufnahme-Schritt (0.7.79)

Auslöser war CI e605b2e, classic mit 50 Workern: Ein Retry setzte den Engine-Versuch der ersten Ausführung fort
(65 Antworten), zählte aber ab 0. Jede weitere Abstimmung las daher „66 gespeichert, der Worker hatte 0“ als
Widerspruch, und die Bearbeitung ging verloren.

Der Worker fragt die Engine jetzt vor der ersten Antwort einer Ausführung nach ihrem Stand und zählt von dort weiter.
Der Schritt `resume` (`RESUME_AT=W`) prüft das mit `CATQUIZLAB_FAULTS=lostresponse@3,endexecution@4`:
- **Erste Ausführung:** Die Antwort auf die 3. Abgabe geht verloren (gespeichert), die 4. Anfrage geht verloren, dann
  endet die Ausführung.
- **Zweite Ausführung:** Sie setzt den Engine-Versuch fort. Die Antwort auf ihre 3. Abgabe geht wieder verloren und
  muss als gespeichert abgestimmt werden.

Erwartet werden je Bearbeitung genau ein durch den Fehler beendeter Lauf (`--expect-injected-ends`), drei
abgestimmte Abgaben und in der Zeitleiste 1× nicht angekommen und 2× nicht ausgeliefert. Mit der Zählung von 0.7.78
scheitert der Schritt so wie die CI; mit 0.7.79 besteht er.

## Wann ein Schritt scheitert (`load_step_verdict.py --extras`, fail closed)

Zusätzlich zu den Regeln seit 0.7.77 scheitert ein Schritt an:
- einem Deadlock;
- einer Exception der Datenbankschicht in einer Anfrage, z. B. einem fehlgeschlagenen Write;
- Datenbank-Zählern, die nicht lesbar sind;
- einer unsicheren Abgabe ohne Seite, oder im Fehler-Schritt an anderen Ursachen als den eingespeisten;
- im Query-Log-Schritt an einem nicht gemessenen Trace-INSERT.

Doppelabgaben und nicht beendete Bearbeitungen ließen den Schritt schon vorher scheitern: Abgleich von Engine- und
Laborzählung in smoke.php, Abrechnung seit 0.7.77.

## Pflichtumfang und fail closed (#170, 0.7.80)

`clara_bench.py` prüft den Datensatz, bevor es ihn bewertet. Jeder Mangel wird benannt mit:
- Zweig, Zelle, Datei und Messgröße;
- den Punkten, die er scheitern lässt.

Antworten und Zugangsdaten nennt der Bericht nie. Ein Mangel lässt seine Punkte scheitern und dazu `GATE-170`; der Job
endet dann mit Exit 1.

**Pflichtmatrix (Profil `current`):** genau diese Zellen, jede genau einmal, keine andere.

| Zweig | Zellen (Art, Worker) |
|---|---|
| after (Release-Satz) | load 1, 5, 12, 25; querylog 5; faults 5; resume 5 |
| before (Vorgänger-Satz) | load 1, 25 |

Für jeden Schritt gilt außerdem:
- Strategie HIGHESTSUB;
- ein Pool von mindestens 2 500 Items, in beiden Zweigen derselbe.

Das Profil `0.7.78` (ohne Wiederaufnahme-Schritt) gilt nur für den Referenzlauf auf e605b2e7 (Acceptance
45038234242). Ein neuerer Datensatz wird nie mit der kleineren Matrix bewertet.

**Zwingende Rohdaten je Schritt** (fehlt eine Datei oder ist sie kein lesbares JSON: FAIL):

| Datei | geprüft |
|---|---|
| `matrix.jsonl` | jede Zeile lesbar, kein Schritt doppelt; `from` < `until`, `seconds` > 0, Ergebnis PASS/FAIL; `http5xx` und `fatals` gezählt (im after-Zweig 0); `request_ms` als Kennzahl |
| `*-samples.jsonl` | siehe unten |
| `*-smoke.json` | die Teile der Sittings (gesammelt, wartend, laufend, gescheitert, abgebrochen, sonstige) ergeben die Gesamtzahl, und diese ist die Personenzahl des Schritts; die Gültigkeitsurteile ergeben die Zahl der gesammelten Sittings; es gibt mindestens so viele Ausführungen wie beendete Sittings |
| `*-traces.json` | Selektorzeit in jeder Laststufe: n > 0, p50 ≤ p95 ≤ p99 ≤ max, keine negativen oder nicht-numerischen Werte |
| `*-requests.json`, `*-db.json` | lesbar |
| `*-cdp.json` | lesbar, mit Zählung, Seiten, `unattributed`, `problems` |
| `*.log` | nennt den Pool |

**Samples (AK 1):**
- Jede Zeile außer der letzten muss lesbar sein. Die letzte darf abgeschnitten sein, weil der Sampler mitten in der
  Zeile gestoppt wird.
- Mindestens ein Sample ist da, und ihre Zahl stimmt mit `samples` in `matrix.jsonl` überein.
- Jedes Sample liegt im Zeitfenster des Schritts (± 90 s). Sonst stammt die Datei aus einem anderen Schritt.
- Die Worker sind gelesen: kein `workersunreadable`, mindestens ein Sample mit Workerzahl, und mindestens eines sah
  einen Worker.
- `workers_live_max` und `throttled_samples` in der Matrix stimmen mit den Samples überein.
- Keine Workerzahl und kein Limit liegen über dem Safety-Cap.

Ein ausgefallener Sampler ergibt damit nie ein „gemessenes“ 0.

**Provenienz (AK 3):**
- `host.json` je Zweig enthält: Plugin-Commit, Lauf, Runner, CPUs, CPU-Modell, Speicher, Zweig und Engine-Satz.
- Der Plugin-Commit ist in beiden Zweigen der geprüfte Commit (`--commit`).
- `limits.json` ist je Zweig lesbar.
- `engine-manifest.txt` je Zweig: Satz `release` bzw. `previous`; jede Komponente (local_catquiz, mod_adaptivequiz,
  adaptivequizcatmodel_catquiz, local_wunderbyte_table) mit aufgelöstem Commit, und zwar genau dem aus
  `engine-pins.env` bzw. `engine-pins-previous.env`.
- local_catquiz unterscheidet sich zwischen den Zweigen.
- CPUs, `memory_limit`, `max_execution_time`, SAPI, `pm`, `pm.max_children` und `max_connections` sind in beiden
  Zweigen gleich, sonst gelten die Zweige als nicht vergleichbar.
- Die beiden Zweige laufen auf zwei gehosteten Runnern. Der Bericht sagt das und nennt Runner, Image, CPU-Modell und
  Speicher beider Zweige; als dieselbe Maschine gibt er sie nie aus.

**Bericht (AK 4):** `clara_bench.py --verify-report` liest den geschriebenen Bericht zurück. Er scheitert, wenn:
- ein Punkt fehlt oder doppelt ist;
- ein Status unbekannt ist;
- der Erlass für #87/#88 nicht `WAIVED` ist;
- ein anderer Punkt `WAIVED` ist;
- Markdown und JSON sich widersprechen;
- ein Mangel oder ein FAIL darin steht.

**Negativmatrix (DoD 1, DoD 3):** `clara_bench.py --self-test --protocol FILE` baut einen vollständigen, schlüssigen
Datensatz beider Zweige. In je eine Kopie spritzt es einen Mangel ein und ruft dann die Kommandozeile auf. Die
Positivkontrolle muss mit 0 enden, jeder andere Fall nicht mit 0, mit den genannten Punkten auf FAIL und dem Mangel
benannt.

Die Fälle:
- (a) leerer, kaputter, fremder oder workerloser Sampler; Matrix und Samples uneins;
- (b) after-w12 fehlt, eine Zelle doppelt, eine fremde Zelle;
- (c) before-w25 fehlt, before-Zweig fehlt;
- (d) Engine-Manifest fehlt, Engine neben dem Pin, before auf dem Release-Satz, Commit nicht aufgelöst, Pins nicht
  übergeben, falscher Plugin-Commit, `host.json` fehlt, Runner nicht vergleichbar, altes Profil für neue Daten;
- (e) Query-Log-, Fehler- oder Wiederaufnahme-Zelle fehlt, Fehler-Teilbericht fehlt;
- (f) Latenzen ungeordnet oder nicht numerisch, Statuszahlen gehen nicht auf, 5xx nicht gezählt, Fatal im
  after-Zweig, Schrittbericht oder Matrixzeile kaputt, dazu vier verfälschte Berichte.

Die Matrix läuft in der Release-CI und der Entwicklungs-CI im Job „Issue 170 — benchmark gate: negative matrix“; das
Protokoll ist das Artefakt `catquizlab-gate-selftest`. Zusätzlich läuft sie im Job `clara-report` vor der Bewertung
(Artefakt `catquizlab-acceptance-clara-report`, `negative-matrix.md`).

## Die Punkte (`clara_bench.py`, Job `clara-report`)

`clara-bench.json` enthält alle Kennzahlen, die Mängel und die Vergleichbarkeit maschinenlesbar. `clara-bench.md` ist
der Bericht samt Ursache. Ein Punkt ist PASS nur auf Daten; fehlen Daten, ist er FAIL.

| Punkt | geprüft |
|---|---|
| GATE-170 | der Datensatz wie oben: vollständig, lesbar, schlüssig, Provenienz geprüft |
| Datenbankfehler | jede Stufe ohne DML-Exception und Deadlock, Zähler gelesen |
| PHP-/Webserver-Grenzen | alle genannten Grenzen festgehalten, vom Server selbst |
| Datenbankseitig | `max_connections`, höchste Verbindungszahl darunter, keine Deadlocks, keine fehlgeschlagenen Writes |
| AK-CLARA-01 | keine Abgabe ohne Seite, Fehler-Schritt PASS mit genau den eingespeisten Ursachen |
| AK-CLARA-03 | Selektor, Server/DB/Sperre, Client getrennt gemessen; Trace-INSERT im Query-Log-Schritt; Drosselung je Stufe |
| AK-CLARA-04 | jede Laststufe mit Worker-Proben (keine Probe oder 0 Worker ist FAIL), Worker nie über dem Safety-Cap; keine Drosselung bis 25 Worker im after-Zweig; der before-Zweig zeigt, wann und warum |
| AK-CLARA-05 | jede Bearbeitung terminal und gültig, Recovery ohne Doppelabgabe; Pool ≥ 2 500; Wiederaufnahme-Schritt PASS mit genau einem Retry je Bearbeitung |
| DoD-CLARA-01 | vorher/nachher: Selektor, Durchsatz, Fehlerquote, UTC-Fenster, Ursache aus den Zahlen |

**Erlass:**
- `AK-CLARA-05-historical` steht für die historischen Runs #87/#88 auf CLARA und ist immer `WAIVED`.
- Getestet sind sie nicht, und so werden sie auch nicht ausgegeben. Ihre Datenbank, Logs und Engine-Stände liegen beim
  Betreiber; sie sind als nicht rekonstruierbare Betreiber-Evidenz erlassen (Re-Audit 22.11.2026, Abschnitt 3 A).
- `WAIVED` zählt nicht als PASS und macht keinen.
- Geprüft wird ein Lauf derselben Art, vorher und nachher.

**Grenzen der Ursachenaussage:**
- Die beiden Zweige unterscheiden sich in mehreren Engine-Commits zugleich (Release- gegen Vorgänger-Satz), nicht in
  einem Patch.
- Sie laufen auf zwei Runnern.
- Der Benchmark ist ein Lauf derselben Art wie der Vorfall, nicht dessen forensische Rekonstruktion.

Der Bericht sagt deshalb nur:
- dass der Selektor des Vorgänger-Satzes bei diesem Pool um Größenordnungen länger brauchte;
- auf welcher Seite die unsicheren Abgaben lagen.

Er sagt nicht, dass eine einzelne Funktion alle historischen HTTP-500 verursacht hat. Abgaben ohne Seite werden
gezählt und von der Ursachenaussage ausgenommen.
