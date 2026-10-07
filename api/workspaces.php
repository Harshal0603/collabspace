<?php
/**
 * CollabSpace Workspaces API
 * Workspace CRUD & Membership Overview
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

// GET Workspaces
if ($method === 'GET') {
    $workspaceId = isset($_GET['id']) ? (int)$_GET['id'] : null;

    if ($workspaceId) {
        // Enforce membership
        $role = requireWorkspaceMember($workspaceId, 'member');

        $stmt = $db->prepare('
            SELECT w.*, u.name AS owner_name, u.email AS owner_email
            FROM workspaces w
            JOIN users u ON w.created_by = u.id
            WHERE w.id = ?
        ');
        $stmt->execute([$workspaceId]);
        $workspace = $stmt->fetch();

        if (!$workspace) {
            jsonError('Workspace not found', 404);
        }

        $workspace['current_user_role'] = $role;

        // Fetch projects
        $pStmt = $db->prepare('
            SELECT p.*,
                   (SELECT COUNT(*) FROM tasks WHERE project_id = p.id) AS task_count,
                   (SELECT COUNT(*) FROM tasks WHERE project_id = p.id AND status = "done") AS completed_task_count
            FROM projects p
            WHERE p.workspace_id = ?
            ORDER BY p.id ASC
        ');
        $pStmt->execute([$workspaceId]);
        $projects = $pStmt->fetchAll();

        // Fetch members with presence
        $mStmt = $db->prepare('
            SELECT wm.id AS membership_id, wm.role, wm.joined_at,
                   u.id AS user_id, u.name, u.email, u.avatar_color, u.last_seen,
                   (CASE WHEN u.last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 1 ELSE 0 END) AS is_online
            FROM workspace_members wm
            JOIN users u ON wm.user_id = u.id
            WHERE wm.workspace_id = ?
            ORDER BY (CASE wm.role WHEN "owner" THEN 1 WHEN "admin" THEN 2 ELSE 3 END), u.name ASC
        ');
        $mStmt->execute([$workspaceId]);
        $members = $mStmt->fetchAll();

        // Fetch task summary metrics
        $statsStmt = $db->prepare('
            SELECT 
                COUNT(*) AS total_tasks,
                SUM(CASE WHEN status = "todo" THEN 1 ELSE 0 END) AS todo_tasks,
                SUM(CASE WHEN status = "in_progress" THEN 1 ELSE 0 END) AS in_progress_tasks,
                SUM(CASE WHEN status = "done" THEN 1 ELSE 0 END) AS done_tasks,
                SUM(CASE WHEN priority = "high" THEN 1 ELSE 0 END) AS high_priority_tasks
            FROM tasks
            WHERE workspace_id = ?
        ');
        $statsStmt->execute([$workspaceId]);
        $stats = $statsStmt->fetch();

        jsonSuccess([
            'workspace' => $workspace,
            'projects'  => $projects,
            'members'   => $members,
            'stats'     => $stats,
        ]);
    } else {
        // List all workspaces for current user
        $stmt = $db->prepare('
            SELECT w.id, w.name, w.description, wm.role, w.created_at,
                   (SELECT COUNT(*) FROM workspace_members WHERE workspace_id = w.id) AS member_count,
                   (SELECT COUNT(*) FROM projects WHERE workspace_id = w.id) AS project_count,
                   (SELECT COUNT(*) FROM tasks WHERE workspace_id = w.id) AS total_tasks,
                   (SELECT COUNT(*) FROM tasks WHERE workspace_id = w.id AND status = "done") AS completed_tasks
            FROM workspaces w
            JOIN workspace_members wm ON w.id = wm.workspace_id
            WHERE wm.user_id = ?
            ORDER BY w.id ASC
        ');
        $stmt->execute([$userId]);
        $workspaces = $stmt->fetchAll();

        jsonSuccess(['workspaces' => $workspaces]);
    }
}

// POST Workspaces
if ($method === 'POST') {
    requireCsrf();

    // 1. CREATE WORKSPACE
    if ($action === 'create' || empty($action)) {
        $name = trim((string)($input['name'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));

        if (mb_strlen($name) < 2) {
            jsonError('Workspace name must be at least 2 characters long', 422);
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare('INSERT INTO workspaces (name, description, created_by, created_at) VALUES (?, ?, ?, NOW())');
            $stmt->execute([$name, $description, $userId]);
            $workspaceId = (int)$db->lastInsertId();

            // Set creator as owner
            $memStmt = $db->prepare('INSERT INTO workspace_members (workspace_id, user_id, role, joined_at) VALUES (?, ?, "owner", NOW())');
            $memStmt->execute([$workspaceId, $userId]);

            // Create initial General project
            $projStmt = $db->prepare('INSERT INTO projects (workspace_id, name, description, color, created_by, created_at) VALUES (?, "General", "General project space", "#6750A4", ?, NOW())');
            $projStmt->execute([$workspaceId, $userId]);

            logActivity($workspaceId, $userId, 'created', 'workspace', $workspaceId, "Created workspace \"$name\"");

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            jsonError('Failed to create workspace: ' . $e->getMessage(), 500);
        }

        jsonSuccess([
            'workspace_id' => $workspaceId,
            'name'         => $name,
            'role'         => 'owner',
        ], 'Workspace created successfully', 201);
    }

    // 2. UPDATE WORKSPACE
    if ($action === 'update') {
        $workspaceId = (int)($input['workspace_id'] ?? 0);
        requireWorkspaceMember($workspaceId, 'admin');

        $name = trim((string)($input['name'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));

        if (mb_strlen($name) < 2) {
            jsonError('Workspace name must be at least 2 characters long', 422);
        }

        $stmt = $db->prepare('UPDATE workspaces SET name = ?, description = ? WHERE id = ?');
        $stmt->execute([$name, $description, $workspaceId]);

        logActivity($workspaceId, $userId, 'updated', 'workspace', $workspaceId, "Updated workspace settings");

        jsonSuccess([], 'Workspace updated successfully');
    }

    // 3. DELETE WORKSPACE
    if ($action === 'delete') {
        $workspaceId = (int)($input['workspace_id'] ?? 0);
        requireWorkspaceMember($workspaceId, 'owner');

        $stmt = $db->prepare('DELETE FROM workspaces WHERE id = ?');
        $stmt->execute([$workspaceId]);

        jsonSuccess([], 'Workspace deleted successfully');
    }
}

jsonError('Unsupported request method or action', 400);
