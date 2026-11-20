#!/usr/bin/env bash
#
# One installation for the acceptance measurements (re-audit 18.11.2026): Moodle, the pinned CAT engine, this plugin,
# a database and a production-like web server — Apache (event MPM) in front of PHP-FPM at PHP_MEMORY_LIMIT, with
# mod_status for the scoreboard — or PHP's built-in server where a job needs nothing else.
#
# Every acceptance job calls this script, so that load, trace, scheduler and engine measurements run on the same kind
# of installation, set up the same way, and the release manifest of each job says exactly what that was.
#
# Environment (with defaults):
#   MOODLE_BRANCH=MOODLE_405_STABLE   The Moodle line.
#   PINS=release                      release (.github/engine-pins.env), candidate (engine-pins-candidate.env: the
#                                     branch heads, not pinned) or previous (engine-pins-previous.env).
#   DB=pgsql                          pgsql or mariadb (a service container on 127.0.0.1).
#   WEB=apache                        apache (Apache + PHP-FPM) or builtin (php -S on :8000).
#   PHP_MEMORY_LIMIT=512M             The web server's memory_limit: the production reference.
#   FPM_CHILDREN=64                   PHP-FPM processes (pm = static): the web server's PHP slots.
#   APACHE_MAX_WORKERS=150            Apache's MaxRequestWorkers.
#   PLUGIN_DIR=plugin                 The checkout of this plugin.
#   WEB_PORT=80 (apache) / 8000       The web server's port.
#   DB_NAME=moodle, DB_PORT=5432/3306 The database (a service container on 127.0.0.1).
#
# Writes to $GITHUB_ENV (when set): MOODLE_ROOT (plugins), CORE_ROOT (config.php, admin/cli), WEB_URL, STATUS_URL.
# Leaves engine-manifest.txt and installed-versions.txt in the workspace.

set -euo pipefail

MOODLE_BRANCH="${MOODLE_BRANCH:-MOODLE_405_STABLE}"
PINS="${PINS:-release}"
DB="${DB:-pgsql}"
WEB="${WEB:-apache}"
PHP_MEMORY_LIMIT="${PHP_MEMORY_LIMIT:-512M}"
FPM_CHILDREN="${FPM_CHILDREN:-64}"
APACHE_MAX_WORKERS="${APACHE_MAX_WORKERS:-150}"
PLUGIN_DIR="${PLUGIN_DIR:-plugin}"
DB_NAME="${DB_NAME:-moodle}"
WS="${GITHUB_WORKSPACE:-$PWD}"
PHPV=8.3

say() { echo "::group::$*"; }
done_() { echo "::endgroup::"; }

say "PHP $PHPV with FPM and the extensions Moodle needs"
sudo apt-get update -q
sudo apt-get install -y -q --no-install-recommends "php$PHPV-cli" "php$PHPV-fpm" "php$PHPV-pgsql" "php$PHPV-mysql" \
    "php$PHPV-intl" "php$PHPV-gd" "php$PHPV-soap" "php$PHPV-bcmath" "php$PHPV-zip" "php$PHPV-mbstring" \
    "php$PHPV-curl" "php$PHPV-xml" apache2
sudo update-alternatives --set php "/usr/bin/php$PHPV" || true
# The command line keeps room; the web server runs at PHP_MEMORY_LIMIT.
echo "memory_limit=1024M" | sudo tee "/etc/php/$PHPV/cli/conf.d/99-catquizlab.ini" > /dev/null
echo "max_input_vars=8000" | sudo tee -a "/etc/php/$PHPV/cli/conf.d/99-catquizlab.ini" > /dev/null
php -v
done_

case "$MOODLE_BRANCH" in
    MOODLE_405_STABLE) DIRROOT=moodle ;;
    *) DIRROOT=moodle/public ;;
esac
CORE="$WS/moodle"
ROOT="$WS/$DIRROOT"

say "Moodle $MOODLE_BRANCH and the $PINS engine set"
git clone --depth 1 -b "$MOODLE_BRANCH" https://github.com/moodle/moodle.git "$CORE"
case "$PINS" in
    release) PINS_FILE="$WS/$PLUGIN_DIR/.github/engine-pins.env" ;;
    candidate|previous) PINS_FILE="$WS/$PLUGIN_DIR/.github/engine-pins-$PINS.env" ;;
    *) echo "PINS must be release, candidate or previous, not '$PINS'" >&2; exit 2 ;;
esac
echo "Engine set: $PINS ($(basename "$PINS_FILE"))"
ENGINE_DIR="$ROOT" ENGINE_LAYOUT=moodle ENGINE_SUBMODULES=1 ENGINE_MANIFEST="$WS/engine-manifest.txt" \
    PINS_FILE="$PINS_FILE" MOODLE_BRANCH="$MOODLE_BRANCH" bash "$WS/$PLUGIN_DIR/.github/scripts/fetch-engine.sh"
echo "local_catquizlab  ${GITHUB_SHA:-$(git -C "$WS/$PLUGIN_DIR" rev-parse HEAD 2>/dev/null || echo unknown)}" \
    >> "$WS/engine-manifest.txt"
cp -r "$WS/$PLUGIN_DIR" "$ROOT/local/catquizlab"
rm -rf "$ROOT/local/catquizlab/.git"
done_

if [ "$WEB" = apache ]; then
    WEB_PORT="${WEB_PORT:-80}"
else
    WEB_PORT="${WEB_PORT:-8000}"
fi
URL="http://127.0.0.1$([ "$WEB_PORT" = 80 ] || echo ":$WEB_PORT")"
mkdir -p "$HOME/moodledata"
if [ "$DB" = mariadb ]; then
    DBTYPE=mariadb; DBUSER=root; DBPASS=root; DBPORT="${DB_PORT:-3306}"
else
    DBTYPE=pgsql; DBUSER=postgres; DBPASS=moodle; DBPORT="${DB_PORT:-5432}"
fi
cat > "$CORE/config.php" <<PHP
<?php
unset(\$CFG);
global \$CFG;
\$CFG = new stdClass();
\$CFG->dbtype    = '$DBTYPE';
\$CFG->dblibrary = 'native';
\$CFG->dbhost    = '127.0.0.1';
\$CFG->dbname    = '$DB_NAME';
\$CFG->dbuser    = '$DBUSER';
\$CFG->dbpass    = '$DBPASS';
\$CFG->prefix    = 'mdl_';
\$CFG->dboptions = ['dbpersist' => 0, 'dbport' => $DBPORT, 'dbsocket' => '', 'dbcollation' => 'utf8mb4_unicode_ci'];
\$CFG->wwwroot   = '$URL';
\$CFG->dataroot  = '$HOME/moodledata';
\$CFG->admin     = 'admin';
\$CFG->directorypermissions = 0777;
require_once(__DIR__ . '/lib/setup.php');
PHP

say "Install Moodle"
if [ "$DB" = mariadb ]; then
    mysql -h 127.0.0.1 -P "$DBPORT" -uroot -proot \
        -e "CREATE DATABASE IF NOT EXISTS $DB_NAME DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
fi
(cd "$CORE" && php admin/cli/install_database.php --agree-license --adminpass='Admin123!' \
    --adminemail=admin@example.com --fullname="CATLab acceptance" --shortname="CATLabAcc")
: > "$WS/installed-versions.txt"
for component in core local_catquiz mod_adaptivequiz adaptivequizcatmodel_catquiz local_catquizlab; do
    if [ "$component" = core ]; then v=$(cd "$CORE" && php admin/cli/cfg.php --name=version);
    else v=$(cd "$CORE" && php admin/cli/cfg.php --component="$component" --name=version); fi
    if [ -z "$v" ]; then echo "::error::$component is not installed"; exit 1; fi
    echo "$component $v" | tee -a "$WS/installed-versions.txt"
done
(cd "$CORE" && php admin/cli/adhoc_task.php --execute)
done_

if [ "$WEB" = apache ]; then
    say "Apache (event MPM, MaxRequestWorkers $APACHE_MAX_WORKERS) and PHP-FPM ($FPM_CHILDREN processes, memory_limit $PHP_MEMORY_LIMIT)"
    me=$(id -un); grp=$(id -gn)
    sudo tee "/etc/php/$PHPV/fpm/pool.d/www.conf" > /dev/null <<CONF
[www]
user = $me
group = $grp
listen = /run/php/php$PHPV-fpm.sock
listen.owner = $me
listen.group = $grp
listen.mode = 0660
listen.backlog = 4096
pm = static
pm.max_children = $FPM_CHILDREN
pm.status_path = /fpm-status
request_terminate_timeout = 300
php_admin_value[memory_limit] = $PHP_MEMORY_LIMIT
php_admin_value[max_input_vars] = 8000
php_admin_value[max_execution_time] = 300
catch_workers_output = yes
CONF
    sudo sed -i "s/^export APACHE_RUN_USER=.*/export APACHE_RUN_USER=$me/; s/^export APACHE_RUN_GROUP=.*/export APACHE_RUN_GROUP=$grp/" \
        /etc/apache2/envvars
    sudo a2dismod -q php"$PHPV" mpm_prefork 2>/dev/null || true
    sudo a2enmod -q mpm_event proxy_fcgi setenvif status rewrite headers
    sudo tee /etc/apache2/conf-available/catquizlab-mpm.conf > /dev/null <<CONF
<IfModule mpm_event_module>
    ServerLimit 16
    ThreadsPerChild 25
    MaxRequestWorkers $APACHE_MAX_WORKERS
</IfModule>
ExtendedStatus On
CONF
    sudo tee /etc/apache2/sites-available/catquizlab.conf > /dev/null <<CONF
Listen 127.0.0.1:$WEB_PORT
<VirtualHost 127.0.0.1:$WEB_PORT>
    ServerName 127.0.0.1
    DocumentRoot $ROOT
    <Directory $WS>
        Options FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>
    AcceptPathInfo On
    DirectoryIndex index.php
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/php$PHPV-fpm.sock|fcgi://localhost"
    </FilesMatch>
    <Location /server-status>
        SetHandler server-status
        Require local
    </Location>
    <LocationMatch "^/fpm-status$">
        SetHandler "proxy:unix:/run/php/php$PHPV-fpm.sock|fcgi://localhost"
        Require local
    </LocationMatch>
    ErrorLog /tmp/apache-error.log
    CustomLog /tmp/apache-access.log "%h %t \"%r\" %>s %b %D"
</VirtualHost>
CONF
    sudo a2enconf -q catquizlab-mpm
    sudo a2dissite -q 000-default
    sudo a2ensite -q catquizlab
    # Port 80 is Apache's default Listen already.
    if [ "$WEB_PORT" = 80 ]; then sudo sed -i '/^Listen 127.0.0.1:80$/d' /etc/apache2/sites-available/catquizlab.conf; fi
    sudo service "php$PHPV-fpm" restart
    sudo service apache2 restart
    STATUS="$URL/server-status?auto"
    done_
else
    say "PHP's built-in server on :8000, memory_limit $PHP_MEMORY_LIMIT"
    (cd "$ROOT" && PHP_CLI_SERVER_WORKERS=8 nohup php -d memory_limit="$PHP_MEMORY_LIMIT" -S "127.0.0.1:$WEB_PORT" -t . \
        > /tmp/webserver.log 2>&1 &)
    STATUS=""
    done_
fi

for _ in $(seq 1 30); do
    if curl -sf -o /dev/null "$URL/"; then break; fi
    sleep 1
done
curl -sf -o /dev/null "$URL/" || { echo "::error::The web server at $URL does not answer"; \
    tail -50 /tmp/apache-error.log /tmp/webserver.log 2>/dev/null; exit 1; }
if [ -n "$STATUS" ]; then curl -sf "$STATUS" | head -20; fi

say "Worker dependencies"
(cd "$ROOT/local/catquizlab/worker" && npm install --no-audit --no-fund)
done_

if [ -n "${GITHUB_ENV:-}" ]; then
    {
        echo "MOODLE_ROOT=$ROOT"
        echo "CORE_ROOT=$CORE"
        echo "WEB_URL=$URL"
        echo "CATLAB_BASE_URL=$URL"
        echo "STATUS_URL=$STATUS"
    } >> "$GITHUB_ENV"
fi
echo "Ready: $URL (plugins in $ROOT, core in $CORE, web server $WEB, memory_limit $PHP_MEMORY_LIMIT)"
