<?php
/**
 * CollabSpace Tasks API
 * Kanban Drag-and-Drop, Filtering, Pagination, Full Task Details
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

// GET Tasks
if ($method === 'GET') {
    $taskId = isset($_GET['id']) ? (int)$_GET['id'] : null;

    // Single task lookup
    if ($taskId) {
        $stmt = $db->prepare('
            SELECT t.*, 
                   p.name AS project_name, p.color AS project_color,
                   w.name AS workspace_name,
                   u_assignee.name AS assignee_name, u_assignee.email AS assignee_email, u_assignee.avatar_color AS assignee_avatar,
                   u_creator.name AS creator_name, u_creator.avatar_color AS creator_avatar,
                   (SELECT COUNT(*) FROM comments WHERE task_id = t.id) AS comment_count
            FROM tasks t
            JOIN projects p ON t.project_id = p.id
            JOIN workspaces w ON t.workspace_id = w.id
            LEFT JOIN users u_assignee ON t.assignee_id = u_assignee.id
            JOIN users u_creator ON t.created_by = u_creator.id
            WHERE t.id = ?
        ');
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        if (!$task) {
            jsonError('Task not found', 404);
        }

        requireWorkspaceMember((int)$task['workspace_id'], 'member');

        // Fetch comments
        $cStmt = $db->prepare('
            SELECT c.*, u.name AS author_name, u.avatar_color AS author_avatar
            FROM comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.task_id = ?
            ORDER BY c.created_at ASC
        ');
        $cStmt->execute([$taskId]);
        $task['comments'] = $cStmt->fetchAll();

        jsonSuccess(['task' => $task]);
    }

    // List & filter tasks
    $workspaceId = (int)($_GET['workspace_id'] ?? 0);
    if (!$workspaceId) {
        jsonError('workspace_id is required', 400);
    }

    requireWorkspaceMember($workspaceId, 'member');

    $projectId = !empty($_GET['project_id']) ? (int)$_GET['project_id'] : null;
    $status = !empty($_GET['status']) ? (string)$_GET['status'] : null;
    $priority = !empty($_GET['priority']) ? (string)$_GET['priority'] : null;
    $assigneeId = isset($_GET['assignee_id']) && $_GET['assignee_id'] !== '' ? $_GET['assignee_id'] : null;
    $search = !empty($_GET['search']) ? trim((string)$_GET['search']) : null;

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $whereClauses = ['t.workspace_id = ?'];
    $params = [$workspaceId];

    if ($projectId) {
        $whereClauses[] = 't.project_id = ?';
        $params[] = $projectId;
    }
    if ($status && in_array($status, ['todo', 'in_progress', 'done'], true)) {
        $whereClauses[] = 't.status = ?';
        $params[] = $status;
    }
    if ($priority && in_array($priority, ['low', 'medium', 'high'], true)) {
        $whereClauses[] = 't.priority = ?';
        $params[] = $priority;
    }
    if ($assigneeId !== null) {
        if ($assigneeId === 'unassigned') {
            $whereClauses[] = 't.assignee_id IS NULL';
        } else {
            $whereClauses[] = 't.assignee_id = ?';
            $params[] = (int)$assigneeId;
        }
    }
    if ($search) {
        $whereClauses[] = '(t.title LIKE ? OR t.description LIKE ?)';
        $searchParam = "%{$search}%";
        $params[] = $searchParam;
        $params[] = $searchParam;
    }

    $whereSql = implode(' AND ', $whereClauses);

    // Count total matches
    $countStmt = $db->prepare("SELECT COUNT(*) FROM tasks t WHERE {$whereSql}");
    $countStmt->execute($params);
    $totalTasks = (int)$countStmt->fetchColumn();

    // Query tasks
    $sql = "
        SELECT t.*, 
               p.name AS project_name, p.color AS project_color,
               u_assignee.name AS assignee_name, u_assignee.avatar_color AS assignee_avatar,
               (SELECT COUNT(*) FROM comments WHERE task_id = t.id) AS comment_count
        FROM tasks t
        JOIN projects p ON t.project_id = p.id
        LEFT JOIN users u_assignee ON t.assignee_id = u_assignee.id
        WHERE {$whereSql}
        ORDER BY t.status ASC, t.position ASC, t.id DESC
        LIMIT {$limit} OFFSET {$offset}
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $tasks = $stmt->fetchAll();

    jsonSuccess([
        'tasks'       => $tasks,
        'total'       => $totalTasks,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => ceil($totalTasks / $limit),
    ]);
}

// POST Task Actions
if ($method === 'POST') {
    requireCsrf();

    // 1. CREATE TASK
    if ($action === 'create' || empty($action)) {
        $workspaceId = (int)($input['workspace_id'] ?? 0);
        $projectId = (int)($input['project_id'] ?? 0);
        $title = trim((string)($input['title'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $priority = (string)($input['priority'] ?? 'medium');
        $status = (string)($input['status'] ?? 'todo');
        $dueDate = !empty($input['due_date']) ? (string)$input['due_date'] : null;
        $assigneeId = !empty($input['assignee_id']) ? (int)$input['assignee_id'] : null;

        if (!$workspaceId || !$projectId) {
            jsonError('workspace_id and project_id are required', 400);
        }
        if (mb_strlen($title) < 2) {
            jsonError('Task title must be at least 2 characters long', 422);
        }
        if (!in_array($priority, ['low', 'medium', 'high'], true)) {
            $priority = 'medium';
        }
        if (!in_array($status, ['todo', 'in_progress', 'done'], true)) {
            $status = 'todo';
        }

        requireWorkspaceMember($workspaceId, 'member');

        // Determine max position
        $posStmt = $db->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM tasks WHERE workspace_id = ? AND status = ?');
        $posStmt->execute([$workspaceId, $status]);
        $position = (int)$posStmt->fetchColumn();

        $stmt = $db->prepare('
            INSERT INTO tasks (workspace_id, project_id, title, description, status, priority, due_date, assignee_id, created_by, position, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([$workspaceId, $projectId, $title, $description, $status, $priority, $dueDate, $assigneeId, $userId, $position]);
        $taskId = (int)$db->lastInsertId();

        // Notify assignee if not self
        if ($assigneeId && $assigneeId !== $userId) {
            createNotification(
                $assigneeId,
                $workspaceId,
                'Task Assigned',
                "{$user['name']} assigned you to \"{$title}\"",
                'assignment'
            );
        }

        logActivity($workspaceId, $userId, 'created', 'task', $taskId, "Created task \"{$title}\" in " . ucfirst(str_replace('_', ' ', $status)));

        jsonSuccess([
            'task_id' => $taskId,
            'title'   => $title,
            'status'  => $status,
        ], 'Task created successfully', 201);
    }

    // 2. UPDATE TASK STATUS / MOVE (Drag-and-Drop & Accessible Keyboard Navigation)
    if ($action === 'update_status' || $action === 'move') {
        $taskId = (int)($input['task_id'] ?? 0);
        $newStatus = (string)($input['status'] ?? '');
        $newPosition = isset($input['position']) ? (int)$input['position'] : null;

        if (!$taskId || !in_array($newStatus, ['todo', 'in_progress', 'done'], true)) {
            jsonError('Valid task_id and status are required', 400);
        }

        $tStmt = $db->prepare('SELECT id, workspace_id, title, status, assignee_id FROM tasks WHERE id = ?');
        $tStmt->execute([$taskId]);
        $task = $tStmt->fetch();
        if (!$task) {
            jsonError('Task not found', 404);
        }

        $workspaceId = (int)$task['workspace_id'];
        requireWorkspaceMember($workspaceId, 'member');

        $oldStatus = $task['status'];

        if ($newPosition === null) {
            $posStmt = $db->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM tasks WHERE workspace_id = ? AND status = ? AND id != ?');
            $posStmt->execute([$workspaceId, $newStatus, $taskId]);
            $newPosition = (int)$posStmt->fetchColumn();
        }

        $upStmt = $db->prepare('UPDATE tasks SET status = ?, position = ? WHERE id = ?');
        $upStmt->execute([$newStatus, $newPosition, $taskId]);

        $statusLabels = ['todo' => 'To Do', 'in_progress' => 'In Progress', 'done' => 'Done'];
        $oldLabel = $statusLabels[$oldStatus] ?? $oldStatus;
        $newLabel = $statusLabels[$newStatus] ?? $newStatus;

        if ($oldStatus !== $newStatus) {
            logActivity($workspaceId, $userId, 'moved_task', 'task', $taskId, "Moved \"{$task['title']}\" from {$oldLabel} to {$newLabel}");

            // Notify assignee if someone else moved their task
            if (!empty($task['assignee_id']) && (int)$task['assignee_id'] !== $userId) {
                createNotification(
                    (int)$task['assignee_id'],
                    $workspaceId,
                    'Task Status Changed',
                    "{$user['name']} moved \"{$task['title']}\" to {$newLabel}",
                    'task_status'
                );
            }
        }

        jsonSuccess([
            'task_id'  => $taskId,
            'status'   => $newStatus,
            'position' => $newPosition
        ], 'Task position updated');
    }

    // 3. UPDATE TASK DETAILS
    if ($action === 'update') {
        $taskId = (int)($input['task_id'] ?? 0);
        if (!$taskId) {
            jsonError('task_id is required', 400);
        }

        $tStmt = $db->prepare('SELECT workspace_id, assignee_id, title FROM tasks WHERE id = ?');
        $tStmt->execute([$taskId]);
        $task = $tStmt->fetch();
        if (!$task) {
            jsonError('Task not found', 404);
        }

        $workspaceId = (int)$task['workspace_id'];
        requireWorkspaceMember($workspaceId, 'member');

        $projectId = (int)($input['project_id'] ?? 0);
        $title = trim((string)($input['title'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $priority = (string)($input['priority'] ?? 'medium');
        $dueDate = !empty($input['due_date']) ? (string)$input['due_date'] : null;
        $assigneeId = !empty($input['assignee_id']) ? (int)$input['assignee_id'] : null;

        if (mb_strlen($title) < 2) {
            jsonError('Title must be at least 2 characters', 422);
        }

        $upStmt = $db->prepare('
            UPDATE tasks 
            SET project_id = ?, title = ?, description = ?, priority = ?, due_date = ?, assignee_id = ?
            WHERE id = ?
        ');
        $upStmt->execute([$projectId, $title, $description, $priority, $dueDate, $assigneeId, $taskId]);

        // If assignee changed, notify new assignee
        if ($assigneeId && $assigneeId !== (int)$task['assignee_id'] && $assigneeId !== $userId) {
            createNotification(
                $assigneeId,
                $workspaceId,
                'Task Re-assigned',
                "{$user['name']} assigned you to \"{$title}\"",
                'assignment'
            );
        }

        logActivity($workspaceId, $userId, 'updated', 'task', $taskId, "Updated task \"{$title}\"");

        jsonSuccess([], 'Task updated successfully');
    }

    // 4. DELETE TASK
    if ($action === 'delete') {
        $taskId = (int)($input['task_id'] ?? 0);
        if (!$taskId) {
            jsonError('task_id is required', 400);
        }

        $tStmt = $db->prepare('SELECT workspace_id, title, created_by FROM tasks WHERE id = ?');
        $tStmt->execute([$taskId]);
        $task = $tStmt->fetch();
        if (!$task) {
            jsonError('Task not found', 404);
        }

        $workspaceId = (int)$task['workspace_id'];
        $role = requireWorkspaceMember($workspaceId, 'member');

        // Creator, admin or owner can delete
        if ($role === 'member' && (int)$task['created_by'] !== $userId) {
            jsonError('Only task creator or workspace admins can delete this task', 403);
        }

        $delStmt = $db->prepare('DELETE FROM tasks WHERE id = ?');
        $delStmt->execute([$taskId]);

        logActivity($workspaceId, $userId, 'deleted', 'task', $taskId, "Deleted task \"{$task['title']}\"");

        jsonSuccess([], 'Task deleted successfully');
    }
}

jsonError('Unsupported request method or action', 400);
