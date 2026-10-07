-- ==========================================================
-- CollabSpace: Team Collaboration Platform Database Schema
-- Compatible with MySQL 8.0+ / MariaDB 10.4+ / phpMyAdmin
-- ==========================================================

CREATE DATABASE IF NOT EXISTS `collabspace` 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE `collabspace`;

-- Disable foreign key checks during schema creation
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `activity_logs`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `messages`;
DROP TABLE IF EXISTS `comments`;
DROP TABLE IF EXISTS `tasks`;
DROP TABLE IF EXISTS `projects`;
DROP TABLE IF EXISTS `workspace_members`;
DROP TABLE IF EXISTS `workspaces`;
DROP TABLE IF EXISTS `users`;

-- ----------------------------------------------------------
-- 1. Users Table
-- ----------------------------------------------------------
CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(191) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `avatar_color` VARCHAR(20) DEFAULT '#6750A4',
  `last_seen` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 2. Workspaces Table
-- ----------------------------------------------------------
CREATE TABLE `workspaces` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT NULL,
  `created_by` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_workspaces_creator` (`created_by`),
  CONSTRAINT `fk_workspaces_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 3. Workspace Members Table (RBAC)
-- ----------------------------------------------------------
CREATE TABLE `workspace_members` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `workspace_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `role` ENUM('owner', 'admin', 'member') NOT NULL DEFAULT 'member',
  `joined_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_workspace_member` (`workspace_id`, `user_id`),
  INDEX `idx_member_lookup` (`workspace_id`, `role`),
  CONSTRAINT `fk_members_workspace` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 4. Projects Table
-- ----------------------------------------------------------
CREATE TABLE `projects` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `workspace_id` INT NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT NULL,
  `color` VARCHAR(20) DEFAULT '#6750A4',
  `created_by` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_projects_workspace` (`workspace_id`),
  CONSTRAINT `fk_projects_workspace` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_projects_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 5. Tasks Table (Kanban Board)
-- ----------------------------------------------------------
CREATE TABLE `tasks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `workspace_id` INT NOT NULL,
  `project_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `status` ENUM('todo', 'in_progress', 'done') NOT NULL DEFAULT 'todo',
  `priority` ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
  `due_date` DATE NULL,
  `assignee_id` INT NULL,
  `created_by` INT NOT NULL,
  `position` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_tasks_workspace_status` (`workspace_id`, `status`),
  INDEX `idx_tasks_project_status` (`project_id`, `status`),
  INDEX `idx_tasks_assignee` (`assignee_id`),
  CONSTRAINT `fk_tasks_workspace` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tasks_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tasks_assignee` FOREIGN KEY (`assignee_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tasks_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 6. Task Comments Table
-- ----------------------------------------------------------
CREATE TABLE `comments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `task_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `content` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_comments_task` (`task_id`),
  CONSTRAINT `fk_comments_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 7. Team Messages Table (Workspace Chat)
-- ----------------------------------------------------------
CREATE TABLE `messages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `workspace_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `message` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_messages_workspace_id` (`workspace_id`, `id`),
  CONSTRAINT `fk_messages_workspace` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messages_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 8. Notifications Table
-- ----------------------------------------------------------
CREATE TABLE `notifications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `workspace_id` INT NULL,
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `type` VARCHAR(50) DEFAULT 'info',
  `is_read` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_notifications_user_read` (`user_id`, `is_read`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notifications_workspace` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 9. Activity Logs Table
-- ----------------------------------------------------------
CREATE TABLE `activity_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `workspace_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(50) NOT NULL,
  `entity_id` INT NULL,
  `details` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_activity_workspace` (`workspace_id`, `id`),
  CONSTRAINT `fk_activity_workspace` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_activity_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ==========================================================
-- SEED DATA
-- Default Demo Credentials:
-- Password for all 3 demo users: Test@1234
-- Hash: $2y$10$BKCDl1b7GrFkCvk8T/6sNeunav2jTEBSH5tIUqfTS1Gjcwztumuq.
-- ==========================================================

-- 1. Insert Demo Users
INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `avatar_color`, `last_seen`) VALUES
(1, 'Alex Rivera', 'alex.owner@collabspace.dev', '$2y$10$BKCDl1b7GrFkCvk8T/6sNeunav2jTEBSH5tIUqfTS1Gjcwztumuq.', '#6750A4', NOW()),
(2, 'Sarah Chen', 'sarah.admin@collabspace.dev', '$2y$10$BKCDl1b7GrFkCvk8T/6sNeunav2jTEBSH5tIUqfTS1Gjcwztumuq.', '#7D5260', DATE_SUB(NOW(), INTERVAL 4 MINUTE)),
(3, 'Mike Torres', 'mike.member@collabspace.dev', '$2y$10$BKCDl1b7GrFkCvk8T/6sNeunav2jTEBSH5tIUqfTS1Gjcwztumuq.', '#2E6C4D', DATE_SUB(NOW(), INTERVAL 25 MINUTE));

-- 2. Insert Workspace
INSERT INTO `workspaces` (`id`, `name`, `description`, `created_by`, `created_at`) VALUES
(1, 'Nova Product Studio', 'Cross-functional engineering and design workspace building next-gen collaborative tools.', 1, DATE_SUB(NOW(), INTERVAL 7 DAY));

-- 3. Workspace Members (Role-Based Access Control)
INSERT INTO `workspace_members` (`id`, `workspace_id`, `user_id`, `role`, `joined_at`) VALUES
(1, 1, 1, 'owner', DATE_SUB(NOW(), INTERVAL 7 DAY)),
(2, 1, 2, 'admin', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(3, 1, 3, 'member', DATE_SUB(NOW(), INTERVAL 5 DAY));

-- 4. Projects
INSERT INTO `projects` (`id`, `workspace_id`, `name`, `description`, `color`, `created_by`, `created_at`) VALUES
(1, 1, 'Mobile App 2.0', 'Native iOS & Android overhaul with offline sync and Material You theming.', '#6750A4', 1, DATE_SUB(NOW(), INTERVAL 6 DAY)),
(2, 1, 'Cloud Platform Migration', 'Zero-downtime database replication, Redis caching layer, and SSE real-time gateway.', '#7D5260', 2, DATE_SUB(NOW(), INTERVAL 5 DAY));

-- 5. Tasks (Diverse Statuses & Priorities)
INSERT INTO `tasks` (`id`, `workspace_id`, `project_id`, `title`, `description`, `status`, `priority`, `due_date`, `assignee_id`, `created_by`, `position`, `created_at`) VALUES
-- To Do
(1, 1, 1, 'Design Material You bottom navigation', 'Create responsive mobile navigation specs following M3 guidelines with active indicator pill.', 'todo', 'high', DATE_ADD(CURRENT_DATE, INTERVAL 3 DAY), 2, 1, 0, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(2, 1, 2, 'Configure SSL certificates for staging', 'Install Let\'s Encrypt wildcard certs on staging ingress controller.', 'todo', 'medium', DATE_ADD(CURRENT_DATE, INTERVAL 5 DAY), 3, 2, 1, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(3, 1, 1, 'Audit WCAG 2.1 AA accessibility contrast', 'Verify all tonal surfaces meet 4.5:1 text contrast and 3:1 graphical control contrast.', 'todo', 'low', DATE_ADD(CURRENT_DATE, INTERVAL 8 DAY), 1, 1, 2, DATE_SUB(NOW(), INTERVAL 1 DAY)),

-- In Progress
(4, 1, 1, 'Implement offline data caching with IndexedDB', 'Build offline-first synchronization adapter for local task modifications and conflict resolution.', 'in_progress', 'high', DATE_ADD(CURRENT_DATE, INTERVAL 2 DAY), 3, 1, 0, DATE_SUB(NOW(), INTERVAL 4 DAY)),
(5, 1, 2, 'Migrate MySQL schema with foreign keys', 'Apply utf8mb4 collation and cascade deletion rules for workspace member entities.', 'in_progress', 'medium', DATE_ADD(CURRENT_DATE, INTERVAL 4 DAY), 1, 2, 1, DATE_SUB(NOW(), INTERVAL 3 DAY)),

-- Done
(6, 1, 1, 'Setup Material 3 design token definitions', 'Define CSS custom properties for surfaces, tonal palettes, radii, and diffuse shadows in tokens.css.', 'done', 'high', DATE_SUB(CURRENT_DATE, INTERVAL 1 DAY), 2, 1, 0, DATE_SUB(NOW(), INTERVAL 6 DAY)),
(7, 1, 2, 'Benchmark REST API latency across endpoints', 'Run ApacheBench load testing on authentication and task serialization handlers.', 'done', 'low', DATE_SUB(CURRENT_DATE, INTERVAL 2 DAY), 3, 2, 1, DATE_SUB(NOW(), INTERVAL 5 DAY));

-- 6. Comments
INSERT INTO `comments` (`id`, `task_id`, `user_id`, `content`, `created_at`) VALUES
(1, 4, 1, 'Ensure that optimistic UI updates trigger immediate tactile feedback before sync.', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(2, 4, 3, 'Will do! IndexedDB transactions are currently wrapping the batch queue smoothly.', DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(3, 1, 2, 'I have drafted the token specs in Figma; the 48px touch targets are fully compliant.', DATE_SUB(NOW(), INTERVAL 4 HOUR)),
(4, 6, 1, 'The tonal elevation layers look stunning in both light and dark modes.', DATE_SUB(NOW(), INTERVAL 1 DAY));

-- 7. Team Messages (Workspace Chat)
INSERT INTO `messages` (`id`, `workspace_id`, `user_id`, `message`, `created_at`) VALUES
(1, 1, 1, 'Welcome everyone to Nova Product Studio! Let\'s knock out the sprint goals.', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(2, 1, 2, 'Hey team! Design tokens and typography scale are finalized. Check out the board.', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(3, 1, 3, 'Backend endpoints are running super fast. SSE live feed is streaming smoothly.', DATE_SUB(NOW(), INTERVAL 5 HOUR)),
(4, 1, 1, 'Awesome work Mike. Sarah, how are we looking on the navigation prototypes?', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(5, 1, 2, 'Almost ready for review, will push the update to task #1 shortly!', DATE_SUB(NOW(), INTERVAL 30 MINUTE));

-- 8. Notifications
INSERT INTO `notifications` (`id`, `user_id`, `workspace_id`, `title`, `message`, `type`, `is_read`, `created_at`) VALUES
(1, 1, 1, 'New comment on task', 'Mike Torres commented on "Implement offline data caching with IndexedDB"', 'comment', 0, DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(2, 1, 1, 'Task completed', 'Sarah Chen moved "Setup Material 3 design token definitions" to Done', 'task_done', 1, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(3, 2, 1, 'Task assigned', 'Alex Rivera assigned you to "Design Material You bottom navigation"', 'assignment', 0, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(4, 3, 1, 'Welcome to CollabSpace', 'You have been added to Nova Product Studio as a Member.', 'workspace', 1, DATE_SUB(NOW(), INTERVAL 5 DAY));

-- 9. Activity Logs
INSERT INTO `activity_logs` (`id`, `workspace_id`, `user_id`, `action`, `entity_type`, `entity_id`, `details`, `created_at`) VALUES
(1, 1, 1, 'created', 'workspace', 1, 'Created workspace "Nova Product Studio"', DATE_SUB(NOW(), INTERVAL 7 DAY)),
(2, 1, 1, 'invited', 'member', 2, 'Added Sarah Chen as admin', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(3, 1, 1, 'created', 'project', 1, 'Created project "Mobile App 2.0"', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(4, 1, 2, 'created', 'project', 2, 'Created project "Cloud Platform Migration"', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(5, 1, 2, 'updated_status', 'task', 6, 'Moved task #6 to Done', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(6, 1, 3, 'commented', 'task', 4, 'Added comment to task #4', DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(7, 1, 1, 'created', 'task', 1, 'Created task "Design Material You bottom navigation"', DATE_SUB(NOW(), INTERVAL 3 DAY));
