<?php
/** Lead lifecycle: list, get, update, approve, reject, trash, restore, purge. */

const EDITABLE_LEAD_FIELDS = [
    'companyName', 'website', 'email', 'phonePrimary', 'phoneSecondary', 'address',
    'city', 'state', 'country', 'postalCode', 'gstin', 'industry', 'linkedin',
    'twitter', 'facebook', 'instagram', 'youtube', 'whatsapp', 'qrCodeData', 'notes', 'source',
];

function route_leads_list() {
    $user = require_auth(true);
    $take = min((int)($_GET['take'] ?? 50), 200);
    $skip = max((int)($_GET['skip'] ?? 0), 0);

    $sql = 'SELECT * FROM leads l WHERE l.deletedAt IS NULL';
    $params = [];
    list($scopeSql, $scopeParams) = owner_scope($user, 'l');
    $sql .= $scopeSql; $params = array_merge($params, $scopeParams);

    if (!empty($_GET['status'])) { $sql .= ' AND l.status = ?'; $params[] = $_GET['status']; }
    if (!empty($_GET['q'])) {
        $sql .= ' AND (l.companyName LIKE ? OR l.email LIKE ?)';
        $params[] = '%' . $_GET['q'] . '%'; $params[] = '%' . $_GET['q'] . '%';
    }

    $countRow = q1('SELECT COUNT(*) AS n FROM (' . $sql . ') t', $params);
    $total = (int) $countRow['n'];

    $sql .= ' ORDER BY l.createdAt DESC LIMIT ' . (int)$take . ' OFFSET ' . (int)$skip;
    $rows = q($sql, $params);

    $ids = array_column($rows, 'id');
    $contacts = contacts_by_lead($ids);
    $items = array_map(fn($r) => serialize_lead($r, $contacts[$r['id']] ?? []), $rows);
    json_out(['items' => $items, 'total' => $total]);
}

function route_leads_trash() {
    $user = require_auth(true);
    $sql = 'SELECT * FROM leads l WHERE l.deletedAt IS NOT NULL';
    $params = [];
    list($scopeSql, $scopeParams) = owner_scope($user, 'l');
    $sql .= $scopeSql; $params = array_merge($params, $scopeParams);
    $sql .= ' ORDER BY l.deletedAt DESC LIMIT 200';
    $rows = q($sql, $params);
    $ids = array_column($rows, 'id');
    $contacts = contacts_by_lead($ids);
    $items = array_map(fn($r) => serialize_lead($r, $contacts[$r['id']] ?? []), $rows);
    json_out(['items' => $items, 'total' => count($items)]);
}

function load_full_lead($id) {
    $r = q1('SELECT * FROM leads WHERE id = ?', [$id]);
    if (!$r) return null;
    $contacts = contacts_by_lead([$id]);
    $company = $r['companyId'] ? q1('SELECT * FROM companies WHERE id = ?', [$r['companyId']]) : null;
    return serialize_lead($r, $contacts[$id] ?? [], $company);
}

function route_leads_get($id) {
    require_auth(true);
    $lead = load_full_lead($id);
    if (!$lead) error_out('Not found', 404);
    json_out($lead);
}

/** Non-admins may only touch their own leads. Returns true or sends 404. */
function owns_lead_or_404($user, $id) {
    if (is_admin($user)) return true;
    $owned = q1(
        'SELECT l.id FROM leads l JOIN cards c ON c.id = l.cardId
         JOIN uploads u ON u.id = c.uploadId WHERE l.id = ? AND u.uploadedById = ?',
        [$id, $user['sub']]
    );
    if (!$owned) { error_out('Lead not found', 404); }
    return true;
}

function route_leads_update($id) {
    $user = require_role('ADMIN', 'REVIEWER');
    $b = body();

    $set = []; $params = [];
    foreach (EDITABLE_LEAD_FIELDS as $f) {
        if (array_key_exists($f, $b)) { $set[] = "$f = ?"; $params[] = $b[$f]; }
    }

    db()->beginTransaction();
    try {
        if ($set) {
            $params[] = now3(); $params[] = $id;
            exec_sql('UPDATE leads SET ' . implode(', ', $set) . ', updatedAt = ? WHERE id = ?', $params);
        } else {
            exec_sql('UPDATE leads SET updatedAt = ? WHERE id = ?', [now3(), $id]);
        }
        if (isset($b['contacts']) && is_array($b['contacts'])) {
            exec_sql('DELETE FROM contacts WHERE leadId = ?', [$id]);
            foreach ($b['contacts'] as $c) {
                exec_sql('INSERT INTO contacts (id, leadId, fullName, designation, department, email, mobile, phone, isPrimary, createdAt)
                          VALUES (?,?,?,?,?,?,?,?,?,?)', [
                    uuid_v4(), $id, $c['fullName'] ?? null, $c['designation'] ?? null, $c['department'] ?? null,
                    $c['email'] ?? null, $c['mobile'] ?? null, $c['phone'] ?? null, !empty($c['isPrimary']) ? 1 : 0, now3(),
                ]);
            }
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        error_out('Update failed', 500);
    }
    audit($user['sub'], 'LEAD_UPDATE', 'Lead', $id);
    json_out(load_full_lead($id));
}

function route_leads_approve($id = null) {
    $user = require_role('ADMIN', 'REVIEWER');
    $ids = $id ? [$id] : (body()['ids'] ?? []);
    if (!$ids) error_out('No IDs provided', 400);
    $place = implode(',', array_fill(0, count($ids), '?'));
    exec_sql("UPDATE leads SET status='APPROVED', reviewedById=?, reviewedAt=?, updatedAt=? WHERE id IN ($place)",
        array_merge([$user['sub'], now3(), now3()], $ids));
    audit($user['sub'], 'LEAD_APPROVE', 'Lead', null, ['ids' => $ids]);
    json_out(['approved' => count($ids)]);
}

function route_leads_reject($id = null) {
    $user = require_role('ADMIN', 'REVIEWER');
    $ids = $id ? [$id] : (body()['ids'] ?? []);
    if (!$ids) error_out('No IDs provided', 400);
    $place = implode(',', array_fill(0, count($ids), '?'));
    exec_sql("UPDATE leads SET status='REJECTED', reviewedById=?, reviewedAt=?, updatedAt=? WHERE id IN ($place)",
        array_merge([$user['sub'], now3(), now3()], $ids));
    audit($user['sub'], 'LEAD_REJECT', 'Lead', null, ['ids' => $ids]);
    json_out(['rejected' => count($ids)]);
}

function route_leads_remove($id) {
    $user = require_auth(true);
    owns_lead_or_404($user, $id);
    exec_sql('UPDATE leads SET deletedAt = ? WHERE id = ?', [now3(), $id]);
    audit($user['sub'], 'LEAD_TRASH', 'Lead', $id);
    http_response_code(204); exit;
}

function route_leads_restore($id) {
    $user = require_auth(true);
    owns_lead_or_404($user, $id);
    exec_sql('UPDATE leads SET deletedAt = NULL WHERE id = ?', [$id]);
    audit($user['sub'], 'LEAD_RESTORE', 'Lead', $id);
    json_out(load_full_lead($id));
}

function route_leads_purge($id) {
    $user = require_auth(true);
    owns_lead_or_404($user, $id);
    exec_sql('DELETE FROM leads WHERE id = ?', [$id]);
    audit($user['sub'], 'LEAD_DELETE_PERMANENT', 'Lead', $id);
    http_response_code(204); exit;
}
