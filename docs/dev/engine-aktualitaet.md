# Wie aktuell die Engine ihre Itemdaten sieht (#136 AK 3)

Stand 0.7.73 (Release-Satz seit 20.11.2026: local_catquiz 2026111707/2026111708). Bewertet wird, was die Engine (local_catquiz seit #146) bei der Auswahl der nächsten Frage über Items,
Parameter und Nutzung weiß, wie alt das sein kann, was davon das Labor betrifft — und wie es geprüft wird. Gemessen an
der installierten Engine, nicht angenommen.

## Die drei Quellen und ihr Alter

| Was | Wo | Wie alt höchstens | Wann neu gelesen |
|---|---|---|---|
| Items, Parameter (Schwierigkeit …), Status im Skalenbaum | Runtime-Pool, Application-Cache `runtimepool` | bis zur nächsten Invalidierung | sofort bei jeder Änderung über die Engine: Ereignisse `changesintestitems`, `changesinitemparams`, `changesincatscales` |
| Globale Nutzung je Item (`attempts`: Antworten im CAT-Kontext) | derselbe Runtime-Pool | **600 s** (TTL) — keine Invalidierung durch neue Antworten | nach Ablauf der TTL oder bei einer der Änderungen oben |
| Fragenbank: Frage verborgen oder Entwurf | derselbe Runtime-Pool | **600 s** — die Fragenbank löst kein Ereignis der Engine aus | nach Ablauf der TTL |
| Persönliche Historie (`userattempts`, `userlastattempttime`: die Grundlage der lastplayed-Pönale) | Session-Cache, je Person und Generation | bis zum nächsten Versuchsstart dieser Person | bei jedem Versuchsstart (`catscale::renew_user_question_stats()`) |

## Was davon die Auswahl beeinflusst

- **Globale Nutzung** geht nur in zwei Bausteine ein: die Ausgleichsstrategie (`strategybalancedscore`) und die
  Pilot-Entscheidung (`maybe_return_pilot`). Die Ausgleichsstrategie hat in der Engine keine Klasse und kann im Labor
  nicht gespielt werden (`strategy_catalog`: „balanced“ nur für historische Definitionen). Pilot-Items gibt es nur, wo
  ein Experiment sie einschaltet. Mit Nutzungszahlen bis 10 Minuten alt kann ein Pilot-Item in dieser Zeit öfter
  ausgespielt werden, als sein Kalibrierungsbedarf verlangt — eine Abweichung der Pilotverteilung, kein unzulässiges
  Item.
- **lastplayed-Pönale** (`lasttimeplayedpenalty`): wirkt nur, wo dieselbe Person ein Item schon gesehen hat. Im Labor
  hat jeder Twin genau eine Bearbeitung je Lauf; die Pönale ist dort für jedes Item gleich (nie gesehen). In der
  Produktion sieht ein zweiter, parallel offener Versuch derselben Person die Items des ersten erst ab seinem nächsten
  Start — die Pönale ist also bis zur Dauer eines Versuchs alt, nicht bis zur TTL. Items desselben Versuchs schließt
  die Engine ohnehin aus (`removeplayedquestions`).
- **Unzulässige Items**: Ein Item, das in seiner Skala inaktiv gesetzt wird, verschwindet sofort aus dem Pool
  (Ereignis). Eine in der Fragenbank verborgene Frage kann dagegen bis zu 600 s weiter ausgespielt werden — das ist die
  Semantik der Engine (dokumentiert in `local_catquiz/db/caches.php`), nicht des Labors. Das Labor ändert die
  Fragenbank seiner Läufe während des Spiels nicht; wer auf einer Installation während laufender Tests Fragen verbirgt,
  muss die zehn Minuten kennen. Eine Änderung daran gehört als Issue in local_catquiz.

## Was das Labor zeigt

Die Exposition im Labor (Konzentration, HHI, maximale und mittlere Exposition, sortierte Expositionskurve) wird aus den
tatsächlich verabreichten Items der gewählten Bearbeitungen gezählt, Schritt für Schritt — nie aus den Nutzungszahlen
der Engine. Die Ergebnisseite sagt das unter der Tabelle (`exposure:source`), damit keine 10 Minuten alte
Engine-Zahl als Echtzeit-Exposition gelesen wird.

## Wie es geprüft wird

`tests/playwright/engine_session.spec.js` (Abnahme-Workflow, Job `engine-session`, Moodle 4.5/5.2/5.3 × release/
candidate) mit `cli/engine_session_seed.php`:

1. Zwei offene Versuche einer Person in einer Sitzung, ein zweiter Lerner, `purge_caches`, 610 s Warten über die TTL —
   nach jedem Schritt: keine Frage doppelt, jede aus dem eigenen Pool, nichts verloren.
2. **Änderung bei offenen Versuchen** (`--change-items`): Der Pool wird vorher aus dem warmen Cache gelesen; dann alle
   Schwierigkeiten von Aktivität A +0,25 Logit (mit der Invalidierung, die das Speichern der Engine auslöst) und ein
   noch von niemandem gesehenes Item von A über den Engine-Aufruf `catscale::add_or_update_testitem_to_scale()` inaktiv.
   Geprüft: Das Item war im warmen Pool, ist im Pool direkt danach nicht mehr, keine Schwierigkeit im Pool ist älter als
   die Änderung — und keine der folgenden Fragen beider Lerner in A ist dieses Item.
3. **Alter der Nutzungszahlen** (`--usage-probe`, am Ende): Der Pool aus dem Cache gegen denselben Pool frisch aus der
   Datenbank: wie viele Items, um wie viele Antworten zurück (`readings.json` → `usage`). Gefordert: nie voraus. Lokal
   (Moodle 4.5, Engine 2026102408): 3 von 19 Items, zusammen 6 Antworten, höchstens 2 je Item zurück — die
   10-minütige Aktualität, gemessen.

## Befund auf der Release-Engine bis 0.7.72 (CI d23b39c0, 19.11.2026)

Der Schritt 2 scheiterte in allen drei Release-Jobs (Moodle 4.5, 5.2, 5.3) und lief in allen drei Kandidaten-Jobs durch.
Der damalige Release-Satz (local_catquiz `ee1f096`, 2026100922) hielt den Runtime-Pool im Session-Cache
`adaptivequizattempt`, der nur auf `changesinadaptivequizattempt` invalidiert wird: ein in seiner Skala inaktiv
gesetztes Item und die neuen Schwierigkeiten blieben im warmen Pool, bis die Sitzung einen neuen Versuch begann. Genau
das, wofür der Test geschrieben ist. Die Engines seit #146 (Application-Cache `runtimepool`, seit 2026111706 mit
Revisionen, `runtimepoolrevision` auf die drei Ereignisse) bauen den Pool bei diesen Änderungen neu. Mit dem
Release-Satz vom 20.11.2026 lokal (Moodle 4.5, Engine 2026111707): 20 Parameterzeilen geändert, ein Item inaktiv,
`warmpool` 20 → `pool` 19, das Item war im warmen Pool und ist danach nicht mehr darin, keine veraltete Schwierigkeit;
Nutzungsprobe: 3 von 19 Items, 6 Antworten zurück, höchstens 2 je Item, keins voraus. Der alte Satz steht nur noch in
`engine-pins-previous.env` (Start der Upgrade-Prüfung, Kostenvergleich); im Session-Test läuft er nicht mehr.
