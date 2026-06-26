<?php
/**
 * One-time installer (web-root copy). Lives at /install.php so the /api
 * rewrite rules don't intercept it. Creates the admin user from
 * api/config.php, verifies the DB + tables, then SELF-DELETES.
 *   Open once:  https://app.cosmos.in/install.php
 */
require_once __DIR__ . '/api/lib/helpers.php';

header('Content-Type: text/plain; charset=utf-8');
$cfg = config();

try {
    $pdo = db();
    echo "OK  Connected to MySQL ({$cfg['db']['name']})\n";

    $need = ['users','uploads','cards','ocr_results','companies','leads','contacts','duplicates','exports','audit_logs'];
    $have = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_diff($need, $have);
    if ($missing) { echo "ERR Missing tables: " . implode(', ', $missing) . "\n"; exit; }
    echo "OK  All " . count($need) . " tables present\n";

    $email = strtolower(trim($cfg['admin_email']));
    $hash  = password_hash($cfg['admin_password'], PASSWORD_BCRYPT);
    $existing = q1('SELECT id FROM users WHERE email = ?', [$email]);
    if ($existing) {
        exec_sql('UPDATE users SET passwordHash = ?, role = ?, isActive = 1, updatedAt = ? WHERE id = ?',
            [$hash, 'ADMIN', now3(), $existing['id']]);
        echo "OK  Admin user updated: $email\n";
    } else {
        exec_sql('INSERT INTO users (id, email, passwordHash, name, role, isActive, createdAt, updatedAt)
                  VALUES (?,?,?,?,?,1,?,?)',
            [uuid_v4(), $email, $hash, 'Cosmos Admin', 'ADMIN', now3(), now3()]);
        echo "OK  Admin user created: $email\n";
    }

    echo "OK  Groq key " . (config()['groq_api_key'] ? 'is set — scanning ready' : 'NOT set yet (add it in api/config.php)') . "\n";
    echo "\nSetup complete. Log in at https://app.cosmos.in\n";
    @unlink(__FILE__);
    echo file_exists(__FILE__) ? "WARN could not self-delete — remove /install.php manually.\n"
                               : "OK  Installer removed.\n";
} catch (Throwable $e) {
    echo "ERR " . $e->getMessage() . "\n";
}
