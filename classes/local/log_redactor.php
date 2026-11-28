<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * What may go into a log, and in what form (#149).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * One rule for every log the plugin writes: which parameter names are secret, how a URI loses their values, and how
 * a value is made safe for a single log line.
 *
 * Deliberately free of Moodle calls and of mbstring: the fatal-error log line is written at shutdown after a memory
 * exhaustion, and in a test process that loads nothing but this class and the guard.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class log_redactor {
    /** @var string What a secret value is replaced with. */
    public const REDACTED = '[redacted]';

    /** @var string What the value of a parameter nobody listed is replaced with (#149 AK2). */
    public const OMITTED = '[omitted]';

    /**
     * @var string[] Parameters whose values a request's URI keeps in a log, after normalising (see
     * {@see normalise_name()}): what says which page, which object and which step — numbers, tabs, filters. Every
     * other parameter keeps its name and loses its value (#149 AK2: unknown parameters are not logged). The same list
     * is in worker/log_params.json for the worker's own logs; log_redactor_test checks that they agree.
     */
    public const ROUTING_NAMES = [
        // Moodle and the activity the twins answer.
        'id', 'cmid', 'courseid', 'contextid', 'attempt', 'attemptid', 'page', 'slot', 'slots', 'qid', 'questionid',
        'uniqueid', 'thispage', 'nextpage', 'finishattempt', 'timeup', 'scrollpos', 'n', 'wsfunction', 'errorcode',
        'lang', 'forceview', 'mode', 'section',
        // The plugin's own pages.
        'action', 'tab', 'experimentid', 'runid', 'personid', 'replication', 'tier', 'model', 'strategy', 'variant',
        'stratum', 'severity', 'validity', 'validityrule', 'hours', 'from', 'to', 'level', 'lines', 'limit', 'offset',
        'perpage', 'sort', 'dir', 'format', 'filter', 'profile', 'cellkey', 'state', 'status', 'kind', 'scope', 'view',
        'xpage', 'step', 'stage', 'workerid', 'after', 'attemptno', 'channel', 'correlationid', 'deep', 'download',
        'executionid',
        'downloadjson', 'engineinfo', 'force', 'fromexperiment', 'groupby', 'logaction', 'metric', 'newestfirst',
        'rawpage', 'show', 'tail', 'taskid', 'classname', 'cmpattempts', 'cmpgroup', 'cmplayout', 'cmpmetric',
        'cmpscale', 'cmptemplate', 'cmptwin',
    ];

    /** @var string[] Parameter names that are secret as they stand, after normalising (see {@see normalise_name()}). */
    protected const SECRET_NAMES = [
        'key', 'apikey', 'privatekey', 'secretkey', 'accesskey', 'enrolkey', 'enrolmentkey', 'wskey',
        'pass', 'passwd', 'pw', 'pwd', 'auth', 'sid', 'mail', 'code', 'otp', 'pin',
    ];

    /** @var string[] Parts of a name that make it secret wherever they appear in it. */
    protected const SECRET_PARTS = [
        'sesskey', 'token', 'secret', 'password', 'passwort', 'authoriz', 'email', 'cookie', 'moodlesession',
        'sessionid', 'credential', 'signature', 'logintoken',
    ];

    /** @var int The longest value one field of a log line may have, in bytes. */
    public const FIELD_MAX = 160;

    /** @var string|null This request's id, once it has one. */
    protected static ?string $requestid = null;

    /** @var string Where the id came from: apache, header, worker or local. */
    protected static string $requestidsource = '';

    /**
     * A parameter name as it is compared: decoded (also when encoded twice), lower case, array brackets and
     * everything but letters and digits removed. "ses%73key", "SessKey[]" and "sess_key" all become "sesskey".
     *
     * @param string $name The name as it was sent.
     * @return string
     */
    public static function normalise_name(string $name): string {
        for ($i = 0; $i < 3; $i++) {
            $decoded = rawurldecode(str_replace('+', ' ', $name));
            if ($decoded === $name) {
                break;
            }
            $name = $decoded;
        }
        $name = strtolower($name);
        $bracket = strpos($name, '[');
        if ($bracket !== false) {
            $name = substr($name, 0, $bracket);
        }

        return (string) preg_replace('/[^a-z0-9]/', '', $name);
    }

    /**
     * Whether a parameter of this name must never reach a log with its value.
     *
     * @param string $name The name as it was sent.
     * @return bool
     */
    public static function is_secret_name(string $name): bool {
        $name = self::normalise_name($name);
        if ($name === '') {
            return false;
        }
        if (in_array($name, self::SECRET_NAMES, true)) {
            return true;
        }
        foreach (self::SECRET_PARTS as $part) {
            if (strpos($name, $part) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a value is, once decoded, an e-mail address: personal, whatever the parameter is called.
     *
     * @param string $value The value as it was sent.
     * @return bool
     */
    public static function looks_like_email(string $value): bool {
        return (bool) preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', rawurldecode(rawurldecode($value)));
    }

    /**
     * Whether a parameter's value may stand in a logged URI: its name is a routing name (#149 AK2).
     *
     * @param string $name The name as it was sent.
     * @return bool
     */
    public static function is_routing_name(string $name): bool {
        return in_array(self::normalise_name($name), self::ROUTING_NAMES, true);
    }

    /**
     * A URI with only the values a diagnosis needs, without its fragment.
     *
     * Every pair of the query is looked at, by its decoded name: encoded names, repeated names and array names
     * are caught as well as the plain ones. A secret parameter's value becomes [redacted]; the value of any parameter
     * that is not a routing name becomes [omitted] (#149 AK2: unknown parameters are not logged); a routing
     * parameter whose value is an e-mail address loses it too. A token in the path itself (tokenpluginfile.php,
     * webservice/pluginfile.php) goes as well. Control characters are removed and the result is at most $max bytes
     * long.
     *
     * @param string $uri The URI.
     * @param int $max The longest result, in bytes.
     * @return string
     */
    public static function redact_uri(string $uri, int $max = 512): string {
        $uri = self::strip_controls($uri);
        $hash = strpos($uri, '#');
        if ($hash !== false) {
            $uri = substr($uri, 0, $hash);
        }
        $at = strpos($uri, '?');
        $path = $at === false ? $uri : substr($uri, 0, $at);
        $path = (string) preg_replace(
            '~(/(?:tokenpluginfile|webservice/pluginfile)\.php/)[^/?]+~i',
            '$1' . self::REDACTED,
            $path
        );
        if ($at === false) {
            return self::cut($path, $max);
        }

        $pairs = [];
        foreach (preg_split('/[&;]/', substr($uri, $at + 1)) as $pair) {
            if ($pair === '') {
                continue;
            }
            $eq = strpos($pair, '=');
            $name = $eq === false ? $pair : substr($pair, 0, $eq);
            $value = $eq === false ? null : substr($pair, $eq + 1);
            if ($value !== null && (self::is_secret_name($name) || self::looks_like_email($value))) {
                $value = self::REDACTED;
            } else if ($value !== null && $value !== '' && !self::is_routing_name($name)) {
                $value = self::OMITTED;
            }
            $pairs[] = $value === null ? $name : $name . '=' . $value;
        }

        // Too long: whole pairs are dropped from the end, never one cut in two — "[redac~cut" would read as a value
        // that escaped redaction, and half a value as a value.
        $whole = $path . '?' . implode('&', $pairs);
        if (strlen($whole) <= $max) {
            return $whole;
        }
        $kept = $path . '?';
        $room = $max - 16;
        foreach ($pairs as $i => $pair) {
            $next = $kept . ($i > 0 ? '&' : '') . $pair;
            if (strlen($next) > $room) {
                break;
            }
            $kept = $next;
        }
        if (strlen($kept) > $room) {
            return self::cut($whole, $max);
        }

        return $kept . '&~cut' . (strlen($whole) - strlen($kept));
    }

    /**
     * Free text without the values of secret "name=value" pairs, wherever in the text they stand.
     *
     * @param string $text The text.
     * @return string
     */
    public static function redact_pairs(string $text): string {
        return (string) preg_replace_callback(
            '/(^|[?&;\s,|(\["\'])([A-Za-z0-9_.%\[\]\-]{1,64})=([^&;?#\s"\'<>|,)]*)/',
            static function (array $m): string {
                if ($m[3] === '' || $m[3] === self::REDACTED) {
                    return $m[0];
                }
                if (self::is_secret_name($m[2]) || self::looks_like_email($m[3])) {
                    return $m[1] . $m[2] . '=' . self::REDACTED;
                }

                return $m[0];
            },
            $text
        );
    }

    /**
     * The script a request ran, without query, fragment or slash arguments — all a fatal-error line needs.
     *
     * @param array $server The request's server variables.
     * @return string
     */
    public static function script_path(array $server): string {
        $uri = (string) ($server['REQUEST_URI'] ?? '');
        $at = strcspn($uri, '?#');
        $path = substr($uri, 0, $at);
        $php = stripos($path, '.php');
        if ($php !== false) {
            $path = substr($path, 0, $php + 4);
        }

        return self::field($path, 120);
    }

    /**
     * A value made safe for one field of a one-line log: printable ASCII only, no spaces, no line breaks, at most
     * $max bytes. Whatever else there was becomes "?", and a cut value says how much was cut.
     *
     * @param string $value The value.
     * @param int $max The longest result, in bytes.
     * @return string
     */
    public static function field(string $value, int $max = self::FIELD_MAX): string {
        $value = (string) preg_replace('/[^\x21-\x7E]/', '?', $value);

        return self::cut($value, $max);
    }

    /**
     * Text without control characters: no line breaks, no terminal escapes, no Unicode line separators.
     *
     * @param string $text The text.
     * @return string
     */
    public static function strip_controls(string $text): string {
        return (string) preg_replace('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]|\xE2\x80[\xA8\xA9]/', '', $text);
    }

    /**
     * At most $max bytes, with how much was cut.
     *
     * @param string $value The value.
     * @param int $max The longest result, in bytes.
     * @return string
     */
    protected static function cut(string $value, int $max): string {
        $length = strlen($value);
        if ($length <= $max) {
            return $value;
        }
        $kept = substr($value, 0, max(0, $max - 12));
        // Not in the middle of a UTF-8 character.
        $kept = (string) preg_replace('/[\xC0-\xFF][\x80-\xBF]*$/', '', $kept);

        return $kept . '~cut' . ($length - strlen($kept));
    }

    /**
     * An id for this request that a log line and an access log, a worker's report or a screenshot can be matched by,
     * and that says nothing about who made it: the web server's (mod_unique_id), a proxy's X-Request-Id, the
     * worker's correlation id, or else a random one.
     *
     * @param array|null $server The request's server variables; $_SERVER when null.
     * @return string
     */
    public static function request_id(?array $server = null): string {
        if (self::$requestid !== null) {
            return self::$requestid;
        }
        $server = $server ?? $_SERVER;
        $candidates = [
            'apache' => [(string) ($server['UNIQUE_ID'] ?? ''), '/^[A-Za-z0-9@_\-]{8,64}$/'],
            'header' => [(string) ($server['HTTP_X_REQUEST_ID'] ?? ''), '/^[A-Za-z0-9._\-]{8,64}$/'],
            'worker' => [(string) ($server['HTTP_X_CATQUIZLAB_CORRELATION'] ?? ''), '/^[A-Za-z0-9_\-]{1,64}$/'],
        ];
        foreach ($candidates as $source => [$value, $pattern]) {
            if ($value !== '' && preg_match($pattern, $value)) {
                self::$requestidsource = $source;
                return self::$requestid = $value;
            }
        }
        self::$requestidsource = 'local';

        return self::$requestid = bin2hex(random_bytes(8));
    }

    /**
     * Where this request's id came from: apache, header, worker or local.
     *
     * @return string
     */
    public static function request_id_source(): string {
        self::request_id();

        return self::$requestidsource;
    }

    /**
     * Forget this request's id — for tests.
     *
     * @return void
     */
    public static function reset(): void {
        self::$requestid = null;
        self::$requestidsource = '';
    }
}
