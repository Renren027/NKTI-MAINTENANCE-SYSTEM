<?php
// ============================================================
// api/auth.php  — Login / Logout / Session check
// POST /api/auth.php?action=login
// POST /api/auth.php?action=logout
// GET  /api/auth.php?action=me
// POST /api/auth.php?action=register   (admin only)
// PUT  /api/auth.php?action=update     (admin only)
// DELETE /api/auth.php?action=delete   (admin only)
// GET  /api/auth.php?action=users      (admin only)
// ============================================================
require_once __DIR__ . '/bootstrap.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ─── LOGIN ───────────────────────────────────────────────────
if ($action === 'login' && $method === 'POST') {
    $data = getJSON();
    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';

    if (!$username || !$password) {
        respondError('Username and password are required.');
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        auditLog('LOGIN_FAIL', null, null, "Failed login attempt for: $username");
        respondError('Invalid username or password.', 401);
    }

    // Regenerate session ID to prevent fixation
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id'               => (int)$user['id'],
        'username'         => $user['username'],
        'role'             => $user['role'],
        'assigned_building'=> $user['assigned_building'],
        'assigned_section' => $user['assigned_section'],
    ];

    auditLog('LOGIN', 'users', (int)$user['id'], "User logged in");

    respondSuccess($_SESSION['user'], 'Login successful');
}

// ─── LOGOUT ──────────────────────────────────────────────────
if ($action === 'logout' && $method === 'POST') {
    $user = currentUser();
    if ($user) auditLog('LOGOUT', 'users', $user['id']);
    session_destroy();
    respondSuccess(null, 'Logged out');
}

// ─── ME (session check) ──────────────────────────────────────
if ($action === 'me' && $method === 'GET') {
    $user = requireAuth();
    respondSuccess($user);
}

// ─── LIST USERS (admin only) ─────────────────────────────────
if ($action === 'users' && $method === 'GET') {
    requireRole('admin');
    $db = getDB();
    $rows = $db->query(
        'SELECT id, username, role, assigned_building, assigned_section, is_active, created_at
         FROM users ORDER BY role, username'
    )->fetchAll();
    respondSuccess($rows);
}

// ─── REGISTER NEW USER (admin only) ──────────────────────────
if ($action === 'register' && $method === 'POST') {
    requireRole('admin');
    $data = getJSON();

    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';
    $role     = $data['role'] ?? 'viewer';
    $building = trim($data['assigned_building'] ?? '');
    $section  = trim($data['assigned_section']  ?? '');

    $validRoles = ['admin', 'engineer', 'viewer'];
    $validBuildings = ['', 'MAIN BUILDING', 'ANNEX I BUILDING', 'ANNEX II BUILDING', 'DIAGNOSTIC BUILDING'];

    if (!$username) respondError('Username is required.');
    if (strlen($password) < 6) respondError('Password must be at least 6 characters.');
    if (!in_array($role, $validRoles, true)) respondError('Invalid role.');
    if (!in_array($building, $validBuildings, true)) respondError('Invalid building.');

    $db = getDB();
    // Check duplicate
    $check = $db->prepare('SELECT id FROM users WHERE username = ?');
    $check->execute([$username]);
    if ($check->fetch()) respondError('Username already exists.');

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $db->prepare(
        'INSERT INTO users (username, password_hash, role, assigned_building, assigned_section)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$username, $hash, $role, $building ?: null, $section ?: null]);
    $newId = (int)$db->lastInsertId();

    auditLog('CREATE', 'users', $newId, "Created user: $username ($role)");
    respondSuccess(['id' => $newId, 'username' => $username, 'role' => $role], 'User created');
}

// ─── UPDATE USER (admin only) ─────────────────────────────────
if ($action === 'update' && $method === 'PUT') {
    requireRole('admin');
    $data = getJSON();
    $id   = (int)($data['id'] ?? 0);
    if (!$id) respondError('User ID required.');

    $db = getDB();
    $existing = $db->prepare('SELECT * FROM users WHERE id = ?');
    $existing->execute([$id]);
    $u = $existing->fetch();
    if (!$u) respondError('User not found.', 404);

    $role     = $data['role']              ?? $u['role'];
    $building = $data['assigned_building'] ?? $u['assigned_building'];
    $section  = $data['assigned_section']  ?? $u['assigned_section'];
    $active   = isset($data['is_active']) ? (int)(bool)$data['is_active'] : (int)$u['is_active'];

    // Protect main admin account from role change
    if ($u['username'] === 'admin') {
        $role = 'admin';
        $active = 1;
    }

    $fields = ['role = ?', 'assigned_building = ?', 'assigned_section = ?', 'is_active = ?'];
    $params = [$role, $building ?: null, $section ?: null, $active];

    // Optionally change password
    if (!empty($data['password'])) {
        if (strlen($data['password']) < 6) respondError('Password must be at least 6 characters.');
        $fields[] = 'password_hash = ?';
        $params[]  = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
    }

    $params[] = $id;
    $db->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    auditLog('UPDATE', 'users', $id, "Updated user: {$u['username']}");
    respondSuccess(null, 'User updated');
}

// ─── DELETE USER (admin only) ─────────────────────────────────
if ($action === 'delete' && $method === 'DELETE') {
    requireRole('admin');
    $data = getJSON();
    $id   = (int)($data['id'] ?? 0);
    if (!$id) respondError('User ID required.');

    $db = getDB();
    $u = $db->prepare('SELECT username FROM users WHERE id = ?');
    $u->execute([$id]);
    $user = $u->fetch();
    if (!$user) respondError('User not found.', 404);
    if ($user['username'] === 'admin') respondError('Cannot delete the main admin account.');

    $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    auditLog('DELETE', 'users', $id, "Deleted user: {$user['username']}");
    respondSuccess(null, 'User deleted');
}

respondError('Unknown action or method.', 404);
