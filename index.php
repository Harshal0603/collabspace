<?php
/**
 * CollabSpace Application Entry Point
 * Routes session to dashboard or login
 */

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';

$user = getCurrentUser();

if ($user) {
    header('Location: pages/dashboard.html');
} else {
    header('Location: pages/login.html');
}
exit;
