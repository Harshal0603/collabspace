<?php
/**
 * CollabSpace Comments API
 * Task Comment Feed & Real-time Collaboration
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

$user = requireAuth();
$userId = (int)$user['id'];
$db = getDB();
updateLastSeen($userId);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = getJsonInput();

// GET Comments
if ($method === 'GET') {
    $taskId = (int)($_GET['task_id'] ?? 0);
    if (!$taskId) {
        jsonError('task_id is required', 400);
    }

    $tStmt = $db->prepare('SELECT workspace_id FROM tasks WHERE id = ?');
    $tStmt->execute([$taskId]);
    $workspaceId = $tStmt->fetchColumn();
    if (!$workspaceId) {
        jsonError('Task not found', 404);
    }

    requireWorkspaceMember((int)$workspaceId, 'member');

    $stmt = $db->prepare('
        SELECT c.*, u.name AS author_name, u.avatar_color AS author_avatar
        FROM comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.task_id = ?
        ORDER BY c.created_at ASC
    ');
    $stmt->execute([$taskId]);
    $comments = $stmt->fetchAll();

    jsonSuccess(['comments' => $comments]);
}

// POST Comment
if ($method === 'POST') {
    requireCsrf();

    $taskId = (int)($input['task_id'] ?? 0);
    $content = trim((string)($input['content'] ?? ''));

    if (!$taskId) {
        jsonError('task_id is required', 400);
    }
    if (empty($content)) {
        jsonError('Comment content cannot be empty', 422);
    }

    $tStmt = $db->prepare('SELECT id, title, workspace_id, assignee_id, created_by FROM tasks WHERE id = ?');
    $tStmt->execute([$taskId]);
    $task = $tStmt->fetch();

    if (!$task) {
        jsonError('Task not found', 404);
    }

    $workspaceId = (int)$task['workspace_id'];
    requireWorkspaceMember($workspaceId, 'member');

    $stmt = $db->prepare('INSERT INTO comments (task_id, user_id, content, created_at) VALUES (?, ?, ?, NOW())');
    $stmt->execute([$taskId, $userId, $content]);
    $commentId = (int)$db->lastInsertId();

    // Notify assignee
    if (!empty($task['assignee_id']) && (int)$task['assignee_id'] !== $userId) {
        createNotification(
            (int)$task['assignee_id'],
            $workspaceId,
            'New comment on task',
            "{$user['name']} commented on \"{$task['title']}\"",
            'comment'
        );
    }

    // Notify creator if different from assignee and commenter
    if ((int)$task['created_by'] !== $userId && (int)$task['created_by'] !== (int)$task['assignee_id']) {
        createNotification(
            (int)$task['created_by'],
            $workspaceId,
            'New comment on task',
            "{$user['name']} commented on \"{$task['title']}\"",
            'comment'
        );
    }

    logActivity($workspaceId, $userId, 'commented', 'task', $taskId, "Commented on \"{$task['title']}\"");

    jsonSuccess([
        'comment' => [
            'id'            => $commentId,
            'task_id'       => $taskId,
            'user_id'       => $userId,
            'content'       => $content,
            'author_name'   => $user['name'],
            'author_avatar' => $user['avatar_color'],
            'created_at'    => date('Y-m-d H:i:s'),
        ]
    ], 'Comment posted successfully', 201);
}

jsonError('Unsupported request method', 400);
