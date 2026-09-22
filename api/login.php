<?php
/**
 * Secure Login Handler for Tender Portal
 * Supports: Admins (with roles) + Applicants (vendors)
 * Includes: Rate limiting, audit logging, session fixation prevention, account verification check.
 */

session_start();
require_once 'database.php';  // Must define Database::getConnection() returning PDO
//require_once 'audit_logger.php'; // Optional: function writeAuditLog(...) - if missing, we'll implement inline

header('Content-Type: application/json');

// ========================
// 1. Only POST allowed
// ========================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// ========================
// 2. Input validation
// ========================
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if (empty($email) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
    exit;
}

// ========================
// 3. Rate limiting (by IP + email)
// ========================
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rateLimitKey = 'login_attempts_' . md5($ip . $email);
$maxAttempts = 5;
$lockoutMinutes = 15;

if (isset($_SESSION[$rateLimitKey])) {
    $attempts = $_SESSION[$rateLimitKey]['count'];
    $firstAttempt = $_SESSION[$rateLimitKey]['first_attempt'];
    if ($attempts >= $maxAttempts && (time() - $firstAttempt) < ($lockoutMinutes * 60)) {
        echo json_encode([
            'success' => false,
            'message' => "Too many failed attempts. Try again in $lockoutMinutes minutes."
        ]);
        exit;
    } elseif (time() - $firstAttempt > ($lockoutMinutes * 60)) {
        // Reset after lockout period
        unset($_SESSION[$rateLimitKey]);
    }
}

// ========================
// 4. Database connection
// ========================
try {
    $db = Database::getConnection();
} catch (PDOException $e) {
    error_log('Login DB Connection Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'System error. Please try later.']);
    exit;
}

// Helper: record audit log (if your schema has audit_logs table)
function recordAuditLog($db, $actorType, $actorId, $action, $result, $ip, $email = null, $reason = null) {
    try {
        $sql = "INSERT INTO audit_logs (actor_type, actor_id, actor_email, action, result, ip_address, failure_reason, created_at)
                VALUES (:actor_type, :actor_id, :actor_email, :action, :result, :ip, :failure_reason, NOW())";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':actor_type' => $actorType,
            ':actor_id' => $actorId,
            ':actor_email' => $email,
            ':action' => $action,
            ':result' => $result,
            ':ip' => $ip,
            ':failure_reason' => $reason
        ]);
    } catch (Exception $e) {
        error_log('Audit log failed: ' . $e->getMessage());
    }
}

// ========================
// 5. Authenticate Admin
// ========================
$user = null;
$isAdmin = false;
$roleName = null;

$adminQuery = "SELECT a.*, r.name AS role_name, r.permissions 
               FROM admins a
               LEFT JOIN roles r ON a.role_id = r.id
               WHERE a.email = :email AND a.is_active = 1
               LIMIT 1";
$stmt = $db->prepare($adminQuery);
$stmt->execute([':email' => $email]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if ($admin && password_verify($password, $admin['password_hash'])) {
    // Check if admin email is verified (schema has is_verified)
    if (empty($admin['is_verified'])) {
        recordAuditLog($db, 'admin', $admin['id'], 'admin.login', 'failure', $ip, $email, 'Email not verified');
        echo json_encode(['success' => false, 'message' => 'Please verify your email before logging in.']);
        exit;
    }
    $user = $admin;
    $isAdmin = true;
    $roleName = $admin['role_name'] ?? 'admin';
} else {
    // ========================
    // 6. Authenticate Applicant (Vendor)
    // ========================
    $applicantQuery = "SELECT * FROM applicants WHERE email = :email AND is_active = 1 LIMIT 1";
    $stmt = $db->prepare($applicantQuery);
    $stmt->execute([':email' => $email]);
    $applicant = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($applicant && password_verify($password, $applicant['password_hash'])) {
        if (empty($applicant['is_verified'])) {
            recordAuditLog($db, 'applicant', $applicant['id'], 'applicant.login', 'failure', $ip, $email, 'Email not verified');
            echo json_encode(['success' => false, 'message' => 'Please verify your email before logging in.']);
            exit;
        }
        $user = $applicant;
        $isAdmin = false;
        $roleName = 'vendor';
    }
}

// ========================
// 7. Handle failed login
// ========================
if (!$user) {
    // Increment rate limit counter
    if (!isset($_SESSION[$rateLimitKey])) {
        $_SESSION[$rateLimitKey] = ['count' => 1, 'first_attempt' => time()];
    } else {
        $_SESSION[$rateLimitKey]['count']++;
    }
    $remaining = $maxAttempts - $_SESSION[$rateLimitKey]['count'];
    $msg = "Invalid email or password.";
    if ($remaining > 0) {
        $msg .= " You have $remaining attempt(s) left.";
    }
    recordAuditLog($db, 'unknown', null, 'login.failed', 'failure', $ip, $email, 'Invalid credentials');
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

// ========================
// 8. Login success – clear rate limit and update last login
// ========================
unset($_SESSION[$rateLimitKey]);

// Update last login timestamp & IP
if ($isAdmin) {
    $update = "UPDATE admins SET last_login_at = NOW(), last_login_ip = :ip WHERE id = :id";
} else {
    $update = "UPDATE applicants SET last_login_at = NOW() WHERE id = :id";
}
$updStmt = $db->prepare($update);
$updStmt->execute([':id' => $user['id'], ':ip' => $ip]);

// ========================
// 9. Regenerate session ID to prevent fixation
// ========================
session_regenerate_id(true);

// ========================
// 10. Set session variables
// ========================
$_SESSION['user_id'] = $user['id'];
$_SESSION['user_email'] = $user['email'];
$_SESSION['user_name'] = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
$_SESSION['user_role'] = $isAdmin ? ($roleName) : 'vendor';
$_SESSION['login_time'] = time();
$_SESSION['ip_address'] = $ip;
$_SESSION['last_activity'] = time();

// Decode permissions if any (admin only)
$permissions = null;
if ($isAdmin && !empty($user['permissions'])) {
    $permissions = json_decode($user['permissions'], true);
}

// ========================
// 11. Determine redirect based on role
// ========================
$redirect = './pages/dashboard/dashboard.html'; // default for vendors
if ($isAdmin) {
    $role = strtolower($roleName);
    if ($role === 'super_admin' || $role === 'admin') {
        $redirect = './pages/dashboard/dashboard.html';
    } else { // reviewer, viewer etc.
        $redirect = './pages/dashboard/dashboard.html';
    }
}

// ========================
// 12. Audit log success
// ========================
recordAuditLog($db, $isAdmin ? 'admin' : 'applicant', $user['id'], $isAdmin ? 'admin.login' : 'applicant.login', 'success', $ip, $user['email']);

// ========================
// 13. Return JSON response
// ========================
echo json_encode([
    'success' => true,
    'message' => 'Welcome ' . $_SESSION['user_name'] . '!',
    'redirect' => $redirect,
    'user' => [
        'id' => $user['id'],
        'name' => $_SESSION['user_name'],
        'email' => $user['email'],
        'role' => $_SESSION['user_role'],
        'permissions' => $permissions,
        'first_name' => $user['first_name'] ?? null,
        'last_name' => $user['last_name'] ?? null,
        'avatar' => $user['avatar_url'] ?? ($user['avatar'] ?? null)
    ]
]);
exit;