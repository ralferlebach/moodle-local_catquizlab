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
 * An HTTP client for the acceptance scripts (#121, #132): a real login, a real session, the server's real answers.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * A logged-in browser without a browser: one connection, its cookies in memory, every request through the web server.
 *
 * For the CLI acceptance scripts, which run on the server they test and check what its pages and downloads hand out.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class acceptance_client {
    /** @var \CurlHandle|resource One connection, with its cookies in memory, for every request of this user. */
    protected $ch;

    /** @var bool Whether to skip TLS verification (a self-signed test server). */
    protected bool $insecure;

    /**
     * A temporary user with a password, and the manager role in the system context where asked.
     *
     * @param string $kind A word for the user name.
     * @param bool $manager Whether to give the manager role.
     * @return array{0: \stdClass, 1: string} The user and the password.
     */
    public static function temporary_user(string $kind, bool $manager): array {
        global $CFG;
        require_once($CFG->dirroot . '/user/lib.php');

        $password = 'Cql#' . random_string(20) . '9a';
        $user = (object) ['username' => 'cqlaccept_' . $kind . '_' . strtolower(random_string(6)), 'password' => $password,
            'firstname' => 'Acceptance', 'lastname' => ucfirst($kind), 'email' => 'cqlaccept@example.invalid',
            'auth' => 'manual', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id];
        $user->id = user_create_user($user, true, false);
        if ($manager) {
            $roles = get_archetype_roles('manager');
            role_assign((int) $roles[array_key_first($roles)]->id, $user->id, \context_system::instance()->id);
        }

        return [$user, $password];
    }

    /**
     * Remove a temporary user again.
     *
     * @param \stdClass $user The user from {@see temporary_user()}.
     * @return void
     */
    public static function remove_user(\stdClass $user): void {
        global $DB;
        $record = $DB->get_record('user', ['id' => $user->id]);
        if ($record) {
            delete_user($record);
        }
    }

    /**
     * Log in.
     *
     * @param string $username The user name.
     * @param string $password The password.
     * @param bool $insecure Skip TLS verification.
     */
    public function __construct(string $username, string $password, bool $insecure) {
        global $CFG;
        $this->insecure = $insecure;
        $this->ch = curl_init();
        // An empty cookie file switches on curl's cookie engine without writing a file. A browser's user agent: Moodle
        // gives a request without one no session, and the login token then never matches.
        curl_setopt($this->ch, CURLOPT_COOKIEFILE, '');
        curl_setopt($this->ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; local_catquizlab acceptance)');
        $page = $this->get($CFG->wwwroot . '/login/index.php')['body'];
        preg_match('/name="logintoken" value="([^"]+)"/', $page, $m);
        $this->get($CFG->wwwroot . '/login/index.php', ['username' => $username, 'password' => $password,
            'logintoken' => $m[1] ?? '']);
    }

    /**
     * A query string with a plain "&".
     *
     * Moodle sets arg_separator.output to "&amp;" for its pages; a request built with it loses every parameter after the
     * first — the login, for one, then fails on its token.
     *
     * @param array $params The parameters.
     * @return string
     */
    public static function query(array $params): string {
        return http_build_query($params, '', '&');
    }

    /**
     * Fetch a URL, GET or POST.
     *
     * @param string $url The URL.
     * @param array|null $post Fields to post.
     * @param string[] $headers Extra request headers, "Name: value".
     * @return array{status: int, type: string, disposition: string, body: string, seconds: float}
     */
    public function get(string $url, ?array $post = null, array $headers = []): array {
        $received = [];
        $ch = $this->ch;
        curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 900, CURLOPT_HTTPGET => true, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => !$this->insecure, CURLOPT_SSL_VERIFYHOST => $this->insecure ? 0 : 2,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$received): int {
                if (strpos($line, ':') !== false) {
                    [$name, $value] = explode(':', $line, 2);
                    $received[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            }]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, self::query($post));
        }
        $start = microtime(true);
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return ['status' => $status, 'type' => (string) ($received['content-type'] ?? ''),
            'disposition' => (string) ($received['content-disposition'] ?? ''), 'body' => $body,
            'seconds' => round(microtime(true) - $start, 2)];
    }

    /**
     * The session key of the logged-in session, from a page.
     *
     * @return string
     */
    public function sesskey(): string {
        global $CFG;
        preg_match('/"sesskey":"([^"]+)"/', $this->get($CFG->wwwroot . '/my/')['body'], $m);

        return $m[1] ?? '';
    }
}
