<?php
/**
 * Single shared PDO (MySQL) connection.
 * Prepared statements only — never interpolate user input into SQL.
 */

function db() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $d = config()['db'];
    $dsn = "mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false, // safer on shared hosting conn limits
        ]);
    } catch (Throwable $e) {
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database unavailable']);
        exit;
    }
    return $pdo;
}

/** Run a prepared query and return all rows. */
function q($sql, $params = []) {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** Run a prepared query and return the first row (or null). */
function q1($sql, $params = []) {
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** Run a prepared statement (INSERT/UPDATE/DELETE); returns affected rows. */
function exec_sql($sql, $params = []) {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}
