-- ============================================================
-- Pulse Admin — схема БД
-- Применяется поверх основной БД pulse.
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = "+00:00";

-- ------------------------------------------------------------
-- admins
-- Отдельно от users — чтобы админка имела свою сессию и роли.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admins` (
  `id`           int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      int UNSIGNED NULL,               -- связь с users, если есть
  `email`        varchar(190) NOT NULL,
  `username`     varchar(60)  NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `display_name` varchar(120) NOT NULL,
  `role`         enum('super','moderator','readonly') NOT NULL DEFAULT 'moderator',
  `totp_secret`  varchar(64)  NULL,
  `totp_enabled` tinyint(1)   NOT NULL DEFAULT 0,
  `is_active`    tinyint(1)   NOT NULL DEFAULT 1,
  `ip_allowlist` varchar(255) NULL,               -- CSV IP или CIDR
  `last_login_at` datetime    NULL,
  `last_login_ip` varchar(45) NULL,
  `failed_attempts` tinyint UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` datetime     NULL,
  `created_at`   timestamp    NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   timestamp    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_email` (`email`),
  UNIQUE KEY `uniq_username` (`username`),
  KEY `idx_user` (`user_id`),
  KEY `idx_role` (`role`),
  KEY `idx_active` (`is_active`),
  CONSTRAINT `admins_user_fk`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- admin_audit_log
-- Каждое действие админа фиксируется.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_audit_log` (
  `id`         bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`   int UNSIGNED NULL,
  `action`     varchar(80)  NOT NULL,             -- login, logout, user.update, sql.query, ...
  `target_type` varchar(40) NULL,                 -- user, activity, post, ...
  `target_id`  bigint UNSIGNED NULL,
  `payload`    json NULL,                         -- что менялось
  `ip`         varchar(45) NULL,
  `user_agent` varchar(255) NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_time` (`admin_id`,`created_at`),
  KEY `idx_action_time` (`action`,`created_at`),
  KEY `idx_target` (`target_type`,`target_id`),
  CONSTRAINT `admin_audit_admin_fk`
    FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- admin_saved_queries
-- Сохранённые SQL-запросы, чтобы не набирать их каждый раз.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_saved_queries` (
  `id`         int UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`   int UNSIGNED NOT NULL,
  `name`       varchar(120) NOT NULL,
  `sql_text`   text NOT NULL,
  `is_shared`  tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin` (`admin_id`),
  CONSTRAINT `admin_saved_queries_fk`
    FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Расширение users: блокировка и метки
-- ------------------------------------------------------------
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `is_banned`   tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `banned_at`   datetime NULL,
  ADD COLUMN IF NOT EXISTS `banned_until` datetime NULL,
  ADD COLUMN IF NOT EXISTS `ban_reason`  varchar(255) NULL,
  ADD COLUMN IF NOT EXISTS `last_seen_at` datetime NULL,
  ADD COLUMN IF NOT EXISTS `registered_ip` varchar(45) NULL;

ALTER TABLE `users`
  ADD KEY IF NOT EXISTS `idx_banned` (`is_banned`),
  ADD KEY IF NOT EXISTS `idx_last_seen` (`last_seen_at`);