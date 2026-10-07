<?php
/**
 * CollabSpace Activity Log API
 * Audit Trail & Timeline of Workspace Actions
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

$user = requireAuth();
$userId = (int)$user['id'];
$db = getDB();
updateLastSeen($userId);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $workspaceId = (int)($_GET['workspace_id'] ?? 0);
    if (!$workspaceId) {
        jsonError('workspace_id is required', 400);
    }

    requireWorkspaceMember($workspaceId, 'member');

    $limit = min(100, max(1, (int)($_GET['limit'] ?? 40)));

    $stmt = $db->prepare("
        SELECT a.*, u.name AS user_name, u.avatar_color AS user_avatar
        FROM activity_logs a
        JOIN users u ON a.user_id = u.id
        WHERE a.workspace_id = ?
        ORDER BY a.id DESC
        LIMIT {$limit}
    ");
    $stmt->execute([$workspaceId]);
    $activities = $stmt->fetchAll();

    jsonSuccess(['activities' => $activities]);
}

jsonError('Unsupported request method', 400);
