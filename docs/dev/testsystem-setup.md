# Testsystem-Setup: `local_catquizlab` installieren und verifizieren

Diese Anleitung bringt den Stub auf ein Moodle-Testsystem (4.5+). Der Stub
installiert bewusst ohne harte Abhängigkeiten; für spätere Experimentläufe
müssen `local_catquiz` und der Wunderbyte-Fork von `mod_adaptivequiz`
(inkl. `adaptivequizcatmodel_catquiz` und `local_wunderbyte_table`)
vorhanden sein — der Umgebungsstatus auf der Einstellungsseite zeigt das an.

## 1. Installation

Variante A — Git (empfohlen auf dem Testsystem):

    cd <MOODLE_ROOT>/local
    git clone https://github.com/ralferlebach/moodle-local_catquizlab.git catquizlab

Variante B — ZIP: Das Release-ZIP enthält den Ordner `catquizlab/`; diesen
nach `<MOODLE_ROOT>/local/` entpacken oder über *Website-Administration →
Plugins → Plugin installieren* hochladen.

Dann das Upgrade ausführen:

    php <MOODLE_ROOT>/admin/cli/upgrade.php --non-interactive

oder im Browser über *Website-Administration → Mitteilungen*.

## 2. Verifikation

1. *Website-Administration → Plugins → Lokale Plugins → CAT-Experimenten-
   Suite* öffnen. Erwartet: Umgebungsstatus (Engine gefunden/fehlend),
   „Experimentläufe aktivieren" = aus, „Instanz-Rolle" = Node.
2. Datenbank: Tabelle `mdl_local_catquizlab_experiment` existiert
   (Präfix ggf. abweichend).
3. Capabilities: `local/catquizlab:manage` und `:view` erscheinen unter
   *Rechte ändern* im Systemkontext (Manager-Archetyp).

## 3. Testumgebungen initialisieren (optional, für Entwicklung)

PHPUnit:

    cd <MOODLE_ROOT>
    php admin/tool/phpunit/cli/init.php
    vendor/bin/phpunit --testsuite local_catquizlab_testsuite

Behat:

    php admin/tool/behat/cli/init.php
    php admin/tool/behat/cli/run.php --tags=@local_catquizlab

Beide Aufrufe stehen auch im Makefile (`make phpunit`, `make behat`).

## 4. Worker-Toolchain (optional, ab Meilenstein M1 relevant)

Auf dem Host, der später die Puppeteer-Läufe ausführt (kann der
Applikationsserver sein):

    cd <MOODLE_ROOT>/local/catquizlab/worker
    npm install
    node run_attempt.js --base-url=<wwwroot> --run-id=0 --token=dummy

Erwartete Ausgabe: die Prüfliste des Selbsttests, abschließend
`Worker self test passed; no Moodle instance was contacted.` Der Selbsttest
prüft Argumentverarbeitung, URL-Aufbau, Antwortauswahl sowie das Laden von
Puppeteer und den Start eines Browsers; er ruft keinen Webservice auf und
claimt keinen Job. Mit `--no-browser` entfällt der Browserstart.
Node 20+ wird benötigt; `npm install` lädt Puppeteer samt Chromium.

## 5. Deinstallation / Reset

Der Stub legt genau eine Tabelle und zwei Capabilities an; die
Deinstallation über die Plugin-Übersicht entfernt beides rückstandsfrei.
Ab Meilenstein M2 kommen dedizierte Reset-Routinen hinzu (Backlog E2.5).
