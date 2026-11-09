# Stillstand diagnostizieren: „Worker starten nicht“

Die Anleitung entstand nach dem Vorfall vom 09.11.2026 auf clara. Dort hatte Experiment 16 sechs Läufe, die angelegt, aber nie gestartet waren. Die Ausführungswarteschlange hing seit 48 Tagen an Experiment 6, das keinen einzigen Lauf mehr hatte. Das Log meldete nur „no-claimable-work“, und das als Fehler.

Seit 0.7.48 erklärt das Plugin solche Zustände selbst. Diese Seite sagt, wo man nachsieht, wenn trotzdem etwas steht.

## 1. Ein Befehl

Als Webserver-User ausführen, aus einem Verzeichnis, das dieser User betreten darf. So läuft auch der Cron:

```bash
cd /tmp && sudo -u www-data php /var/www/moodle/local/catquizlab/cli/diagnose.php --experimentid=16
cd /tmp && sudo -u www-data php /var/www/moodle/local/catquizlab/cli/diagnose.php --experimentid=16 --json
```

Der Befehl ändert nichts und gibt keine Tokens aus. Er zeigt:

- den Zustand des Experiments und seiner Läufe: DigiTwins sowie eingeplante, laufende und gescheiterte Sitzungen;
- warum nichts abspielbar ist (siehe Abschnitt 3);
- den letzten Tick;
- die Ausführungswarteschlange, mit verwaisten Einträgen;
- offene Einrichtungsaufgaben, mit früheren Fehlversuchen;
- Einrichtung, Worker-Einstellungen, Kapazität und Worker-Zustand.

## 2. Was der Tick jetzt selbst sagt

Ausgabe im Cron bzw. in `mdl_task_log.output`:

- `no worker started: no-claimable-work — 6 run(s) created but never started (experiment 16): start them on the progress page`
- `execution queue: entry of experiment 6 closed (orphaned: experiment has no runs)`
- `execution queue held by experiment 12 since …`

Auf der Log-Seite (Schritt 5) steht dieselbe Tick-Zeile mit einem Schweregrad:

| Schweregrad | Wann |
|---|---|
| Info | Nichts zu tun, oder alle Plätze belegt. |
| Warnung | Etwas wartet auf eine Person: Läufe nie gestartet, Warteschlange belegt. |
| Fehler | Nur, wenn ein Start fehlgeschlagen ist. |

## 3. Die häufigsten Ursachen

| Was `diagnose.php` sagt | Bedeutung | Was zu tun ist |
|---|---|---|
| „run(s) created but never started“ | Das Experiment wurde mit „Speichern und Läufe anlegen“ angelegt, aber nicht gestartet. | Auf der Fortschrittsseite **Läufe starten**. |
| „run(s) being prepared“ | Einrichtungsaufgaben laufen (DigiTwins, Test, Sitzungen). Bei 2000 Personen und 2500 Items dauert das 1–2 Minuten je Lauf. | Warten. Bei `faildelay` > 0 steht der Grund in `mdl_task_log`. |
| „execution queue held by experiment N“ | Ein anderes Experiment läuft. | Warten, oder dieses Experiment beenden bzw. zurücksetzen. Verwaiste Einträge schließt der Tick selbst. |
| „sitting(s) in paused runs“ | Ein Lauf ist pausiert, von Hand oder durch den Schutzschalter. | Auf der Fortschrittsseite unter „Diagnose und Wiederherstellung“ fortsetzen. |
| „Worker settings missing“ | Eine Einstellung fehlt. | Einstellungen ergänzen, Einrichtung Schritt 1. |

## 4. Wenn der Tick von Hand abbricht

```
/tmp/requestdir/… can not be created, check permissions
```

Das betrifft nur den Aufruf von Hand, der Cron läuft trotzdem. Ursache: `/tmp/requestdir` gehört nicht `www-data`. Prüfen und beheben:

```bash
ls -ld /tmp/requestdir
sudo chown www-data:www-data /tmp/requestdir
cd /tmp && sudo -u www-data php /var/www/moodle/admin/cli/scheduled_task.php --execute='\local_catquizlab\task\pipeline_tick'
```

`chown` ist nur nötig, wenn das Verzeichnis jemand anderem gehört.

## 5. SQL für Adminer (Präfix `mdl_`)

```sql
-- Läufe eines Experiments
SELECT r.id, r.cellkey, r.status, r.lasterror,
  (SELECT COUNT(*) FROM mdl_local_catquizlab_person p WHERE p.runid = r.id) AS digitwins,
  (SELECT COUNT(*) FROM mdl_local_catquizlab_attempt a WHERE a.runid = r.id AND a.status = 0)  AS wartend,
  (SELECT COUNT(*) FROM mdl_local_catquizlab_attempt a WHERE a.runid = r.id AND a.status = 10) AS laufend,
  (SELECT COUNT(*) FROM mdl_local_catquizlab_attempt a WHERE a.runid = r.id AND a.status IN (20,25,30)) AS beendet
FROM mdl_local_catquizlab_run r WHERE r.experimentid = 16 ORDER BY r.id;
-- Lauf-Status: 0 angelegt, 10 eingeplant/Einrichtung, 15 bereit, 20 läuft, 25 aggregiert, 30 fertig, 40 fehlgeschlagen, 50 abgebrochen

-- letzter Tick, mit Begründung
SELECT value FROM mdl_config_plugins WHERE plugin = 'local_catquizlab' AND name = 'lastdispatch';

-- Ausgabe der letzten Ticks und Einrichtungsaufgaben
SELECT id, classname, result, timestart, ROUND(timeend - timestart, 1) AS sekunden, output
FROM mdl_task_log WHERE component = 'local_catquizlab' ORDER BY id DESC LIMIT 15;

-- Ausführungswarteschlange
SELECT id, experimentid, state, position, reason, timecreated, timestarted, timefinished
FROM mdl_local_catquizlab_execqueue ORDER BY id;
```

## 6. Worker-Laufzeit prüfen (Node, Puppeteer, Chrome)

```bash
cd /var/www/moodledata/local_catquizlab/worker-runtime && sudo -u www-data env \
  HOME=/var/www/moodledata/local_catquizlab/worker-home \
  PUPPETEER_CACHE_DIR=/var/www/moodledata/local_catquizlab/worker-home/.cache/puppeteer \
  NODE_PATH=/var/www/moodledata/local_catquizlab/worker-runtime/node_modules \
  /usr/bin/node /var/www/moodle/local/catquizlab/worker/run_attempt.js --self-test
```

Wenn alles stimmt, endet die Ausgabe mit `browser starts (Chrome/…)` und `Worker self test passed`. Wird das System-Chrome aktualisiert, ist der Worker nicht betroffen: Er nutzt sein eigenes Chrome im Puppeteer-Cache.
