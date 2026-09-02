# Entwicklungs- und Testumgebung

Diese Anleitung beschreibt eine Umgebung, in der sich `local_catquizlab`
vollständig verifizieren lässt: PHPUnit, Behat, phpcs und die PHPDoc-Prüfung.
Sie ist keine Theorie — genau diese Schritte wurden in Session 002 auf einem
frischen Ubuntu-24.04-Container durchlaufen, und die aufgeführten Stolpersteine
sind die, die dabei tatsächlich aufgetreten sind.

Für die Bedienung des fertigen Systems siehe `docs/dev/durchfuehrung.md`, für
die Teststrategie `docs/dev/testen.md`, für ein Instanz-Setup mit Engine
`docs/dev/testsystem-setup.md`.

---

## 1. Systempakete

```bash
apt-get update
apt-get install -y --no-install-recommends \
    php-cli php-xml php-mbstring php-curl php-zip php-intl \
    php-pgsql php-gd php-soap php-bcmath \
    postgresql git unzip locales
```

Moodle 4.5 läuft mit PHP 8.1 bis 8.3. Die Extensions sind nicht optional:
ohne `pgsql` startet PHPUnit nicht, ohne `gd`/`soap`/`intl` bricht der
Environment-Check der Installation ab.

## 2. PHP-Einstellungen

```bash
PHPINI=$(php -i | grep "Loaded Configuration File" | awk '{print $NF}')
printf "\nmax_input_vars=8000\nmemory_limit=1024M\nmax_execution_time=0\n" >> "$PHPINI"
```

`max_input_vars` muss mindestens 5000 betragen, sonst verweigert
`install_database.php` den Dienst mit einem Environment-Fehler. Der Default von
1000 reicht nicht.

## 3. Locale

```bash
sed -i 's/^# *en_AU.UTF-8/en_AU.UTF-8/' /etc/locale.gen
locale-gen en_AU.UTF-8
```

Moodles PHPUnit-Initialisierung besteht auf `en_AU.UTF-8` und bricht sonst mit
„Required locale is not installed" ab.

## 4. Datenbank

```bash
service postgresql start
su postgres -c "psql -c \"ALTER USER postgres WITH PASSWORD 'moodle';\""
su postgres -c "createdb moodle"
```

In einem Container ohne systemd überlebt der Dienst keinen Neustart der
Sitzung — vor jedem Testlauf `service postgresql start` aufrufen. Ein
fehlgeschlagener PHPUnit-Bootstrap mit „Connection refused" hat fast immer
diese Ursache und nicht die Konfiguration.

## 5. Moodle und das Plugin

```bash
git clone --depth 1 -b MOODLE_405_STABLE https://github.com/moodle/moodle.git ~/moodle
mkdir -p ~/moodledata ~/moodledata_phpunit ~/moodledata_behat ~/behat_faildumps
git clone https://github.com/ralferlebach/moodle-local_catquizlab.git ~/moodle/local/catquizlab
```

Das Plugin muss **innerhalb** des Moodle-Baums liegen. Mehrere phpcs-Sniffs
(`moodle.Files.LangFilesOrdering`, `moodle.PHPUnit.TestCaseCovers`) schweigen
bei einer Prüfung außerhalb, und „lokal grün" bedeutet dann nichts.

## 6. config.php

```php
<?php
unset($CFG);
global $CFG;
$CFG = new stdClass();
$CFG->dbtype    = 'pgsql';
$CFG->dblibrary = 'native';
$CFG->dbhost    = 'localhost';
$CFG->dbname    = 'moodle';
$CFG->dbuser    = 'postgres';
$CFG->dbpass    = 'moodle';
$CFG->prefix    = 'mdl_';
$CFG->dboptions = ['dbpersist' => 0, 'dbport' => 5432, 'dbsocket' => '', 'dbcollation' => ''];
$CFG->wwwroot   = 'http://127.0.0.1:8000';
$CFG->dataroot  = '/home/claude/moodledata';
$CFG->admin     = 'admin';
$CFG->directorypermissions = 0777;

$CFG->phpunit_dataroot = '/home/claude/moodledata_phpunit';
$CFG->phpunit_prefix   = 'phpu_';

$CFG->behat_dataroot      = '/home/claude/moodledata_behat';
$CFG->behat_prefix        = 'bht_';
$CFG->behat_wwwroot       = 'http://127.0.0.1:8001';
$CFG->behat_faildump_path = '/home/claude/behat_faildumps';

$CFG->behat_profiles = [
    'default' => [
        'browser' => 'chrome',
        'wd_host' => 'http://127.0.0.1:4444',
        'capabilities' => [
            'extra_capabilities' => [
                'goog:chromeOptions' => [
                    'binary' => '/tmp/chrome-linux64/chrome',
                    'args'   => [
                        'no-sandbox', 'headless=new', 'disable-dev-shm-usage',
                        'disable-gpu', 'window-size=1366,1000',
                    ],
                ],
            ],
        ],
    ],
];

require_once(__DIR__ . '/lib/setup.php');
```

`behat_wwwroot` **muss** sich von `wwwroot` unterscheiden, sonst verweigert
`admin/tool/behat/cli/init.php` die Konfiguration. Deshalb Port 8001 für Behat
und 8000 für die normale Instanz.

Der Server wird an `127.0.0.1` gebunden, nicht an `localhost`: letzteres kann
auf `::1` auflösen, wo PHPs eingebauter Server nicht lauscht, und der Client
meldet dann HTTP 0.

## 7. Installation

```bash
cd ~/moodle
php admin/cli/install_database.php --agree-license \
    --adminpass='Admin123!' --adminemail=admin@example.com \
    --fullname="CATLab" --shortname="CATLab"

composer install --no-interaction   # PHPUnit, Behat
php admin/tool/phpunit/cli/init.php
```

`php admin/tool/phpunit/cli/init.php` ist **nach jeder Schemaänderung** des
Plugins erneut aufzurufen, sonst meldet PHPUnit „environment was initialised
for different version".

## 8. Prüfwerkzeuge

```bash
export COMPOSER_ALLOW_SUPERUSER=1
composer global require moodlehq/moodle-cs
composer -d ~/.config/composer config --no-plugins \
    allow-plugins.dealerdirect/phpcodesniffer-composer-installer true
composer -d ~/.config/composer update
export PATH="$HOME/.config/composer/vendor/bin:$PATH"

git clone --depth 1 https://github.com/moodlehq/moodle-local_moodlecheck.git \
    ~/moodle/local/moodlecheck
```

Das phpcs-Composer-Plugin registriert den `moodle`-Standard. Ohne die
`allow-plugins`-Freigabe wird es übersprungen und phpcs meldet „Referenced
sniff 'moodle' does not exist" — der Standard ist dann installiert, aber nicht
angemeldet.

## 9. Browser für Behat

```bash
V=131.0.6778.85
cd /tmp
curl -sL -o chrome.zip       "https://storage.googleapis.com/chrome-for-testing-public/$V/linux64/chrome-linux64.zip"
curl -sL -o chromedriver.zip "https://storage.googleapis.com/chrome-for-testing-public/$V/linux64/chromedriver-linux64.zip"
unzip -q chrome.zip && unzip -q chromedriver.zip

apt-get install -y --no-install-recommends \
    libnss3 libatk1.0-0 libatk-bridge2.0-0 libcups2 libdrm2 libxkbcommon0 \
    libxcomposite1 libxdamage1 libxfixes3 libxrandr2 libgbm1 \
    libpango-1.0-0 libcairo2 libasound2t64
```

Chrome und Chromedriver müssen dieselbe Hauptversion haben. Ist bereits ein
anderes Chrome installiert (etwa unter `/opt/google/chrome`), übernimmt der
Chromedriver dieses und scheitert mit „This version of ChromeDriver only
supports Chrome version 131" — deshalb der `binary`-Eintrag in
`behat_profiles` oben.

Die Ubuntu-Pakete `chromium-browser`/`chromium-chromedriver` sind in 24.04 nur
Snap-Wrapper und in einem Container ohne snapd nutzlos.

## 10. Die vier Gates

```bash
# 1. PHPUnit
service postgresql start
cd ~/moodle && vendor/bin/phpunit --testsuite local_catquizlab_testsuite --no-coverage

# 2. Coding-Standard
cd ~/moodle/local/catquizlab && phpcs --standard=phpcs.xml --extensions=php .

# 3. PHPDoc
cd ~/moodle && php local/moodlecheck/cli/moodlecheck.php --path=local/catquizlab --format=text

# 4. Behat
cd ~/moodle
php admin/tool/behat/cli/init.php
(nohup /tmp/chromedriver-linux64/chromedriver --port=4444 >/tmp/chromedriver.log 2>&1 &)
(nohup php -S 127.0.0.1:8001 -t ~/moodle >/tmp/webserver.log 2>&1 &)
vendor/bin/behat --config ~/moodledata_behat/behatrun/behat/behat.yml \
    --tags @local_catquizlab
```

`make check` im Plugin-Verzeichnis bündelt die statischen Prüfungen, sofern
`moodle-plugin-ci` verfügbar ist; die Einzelaufrufe oben decken dieselben Gates
auch ohne es ab.

## 11. Engine-Plugins

Die Suite erkennt `local_catquiz` und `mod_adaptivequiz` zur Laufzeit
(`classes/local/environment.php`) und schaltet ohne sie sauber ab — deshalb ist
sie in dieser Umgebung ohne Engine installierbar und die Tests bleiben grün.
Für einen echten Durchlauf werden zusätzlich gebraucht:

- `local_catquiz` (CAT-Engine, liefert die Strategiekonstanten und die
  catmodel-Subplugins),
- `mod_adaptivequiz` (Trägeraktivität),
- `adaptivequizcatmodel_catquiz` (Brücke zwischen beiden).

Beim Aktualisieren dieser Plugins gilt: Engine-Interna nie raten, sondern im
Quellcode nachsehen. Die Strategiekonstanten stehen in `local_catquiz/lib.php`,
die verfügbaren Modelle unter `local_catquiz/catmodel/`.

## 12. Wiederkehrende Stolpersteine

| Symptom | Ursache |
|---|---|
| „Connection refused" beim PHPUnit-Bootstrap | PostgreSQL läuft nicht mehr; `service postgresql start` |
| „environment was initialised for different version" | Schemaänderung; `admin/tool/phpunit/cli/init.php` erneut ausführen |
| „Referenced sniff 'moodle' does not exist" | phpcs-Composer-Plugin nicht freigegeben |
| „behat_wwwroot must be different from wwwroot" | beide Ports identisch |
| „ChromeDriver only supports Chrome version N" | fremdes Chrome im Pfad; `binary` in `behat_profiles` setzen |
| phpcs lokal grün, CI rot | Plugin außerhalb des Moodle-Baums geprüft |
| „max_input_vars must be at least 5000" | PHP-Default 1000 nicht erhöht |

## Debug-Stufe und `store_debug_info`

Die beiden vertragen sich derzeit nicht. `local_catquiz | store_debug_info`
füllt `local_catquiz_attempts.debug_info` — die Quelle für den
Fähigkeitsverlauf je Schritt. Auf einer Instanz mit
`$CFG->debug = DEBUG_DEVELOPER` bricht damit aber jeder Attempt beim ersten
Frageaufruf ab: Ein ungeprüfter Zugriff auf `lastquestion` in der
Debug-Ausgabe der Engine ist dort eine Ausnahme statt einer Notice
(siehe `docs/design/issue-catquiz-debuginfo-lastquestion.md`).

Für Sammelläufe gilt deshalb:

| Zweck | `$CFG->debug` | `store_debug_info` |
|---|---|---|
| Attempts spielen, Verlauf einsammeln | `0` | `1` |
| Plugin-Code entwickeln, PHPUnit, Behat | `DEBUG_DEVELOPER` | `0` |

Die Testumgebung steht auf der ersten Zeile, weil ohne Verlauf die halbe
Auswertung leer bleibt. Für Arbeit am Plugin selbst lohnt das Umschalten —
Moodles eigene Entwicklerprüfungen sind zu wertvoll, um dauerhaft darauf zu
verzichten.
