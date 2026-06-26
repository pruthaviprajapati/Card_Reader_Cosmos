<?php
/** CSV export in the exact Cosmos CRM column order. Scoped to owner for non-admins. */
require_once __DIR__ . '/../lib/crm.php';

function route_export_csv() {
    $user = require_auth(true);

    $where = ['l.deletedAt IS NULL']; $params = [];

    $status = $_GET['status'] ?? null;
    if ($status && $status !== 'all') {
        if ($status === 'Extracted') { $where[] = 'l.status = ?'; $params[] = 'PENDING_REVIEW'; }
        else { $where[] = 'l.status = ?'; $params[] = strtoupper($status); }
    } else {
        $where[] = "l.status <> 'REJECTED'";
    }
    if (!empty($_GET['source'])) { $where[] = 'l.source = ?'; $params[] = $_GET['source']; }

    // Optional date range (frontend sends timezone-adjusted ISO timestamps).
    if (!empty($_GET['from'])) { $where[] = 'l.createdAt >= ?'; $params[] = iso_to_mysql($_GET['from']); }
    if (!empty($_GET['to'])) {
        $to = $_GET['to'];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to .= ' 23:59:59';
        $where[] = 'l.createdAt <= ?'; $params[] = iso_to_mysql($to);
    }

    if (!is_admin($user)) {
        $where[] = 'l.cardId IN (SELECT c.id FROM cards c JOIN uploads u ON u.id = c.uploadId WHERE u.uploadedById = ?)';
        $params[] = $user['sub'];
    }

    $sql = 'SELECT * FROM leads l WHERE ' . implode(' AND ', $where) .
           ' ORDER BY (l.reviewedAt IS NULL), l.reviewedAt DESC, l.createdAt DESC';
    $leads = q($sql, $params);

    // Build rows (primary + extra-contact rows).
    $ids = array_column($leads, 'id');
    $contactsByLead = [];
    if ($ids) {
        $place = implode(',', array_fill(0, count($ids), '?'));
        foreach (q("SELECT * FROM contacts WHERE leadId IN ($place) ORDER BY isPrimary DESC", $ids) as $c) {
            $contactsByLead[$c['leadId']][] = $c;
        }
    }

    $rows = [];
    foreach ($leads as $lead) {
        $cs = $contactsByLead[$lead['id']] ?? [];
        $rows[] = crm_map_lead($lead, $cs);
        foreach (crm_extra_rows($lead, $cs) as $extra) $rows[] = $extra;
    }

    // Record the export (best-effort).
    try {
        exec_sql('INSERT INTO exports (id, format, storedPath, leadCount, filters, createdById, createdAt)
                  VALUES (?,?,?,?,?,?,?)',
            [uuid_v4(), 'CSV', 'stream', count($rows), json_encode(['where' => $where]), $user['sub'], now3()]);
    } catch (Throwable $e) { /* ignore */ }

    $fileName = 'cosmos_leads_' . gmdate('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');

    $out = fopen('php://output', 'w');
    fputcsv($out, COSMOS_COLUMNS);
    foreach ($rows as $r) {
        $line = [];
        foreach (COSMOS_COLUMNS as $col) $line[] = $r[$col] ?? '';
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

/** Convert an ISO-8601 (or plain) timestamp to MySQL UTC DATETIME. */
function iso_to_mysql($s) {
    $t = strtotime($s);
    return $t === false ? gmdate('Y-m-d H:i:s') : gmdate('Y-m-d H:i:s', $t);
}
