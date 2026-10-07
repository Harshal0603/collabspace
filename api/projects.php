<?php
/**
 * CollabSpace Projects API
 * Project CRUD, Progress Metrics, Workspace Filtering
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

// GET Projects
if ($method === 'GET') {
    $projectId = isset($_GET['id']) ? (int)$_GET['id'] : null;
    $workspaceId = isset($_GET['workspace_id']) ? (int)$_GET['workspace_id'] : null;

    if ($projectId) {
        $stmt = $db->prepare('
            SELECT p.*, w.name AS workspace_name, u.name AS creator_name,
                   (SELECT COUNT(*) FROM tasks WHERE project_id = p.id) AS task_count,
                   (SELECT COUNT(*) FROM tasks WHERE project_id = p.id AND status = "done") AS completed_task_count
            FROM projects p
            JOIN workspaces w ON p.workspace_id = w.id
            JOIN users u ON p.created_by = u.id
            WHERE p.id = ?
        ');
        $stmt->execute([$projectId]);
        $project = $stmt->fetch();

        if (!$project) {
            jsonError('Project not found', 404);
        }

        requireWorkspaceMember((int)$project['workspace_id'], 'member');
        jsonSuccess(['project' => $project]);
    }

    if ($workspaceId) {
        requireWorkspaceMember($workspaceId, 'member');

        $stmt = $db->prepare('
            SELECT p.*,
                   COUNT(t.id) AS total_tasks,
                   SUM(CASE WHEN t.status = "done" THEN 1 ELSE 0 END) AS completed_tasks,
                   SUM(CASE WHEN t.status = "in_progress" THEN 1 ELSE 0 END) AS in_progress_tasks,
                   SUM(CASE WHEN t.status = "todo" THEN 1 ELSE 0 END) AS todo_tasks
            FROM projects p
            LEFT JOIN tasks t ON p.id = t.project_id
            WHERE p.workspace_id = ?
            GROUP BY p.id
            ORDER BY p.id ASC
        ');
        $stmt->execute([$workspaceId]);
        $projects = $stmt->fetchAll();

        // Calculate percentage progress
        foreach ($projects as &$p) {
            $total = (int)$p['total_tasks'];
            $done = (int)$p['completed_tasks'];
            $p['progress_pct'] = $total > 0 ? round(($done / $total) * 100) : 0;
        }

        jsonSuccess(['projects' => $projects]);
    }

    jsonError('workspace_id or id parameter required', 400);
}

// POST Projects
if ($method === 'POST') {
    requireCsrf();

    // 1. CREATE PROJECT
    if ($action === 'create' || empty($action)) {
        $workspaceId = (int)($input['workspace_id'] ?? 0);
        if (!$workspaceId) {
            jsonError('workspace_id is required', 400);
        }

        requireWorkspaceMember($workspaceId, 'member');

        $name = trim((string)($input['name'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $color = (string)($input['color'] ?? '#6750A4');

        if (mb_strlen($name) < 2) {
            jsonError('Project name must be at least 2 characters long', 422);
        }

        $stmt = $db->prepare('INSERT INTO projects (workspace_id, name, description, color, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$workspaceId, $name, $description, $color, $userId]);
        $projectId = (int)$db->lastInsertId();

        logActivity($workspaceId, $userId, 'created', 'project', $projectId, "Created project \"$name\"");

        jsonSuccess([
            'project_id' => $projectId,
            'name'       => $name,
            'color'      => $color,
        ], 'Project created successfully', 201);
    }

    // 2. UPDATE PROJECT
    if ($action === 'update') {
        $projectId = (int)($input['project_id'] ?? 0);
        if (!$projectId) {
            jsonError('project_id is required', 400);
        }

        // Find workspace
        $pStmt = $db->prepare('SELECT workspace_id, name FROM projects WHERE id = ?');
        $pStmt->execute([$projectId]);
        $proj = $pStmt->fetch();
        if (!$proj) {
            jsonError('Project not found', 404);
        }

        $workspaceId = (int)$proj['workspace_id'];
        requireWorkspaceMember($workspaceId, 'admin');

        $name = trim((string)($input['name'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $color = (string)($input['color'] ?? '#6750A4');

        if (mb_strlen($name) < 2) {
            jsonError('Project name must be at least 2 characters long', 422);
        }

        $upStmt = $db->prepare('UPDATE projects SET name = ?, description = ?, color = ? WHERE id = ?');
        $upStmt->execute([$name, $description, $color, $projectId]);

        logActivity($workspaceId, $userId, 'updated', 'project', $projectId, "Updated project \"$name\"");

        jsonSuccess([], 'Project updated successfully');
    }

    // 3. DELETE PROJECT
    if ($action === 'delete') {
        $projectId = (int)($input['project_id'] ?? 0);
        if (!$projectId) {
            jsonError('project_id is required', 400);
        }

        $pStmt = $db->prepare('SELECT workspace_id, name FROM projects WHERE id = ?');
        $pStmt->execute([$projectId]);
        $proj = $pStmt->fetch();
        if (!$proj) {
            jsonError('Project not found', 404);
        }

        $workspaceId = (int)$proj['workspace_id'];
        requireWorkspaceMember($workspaceId, 'admin');

        $delStmt = $db->prepare('DELETE FROM projects WHERE id = ?');
        $delStmt->execute([$projectId]);

        logActivity($workspaceId, $userId, 'deleted', 'project', $projectId, "Deleted project \"{$proj['name']}\"");

        jsonSuccess([], 'Project deleted successfully');
    }
}

jsonError('Unsupported request method or action', 400);
