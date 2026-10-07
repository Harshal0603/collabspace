<?php
/**
 * CollabSpace Notifications API
 * Unread Badge Counts, Real-time Alerts & Mark-as-read
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

$user = requireAuth();
$userId = (int)$user['id'];
$db = getDB();
updateLastSeen($userId);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = getJsonInput();
$action = $_GET['action'] ?? $input['action'] ?? '';

// GET Notifications
if ($method === 'GET') {
    $stmt = $db->prepare('
        SELECT n.*, w.name AS workspace_name
        FROM notifications n
        LEFT JOIN workspaces w ON n.workspace_id = w.id
        WHERE n.user_id = ?
        ORDER BY n.id DESC
        LIMIT 30
    ');
    $stmt->execute([$userId]);
    $notifications = $stmt->fetchAll();

    $countStmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $countStmt->execute([$userId]);
    $unreadCount = (int)$countStmt->fetchColumn();

    jsonSuccess([
        'notifications' => $notifications,
        'unread_count'  => $unreadCount,
    ]);
}

// POST Notification Actions
if ($method === 'POST') {
    requireCsrf();

    // 1. MARK SINGLE NOTIFICATION AS READ
    if ($action === 'mark_read') {
        $id = (int)($input['notification_id'] ?? 0);
        if (!$id) {
            jsonError('notification_id is required', 400);
        }

        $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);

        jsonSuccess([], 'Notification marked as read');
    }

    // 2. MARK ALL NOTIFICATIONS AS READ
    if ($action === 'mark_all_read') {
        $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?');
        $stmt->execute([$userId]);

        jsonSuccess([], 'All notifications marked as read');
    }
}

jsonError('Unsupported request method or action', 400);
