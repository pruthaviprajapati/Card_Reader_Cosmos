<?php
/**
 * Cosmos LIP — PHP API front controller (router).
 * All /api/* and /health requests are rewritten here by .htaccess.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/model.php';
require_once __DIR__ . '/routes/auth.php';
require_once __DIR__ . '/routes/users.php';
require_once __DIR__ . '/routes/leads.php';
require_once __DIR__ . '/routes/upload.php';
require_once __DIR__ . '/routes/duplicates.php';
require_once __DIR__ . '/routes/analytics.php';
require_once __DIR__ . '/routes/export.php';

// Any uncaught error becomes a clean JSON 500 (never an HTML error page).
set_exception_handler(function ($e) {
    error_log('[cosmos] ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json'); }
    echo json_encode(['error' => 'Server error']);
});

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path   = '/' . trim($path, '/');

// ── Health check (no auth) ─────────────────────────────────────────────────
if ($path === '/health') {
    $dbOk = false;
    try { db()->query('SELECT 1'); $dbOk = true; } catch (Throwable $e) {}
    json_out([
        'status' => $dbOk ? 'ok' : 'degraded',
        'env'    => 'production',
        'db'     => $dbOk ? 'ok' : 'unreachable',
        'engine' => 'php',
    ], $dbOk ? 200 : 503);
}

// ── Strip the /api prefix ──────────────────────────────────────────────────
if (strpos($path, '/api') !== 0) error_out('Not found', 404);
$p = '/' . trim(substr($path, 4), '/');
$seg = $p === '/' ? [] : explode('/', trim($p, '/'));

function only($m, $allowed) { if ($m !== $allowed) error_out('Method not allowed', 405); }

try {
    switch ($seg[0] ?? '') {

        case 'auth':
            switch ($seg[1] ?? '') {
                case 'login':           only($method, 'POST'); route_auth_login(); break;
                case 'register':        only($method, 'POST'); route_auth_register(); break;
                case 'logout':          only($method, 'POST'); route_auth_logout(); break;
                case 'me':              only($method, 'GET');  route_auth_me(); break;
                case 'change-password': only($method, 'POST'); route_auth_change_password(); break;
                default: error_out('Not found', 404);
            }
            break;

        case 'users':
            if (!isset($seg[1])) { only($method, 'GET'); route_users_list(); }
            else { only($method, 'POST'); route_users_update($seg[1]); }
            break;

        case 'upload':
            only($method, 'POST'); route_upload(); break;

        case 'leads':
            $a = $seg[1] ?? null; $b = $seg[2] ?? null;
            if ($a === null)            { only($method, 'GET');  route_leads_list(); }
            elseif ($a === 'trash')     { only($method, 'GET');  route_leads_trash(); }
            elseif ($a === 'approve')   { only($method, 'POST'); route_leads_approve(); }
            elseif ($a === 'reject')    { only($method, 'POST'); route_leads_reject(); }
            elseif ($b === 'approve')   { only($method, 'POST'); route_leads_approve($a); }
            elseif ($b === 'reject')    { only($method, 'POST'); route_leads_reject($a); }
            elseif ($b === 'restore')   { only($method, 'POST'); route_leads_restore($a); }
            elseif ($b === 'permanent') { only($method, 'DELETE'); route_leads_purge($a); }
            elseif ($b === null) {
                if ($method === 'GET')        route_leads_get($a);
                elseif ($method === 'PUT')    route_leads_update($a);
                elseif ($method === 'DELETE') route_leads_remove($a);
                else error_out('Method not allowed', 405);
            } else error_out('Not found', 404);
            break;

        case 'duplicates':
            switch ($seg[1] ?? '') {
                case '':        only($method, 'GET');  route_duplicates_list(); break;
                case 'scan':    only($method, 'POST'); route_duplicates_scan(); break;
                case 'merge':   only($method, 'POST'); route_duplicates_merge(); break;
                case 'dismiss': only($method, 'POST'); route_duplicates_dismiss(); break;
                default: error_out('Not found', 404);
            }
            break;

        case 'analytics':
            only($method, 'GET'); route_analytics_dashboard(); break;

        case 'reports':
            if (($seg[1] ?? '') === 'employees') { only($method, 'GET'); route_reports_employees(); }
            else { only($method, 'GET'); route_analytics_reports(); }
            break;

        case 'export':
            if (($seg[1] ?? '') === 'csv') { only($method, 'GET'); route_export_csv(); }
            else error_out('Export format not supported (use CSV)', 404);
            break;

        default:
            error_out('Not found', 404);
    }
} catch (Throwable $e) {
    error_log('[cosmos] ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json'); }
    echo json_encode(['error' => 'Server error']);
}
