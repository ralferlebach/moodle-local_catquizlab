# Logs und Datenschutz (#149)

Seit 0.7.62 vollständig. Eine Regel für alles, was das Plugin protokolliert (`classes/local/log_redactor.php`, im
Worker `worker/navigation.js` mit derselben Namensliste `worker/log_params.json`):

- **Geheime Namen** verlieren ihren Wert (`[redacted]`). Dazu gehören `sesskey`, `token`, `key`, `password`, `secret`, `authorization`, `email`, Cookies, Sitzungs-IDs, Signaturen u. a.
- **Erkennung des Namens** nach Dekodieren (auch doppelt), Kleinschreibung und Entfernen von Klammern und Sonderzeichen: `ses%73key`, `SESSKEY`, `sess_key`, `sesskey[]` werden alle erkannt, auch mehrfach im selben Aufruf.
- **E-Mail-Adressen** verlieren ihren Wert unter jedem Namen.
- **In protokollierten Request-URIs** behalten nur Routing-Parameter ihren Wert (`log_redactor::ROUTING_NAMES`: IDs, Seite, Tab, Filter …). Jeder andere Parameter behält nur seinen Namen, der Wert wird `[omitted]`. Unbekannte Parameter werden also nicht protokolliert (AK2).
- **Fragment** und **Steuerzeichen** fallen weg (kein Zeilenumbruch, keine gefälschte Folgezeile).
- **Länge:** Lange Werte werden begrenzt. Eine zu lange URI wird zwischen Parametern gekürzt, nie mitten in einem Wert oder einer Markierung (`&~cut<Bytes>`).

## Kanäle

| Kanal | Wo | Was steht drin | Schutz | Geprüft durch |
|---|---|---|---|---|
| Speicher-Fatal | PHP-Fehlerlog (`error_log`) | Skriptpfad **ohne** Query, typisierte Felder aus einer Allowlist (`resource_guard::CONTEXT_FIELDS`: Experiment, Lauf, Replikation, Tab, Sittings, Tracegröße, größte Charge, gelesene Menge), Phase, Limit, Spitze, Request-ID | Kein `REQUEST_URI`, jedes Feld einzeln begrenzt (`log_redactor::field`) | `fatal_log_redaction_test` (6 Tests, einer mit echter Speichererschöpfung in einem Unterprozess) |
| Request-Trace | `moodledata/catquizlab/requests/<Tag>.jsonl` | Jede Anfrage mit Worker-Trace-Header: Methode, Pfad, Status, Dauer, Speicher | Pfad über `redact_uri` (Routing-Allowlist) | `log_redactor_test`, `request_trace_test`; CI: `probe_log_secrets.php` |
| Debug-Trace | Tabelle `local_catquizlab_debug` | Aufrufe von Kanälen mit Parametern | Übergebene Parameter mit geheimem Namen → `[redacted]` (`debug_trace::submitted`) | `log_redactor_test::test_every_channel_follows_the_rule`, `debug_trace_cause_test` |
| Versuchshistorie, Fehlermeldungen | Tabellen `local_catquizlab_attemptlog` (`detail`), `local_catquizlab_attempt` (Fehler) | Ausgang je Ausführung, Fehlertext, Navigationskette | `attempt_history::redact` (Paare, `Authorization`-Header, JSON) | `log_redactor_test` |
| Worker-Logs | `moodledata/local_catquizlab/worker-logs/*.log` | Ereignisse des Workers, Statuskette | URLs über `sanitiseRequestUrl` (Routing-Allowlist), Freitext über `sanitiseUrl` | `worker/test/navigation.test.js`; CI: `scan_logs_for_secrets.php` über alle Worker-Logs des E2E-Laufs |
| Artefakte bei Fehlschlag | `moodledata/local_catquizlab/artefacts/` | Grund, Ereignisse, DOM-Schnappschuss, Screenshots | JSON über `artefact_store::redacted_json` und `attempt_history::redact`; DOM über `sanitiseHtml` (auch eingebettete `sesskey`) | `log_redactor_test`, Worker-Tests |
| Log-Ansicht und ihre Downloads | `logs.php` | Die Kanäle oben, gelesen | Zweite Schwelle `log_view::redact` beim Anzeigen und Herunterladen | `log_view_redaction_test` |
| Reproduzierbarkeits- und Forschungspaket | Downloads | Definitionen, Manifeste, Verläufe | `attempt_history::redact` über das JSON; Daten synthetisch | `reproducibility_test` |
| Moodle-Ereignisse | Moodles Log-Store | IDs und getippte Namen (z. B. Umbenennung #152) | Keine URIs vom Plugin | – (Moodle-Standard) |
| Links und Formulare des Plugins | Browser-Verlauf, Zugriffslog des Servers, Referer | – | Aktionen und Downloads per POST mit Sitzungsschlüssel im Rumpf; kein Schlüssel in einer GET-URL des Plugins | `no_secret_in_url_test`; `cli/scan_get_secrets.php` (Crawler) |

## Außerhalb des Plugins

- **Zugriffslog des Webservers** (Apache, nginx, `php -S`): schreibt die vollständige URI jeder Anfrage, nach seiner eigenen Konfiguration. Das Plugin setzt keine Geheimnisse in seine eigenen URLs (Zeile oben). Moodle selbst tut das an einigen Stellen, etwa beim Abmelden (`sesskey` per GET). Wie lange und wo das Zugriffslog liegt, regelt der Betrieb. `probe_log_secrets.php --server-log=…` zählt solche Zeilen getrennt aus.
- **PHP-Fehlerlog sonst:** Moodles eigene Ausnahmebehandlung und `debugging()` schreiben Meldungstexte. Das Plugin schreibt dorthin nur die Speicher-Fatal-Zeile oben.

## Nachweis im Betrieb

- **CI (Worker-E2E, alle drei Stacks), Schritt „Issue 149 — no secret in any log the plugin wrote“:**
  - **Probe:** `cli/probe_log_secrets.php` sendet als temporärer Manager Anfragen mit einem Probewert, getraced wie die des Workers. Der Probewert steckt in jeder Schreibweise jedes gelisteten Namens, je zweimal, dazu in einem unbekannten Parameter, einer E-Mail-Adresse, einem Zeilenumbruch, einem Fragment und einem POST-Rumpf.
  - **Prüfung:** Danach liest die Probe alles, was das Plugin seitdem geschrieben hat, Dateien und Log-Tabellen. Der Wert darf nirgends stehen. Die Trace-Zeilen der Probe müssen vorhanden sein, sonst gilt nichts als gezeigt.
  - **Ganzer Lauf:** `cli/scan_logs_for_secrets.php --all` liest alle Request-Traces und Worker-Logs des E2E-Laufs auf geheime Parameter mit Wert.
- **Produktive Altlogs** (vor 0.7.56 enthielt die Fatal-Zeile die ganze Query): siehe „Bestands-Scan“ unten.

## Bestands-Scan alter Logs (#149, seit 0.7.71)

Neuer Code redigiert. Daraus folgt nicht, dass Logdateien von früher sauber sind. Der Bestands-Scan klärt das, ohne
dass ein Log oder ein Wert den Server verlässt.

**Wer:** der Betrieb, auf dem Server, unter dessen Kontrolle. Kein Log geht in ein Issue, in die CI oder an Dritte.

**Was er tut:** `cli/scan_logs_for_secrets.php` liest die angegebenen Dateien oder ganze Verzeichnisse, auch rotierte
und gzip-Dateien. Er zählt Zeilen mit geheimen Parametern samt Wert und ordnet sie zu:

- nach **Kategorie:** Sitzung (`sesskey`, `MoodleSession`), Token oder Schlüssel, Passwort, E-Mail-Adresse;
- nach **Zeitfenster:** erste und letzte Zeile je Kategorie und die betroffenen Stunden (UTC). Gelesen werden die
  Zeitformate von Apache-Fehlerlog, Zugriffslog, PHP-Fehlerlog und ISO 8601; Zeilen ohne lesbare Zeit zählt er
  getrennt.

Im Bericht stehen nur Zahlen, Kategorien, Namen, Zeilennummern und Stunden, **nie ein Wert**.

```
sudo -u www-data php local/catquizlab/cli/scan_logs_for_secrets.php \
    --dir=/var/log/apache2,/var/log/php --all --json > /root/scan-149.json
```

Exit-Code 0: nichts gefunden; 3: gefunden; 1: eine Datei war nicht lesbar.

**Geprüft in der CI:** Die Abnahmemessungen (`acceptance.yml`, Job „Issue 163 — scheduler, 11 real workers
(pgsql)“, Schritt „Issue 149 — scan of existing logs“) scannen synthetische Logs (`tests/fixtures/logs149/`, alle
Formate, rotiert und gzip) mit genau diesem Befehl: Exit-Code 3, Zählungen, Kategorien und Stunden wie in
`expected.json`, kein Wert im Bericht (Artefakt `catquizlab-acceptance-scheduler-pgsql-11`, Datei `log-scan-149.json`).
Dieselben Fälle prüft `log_scan_test` in jedem PHPUnit-Job der Release-CI.

**Bei einem Befund (Betreiberprozess):**

1. Die gefundenen Zeitfenster gegen die Sitzungs- und Token-Lebensdauer halten. Abgelaufene Sitzungsschlüssel sind
   wertlos, Webservice-Tokens und Passwörter nicht.
2. Betroffene Webservice-Tokens neu erzeugen, betroffene Passwörter zurücksetzen. Personen informieren, wenn die
   Datenschutzvorgaben der Einrichtung das verlangen.
3. Die Logdateien ersetzen: `--redact-to=DIR` schreibt eine Kopie ohne Werte. Das Original wird nach den Regeln der
   Einrichtung gelöscht oder ersetzt; das Plugin löscht keine Server-Logs.
4. Den Bericht (nur Zahlen) und die getroffenen Maßnahmen zum Issue legen.

**Oder: ausdrücklicher Risikoverzicht.** Der Betrieb kann den Scan auch nicht durchführen und das Risiko tragen. Das
wird dann so festgehalten, als Verzicht und nicht als Bereinigung:

> Risikoverzicht #149 (Betreiber): Die Server-Logs von `<Installation>` aus der Zeit vor 0.7.56 wurden **nicht** auf
> frühere vertrauliche Einträge geprüft. Begründung: `<z. B. Logs nach 14 Tagen rotiert und gelöscht / kein Zugriff>`.
> Damit ist **nicht** gesagt, dass alte Logs frei von Zugangsdaten sind. Datum, Name, Rolle.

Ein SQL-Bericht kann das nicht ersetzen: Die Altlogs sind Dateien, keine Tabellen.

## Diagnose bleibt möglich (AK5)

Die Speicher-Fatal-Zeile trägt weiter, was die OOM-Suche aus #147 braucht: Skript, Experiment, Lauf, Replikation, Tab,
Sittings, Tracegröße und größte Charge, gelesene Menge, Phase, Limit, Spitze, Request-ID zur Zuordnung (`fatal_log_redaction_test::
test_the_line_keeps_the_diagnosis_and_drops_every_secret`). Request-Traces behalten alle Routing-Werte (IDs, Seite,
Tab, Filter).
