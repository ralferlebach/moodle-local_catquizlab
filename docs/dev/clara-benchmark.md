# CLARA-ähnlicher Benchmark (#110, 0.7.78)

Der Vorfall auf CLARA am 15.11.2026: HIGHESTSUB-Run #87 mit einem Pool von etwa 2 500 Items. Der Selektor der damaligen
Engine brauchte rund eine Minute pro Frage (`strategy:select_question` 56–65 s). Die Browser liefen beim Absenden in
ihre 60 s (`submit_cdp_timeout` bei Frage 5/6). Der Lastschutz drosselte von 25 auf 12 Worker. Der Benchmark stellt
diesen Lauf in der CI nach, vorher und nachher, auf denselben Seeds.

## Aufbau (Workflow `acceptance.yml`, Jobs `clara-bench` und `clara-report`)

| Zweig | Engine-Satz | Worker | Personen je Worker | Zusatzschritte | gegated |
|---|---|---|---|---|---|
| after | Release (`engine-pins.env`) | 1, 5, 12, 25 | 2 | Fehler-Schritt (5 Worker), Query-Log-Schritt (5 Worker) | ja: jede Stufe PASS, 25 Worker getragen |
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

`host.json` hält Commit, Lauf und Runner fest.

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

## Wann ein Schritt scheitert (`load_step_verdict.py --extras`, fail closed)

Zusätzlich zu den Regeln seit 0.7.77 scheitert ein Schritt an:
- einem Deadlock;
- einer Exception der Datenbankschicht in einer Anfrage, z. B. einem fehlgeschlagenen Write;
- Datenbank-Zählern, die nicht lesbar sind;
- einer unsicheren Abgabe ohne Seite, oder im Fehler-Schritt an anderen Ursachen als den eingespeisten;
- im Query-Log-Schritt an einem nicht gemessenen Trace-INSERT.

Doppelabgaben und nicht beendete Bearbeitungen ließen den Schritt schon vorher scheitern: Abgleich von Engine- und
Laborzählung in smoke.php, Abrechnung seit 0.7.77.

## Die acht Punkte (`clara_bench.py`, Job `clara-report`)

`clara-bench.json` enthält alle Kennzahlen maschinenlesbar. `clara-bench.md` ist der Bericht samt Ursache. Ein Punkt
ist PASS nur auf Daten; fehlen Daten, ist er FAIL.

| Punkt | geprüft |
|---|---|
| Datenbankfehler | jede Stufe ohne DML-Exception und Deadlock, Zähler gelesen |
| PHP-/Webserver-Grenzen | alle genannten Grenzen festgehalten, vom Server selbst |
| Datenbankseitig | `max_connections`, höchste Verbindungszahl darunter, keine Deadlocks, keine fehlgeschlagenen Writes |
| AK-CLARA-01 | keine Abgabe ohne Seite, Fehler-Schritt PASS mit genau den eingespeisten Ursachen |
| AK-CLARA-03 | Selektor, Server/DB/Sperre, Client getrennt gemessen; Trace-INSERT im Query-Log-Schritt; Drosselung je Stufe |
| AK-CLARA-04 | Worker nie über dem Safety-Cap; keine Drosselung bis 25 Worker im after-Zweig; der before-Zweig zeigt, wann und warum |
| AK-CLARA-05 | jede Bearbeitung terminal und gültig, Recovery ohne Doppelabgabe; Pool ≈ 2 500 |
| DoD-CLARA-01 | vorher/nachher: Selektor, Durchsatz, Fehlerquote, UTC-Fenster, Ursache aus den Zahlen |

**Nicht getestet und nicht so ausgegeben:** die historischen Runs #87/#88 auf CLARA. Ihre Datenbank, Logs und
Engine-Stände liegen beim Betreiber. Sie sind als nicht rekonstruierbare Betreiber-Evidenz erlassen (Re-Audit
22.11.2026, Abschnitt 3 A). Geprüft wird ein Lauf derselben Art, vorher und nachher.
