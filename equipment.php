<?php
// ============================================================
// api/equipment.php  — Equipment CRUD
// GET    /api/equipment.php          — list all
// GET    /api/equipment.php?id=X     — single record
// POST   /api/equipment.php          — create
// PUT    /api/equipment.php          — update
// DELETE /api/equipment.php          — delete
// POST   /api/equipment.php?action=import — bulk import
// ============================================================
require_once __DIR__ . '/bootstrap.php';

$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ─── Validation helpers ──────────────────────────────────────
$VALID_STATUS    = ['Active', 'Maintenance', 'Inactive', 'Condemned'];
$VALID_BUILDINGS = ['MAIN BUILDING', 'ANNEX I BUILDING', 'ANNEX II BUILDING', 'DIAGNOSTIC BUILDING'];
$VALID_CATEGORIES= ['NKTI-INHOUSE', 'OUTSOURCE-DIRECT CONTRACTING', 'OUTSOURCE-UNDERWARRANTY', 'OUTSOURCE-TIEUP'];

function validateEquipment(array $d, array $vs, array $vb, array $vc): array {
    $errors = [];
    if (empty(trim($d['name'] ?? '')))   $errors[] = 'Equipment name is required.';
    if (empty(trim($d['brand'] ?? '')))  $errors[] = 'Brand is required.';
    if (empty(trim($d['serial'] ?? ''))) $errors[] = 'Serial number is required.';
    if (!in_array($d['status']   ?? '', $vs, true)) $errors[] = 'Invalid status value.';
    if (!in_array($d['building'] ?? '', $vb, true)) $errors[] = 'Invalid building value.';
    if (!in_array($d['category'] ?? '', $vc, true)) $errors[] = 'Invalid category value.';
    return $errors;
}

function buildRow(array $d, int $userId, bool $isNew = true): array {
    $row = [
        'name'         => trim($d['name']     ?? ''),
        'brand'        => trim($d['brand']    ?? ''),
        'model'        => trim($d['model']    ?? ''),
        'serial_no'    => trim($d['serial']   ?? ''),
        'date_acquired'=> !empty($d['date'])   ? $d['date'] : null,
        'section'      => trim($d['section']  ?? ''),
        'status'       => $d['status']        ?? 'Active',
        'supplier'     => trim($d['supplier'] ?? ''),
        'area'         => trim($d['area']     ?? ''),
        'category'     => $d['category']      ?? 'NKTI-INHOUSE',
        'building'     => $d['building']      ?? 'MAIN BUILDING',
        'wattage'      => (float)($d['wattage'] ?? 0),
        'hours_per_day'=> (float)($d['hours']   ?? 0),
        'updated_by'   => $userId,
    ];
    if ($isNew) $row['created_by'] = $userId;
    return $row;
}

// ─── LIST ─────────────────────────────────────────────────────
if ($method === 'GET' && !isset($_GET['id']) && $action === '') {
    $db = getDB();
    $sql = 'SELECT e.*, u1.username AS created_by_name, u2.username AS updated_by_name
            FROM equipment e
            LEFT JOIN users u1 ON u1.id = e.created_by
            LEFT JOIN users u2 ON u2.id = e.updated_by
            ORDER BY e.building, e.section, e.name';
    $rows = $db->query($sql)->fetchAll();
    respondSuccess($rows);
}

// ─── SINGLE ───────────────────────────────────────────────────
if ($method === 'GET' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM equipment WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) respondError('Equipment not found.', 404);
    respondSuccess($row);
}

// ─── CREATE ───────────────────────────────────────────────────
if ($method === 'POST' && $action === '') {
    if (!in_array($user['role'], ['admin', 'engineer', 'viewer'], true)) {
        respondError('Forbidden.', 403);
    }
    $data = getJSON();

    // Viewers: enforce their assigned location
    if ($user['role'] === 'viewer') {
        if (empty($user['assigned_building'])) respondError('You have no assigned location.', 403);
        $data['building'] = $user['assigned_building'];
        if (!empty($user['assigned_section'])) $data['section'] = $user['assigned_section'];
    }

    $errors = validateEquipment($data, $VALID_STATUS, $VALID_BUILDINGS, $VALID_CATEGORIES);
    if ($errors) respondError(implode(' ', $errors));

    $row = buildRow($data, $user['id'], true);
    $db  = getDB();

    $cols = implode(', ', array_keys($row));
    $vals = implode(', ', array_fill(0, count($row), '?'));
    $db->prepare("INSERT INTO equipment ($cols) VALUES ($vals)")->execute(array_values($row));
    $newId = (int)$db->lastInsertId();

    auditLog('CREATE', 'equipment', $newId, "Added: {$row['name']} ({$row['serial_no']})");
    respondSuccess(['id' => $newId], 'Equipment added');
}

// ─── UPDATE ───────────────────────────────────────────────────
if ($method === 'PUT') {
    $data = getJSON();
    $id   = (int)($data['id'] ?? 0);
    if (!$id) respondError('Equipment ID required.');

    $db  = getDB();
    $stmt = $db->prepare('SELECT * FROM equipment WHERE id = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) respondError('Equipment not found.', 404);

    // Location scope check for viewers
    if (!canActOn($user, $existing)) {
        respondError('You can only edit equipment in your assigned location: ' .
            $user['assigned_building'] . ($user['assigned_section'] ? ' / '.$user['assigned_section'] : ''), 403);
    }

    // Viewers: lock building/section
    if ($user['role'] === 'viewer') {
        if (!empty($user['assigned_building'])) $data['building'] = $user['assigned_building'];
        if (!empty($user['assigned_section']))  $data['section']  = $user['assigned_section'];
    }

    $errors = validateEquipment($data, $VALID_STATUS, $VALID_BUILDINGS, $VALID_CATEGORIES);
    if ($errors) respondError(implode(' ', $errors));

    $row = buildRow($data, $user['id'], false);
    $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($row)));
    $db->prepare("UPDATE equipment SET $sets WHERE id = ?")->execute([...array_values($row), $id]);

    auditLog('UPDATE', 'equipment', $id, "Updated: {$row['name']} ({$row['serial_no']})");
    respondSuccess(null, 'Equipment updated');
}

// ─── DELETE ───────────────────────────────────────────────────
if ($method === 'DELETE') {
    $data = getJSON();
    $id   = (int)($data['id'] ?? 0);
    if (!$id) respondError('Equipment ID required.');

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM equipment WHERE id = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) respondError('Equipment not found.', 404);

    if (!canActOn($user, $existing)) {
        respondError('You can only delete equipment in your assigned location.', 403);
    }

    $db->prepare('DELETE FROM equipment WHERE id = ?')->execute([$id]);
    auditLog('DELETE', 'equipment', $id, "Deleted: {$existing['name']} ({$existing['serial_no']})");
    respondSuccess(null, 'Equipment deleted');
}

// ─── BULK IMPORT ──────────────────────────────────────────────
if ($method === 'POST' && $action === 'import') {
    $data  = getJSON();
    $items = $data['items'] ?? [];
    $mode  = $data['mode']  ?? 'add'; // 'add' or 'replace'

    if (!is_array($items) || count($items) === 0) respondError('No items to import.');

    $db = getDB();
    $inserted = 0;
    $skipped  = 0;

    if ($mode === 'replace' && in_array($user['role'], ['admin', 'engineer'], true)) {
        $db->exec('DELETE FROM equipment');
    }

    $db->beginTransaction();
    try {
        foreach ($items as $item) {
            // Viewers: enforce location
            if ($user['role'] === 'viewer') {
                if (empty($user['assigned_building'])) { $skipped++; continue; }
                $item['building'] = $user['assigned_building'];
                if (!empty($user['assigned_section'])) $item['section'] = $user['assigned_section'];
            }
            $errors = validateEquipment($item, $VALID_STATUS, $VALID_BUILDINGS, $VALID_CATEGORIES);
            if ($errors || empty(trim($item['name'] ?? ''))) { $skipped++; continue; }

            $row  = buildRow($item, $user['id'], true);
            $cols = implode(', ', array_keys($row));
            $vals = implode(', ', array_fill(0, count($row), '?'));
            $db->prepare("INSERT INTO equipment ($cols) VALUES ($vals)")->execute(array_values($row));
            $inserted++;
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        respondError('Import failed: ' . $e->getMessage(), 500);
    }

    auditLog('IMPORT', 'equipment', null, "Imported $inserted rows, skipped $skipped");
    respondSuccess(['inserted' => $inserted, 'skipped' => $skipped], "Import complete");
}

respondError('Unknown request.', 404);
