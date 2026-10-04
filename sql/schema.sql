-- phpMyAdmin SQL Dump
-- version 5.2.1deb3
-- https://www.phpmyadmin.net/
--
-- Хост: localhost
-- Время создания: Окт 04 2026 г., 10:29
-- Версия сервера: 8.0.46-0ubuntu0.24.04.4
-- Версия PHP: 8.3.6

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База данных: `yat-sport`
--

-- --------------------------------------------------------

--
-- Структура таблицы `activities`
--

CREATE TABLE `activities` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `type` enum('run','ride','swim','ski','walk','hike','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'run',
  `title` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `started_at` datetime DEFAULT NULL,
  `duration_sec` int UNSIGNED DEFAULT NULL,
  `distance_m` decimal(10,2) DEFAULT NULL,
  `elevation_gain_m` decimal(8,2) DEFAULT NULL,
  `avg_speed_mps` decimal(6,3) DEFAULT NULL,
  `max_speed_mps` decimal(6,3) DEFAULT NULL,
  `avg_hr` smallint UNSIGNED DEFAULT NULL,
  `max_hr` smallint UNSIGNED DEFAULT NULL,
  `avg_cadence` smallint UNSIGNED DEFAULT NULL,
  `max_cadence` smallint UNSIGNED DEFAULT NULL,
  `avg_power_w` smallint UNSIGNED DEFAULT NULL,
  `max_power_w` smallint UNSIGNED DEFAULT NULL,
  `avg_temp_c` decimal(4,1) DEFAULT NULL,
  `has_sensors` tinyint(1) NOT NULL DEFAULT '0',
  `calories` int UNSIGNED DEFAULT NULL,
  `gear_id` int UNSIGNED DEFAULT NULL,
  `track_json` longtext COLLATE utf8mb4_unicode_ci,
  `visibility` enum('public','followers','private') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'public',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `activity_comments`
--

CREATE TABLE `activity_comments` (
  `id` int UNSIGNED NOT NULL,
  `activity_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `activity_likes`
--

CREATE TABLE `activity_likes` (
  `user_id` int UNSIGNED NOT NULL,
  `activity_id` int UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `activity_photos`
--

CREATE TABLE `activity_photos` (
  `id` int UNSIGNED NOT NULL,
  `activity_id` int UNSIGNED NOT NULL,
  `url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `order_index` int UNSIGNED NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `api_tokens`
--

CREATE TABLE `api_tokens` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `token_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `follows`
--

CREATE TABLE `follows` (
  `follower_id` int UNSIGNED NOT NULL,
  `following_id` int UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `gear`
--

CREATE TABLE `gear` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `type` enum('bike','shoes','skis','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other',
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `brand` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `photo_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_retired` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `notifications`
--

CREATE TABLE `notifications` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `type` enum('like','comment','follow','mention','system','segment_new_lead','segment_lost_lead','post_like','post_comment') COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` int UNSIGNED DEFAULT NULL,
  `target_type` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `target_id` int UNSIGNED DEFAULT NULL,
  `message` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `posts`
--

CREATE TABLE `posts` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `title` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `visibility` enum('public','followers','private') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'public',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `post_comments`
--

CREATE TABLE `post_comments` (
  `id` int UNSIGNED NOT NULL,
  `post_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `post_likes`
--

CREATE TABLE `post_likes` (
  `user_id` int UNSIGNED NOT NULL,
  `post_id` int UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `post_photos`
--

CREATE TABLE `post_photos` (
  `id` int UNSIGNED NOT NULL,
  `post_id` int UNSIGNED NOT NULL,
  `url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `order_index` int UNSIGNED NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `routes`
--

CREATE TABLE `routes` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `name` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `type` enum('run','ride','swim','ski','walk','hike','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'run',
  `distance_m` decimal(10,2) DEFAULT NULL,
  `elevation_gain_m` decimal(8,2) DEFAULT NULL,
  `track_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `waypoints_json` longtext COLLATE utf8mb4_unicode_ci,
  `is_public` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `segments`
--

CREATE TABLE `segments` (
  `id` int UNSIGNED NOT NULL,
  `creator_id` int UNSIGNED NOT NULL,
  `name` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('run','ride','swim','ski','walk','hike','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'run',
  `distance_m` decimal(10,2) NOT NULL,
  `elevation_gain_m` decimal(8,2) DEFAULT NULL,
  `track_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_public` tinyint(1) NOT NULL DEFAULT '1',
  `current_leader_id` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `segment_efforts`
--

CREATE TABLE `segment_efforts` (
  `id` int UNSIGNED NOT NULL,
  `segment_id` int UNSIGNED NOT NULL,
  `activity_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `elapsed_time_sec` int UNSIGNED NOT NULL,
  `is_auto` tinyint(1) NOT NULL DEFAULT '0',
  `matched_distance_m` decimal(10,2) DEFAULT NULL,
  `match_quality` tinyint UNSIGNED DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `tokens`
--

CREATE TABLE `tokens` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `type` enum('email_verify','password_reset','api') COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `users`
--

CREATE TABLE `users` (
  `id` int UNSIGNED NOT NULL,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `avatar_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bio` text COLLATE utf8mb4_unicode_ci,
  `city` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gender` enum('male','female','other') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `weight_kg` decimal(5,2) DEFAULT NULL,
  `height_cm` decimal(5,2) DEFAULT NULL,
  `units` enum('metric','imperial') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'metric',
  `is_public` tinyint(1) NOT NULL DEFAULT '1',
  `email_verified` tinyint(1) NOT NULL DEFAULT '0',
  `wahoo_user_id` bigint UNSIGNED DEFAULT NULL,
  `wahoo_access_token` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `wahoo_refresh_token` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `wahoo_code_verifier` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `wahoo_expires_at` datetime DEFAULT NULL,
  `wahoo_connected_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `wahoo_synced_workouts`
--

CREATE TABLE `wahoo_synced_workouts` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `wahoo_workout_id` bigint UNSIGNED NOT NULL,
  `activity_id` int UNSIGNED NOT NULL,
  `synced_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Индексы сохранённых таблиц
--

--
-- Индексы таблицы `activities`
--
ALTER TABLE `activities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `gear_id` (`gear_id`),
  ADD KEY `idx_user_date` (`user_id`,`started_at`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_created_visible` (`created_at`,`visibility`);

--
-- Индексы таблицы `activity_comments`
--
ALTER TABLE `activity_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_activity` (`activity_id`,`created_at`);

--
-- Индексы таблицы `activity_likes`
--
ALTER TABLE `activity_likes`
  ADD PRIMARY KEY (`user_id`,`activity_id`),
  ADD KEY `idx_activity` (`activity_id`);

--
-- Индексы таблицы `activity_photos`
--
ALTER TABLE `activity_photos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_activity_order` (`activity_id`,`order_index`);

--
-- Индексы таблицы `api_tokens`
--
ALTER TABLE `api_tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_hash` (`token_hash`);

--
-- Индексы таблицы `follows`
--
ALTER TABLE `follows`
  ADD PRIMARY KEY (`follower_id`,`following_id`),
  ADD KEY `idx_following` (`following_id`);

--
-- Индексы таблицы `gear`
--
ALTER TABLE `gear`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`);

--
-- Индексы таблицы `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `actor_id` (`actor_id`),
  ADD KEY `idx_user_unread` (`user_id`,`is_read`,`created_at`),
  ADD KEY `idx_type_target` (`type`,`target_type`,`target_id`);

--
-- Индексы таблицы `posts`
--
ALTER TABLE `posts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_date` (`user_id`,`created_at`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_visibility` (`visibility`),
  ADD KEY `idx_created_visible` (`created_at`,`visibility`);

--
-- Индексы таблицы `post_comments`
--
ALTER TABLE `post_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_post` (`post_id`,`created_at`);

--
-- Индексы таблицы `post_likes`
--
ALTER TABLE `post_likes`
  ADD PRIMARY KEY (`user_id`,`post_id`),
  ADD KEY `idx_post` (`post_id`);

--
-- Индексы таблицы `post_photos`
--
ALTER TABLE `post_photos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_post_order` (`post_id`,`order_index`);

--
-- Индексы таблицы `routes`
--
ALTER TABLE `routes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_public` (`is_public`);

--
-- Индексы таблицы `segments`
--
ALTER TABLE `segments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `creator_id` (`creator_id`),
  ADD KEY `idx_public` (`is_public`),
  ADD KEY `idx_current_leader` (`current_leader_id`);

--
-- Индексы таблицы `segment_efforts`
--
ALTER TABLE `segment_efforts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_effort` (`segment_id`,`activity_id`),
  ADD KEY `activity_id` (`activity_id`),
  ADD KEY `idx_leaderboard` (`segment_id`,`elapsed_time_sec`),
  ADD KEY `idx_user_segment` (`user_id`,`segment_id`,`elapsed_time_sec`);

--
-- Индексы таблицы `tokens`
--
ALTER TABLE `tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_type` (`user_id`,`type`),
  ADD KEY `idx_hash` (`token_hash`);

--
-- Индексы таблицы `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `idx_username` (`username`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_wahoo_user` (`wahoo_user_id`);

--
-- Индексы таблицы `wahoo_synced_workouts`
--
ALTER TABLE `wahoo_synced_workouts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_workout` (`user_id`,`wahoo_workout_id`),
  ADD KEY `idx_activity` (`activity_id`);

--
-- AUTO_INCREMENT для сохранённых таблиц
--

--
-- AUTO_INCREMENT для таблицы `activities`
--
ALTER TABLE `activities`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `activity_comments`
--
ALTER TABLE `activity_comments`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `activity_photos`
--
ALTER TABLE `activity_photos`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `api_tokens`
--
ALTER TABLE `api_tokens`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `gear`
--
ALTER TABLE `gear`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `posts`
--
ALTER TABLE `posts`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `post_comments`
--
ALTER TABLE `post_comments`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `post_photos`
--
ALTER TABLE `post_photos`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `routes`
--
ALTER TABLE `routes`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `segments`
--
ALTER TABLE `segments`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `segment_efforts`
--
ALTER TABLE `segment_efforts`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `tokens`
--
ALTER TABLE `tokens`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `users`
--
ALTER TABLE `users`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `wahoo_synced_workouts`
--
ALTER TABLE `wahoo_synced_workouts`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Ограничения внешнего ключа сохраненных таблиц
--

--
-- Ограничения внешнего ключа таблицы `activities`
--
ALTER TABLE `activities`
  ADD CONSTRAINT `activities_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `activities_ibfk_2` FOREIGN KEY (`gear_id`) REFERENCES `gear` (`id`) ON DELETE SET NULL;

--
-- Ограничения внешнего ключа таблицы `activity_comments`
--
ALTER TABLE `activity_comments`
  ADD CONSTRAINT `activity_comments_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `activity_comments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `activity_likes`
--
ALTER TABLE `activity_likes`
  ADD CONSTRAINT `activity_likes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `activity_likes_ibfk_2` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `activity_photos`
--
ALTER TABLE `activity_photos`
  ADD CONSTRAINT `activity_photos_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `api_tokens`
--
ALTER TABLE `api_tokens`
  ADD CONSTRAINT `api_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `follows`
--
ALTER TABLE `follows`
  ADD CONSTRAINT `follows_ibfk_1` FOREIGN KEY (`follower_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `follows_ibfk_2` FOREIGN KEY (`following_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `gear`
--
ALTER TABLE `gear`
  ADD CONSTRAINT `gear_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Ограничения внешнего ключа таблицы `posts`
--
ALTER TABLE `posts`
  ADD CONSTRAINT `posts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `post_comments`
--
ALTER TABLE `post_comments`
  ADD CONSTRAINT `post_comments_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `post_comments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `post_likes`
--
ALTER TABLE `post_likes`
  ADD CONSTRAINT `post_likes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `post_likes_ibfk_2` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `post_photos`
--
ALTER TABLE `post_photos`
  ADD CONSTRAINT `post_photos_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `routes`
--
ALTER TABLE `routes`
  ADD CONSTRAINT `routes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `segments`
--
ALTER TABLE `segments`
  ADD CONSTRAINT `segments_ibfk_1` FOREIGN KEY (`creator_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `segment_efforts`
--
ALTER TABLE `segment_efforts`
  ADD CONSTRAINT `segment_efforts_ibfk_1` FOREIGN KEY (`segment_id`) REFERENCES `segments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `segment_efforts_ibfk_2` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `segment_efforts_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `tokens`
--
ALTER TABLE `tokens`
  ADD CONSTRAINT `tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `wahoo_synced_workouts`
--
ALTER TABLE `wahoo_synced_workouts`
  ADD CONSTRAINT `ws_activity_fk` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ws_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
