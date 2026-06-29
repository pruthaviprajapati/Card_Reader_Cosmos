<?php
/** Auth routes: login, register, logout, me, change-password. */

function route_auth_login() {
    $b = body();
    $email = strtolower(trim($b['email'] ?? ''));
    $password = $b['password'] ?? '';
    if (!$email || !$password) error_out('Email and password are required', 400);
    if (!email_domain_allowed($email)) {
        error_out('Only company email addresses (@cosmos.in or @cosmos-cls.in) can sign in.', 403);
    }

    $user = q1('SELECT * FROM users WHERE email = ?', [$email]);
    // constant-time-ish: always run a hash compare
    $hash = $user ? $user['passwordHash'] : '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234';
    $ok = password_verify($password, $hash);

    if (!$user || !$ok) error_out('Invalid email or password', 401);
    if (!$user['isActive']) error_out('Account is deactivated', 403);

    $token = jwt_sign(
        ['sub' => $user['id'], 'email' => $user['email'], 'role' => $user['role'], 'name' => $user['name']],
        config()['jwt_secret'], config()['jwt_expires_sec']
    );
    audit($user['id'], 'AUTH_LOGIN', 'User', $user['id']);
    json_out(['token' => $token, 'user' => [
        'id' => $user['id'], 'email' => $user['email'], 'name' => $user['name'], 'role' => $user['role'],
    ]]);
}

function route_auth_register() {
    $b = body();
    $name = trim($b['name'] ?? '');
    $email = strtolower(trim($b['email'] ?? ''));
    $password = $b['password'] ?? '';
    if (!$name || !$email || !$password) error_out('Name, email and password are required', 400);
    if (strlen($password) < 6) error_out('Password must be at least 6 characters', 400);
    if (!email_domain_allowed($email)) {
        error_out('Registration is restricted to company email addresses (@cosmos.in or @cosmos-cls.in).', 403);
    }

    $assignedRole = 'REVIEWER';
    if (($b['role'] ?? '') === 'ADMIN' || ($b['role'] ?? '') === 'super') {
        $expected = config()['admin_signup_code'] ?? '';
        if (!$expected) error_out('Super-admin signup is disabled. Ask an existing admin to promote your account.', 403);
        if ((string)($b['adminCode'] ?? '') !== (string)$expected) error_out('Invalid super-admin code.', 403);
        $assignedRole = 'ADMIN';
    }

    if (q1('SELECT id FROM users WHERE email = ?', [$email])) {
        error_out('An account with this email already exists', 409);
    }
    $id = uuid_v4();
    exec_sql('INSERT INTO users (id, email, passwordHash, name, role, isActive, createdAt, updatedAt)
              VALUES (?,?,?,?,?,1,?,?)',
        [$id, $email, password_hash($password, PASSWORD_BCRYPT), $name, $assignedRole, now3(), now3()]);

    $token = jwt_sign(
        ['sub' => $id, 'email' => $email, 'role' => $assignedRole, 'name' => $name],
        config()['jwt_secret'], config()['jwt_expires_sec']
    );
    audit($id, 'AUTH_REGISTER', 'User', $id);
    json_out(['token' => $token, 'user' => [
        'id' => $id, 'email' => $email, 'name' => $name, 'role' => $assignedRole,
    ]], 201);
}

function route_auth_logout() {
    $user = require_auth(false);
    if ($user) audit($user['sub'], 'AUTH_LOGOUT', 'User', $user['sub']);
    json_out(['message' => 'Logged out']);
}

function route_auth_me() {
    $user = require_auth(true);
    $u = q1('SELECT id, email, name, role, isActive, createdAt FROM users WHERE id = ?', [$user['sub']]);
    if (!$u) error_out('User not found', 404);
    $u['isActive'] = (bool) $u['isActive'];
    $u['createdAt'] = to_iso($u['createdAt']);
    json_out($u);
}

function route_auth_change_password() {
    $user = require_auth(true);
    $b = body();
    $cur = $b['currentPassword'] ?? ''; $new = $b['newPassword'] ?? '';
    if (!$cur || !$new) error_out('Current and new password are required', 400);
    if (strlen($new) < 6) error_out('New password must be at least 6 characters', 400);

    $u = q1('SELECT * FROM users WHERE id = ?', [$user['sub']]);
    if (!$u) error_out('User not found', 404);
    if (!password_verify($cur, $u['passwordHash'])) error_out('Current password is incorrect', 401);

    exec_sql('UPDATE users SET passwordHash = ?, updatedAt = ? WHERE id = ?',
        [password_hash($new, PASSWORD_BCRYPT), now3(), $u['id']]);
    audit($u['id'], 'AUTH_PASSWORD_CHANGE', 'User', $u['id']);
    json_out(['message' => 'Password updated']);
}
