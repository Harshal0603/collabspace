<?php
/**
 * CollabSpace Members & RBAC API
 * Workspace member invitation, role modification, member removal
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

// GET Members
if ($method === 'GET') {
    $workspaceId = (int)($_GET['workspace_id'] ?? 0);
    if (!$workspaceId) {
        jsonError('workspace_id parameter is required', 400);
    }

    requireWorkspaceMember($workspaceId, 'member');

    $stmt = $db->prepare('
        SELECT wm.id AS membership_id, wm.role, wm.joined_at,
               u.id AS user_id, u.name, u.email, u.avatar_color, u.last_seen,
               (CASE WHEN u.last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 1 ELSE 0 END) AS is_online
        FROM workspace_members wm
        JOIN users u ON wm.user_id = u.id
        WHERE wm.workspace_id = ?
        ORDER BY (CASE wm.role WHEN "owner" THEN 1 WHEN "admin" THEN 2 ELSE 3 END), u.name ASC
    ');
    $stmt->execute([$workspaceId]);
    $members = $stmt->fetchAll();

    jsonSuccess(['members' => $members]);
}

// POST Member Actions
if ($method === 'POST') {
    requireCsrf();
    $workspaceId = (int)($input['workspace_id'] ?? 0);
    if (!$workspaceId) {
        jsonError('workspace_id is required', 400);
    }

    // 1. INVITE / ADD MEMBER
    if ($action === 'invite' || $action === 'add') {
        $userRole = requireWorkspaceMember($workspaceId, 'admin'); // admin or owner

        $email = trim(strtolower((string)($input['email'] ?? '')));
        $targetRole = (string)($input['role'] ?? 'member');
        if (!in_array($targetRole, ['admin', 'member'], true)) {
            $targetRole = 'member';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonError('Please provide a valid email address', 422);
        }

        // Check if user exists
        $uStmt = $db->prepare('SELECT id, name, email FROM users WHERE email = ?');
        $uStmt->execute([$email]);
        $targetUser = $uStmt->fetch();

        if (!$targetUser) {
            // Auto-provision user with demo password for seamless invitation experience
            $nameFromEmail = ucwords(explode('@', $email)[0]);
            $defaultHash = password_hash('Test@1234', PASSWORD_BCRYPT);
            $palette = ['#6750A4', '#7D5260', '#2E6C4D', '#8C4A60', '#006874', '#9A4522'];
            $color = $palette[array_rand($palette)];

            $insStmt = $db->prepare('INSERT INTO users (name, email, password_hash, avatar_color, created_at) VALUES (?, ?, ?, ?, NOW())');
            $insStmt->execute([$nameFromEmail, $email, $defaultHash, $color]);
            $targetUserId = (int)$db->lastInsertId();
            $targetUserName = $nameFromEmail;
        } else {
            $targetUserId = (int)$targetUser['id'];
            $targetUserName = $targetUser['name'];
        }

        // Check if already in workspace
        $checkMem = $db->prepare('SELECT id FROM workspace_members WHERE workspace_id = ? AND user_id = ?');
        $checkMem->execute([$workspaceId, $targetUserId]);
        if ($checkMem->fetch()) {
            jsonError('User is already a member of this workspace', 409, 'ALREADY_MEMBER');
        }

        // Insert membership
        $memStmt = $db->prepare('INSERT INTO workspace_members (workspace_id, user_id, role, joined_at) VALUES (?, ?, ?, NOW())');
        $memStmt->execute([$workspaceId, $targetUserId, $targetRole]);

        // Get workspace name
        $wsStmt = $db->prepare('SELECT name FROM workspaces WHERE id = ?');
        $wsStmt->execute([$workspaceId]);
        $wsName = $wsStmt->fetchColumn() ?: 'Workspace';

        // Notify member & log activity
        createNotification(
            $targetUserId,
            $workspaceId,
            "Added to {$wsName}",
            "{$user['name']} added you to {$wsName} as a {$targetRole}.",
            'workspace'
        );

        logActivity(
            $workspaceId,
            $userId,
            'invited',
            'member',
            $targetUserId,
            "Added {$targetUserName} ({$email}) as {$targetRole}"
        );

        jsonSuccess([
            'user_id' => $targetUserId,
            'role'    => $targetRole,
        ], "Successfully added {$targetUserName} to workspace");
    }

    // 2. UPDATE ROLE
    if ($action === 'update_role') {
        $actorRole = requireWorkspaceMember($workspaceId, 'owner'); // only owner can promote/demote

        $targetUserId = (int)($input['user_id'] ?? 0);
        $newRole = (string)($input['role'] ?? '');

        if (!in_array($newRole, ['owner', 'admin', 'member'], true)) {
            jsonError('Invalid role specified', 422);
        }

        if ($targetUserId === $userId) {
            jsonError('You cannot alter your own workspace ownership role directly', 400);
        }

        // If transferring ownership
        if ($newRole === 'owner') {
            $db->beginTransaction();
            try {
                // Demote actor to admin
                $demote = $db->prepare('UPDATE workspace_members SET role = "admin" WHERE workspace_id = ? AND user_id = ?');
                $demote->execute([$workspaceId, $userId]);

                // Promote target to owner
                $promote = $db->prepare('UPDATE workspace_members SET role = "owner" WHERE workspace_id = ? AND user_id = ?');
                $promote->execute([$workspaceId, $targetUserId]);

                // Update workspace created_by
                $wsUp = $db->prepare('UPDATE workspaces SET created_by = ? WHERE id = ?');
                $wsUp->execute([$targetUserId, $workspaceId]);

                $db->commit();
                logActivity($workspaceId, $userId, 'transferred_ownership', 'workspace', $workspaceId, "Transferred workspace ownership");
                jsonSuccess([], 'Ownership transferred successfully');
            } catch (\Throwable $e) {
                $db->rollBack();
                jsonError('Failed to transfer ownership: ' . $e->getMessage(), 500);
            }
        }

        // Regular role update
        $upStmt = $db->prepare('UPDATE workspace_members SET role = ? WHERE workspace_id = ? AND user_id = ?');
        $upStmt->execute([$newRole, $workspaceId, $targetUserId]);

        createNotification(
            $targetUserId,
            $workspaceId,
            'Role Updated',
            "Your workspace role was updated to {$newRole}.",
            'role'
        );

        logActivity($workspaceId, $userId, 'updated_role', 'member', $targetUserId, "Changed member role to {$newRole}");
        jsonSuccess([], 'Member role updated successfully');
    }

    // 3. REMOVE MEMBER
    if ($action === 'remove') {
        $actorRole = requireWorkspaceMember($workspaceId, 'admin');
        $targetUserId = (int)($input['user_id'] ?? 0);

        if (!$targetUserId) {
            jsonError('user_id is required', 400);
        }

        // Fetch target's role
        $tStmt = $db->prepare('SELECT role FROM workspace_members WHERE workspace_id = ? AND user_id = ?');
        $tStmt->execute([$workspaceId, $targetUserId]);
        $targetMemRole = $tStmt->fetchColumn();

        if (!$targetMemRole) {
            jsonError('Member does not belong to this workspace', 404);
        }

        if ($targetMemRole === 'owner') {
            jsonError('Workspace owner cannot be removed', 403);
        }

        // Admin cannot remove another admin unless actor is owner
        if ($actorRole === 'admin' && $targetMemRole === 'admin' && $targetUserId !== $userId) {
            jsonError('Only the workspace owner can remove another administrator', 403);
        }

        $delStmt = $db->prepare('DELETE FROM workspace_members WHERE workspace_id = ? AND user_id = ?');
        $delStmt->execute([$workspaceId, $targetUserId]);

        logActivity($workspaceId, $userId, 'removed', 'member', $targetUserId, "Removed member from workspace");
        jsonSuccess([], 'Member removed successfully');
    }
}

jsonError('Unsupported request method or action', 400);
