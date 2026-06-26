<?php
/** User management — super-admin (ADMIN) only. */

function route_users_list() {
    require_role('ADMIN');
    $rows = q("SELECT u.id, u.name, u.email, u.role, u.isActive, u.createdAt,
                      (SELECT COUNT(*) FROM uploads up WHERE up.uploadedById = u.id) AS uploads
               FROM users u ORDER BY u.createdAt ASC");
    $out = array_map(function ($u) {
        return [
            'id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'],
            'role' => $u['role'], 'isActive' => (bool) $u['isActive'],
            'createdAt' => to_iso($u['createdAt']), 'uploads' => (int) $u['uploads'],
        ];
    }, $rows);
    json_out($out);
}

function route_users_update($id) {
    $admin = require_role('ADMIN');
    $b = body();
    $roles = ['ADMIN', 'REVIEWER', 'UPLOADER', 'VIEWER'];

    $set = []; $params = [];
    if (isset($b['isActive']) && is_bool($b['isActive'])) { $set[] = 'isActive = ?'; $params[] = $b['isActive'] ? 1 : 0; }
    if (!empty($b['role']) && in_array($b['role'], $roles, true)) { $set[] = 'role = ?'; $params[] = $b['role']; }
    if (!$set) error_out('Nothing to update (isActive or role)', 400);

    // Guard: an admin must not lock or demote themselves.
    if ($id === $admin['sub']) {
        $deactivating = isset($b['isActive']) && $b['isActive'] === false;
        $demoting = !empty($b['role']) && $b['role'] !== 'ADMIN';
        if ($deactivating || $demoting) error_out('You cannot deactivate or demote your own admin account', 400);
    }

    $params[] = now3(); $params[] = $id;
    exec_sql('UPDATE users SET ' . implode(', ', $set) . ', updatedAt = ? WHERE id = ?', $params);
    $u = q1('SELECT id, name, email, role, isActive FROM users WHERE id = ?', [$id]);
    if (!$u) error_out('User not found', 404);
    $u['isActive'] = (bool) $u['isActive'];
    audit($admin['sub'], 'USER_UPDATE', 'User', $id, $b);
    json_out($u);
}
