<?php
/**
 * CollabSpace Real-Time SSE Gateway (Server-Sent Events)
 * Streams live task updates and notifications without WebSockets
 */

declare(strict_types=1);

// Prevent output buffering interference
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');

header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

require_once __DIR__ . '/../config/auth.php';

$user = getCurrentUser();
if (!$user) {
    echo "event: error\ndata: " . json_encode(['error' => 'unauthorized']) . "\n\n";
    @ob_flush();
    @flush();
    exit;
}

$userId = (int)$user['id'];
$workspaceId = isset($_GET['workspace_id']) ? (int)$_GET['workspace_id'] : 0;
$db = getDB();

// Determine baseline max IDs
$lastActId = 0;
if ($workspaceId > 0) {
    $aStmt = $db->prepare('SELECT COALESCE(MAX(id), 0) FROM activity_logs WHERE workspace_id = ?');
    $aStmt->execute([$workspaceId]);
    $lastActId = (int)$aStmt->fetchColumn();
}

$nStmt = $db->prepare('SELECT COALESCE(MAX(id), 0) FROM notifications WHERE user_id = ?');
$nStmt->execute([$userId]);
$lastNotifId = (int)$nStmt->fetchColumn();

// Initial connected handshake
echo "event: connected\ndata: " . json_encode([
    'status'       => 'connected',
    'time'         => date('c'),
    'last_act'     => $lastActId,
    'last_notif'   => $lastNotifId,
]) . "\n\n";
@ob_flush();
@flush();

$startTime = time();
$maxDuration = 25; // 25s life cycle per SSE connection for optimal PHP worker rotation

while ((time() - $startTime) < $maxDuration) {
    if (connection_aborted()) {
        break;
    }

    // 1. Check for Task / Workspace Activity Updates
    if ($workspaceId > 0) {
        $checkAct = $db->prepare('
            SELECT a.*, u.name AS user_name, u.avatar_color AS user_avatar
            FROM activity_logs a
            JOIN users u ON a.user_id = u.id
            WHERE a.workspace_id = ? AND a.id > ?
            ORDER BY a.id ASC
        ');
        $checkAct->execute([$workspaceId, $lastActId]);
        $newActs = $checkAct->fetchAll();

        foreach ($newActs as $act) {
            $lastActId = max($lastActId, (int)$act['id']);
            echo "event: task_update\ndata: " . json_encode($act) . "\n\n";
            @ob_flush();
            @flush();
        }
    }

    // 2. Check for Notifications for this user
    $checkNotif = $db->prepare('
        SELECT * FROM notifications 
        WHERE user_id = ? AND id > ?
        ORDER BY id ASC
    ');
    $checkNotif->execute([$userId, $lastNotifId]);
    $newNotifs = $checkNotif->fetchAll();

    foreach ($newNotifs as $notif) {
        $lastNotifId = max($lastNotifId, (int)$notif['id']);
        echo "event: notification\ndata: " . json_encode($notif) . "\n\n";
        @ob_flush();
        @flush();
    }

    // 3. Heartbeat Ping every 10 seconds
    if ((time() - $startTime) % 10 === 0) {
        echo "event: ping\ndata: " . json_encode(['time' => time()]) . "\n\n";
        @ob_flush();
        @flush();
    }

    // Wait 2 seconds between polling checks
    sleep(2);
}

// Orderly reconnect trigger
echo "event: cycle\ndata: " . json_encode(['reconnect' => true]) . "\n\n";
@ob_flush();
@flush();
