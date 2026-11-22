#!/usr/bin/env bash
# The web server's, PHP's and the database's limits as they are in force, as JSON on stdout (#110: "PHP-/Webserver-
# Grenzen dokumentieren", "Datenbankseitig prüfen": memory_limit, max_execution_time, pm.max_children, the web server's
# and the gateway's timeouts, the database's connection limit and lock timeouts).
#
# PHP is asked through the web server itself (a probe file in the web root for one request, removed at once): the
# command line's values are not the web server's. Apache and PHP-FPM from their configuration as Apache and FPM
# parse it; the database through Moodle (db_health.php). A value not set is said to be so, with the default that
# applies — never left out.
#
#     MOODLE_ROOT=… CORE_ROOT=… WEB_URL=http://127.0.0.1 PHPV=8.3 bash server-limits.sh > limits.json
set -u
ROOT="${MOODLE_ROOT:?}"
CORE="${CORE_ROOT:-$ROOT}"
URL="${WEB_URL:-http://127.0.0.1}"
PHPV="${PHPV:-8.3}"
probe="catquizlab-limits-$$-$RANDOM.php"
cat > "$ROOT/$probe" <<'PHP'
<?php
header('Content-Type: application/json');
$keys = ['memory_limit', 'max_execution_time', 'max_input_time', 'post_max_size', 'upload_max_filesize',
    'default_socket_timeout', 'max_input_vars', 'opcache.enable', 'opcache.memory_consumption'];
$out = ['sapi' => PHP_SAPI, 'version' => PHP_VERSION];
foreach ($keys as $key) {
    $out[$key] = ini_get($key);
}
echo json_encode($out);
PHP
php_web=$(curl -s --max-time 20 "$URL/$probe" || true)
rm -f "${ROOT:?}/$probe"
fpm_pool="/etc/php/$PHPV/fpm/pool.d/www.conf"
fpm_main="/etc/php/$PHPV/fpm/php-fpm.conf"
apache_main=/etc/apache2/apache2.conf
apache_conf=$(cat /etc/apache2/apache2.conf /etc/apache2/conf-enabled/*.conf /etc/apache2/sites-enabled/*.conf \
    /etc/apache2/mods-enabled/*.conf 2>/dev/null || true)
db=$(cd "$CORE" && php "$ROOT/local/catquizlab/cli/db_health.php" --snapshot 2>/dev/null || echo '{}')
PHP_WEB="$php_web" FPM_POOL="$fpm_pool" FPM_MAIN="$fpm_main" APACHE_CONF="$apache_conf" DB="$db" \
    APACHE_MAIN="$apache_main" python3 - <<'PY'
import json, os, re

def conf(path, keys):
    out = {}
    try:
        text = open(path).read()
    except OSError:
        return {k: 'file missing: ' + path for k in keys}
    for key in keys:
        m = re.search(r'^\s*' + re.escape(key) + r'\s*=\s*(.+?)\s*$', text, re.M)
        out[key] = m.group(1) if m else 'not set'
    return out

def apache(keys, text):
    out = {}
    for key, default in keys.items():
        m = re.findall(r'^\s*' + key + r'\s+(\S+)', text, re.M | re.I)
        out[key] = m[-1] if m else f'not set (default {default})'
    return out

try:
    php_web = json.loads(os.environ.get('PHP_WEB') or '{}')
except ValueError:
    php_web = {'unreadable': (os.environ.get('PHP_WEB') or '')[:200]}
try:
    db = json.loads(os.environ.get('DB') or '{}')
except ValueError:
    db = {}
text = os.environ.get('APACHE_CONF', '')
limits = {
    'php_web': php_web or {'unreadable': 'no answer from the probe'},
    'php_fpm_pool': conf(os.environ['FPM_POOL'], ['pm', 'pm.max_children', 'request_terminate_timeout',
                                                  'listen.backlog', 'php_admin_value[memory_limit]',
                                                  'php_admin_value[max_execution_time]']),
    'php_fpm_main': conf(os.environ['FPM_MAIN'], ['process_control_timeout', 'emergency_restart_threshold']),
    # Apache's own defaults where the configuration says nothing (httpd 2.4 documentation).
    'apache': apache({'Timeout': 60, 'ProxyTimeout': 'Timeout', 'KeepAliveTimeout': 5, 'MaxRequestWorkers': 400,
                      'ServerLimit': 16, 'ThreadsPerChild': 25, 'ListenBacklog': 511}, text),
    # Nothing between the browser and Apache in this setup: no proxy, no gateway — said, not left out.
    'gateway': 'none: the workers reach Apache directly (127.0.0.1), no reverse proxy or gateway in between',
    'database': {'family': db.get('family'), **(db.get('limits') or {'unreadable': 'db_health.php gave nothing'})},
}
print(json.dumps(limits, indent=2))
PY
