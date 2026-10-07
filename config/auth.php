<?php
/**
 * CollabSpace Authentication & Security Helper
 * RBAC, Sessions, CSRF, Activity Logging, JSON Responses
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

// Safe session startup
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 7, // 7 days
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

/**
 * Send JSON response and exit
 */
function jsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Send standardized JSON error
 */
function jsonError(string $message, int $statusCode = 400, ?string $code = null, array $extra = []): void {
    $response = array_merge([
        'success' => false,
        'error'   => $message,
    ], $code ? ['code' => $code] : [], $extra);
    jsonResponse($response, $statusCode);
}

/**
 * Send standardized JSON success
 */
function jsonSuccess(array $data = [], ?string $message = null, int $statusCode = 200): void {
    $response = array_merge(['success' => true], $message ? ['message' => $message] : [], $data);
    jsonResponse($response, $statusCode);
}

/**
 * Parse incoming JSON body or fallback to $_POST
 */
function getJsonInput(): array {
    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_merge($_POST, $decoded);
        }
    }
    return $_POST;
}

/**
 * Generate or retrieve CSRF token
 */
function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token from input or HTTP headers
 */
function verifyCsrfToken(?string $token = null): bool {
    $sessionToken = $_SESSION['csrf_token'] ?? null;
    if (!$sessionToken) {
        return false;
    }

    if ($token !== null && hash_equals($sessionToken, $token)) {
        return true;
    }

    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if ($headerToken && hash_equals($sessionToken, $headerToken)) {
        return true;
    }

    $input = getJsonInput();
    if (!empty($input['csrf_token']) && hash_equals($sessionToken, $input['csrf_token'])) {
        return true;
    }

    return false;
}

/**
 * Enforce CSRF check for mutating HTTP requests
 */
function requireCsrf(): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array(strtoupper($method), ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
        if (!verifyCsrfToken()) {
            jsonError('Invalid or missing CSRF token', 403, 'CSRF_FORBIDDEN');
        }
    }
}

/**
 * Get currently authenticated user or null
 */
function getCurrentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

/**
 * Enforce user authentication
 */
function requireAuth(): array {
    $user = getCurrentUser();
    if (!$user) {
        jsonError('Authentication required. Please log in.', 401, 'UNAUTHORIZED');
    }
    return $user;
}

/**
 * Update user last seen timestamp
 */
function updateLastSeen(int $userId): void {
    try {
        $db = getDB();
        $stmt = $db->prepare('UPDATE `users` SET `last_seen` = NOW() WHERE `id` = ?');
        $stmt->execute([$userId]);
    } catch (\Throwable $e) {
        // Silently tolerate minor timestamp write issues
    }
}

/**
 * Role hierarchy levels
 */
const ROLE_HIERARCHY = [
    'member' => 1,
    'admin'  => 2,
    'owner'  => 3,
];

/**
 * Query workspace role for given user
 */
function getWorkspaceRole(int $userId, int $workspaceId): ?string {
    $db = getDB();
    $stmt = $db->prepare('SELECT `role` FROM `workspace_members` WHERE `workspace_id` = ? AND `user_id` = ?');
    $stmt->execute([$workspaceId, $userId]);
    $row = $stmt->fetch();
    return $row ? (string)$row['role'] : null;
}

/**
 * Enforce workspace membership and role hierarchy
 */
function requireWorkspaceMember(int $workspaceId, string $minimumRole = 'member'): string {
    $user = requireAuth();
    $userRole = getWorkspaceRole((int)$user['id'], $workspaceId);

    if (!$userRole) {
        jsonError('Access denied: You are not a member of this workspace', 403, 'FORBIDDEN_WORKSPACE');
    }

    $userRank = ROLE_HIERARCHY[$userRole] ?? 0;
    $requiredRank = ROLE_HIERARCHY[$minimumRole] ?? 1;

    if ($userRank < $requiredRank) {
        jsonError("Access denied: Requires '{$minimumRole}' privilege or higher", 403, 'INSUFFICIENT_ROLE');
    }

    return $userRole;
}

/**
 * Log an activity event to workspace audit trail
 */
function logActivity(int $workspaceId, int $userId, string $action, string $entityType, ?int $entityId = null, ?string $details = null): void {
    try {
        $db = getDB();
        $stmt = $db->prepare('
            INSERT INTO `activity_logs` (`workspace_id`, `user_id`, `action`, `entity_type`, `entity_id`, `details`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([$workspaceId, $userId, $action, $entityType, $entityId, $details]);
    } catch (\Throwable $e) {
        // Log quietly without breaking primary transaction
        error_log('logActivity error: ' . $e->getMessage());
    }
}

/**
 * Dispatch an in-app notification to a user
 */
function createNotification(int $userId, ?int $workspaceId, string $title, string $message, string $type = 'info'): void {
    try {
        $db = getDB();
        $stmt = $db->prepare('
            INSERT INTO `notifications` (`user_id`, `workspace_id`, `title`, `message`, `type`, `is_read`, `created_at`)
            VALUES (?, ?, ?, ?, ?, 0, NOW())
        ');
        $stmt->execute([$userId, $workspaceId, $title, $message, $type]);
    } catch (\Throwable $e) {
        error_log('createNotification error: ' . $e->getMessage());
    }
}
