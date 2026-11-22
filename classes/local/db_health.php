<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_catquizlab\local;

/**
 * The database under a load step (#110 "Datenbankfehler", "Datenbankseitig prüfen"): its limits, its connections, its
 * locks and deadlocks, and every query Moodle saw fail.
 *
 * Two sources, each counted, none inferred:
 *   the database's own counters, read before and after the step — PostgreSQL pg_stat_database (deadlocks, rolled back
 *   transactions, conflicts) and max_connections, statement/lock timeouts; MySQL/MariaDB SHOW GLOBAL STATUS
 *   (Innodb_deadlocks, Innodb_row_lock_waits, Innodb_row_lock_time, Aborted_connects, Max_used_connections) and
 *   max_connections, lock_wait_timeout;
 *   the requests of the step (request_trace): every exception of Moodle's database layer by class — a failed write
 *   is a dml_write_exception, a lost connection dml_connection_exception, a read dml_read_exception.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class db_health {
    /**
     * The database's limits and counters now.
     *
     * @return array{family: string, limits: array, counters: array, connections: int|null}
     */
    public static function snapshot(): array {
        global $DB;
        $family = $DB->get_dbfamily();
        $limits = [];
        $counters = [];
        $connections = null;
        try {
            if ($family === 'postgres') {
                foreach (
                    ['max_connections', 'statement_timeout', 'lock_timeout', 'idle_in_transaction_session_timeout',
                        'deadlock_timeout', 'superuser_reserved_connections'] as $name
                ) {
                    $limits[$name] = (string) $DB->get_field_sql(
                        'SELECT setting FROM pg_settings WHERE name = ?',
                        [$name]
                    );
                }
                $row = $DB->get_record_sql('SELECT deadlocks, xact_rollback, xact_commit, conflicts, temp_files
                                              FROM pg_stat_database WHERE datname = current_database()');
                foreach ((array) $row as $key => $value) {
                    $counters[$key] = (int) $value;
                }
                $connections = (int) $DB->get_field_sql(
                    'SELECT COUNT(1) FROM pg_stat_activity WHERE datname = current_database()'
                );
            } else if ($family === 'mysql') {
                foreach (
                    ['max_connections', 'lock_wait_timeout', 'innodb_lock_wait_timeout', 'wait_timeout',
                        'max_allowed_packet'] as $name
                ) {
                    $row = $DB->get_record_sql("SHOW GLOBAL VARIABLES LIKE '{$name}'");
                    $limits[$name] = $row ? (string) $row->value : '';
                }
                foreach (
                    ['Innodb_deadlocks', 'Innodb_row_lock_waits', 'Innodb_row_lock_time', 'Aborted_connects',
                        'Aborted_clients', 'Max_used_connections', 'Threads_connected'] as $name
                ) {
                    $row = $DB->get_record_sql("SHOW GLOBAL STATUS LIKE '{$name}'");
                    if ($row) {
                        $counters[strtolower($name)] = (int) $row->value;
                    }
                }
                $connections = $counters['threads_connected'] ?? null;
            }
        } catch (\dml_exception $e) {
            $limits['unreadable'] = $e->getMessage();
        }
        return ['family' => $family, 'limits' => $limits, 'counters' => $counters, 'connections' => $connections];
    }

    /**
     * What a step did to the database: the counters' growth, and the database layer's exceptions in its requests.
     *
     * @param array $before snapshot() before the step.
     * @param array $after snapshot() after it.
     * @param array[] $lines The step's request lines.
     * @return array{limits: array, delta: array, deadlocks: int|null, dmlerrors: array, failedwrites: int, readable: bool}
     */
    public static function step(array $before, array $after, array $lines): array {
        $delta = [];
        foreach ($after['counters'] ?? [] as $key => $value) {
            if (isset($before['counters'][$key])) {
                // Gauges stay as read at the end; counters grow.
                $delta[$key] = in_array($key, ['max_used_connections', 'threads_connected'], true)
                    ? (int) $value : (int) $value - (int) $before['counters'][$key];
            }
        }
        $deadlocks = $delta['deadlocks'] ?? $delta['innodb_deadlocks'] ?? null;
        $dml = [];
        foreach ($lines as $line) {
            $class = (string) ($line['exception']['class'] ?? '');
            if ($class !== '' && (str_starts_with($class, 'dml_') || str_contains($class, '\\dml_'))) {
                $short = substr($class, (int) strrpos('\\' . $class, '\\'));
                $dml[$short] = ($dml[$short] ?? 0) + 1;
            }
        }
        ksort($dml);
        return [
            'family' => (string) ($after['family'] ?? ''),
            'limits' => (array) ($after['limits'] ?? []),
            'connections' => ['before' => $before['connections'] ?? null, 'after' => $after['connections'] ?? null],
            'delta' => $delta,
            'deadlocks' => $deadlocks,
            'dmlerrors' => $dml,
            'failedwrites' => (int) ($dml['dml_write_exception'] ?? 0),
            'readable' => ($after['counters'] ?? []) !== [] && !isset($after['limits']['unreadable']),
        ];
    }
}
