<?php
/**
 * CollabSpace Authentication API
 * Registration, Login, Logout, Session Identity, CSRF Provision
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = getJsonInput();
$action = $_GET['action'] ?? $input['action'] ?? ($method === 'GET' ? 'me' : '');

$db = getDB();

if ($method === 'GET' && $action === 'me') {
    $user = getCurrentUser();
    $csrf = getCsrfToken();
    if (!$user) {
        jsonResponse([
            'success'       => true,
            'authenticated' => false,
            'csrf_token'    => $csrf,
            'user'          => null,
            'workspaces'    => []
        ]);
    }

    updateLastSeen((int)$user['id']);

    // Fetch fresh user record & user's workspaces
    $stmt = $db->prepare('SELECT id, name, email, avatar_color, last_seen, created_at FROM users WHERE id = ?');
    $stmt->execute([(int)$user['id']]);
    $freshUser = $stmt->fetch();

    $wsStmt = $db->prepare('
        SELECT w.id, w.name, w.description, wm.role, w.created_at,
               (SELECT COUNT(*) FROM workspace_members WHERE workspace_id = w.id) AS member_count,
               (SELECT COUNT(*) FROM projects WHERE workspace_id = w.id) AS project_count
        FROM workspaces w
        JOIN workspace_members wm ON w.id = wm.workspace_id
        WHERE wm.user_id = ?
        ORDER BY w.id ASC
    ');
    $wsStmt->execute([(int)$user['id']]);
    $workspaces = $wsStmt->fetchAll();

    jsonResponse([
        'success'       => true,
        'authenticated' => true,
        'csrf_token'    => $csrf,
        'user'          => $freshUser ?: $user,
        'workspaces'    => $workspaces
    ]);
}

if ($method === 'POST') {
    // 1. REGISTER
    if ($action === 'register') {
        $name = trim((string)($input['name'] ?? ''));
        $email = trim((string)($input['email'] ?? ''));
        $password = (string)($input['password'] ?? '');

        if (mb_strlen($name) < 2) {
            jsonError('Name must be at least 2 characters long', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonError('A valid email address is required', 422);
        }
        if (strlen($password) < 6) {
            jsonError('Password must be at least 6 characters long', 422);
        }

        // Check unique email
        $checkStmt = $db->prepare('SELECT id FROM users WHERE email = ?');
        $checkStmt->execute([$email]);
        if ($checkStmt->fetch()) {
            jsonError('An account with this email already exists', 409, 'EMAIL_EXISTS');
        }

        $palette = ['#6750A4', '#7D5260', '#2E6C4D', '#8C4A60', '#006874', '#9A4522', '#525E75'];
        $avatarColor = $palette[array_rand($palette)];
        $hash = password_hash($password, PASSWORD_BCRYPT);

        $db->beginTransaction();
        try {
            $insertStmt = $db->prepare('
                INSERT INTO users (name, email, password_hash, avatar_color, last_seen, created_at)
                VALUES (?, ?, ?, ?, NOW(), NOW())
            ');
            $insertStmt->execute([$name, $email, $hash, $avatarColor]);
            $userId = (int)$db->lastInsertId();

            // Create initial starter workspace
            $wsName = $name . "'s Workspace";
            $wsDesc = "Default workspace for collaboration and projects.";
            $wsStmt = $db->prepare('INSERT INTO workspaces (name, description, created_by, created_at) VALUES (?, ?, ?, NOW())');
            $wsStmt->execute([$wsName, $wsDesc, $userId]);
            $workspaceId = (int)$db->lastInsertId();

            // Add as owner
            $memStmt = $db->prepare('INSERT INTO workspace_members (workspace_id, user_id, role, joined_at) VALUES (?, ?, "owner", NOW())');
            $memStmt->execute([$workspaceId, $userId]);

            // Add starter project
            $projStmt = $db->prepare('INSERT INTO projects (workspace_id, name, description, color, created_by, created_at) VALUES (?, "Sprint Launch", "Initial onboarding project", "#6750A4", ?, NOW())');
            $projStmt->execute([$workspaceId, $userId]);
            $projectId = (int)$db->lastInsertId();

            // Add sample onboarding task
            $taskStmt = $db->prepare('INSERT INTO tasks (workspace_id, project_id, title, description, status, priority, due_date, assignee_id, created_by, position, created_at) VALUES (?, ?, "Explore CollabSpace Kanban", "Test dragging cards, inviting teammates, and leaving comments.", "todo", "high", DATE_ADD(CURRENT_DATE, INTERVAL 3 DAY), ?, ?, 0, NOW())');
            $taskStmt->execute([$workspaceId, $projectId, $userId, $userId]);

            // Log activity
            logActivity($workspaceId, $userId, 'created', 'workspace', $workspaceId, "Created initial workspace \"$wsName\"");

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            jsonError('Failed to complete registration: ' . $e->getMessage(), 500);
        }

        // Set session
        session_regenerate_id(true);
        $userData = [
            'id'           => $userId,
            'name'         => $name,
            'email'        => $email,
            'avatar_color' => $avatarColor,
        ];
        $_SESSION['user'] = $userData;
        $csrf = getCsrfToken();

        jsonSuccess([
            'user'         => $userData,
            'workspace_id' => $workspaceId,
            'csrf_token'   => $csrf,
        ], 'Registration successful', 201);
    }

    // 2. LOGIN
    if ($action === 'login') {
        $email = trim((string)($input['email'] ?? ''));
        $password = (string)($input['password'] ?? '');

        if (empty($email) || empty($password)) {
            jsonError('Email and password are required', 422);
        }

        $stmt = $db->prepare('SELECT id, name, email, password_hash, avatar_color FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            jsonError('Invalid email or password', 401, 'INVALID_CREDENTIALS');
        }

        session_regenerate_id(true);
        $userData = [
            'id'           => (int)$user['id'],
            'name'         => $user['name'],
            'email'        => $user['email'],
            'avatar_color' => $user['avatar_color'],
        ];
        $_SESSION['user'] = $userData;
        updateLastSeen((int)$user['id']);
        $csrf = getCsrfToken();

        // Get first workspace ID for quick navigation
        $wsStmt = $db->prepare('SELECT workspace_id FROM workspace_members WHERE user_id = ? ORDER BY id ASC LIMIT 1');
        $wsStmt->execute([(int)$user['id']]);
        $wsRow = $wsStmt->fetch();

        jsonSuccess([
            'user'         => $userData,
            'workspace_id' => $wsRow ? (int)$wsRow['workspace_id'] : null,
            'csrf_token'   => $csrf,
        ], 'Login successful');
    }

    // 3. LOGOUT
    if ($action === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
        jsonSuccess([], 'Logged out successfully');
    }
}

jsonError('Invalid authentication request', 400);
