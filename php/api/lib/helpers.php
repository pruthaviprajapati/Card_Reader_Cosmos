<?php
/**
 * Shared request/response helpers: JSON I/O, auth guards, UUIDs, audit.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jwt.php';

function config() {
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/../config.php';
    return $c;
}

/** Send a JSON response and stop. */
function json_out($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function error_out($message, $status = 400) {
    json_out(['error' => $message], $status);
}

/** Parse the JSON request body into an associative array. */
function body() {
    static $cached = null;
    if ($cached !== null) return $cached;
    $raw = file_get_contents('php://input');
    $cached = $raw ? (json_decode($raw, true) ?: []) : [];
    return $cached;
}

/** RFC-4122 v4 UUID (matches the format Prisma generated). */
function uuid_v4() {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/** Current MySQL DATETIME(3) timestamp (UTC). */
function now3() {
    return gmdate('Y-m-d H:i:s') . '.000';
}

/**
 * Verify the bearer token. Returns the user payload (sub,email,role,name)
 * or sends 401. Pass false to make auth optional (returns null if absent).
 */
function require_auth($required = true) {
    static $user = null;
    if ($user !== null) return $user;

    $hdr = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) $hdr = $_SERVER['HTTP_AUTHORIZATION'];
    elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $hdr = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    elseif (function_exists('apache_request_headers')) {
        $h = apache_request_headers();
        if (isset($h['Authorization'])) $hdr = $h['Authorization'];
    }

    $token = (stripos($hdr, 'Bearer ') === 0) ? substr($hdr, 7) : null;
    $payload = $token ? jwt_verify($token, config()['jwt_secret']) : null;

    if (!$payload) {
        if ($required) error_out('Invalid or expired token', 401);
        return null;
    }
    $user = $payload;
    return $user;
}

/** Restrict to the given roles, else 403. */
function require_role(...$roles) {
    $user = require_auth(true);
    if (!in_array($user['role'], $roles, true)) error_out('Forbidden', 403);
    return $user;
}

function is_admin($user) {
    return $user && isset($user['role']) && $user['role'] === 'ADMIN';
}

/** Best-effort audit log (never throws). */
function audit($userId, $action, $entityType, $entityId = null, $metadata = null) {
    try {
        exec_sql(
            'INSERT INTO audit_logs (id, userId, action, entityType, entityId, metadata, ip, createdAt)
             VALUES (?,?,?,?,?,?,?,?)',
            [
                uuid_v4(), $userId, $action, $entityType, $entityId,
                $metadata !== null ? json_encode($metadata) : null,
                $_SERVER['REMOTE_ADDR'] ?? null, now3(),
            ]
        );
    } catch (Throwable $e) { /* non-critical */ }
}
