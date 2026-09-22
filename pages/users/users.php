<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/api/database.php';

// ── HEADERS ───────────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── HELPERS ───────────────────────────────────────────────────────────────────
function json_error(int $code, string $msg): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function json_ok(array $payload = []): never {
    echo json_encode(array_merge(['success' => true, 'timestamp' => date('c')], $payload));
    exit;
}

function scalar(PDO $pdo, string $sql, array $params = []): mixed {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_NUM);
    return $row ? $row[0] : null;
}

function rows(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

// Sanitise a string field — trim + strip tags
function str_field(array $data, string $key, string $default = ''): string {
    return trim(strip_tags($data[$key] ?? $default));
}

// Write an audit log entry (non-fatal — errors are swallowed)
function audit(PDO $pdo, array $entry): void {
    try {
        $pdo->prepare("
            INSERT INTO audit_logs
                (actor_type, actor_id, actor_email, actor_role,
                 action, entity_type, entity_id, entity_label,
                 old_data, new_data, change_summary,
                 ip_address, user_agent, result)
            VALUES
                (:actor_type, :actor_id, :actor_email, :actor_role,
                 :action, :entity_type, :entity_id, :entity_label,
                 :old_data, :new_data, :change_summary,
                 :ip_address, :user_agent, :result)
        ")->execute([
            ':actor_type'    => $entry['actor_type']   ?? 'admin',
            ':actor_id'      => $entry['actor_id']     ?? null,
            ':actor_email'   => $entry['actor_email']  ?? null,
            ':actor_role'    => $entry['actor_role']   ?? null,
            ':action'        => $entry['action'],
            ':entity_type'   => $entry['entity_type']  ?? 'admins',
            ':entity_id'     => $entry['entity_id']    ?? null,
            ':entity_label'  => $entry['entity_label'] ?? null,
            ':old_data'      => isset($entry['old_data']) ? json_encode($entry['old_data']) : null,
            ':new_data'      => isset($entry['new_data']) ? json_encode($entry['new_data']) : null,
            ':change_summary'=> $entry['change_summary'] ?? null,
            ':ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
            ':user_agent'    => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ':result'        => $entry['result'] ?? 'success',
        ]);
    } catch (Throwable) { /* audit failures must never break the response */ }
}

// ── DB CONNECTION ─────────────────────────────────────────────────────────────
try {
    $pdo = Database::getConnection();
} catch (PDOException) {
    json_error(503, 'Database connection failed');
}

// ── ACTOR CONTEXT (session / JWT — adapt to your auth layer) ─────────────────
// Read the acting admin from session. Falls back to system if no session is set.
session_start();
$actorId    = $_SESSION['admin_id']    ?? null;
$actorEmail = $_SESSION['admin_email'] ?? 'system';
$actorRole  = $_SESSION['admin_role']  ?? 'super_admin';

// Only super_admins may manage other admins
function requireSuperAdmin(string $role): void {
    if ($role !== 'super_admin') {
        json_error(403, 'Forbidden: super_admin role required');
    }
}

// ── ROUTE ─────────────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];

// Optional ?id= param for single-record operations
$targetId = isset($_GET['id']) ? (int) $_GET['id'] : null;

try {

    // ════════════════════════════════════════════════════════════════════════
    // GET — list all admins with stats, or single admin if ?id= given
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'GET') {

        // ── Single admin ──────────────────────────────────────────────────
        if ($targetId) {
            $row = rows($pdo, "
                SELECT
                    a.id, a.first_name, a.last_name, a.email, a.phone,
                    a.is_active, a.is_verified, a.two_fa_enabled,
                    a.last_login_at, a.last_login_ip,
                    a.created_at, a.updated_at,
                    r.name AS role,
                    r.id   AS role_id,
                    CONCAT(cb.first_name,' ',cb.last_name) AS created_by_name
                FROM admins a
                JOIN roles r ON a.role_id = r.id
                LEFT JOIN admins cb ON a.created_by = cb.id
                WHERE a.id = :id
                LIMIT 1
            ", [':id' => $targetId]);

            if (empty($row)) json_error(404, 'Admin not found');
            json_ok(['admin' => $row[0]]);
        }

        // ── Admins list ───────────────────────────────────────────────────
        $admins = rows($pdo, "
            SELECT
                a.id,
                a.first_name,
                a.last_name,
                a.email,
                a.phone,
                a.avatar_url,
                a.is_active,
                a.is_verified,
                a.two_fa_enabled,
                a.last_login_at,
                a.last_login_ip,
                a.created_at,
                a.updated_at,
                r.name  AS role,
                r.id    AS role_id,
                CONCAT(cb.first_name,' ',cb.last_name) AS created_by_name
            FROM admins a
            JOIN  roles r  ON a.role_id    = r.id
            LEFT JOIN admins cb ON a.created_by = cb.id
            ORDER BY
                FIELD(r.name, 'super_admin','admin','reviewer','viewer'),
                a.first_name ASC
        ");

        // ── KPI stats ─────────────────────────────────────────────────────
        $stats = [
            'total'        => (int) scalar($pdo, "SELECT COUNT(*) FROM admins"),
            'active'       => (int) scalar($pdo, "SELECT COUNT(*) FROM admins WHERE is_active = 1"),
            'inactive'     => (int) scalar($pdo, "SELECT COUNT(*) FROM admins WHERE is_active = 0"),
            'super_admins' => (int) scalar($pdo, "
                SELECT COUNT(*) FROM admins a
                JOIN roles r ON a.role_id = r.id
                WHERE r.name = 'super_admin'
            "),
            'two_fa_on'    => (int) scalar($pdo, "SELECT COUNT(*) FROM admins WHERE two_fa_enabled = 1"),
            'logged_in_today' => (int) scalar($pdo, "
                SELECT COUNT(*) FROM admins
                WHERE DATE(last_login_at) = CURDATE()
            "),
        ];

        // ── Role options (for the add/edit form) ──────────────────────────
        $roles = rows($pdo, "SELECT id, name, description FROM roles ORDER BY id");

        // ── Recent admin-related audit entries ────────────────────────────
        $recent_activity = rows($pdo, "
            SELECT
                al.action,
                al.entity_type,
                al.entity_label,
                al.result,
                al.created_at AS time,
                COALESCE(
                    CASE WHEN al.actor_type = 'admin'
                         THEN CONCAT(a.first_name, ' ', a.last_name)
                         ELSE al.actor_email
                    END,
                    'System'
                ) AS actor
            FROM audit_logs al
            LEFT JOIN admins a ON al.actor_type = 'admin' AND al.actor_id = a.id
            WHERE al.entity_type = 'admins'
            ORDER BY al.created_at DESC
            LIMIT 10
        ");

        json_ok([
            'admins'          => $admins,
            'stats'           => $stats,
            'roles'           => $roles,
            'recent_activity' => $recent_activity,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // POST — create a new admin
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'POST') {
        requireSuperAdmin($actorRole);

        $data = body();

        $firstName = str_field($data, 'first_name');
        $lastName  = str_field($data, 'last_name');
        $email     = strtolower(trim($data['email'] ?? ''));
        $phone     = str_field($data, 'phone');
        $roleName  = str_field($data, 'role', 'admin');
        $password  = $data['password'] ?? '';

        // ── Validation ────────────────────────────────────────────────────
        if (!$firstName)               json_error(422, 'first_name is required');
        if (!$lastName)                json_error(422, 'last_name is required');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_error(422, 'Valid email is required');
        if (strlen($password) < 10)    json_error(422, 'Password must be at least 10 characters');

        // Duplicate email check
        $exists = scalar($pdo, "SELECT id FROM admins WHERE email = :email", [':email' => $email]);
        if ($exists) json_error(409, 'An admin with that email already exists');

        // Resolve role_id
        $roleRow = rows($pdo, "SELECT id FROM roles WHERE name = :name LIMIT 1", [':name' => $roleName]);
        if (empty($roleRow)) json_error(422, "Unknown role: $roleName");
        $roleId = (int) $roleRow[0]['id'];

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

$pdo->prepare("
            INSERT INTO admins
                (role_id, first_name, last_name, email, password_hash,
                 phone, is_active, is_verified, created_by)
            VALUES
                (:role_id, :first_name, :last_name, :email, :password_hash,
                 :phone, 1, 1, :created_by)
        ")->execute([
            ':role_id'       => $roleId,
            ':first_name'    => $firstName,
            ':last_name'     => $lastName,
            ':email'         => $email,
            ':password_hash' => $hash,
            ':phone'         => $phone ?: null,
            ':created_by'    => $actorId,
        ]);

        $newId = (int) $pdo->lastInsertId();

        audit($pdo, [
            'actor_id'      => $actorId,
            'actor_email'   => $actorEmail,
            'actor_role'    => $actorRole,
            'action'        => 'admin.created',
            'entity_id'     => $newId,
            'entity_label'  => "$firstName $lastName <$email>",
            'new_data'      => ['role' => $roleName, 'email' => $email],
            'change_summary'=> "Created admin account for $firstName $lastName ($roleName)",
        ]);

        json_ok([
            'message'  => 'Admin account created successfully',
            'admin_id' => $newId,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // PUT — update an existing admin  (?id=N required)
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'PUT') {
        requireSuperAdmin($actorRole);

        if (!$targetId) json_error(400, 'id parameter is required');

        $data = body();

        // Load existing record for diffing
        $existing = rows($pdo, "
            SELECT a.*, r.name AS role
            FROM admins a JOIN roles r ON a.role_id = r.id
            WHERE a.id = :id LIMIT 1
        ", [':id' => $targetId]);

        if (empty($existing)) json_error(404, 'Admin not found');
        $old = $existing[0];

        // ── Fields that may be updated ────────────────────────────────────
        $firstName = str_field($data, 'first_name', $old['first_name']);
        $lastName  = str_field($data, 'last_name',  $old['last_name']);
        $email     = strtolower(trim($data['email'] ?? $old['email']));
        $phone     = str_field($data, 'phone', $old['phone'] ?? '');
        $roleName  = str_field($data, 'role',  $old['role']);
        $isActive  = array_key_exists('is_active', $data) ? (bool)$data['is_active'] : (bool)$old['is_active'];
        $newPassword = $data['password'] ?? '';  // optional — only hashed if non-empty

        // ── Validation ────────────────────────────────────────────────────
        if (!$firstName)  json_error(422, 'first_name is required');
        if (!$lastName)   json_error(422, 'last_name is required');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_error(422, 'Valid email is required');
        if ($newPassword && strlen($newPassword) < 10) json_error(422, 'Password must be at least 10 characters');

        // Email uniqueness (allow keeping same email)
        $emailConflict = scalar($pdo,
            "SELECT id FROM admins WHERE email = :email AND id != :id",
            [':email' => $email, ':id' => $targetId]
        );
        if ($emailConflict) json_error(409, 'Another admin already uses that email');

        // Prevent demoting or deactivating the last active super_admin
        if ($old['role'] === 'super_admin') {
            $activeSuperCount = (int) scalar($pdo, "
                SELECT COUNT(*) FROM admins a
                JOIN roles r ON a.role_id = r.id
                WHERE r.name = 'super_admin' AND a.is_active = 1
            ");
            if ($roleName !== 'super_admin' && $activeSuperCount <= 1)
                json_error(409, 'Cannot change role: at least one active super_admin must remain');
            if (!$isActive && $roleName === 'super_admin' && $activeSuperCount <= 1)
                json_error(409, 'Cannot deactivate: at least one active super_admin must remain');
        }

        // Resolve role_id
        $roleRow = rows($pdo, "SELECT id FROM roles WHERE name = :name LIMIT 1", [':name' => $roleName]);
        if (empty($roleRow)) json_error(422, "Unknown role: $roleName");
        $roleId = (int) $roleRow[0]['id'];

        // ── Build update ──────────────────────────────────────────────────
        $sets   = "role_id=:role_id, first_name=:first_name, last_name=:last_name,
                   email=:email, phone=:phone, is_active=:is_active";
        $params = [
            ':role_id'    => $roleId,
            ':first_name' => $firstName,
            ':last_name'  => $lastName,
            ':email'      => $email,
            ':phone'      => $phone ?: null,
            ':is_active'  => (int)$isActive,
            ':id'         => $targetId,
        ];

        if ($newPassword) {
            $sets .= ', password_hash=:password_hash, password_changed_at=NOW()';
            $params[':password_hash'] = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        }

        $pdo->prepare("UPDATE admins SET $sets WHERE id = :id")->execute($params);

        // ── Diff for audit ────────────────────────────────────────────────
        $changes = [];
        foreach (['first_name','last_name','email','phone'] as $f) {
            if (($old[$f] ?? '') !== $$f) $changes[$f] = ['from' => $old[$f], 'to' => $$f];
        }
        if ($old['role'] !== $roleName)      $changes['role']      = ['from' => $old['role'],      'to' => $roleName];
        if ((bool)$old['is_active'] !== $isActive) $changes['is_active'] = ['from' => (bool)$old['is_active'], 'to' => $isActive];
        if ($newPassword)                    $changes['password']  = 'changed';

        audit($pdo, [
            'actor_id'      => $actorId,
            'actor_email'   => $actorEmail,
            'actor_role'    => $actorRole,
            'action'        => 'admin.updated',
            'entity_id'     => $targetId,
            'entity_label'  => "$firstName $lastName <$email>",
            'old_data'      => array_intersect_key($old, array_flip(['first_name','last_name','email','role','is_active'])),
            'new_data'      => $changes,
            'change_summary'=> 'Updated admin account: ' . implode(', ', array_keys($changes)),
        ]);

        json_ok(['message' => 'Admin account updated successfully']);
    }

    // ════════════════════════════════════════════════════════════════════════
    // DELETE — permanently remove an admin  (?id=N required)
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'DELETE') {
        requireSuperAdmin($actorRole);

        if (!$targetId) json_error(400, 'id parameter is required');

        // Cannot delete yourself
        if ($targetId === (int)$actorId) json_error(409, 'You cannot delete your own account');

        $existing = rows($pdo, "
            SELECT a.*, r.name AS role
            FROM admins a JOIN roles r ON a.role_id = r.id
            WHERE a.id = :id LIMIT 1
        ", [':id' => $targetId]);

        if (empty($existing)) json_error(404, 'Admin not found');
        $target = $existing[0];

        // Guard: last super_admin cannot be removed
        if ($target['role'] === 'super_admin') {
            $superCount = (int) scalar($pdo, "
                SELECT COUNT(*) FROM admins a
                JOIN roles r ON a.role_id = r.id
                WHERE r.name = 'super_admin'
            ");
            if ($superCount <= 1)
                json_error(409, 'Cannot remove the last super_admin account');
        }

        // Soft-delete approach: deactivate first, then hard-delete
        // Hard-delete removes the admin row; audit_logs actor_id FK is nullable so rows are preserved.
        $pdo->prepare("DELETE FROM admins WHERE id = :id")->execute([':id' => $targetId]);

        audit($pdo, [
            'actor_id'      => $actorId,
            'actor_email'   => $actorEmail,
            'actor_role'    => $actorRole,
            'action'        => 'admin.deleted',
            'entity_id'     => $targetId,
            'entity_label'  => "{$target['first_name']} {$target['last_name']} <{$target['email']}>",
            'old_data'      => ['role' => $target['role'], 'email' => $target['email']],
            'change_summary'=> "Permanently deleted admin account: {$target['first_name']} {$target['last_name']} ({$target['role']})",
        ]);

        json_ok(['message' => 'Admin account permanently removed']);
    }

    json_error(405, 'Method not allowed');

} catch (PDOException $e) {
    json_error(500, 'Database error: ' . $e->getMessage());
}