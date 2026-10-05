-- ============================================================
-- Pulse — система клубов
-- Применяется поверх основной схемы и game-схемы.
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- Расширяем game_clubs до полноценного клуба
-- ------------------------------------------------------------
-- Если таблица не существует — создаём с нуля
CREATE TABLE IF NOT EXISTS `clubs` (
  `id`             bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           varchar(120) NOT NULL,
  `slug`           varchar(120) NOT NULL,
  `description`    text NULL,
  `city`           varchar(120) NULL,
  `country`        varchar(120) NULL,
  `sport_type`     enum('run','ride','swim','ski','walk','hike','mixed','other')
                   NOT NULL DEFAULT 'mixed',
  `visibility`     enum('public','private','hidden') NOT NULL DEFAULT 'public',
  `join_policy`    enum('open','request','invite') NOT NULL DEFAULT 'open',
  `avatar_url`     varchar(255) NULL,
  `cover_url`      varchar(255) NULL,
  `website_url`    varchar(255) NULL,
  `owner_id`       int UNSIGNED NOT NULL,
  `member_count`   int UNSIGNED NOT NULL DEFAULT 1,
  `is_verified`    tinyint(1) NOT NULL DEFAULT 0,
  `is_banned`      tinyint(1) NOT NULL DEFAULT 0,
  `created_at`     timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_slug` (`slug`),
  KEY `idx_owner` (`owner_id`),
  KEY `idx_public` (`visibility`, `is_banned`),
  KEY `idx_city` (`city`),
  KEY `idx_type` (`sport_type`),
  KEY `idx_members` (`member_count`),
  CONSTRAINT `clubs_owner_fk` FOREIGN KEY (`owner_id`)
    REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Членство с ролями
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `club_members` (
  `club_id`     bigint UNSIGNED NOT NULL,
  `user_id`     int UNSIGNED NOT NULL,
  `role`        enum('owner','admin','moderator','member') NOT NULL DEFAULT 'member',
  `status`      enum('active','pending','invited','banned') NOT NULL DEFAULT 'active',
  `joined_at`   timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_by` int UNSIGNED NULL,
  `approved_at` datetime NULL,
  PRIMARY KEY (`club_id`,`user_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`club_id`,`status`),
  KEY `idx_role` (`club_id`,`role`),
  CONSTRAINT `club_members_club_fk` FOREIGN KEY (`club_id`)
    REFERENCES `clubs`(`id`) ON DELETE CASCADE,
  CONSTRAINT `club_members_user_fk` FOREIGN KEY (`user_id`)
    REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Стена клуба (короткие сообщения)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `club_posts` (
  `id`          bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `club_id`     bigint UNSIGNED NOT NULL,
  `user_id`     int UNSIGNED NOT NULL,
  `body`        text NOT NULL,
  `is_pinned`   tinyint(1) NOT NULL DEFAULT 0,
  `is_deleted`  tinyint(1) NOT NULL DEFAULT 0,
  `created_at`  timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_club_time` (`club_id`,`created_at`),
  KEY `idx_user` (`user_id`),
  KEY `idx_pinned` (`club_id`,`is_pinned`),
  CONSTRAINT `club_posts_club_fk` FOREIGN KEY (`club_id`)
    REFERENCES `clubs`(`id`) ON DELETE CASCADE,
  CONSTRAINT `club_posts_user_fk` FOREIGN KEY (`user_id`)
    REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- События клуба (тренировки, встречи)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `club_events` (
  `id`          bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `club_id`     bigint UNSIGNED NOT NULL,
  `creator_id`  int UNSIGNED NOT NULL,
  `title`       varchar(190) NOT NULL,
  `description` text NULL,
  `type`        enum('training','race','meeting','other') NOT NULL DEFAULT 'training',
  `starts_at`   datetime NOT NULL,
  `ends_at`     datetime NULL,
  `location`    varchar(255) NULL,
  `lat`         decimal(10,7) NULL,
  `lng`         decimal(10,7) NULL,
  `max_attendees` int UNSIGNED NULL,
  `created_at`  timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_club_time` (`club_id`,`starts_at`),
  KEY `idx_starts` (`starts_at`),
  CONSTRAINT `club_events_club_fk` FOREIGN KEY (`club_id`)
    REFERENCES `clubs`(`id`) ON DELETE CASCADE,
  CONSTRAINT `club_events_user_fk` FOREIGN KEY (`creator_id`)
    REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `club_event_attendees` (
  `event_id`  bigint UNSIGNED NOT NULL,
  `user_id`   int UNSIGNED NOT NULL,
  `status`    enum('going','maybe','declined') NOT NULL DEFAULT 'going',
  `joined_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_id`,`user_id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `club_event_attendees_event_fk` FOREIGN KEY (`event_id`)
    REFERENCES `club_events`(`id`) ON DELETE CASCADE,
  CONSTRAINT `club_event_attendees_user_fk` FOREIGN KEY (`user_id`)
    REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Инвайты и заявки
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `club_invites` (
  `id`          bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `club_id`     bigint UNSIGNED NOT NULL,
  `token`       varchar(64) NOT NULL,
  `created_by`  int UNSIGNED NOT NULL,
  `expires_at`  datetime NULL,
  `max_uses`    int UNSIGNED NULL,
  `used_count`  int UNSIGNED NOT NULL DEFAULT 0,
  `created_at`  timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token` (`token`),
  KEY `idx_club` (`club_id`),
  CONSTRAINT `club_invites_club_fk` FOREIGN KEY (`club_id`)
    REFERENCES `clubs`(`id`) ON DELETE CASCADE,
  CONSTRAINT `club_invites_user_fk` FOREIGN KEY (`created_by`)
    REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Статистика клуба (материализованные агрегаты)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `club_stats` (
  `club_id`        bigint UNSIGNED NOT NULL,
  `total_distance_m` bigint UNSIGNED NOT NULL DEFAULT 0,
  `total_duration_sec` bigint UNSIGNED NOT NULL DEFAULT 0,
  `total_activities` int UNSIGNED NOT NULL DEFAULT 0,
  `week_distance_m`  bigint UNSIGNED NOT NULL DEFAULT 0,
  `month_distance_m` bigint UNSIGNED NOT NULL DEFAULT 0,
  `updated_at`     timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`club_id`),
  CONSTRAINT `club_stats_club_fk` FOREIGN KEY (`club_id`)
    REFERENCES `clubs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;