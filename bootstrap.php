<?php
// ============================================================
// api/bootstrap.php  — Shared helpers for all API endpoints
// ============================================================
require_once __DIR__ . '/../config/database.php';

// ─── CORS (LAN-safe: restrict to your subnet) ───────────────
$allowed_origins = [
    'http://localhost',
    'http://127.0.0.1',
    // Add your LAN server IP here, e.g.:
    // 'http://192.168.1.100',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins, true)) {
    header("Access-Control-Allow-Origin: $origin");
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── SESSION ─────────────────────────────────────────────────
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
session_name(SESSION_NAME);
session_start();

// ─── RESPONSE HELPERS ────────────────────────────────────────
function respond(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function respondError(string $message, int $code = 400): void {
    respond(['success' => false, 'error' => $message], $code);
}

function respondSuccess($data = null, string $message = 'OK'): void {
    $out = ['success' => true, 'message' => $message];
    if ($data !== null) $out['data'] = $data;
    respond($out);
}

// ─── AUTH HELPERS ────────────────────────────────────────────
function currentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

function requireAuth(): array {
    $user = currentUser();
    if (!$user) respondError('Unauthorized. Please log in.', 401);
    return $user;
}

function requireRole(string ...$roles): array {
    $user = requireAuth();
    if (!in_array($user['role'], $roles, true)) {
        respondError('Forbidden. Insufficient permissions.', 403);
    }
    return $user;
}

// ─── LOCATION SCOPE HELPER ───────────────────────────────────
// Returns true if the current viewer user can act on this equipment row
function canActOn(array $user, array $equipment): bool {
    if (in_array($user['role'], ['admin', 'engineer'], true)) return true;
    // Viewer must have an assigned building
    if (empty($user['assigned_building'])) return false;
    if ($equipment['building'] !== $user['assigned_building']) return false;
    if (!empty($user['assigned_section']) && $equipment['section'] !== $user['assigned_section']) return false;
    return true;
}

// ─── INPUT HELPERS ───────────────────────────────────────────
function getJSON(): array {
    $body = file_get_contents('php://input');
    return json_decode($body, true) ?? [];
}

function sanitize(string $val): string {
    return trim(htmlspecialchars($val, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

// ─── AUDIT LOG ───────────────────────────────────────────────
function auditLog(string $action, string $table = null, int $targetId = null, string $detail = null): void {
    $user = currentUser();
    $db = getDB();
    $stmt = $db->prepare(
        'INSERT INTO audit_log (user_id, username, action, target_table, target_id, detail, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $user['id']       ?? null,
        $user['username'] ?? null,
        $action, $table, $targetId, $detail,
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
}
