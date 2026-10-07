<?php
/**
 * CollabSpace Team Messages API
 * Workspace Group Chat & Presence Heartbeat
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

$user = requireAuth();
$userId = (int)$user['id'];
$db = getDB();
updateLastSeen($userId);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = getJsonInput();

// GET Messages
if ($method === 'GET') {
    $workspaceId = (int)($_GET['workspace_id'] ?? 0);
    $sinceId = (int)($_GET['since_id'] ?? 0);

    if (!$workspaceId) {
        jsonError('workspace_id is required', 400);
    }

    requireWorkspaceMember($workspaceId, 'member');

    if ($sinceId > 0) {
        $stmt = $db->prepare('
            SELECT m.*, u.name AS user_name, u.avatar_color AS user_avatar
            FROM messages m
            JOIN users u ON m.user_id = u.id
            WHERE m.workspace_id = ? AND m.id > ?
            ORDER BY m.id ASC
        ');
        $stmt->execute([$workspaceId, $sinceId]);
        $messages = $stmt->fetchAll();
    } else {
        // Fetch last 50 messages
        $stmt = $db->prepare('
            SELECT * FROM (
                SELECT m.*, u.name AS user_name, u.avatar_color AS user_avatar
                FROM messages m
                JOIN users u ON m.user_id = u.id
                WHERE m.workspace_id = ?
                ORDER BY m.id DESC
                LIMIT 50
            ) sub ORDER BY id ASC
        ');
        $stmt->execute([$workspaceId]);
        $messages = $stmt->fetchAll();
    }

    // Also fetch presence status of all workspace members for live display
    $presenceStmt = $db->prepare('
        SELECT u.id, u.name, u.email, u.avatar_color, u.last_seen,
               (CASE WHEN u.last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 1 ELSE 0 END) AS is_online
        FROM workspace_members wm
        JOIN users u ON wm.user_id = u.id
        WHERE wm.workspace_id = ?
        ORDER BY is_online DESC, u.name ASC
    ');
    $presenceStmt->execute([$workspaceId]);
    $presence = $presenceStmt->fetchAll();

    jsonSuccess([
        'messages' => $messages,
        'presence' => $presence
    ]);
}

// POST Message
if ($method === 'POST') {
    requireCsrf();

    $workspaceId = (int)($input['workspace_id'] ?? 0);
    $messageText = trim((string)($input['message'] ?? ''));

    if (!$workspaceId) {
        jsonError('workspace_id is required', 400);
    }
    if (empty($messageText)) {
        jsonError('Message cannot be empty', 422);
    }

    requireWorkspaceMember($workspaceId, 'member');

    $stmt = $db->prepare('INSERT INTO messages (workspace_id, user_id, message, created_at) VALUES (?, ?, ?, NOW())');
    $stmt->execute([$workspaceId, $userId, $messageText]);
    $messageId = (int)$db->lastInsertId();

    jsonSuccess([
        'message' => [
            'id'           => $messageId,
            'workspace_id' => $workspaceId,
            'user_id'      => $userId,
            'message'      => $messageText,
            'user_name'    => $user['name'],
            'user_avatar'  => $user['avatar_color'],
            'created_at'   => date('Y-m-d H:i:s'),
        ]
    ], 'Message sent successfully', 201);
}

jsonError('Unsupported request method', 400);
