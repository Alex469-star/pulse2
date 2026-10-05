-- Pulse — схема базы данных

CREATE DATABASE IF NOT EXISTS pulse
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE pulse;

-- ---------- USERS ----------
CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    username VARCHAR(60) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    avatar_url VARCHAR(255) DEFAULT NULL,
    bio TEXT DEFAULT NULL,
    city VARCHAR(120) DEFAULT NULL,
    country VARCHAR(120) DEFAULT NULL,
    gender ENUM('male','female','other') DEFAULT NULL,
    birth_date DATE DEFAULT NULL,
    weight_kg DECIMAL(5,2) DEFAULT NULL,
    height_cm DECIMAL(5,2) DEFAULT NULL,
    units ENUM('metric','imperial') NOT NULL DEFAULT 'metric',
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_email (email)
) ENGINE=InnoDB;

-- ---------- FOLLOWS ----------
CREATE TABLE follows (
    follower_id INT UNSIGNED NOT NULL,
    following_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (follower_id, following_id),
    FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (following_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_following (following_id)
) ENGINE=InnoDB;

-- ---------- GEAR ----------
CREATE TABLE gear (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    type ENUM('bike','shoes','skis','other') NOT NULL DEFAULT 'other',
    name VARCHAR(120) NOT NULL,
    brand VARCHAR(120) DEFAULT NULL,
    model VARCHAR(120) DEFAULT NULL,
    purchase_date DATE DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    is_retired TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB;

-- ---------- ACTIVITIES ----------
CREATE TABLE activities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    type ENUM('run','ride','swim','ski','walk','hike','other') NOT NULL DEFAULT 'run',
    title VARCHAR(190) NOT NULL,
    description TEXT DEFAULT NULL,
    started_at DATETIME DEFAULT NULL,
    duration_sec INT UNSIGNED DEFAULT NULL,
    distance_m DECIMAL(10,2) DEFAULT NULL,
    elevation_gain_m DECIMAL(8,2) DEFAULT NULL,
    avg_speed_mps DECIMAL(6,3) DEFAULT NULL,
    max_speed_mps DECIMAL(6,3) DEFAULT NULL,
    calories INT UNSIGNED DEFAULT NULL,
    gear_id INT UNSIGNED DEFAULT NULL,
    track_json LONGTEXT DEFAULT NULL,       -- [{lat,lng,ele,t}]
    visibility ENUM('public','followers','private') NOT NULL DEFAULT 'public',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (gear_id) REFERENCES gear(id) ON DELETE SET NULL,
    INDEX idx_user_date (user_id, started_at),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- ---------- LIKES ----------
CREATE TABLE activity_likes (
    user_id INT UNSIGNED NOT NULL,
    activity_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, activity_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE,
    INDEX idx_activity (activity_id)
) ENGINE=InnoDB;

-- ---------- COMMENTS ----------
CREATE TABLE activity_comments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    activity_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_activity (activity_id, created_at)
) ENGINE=InnoDB;

-- ---------- SEED: демо-пользователь ----------
-- Пароль: password
INSERT INTO users (email, username, password_hash, display_name, city, country, gender, bio)
VALUES (
    'demo@pulse.local',
    'demo',
    '$2y$10$e0MYzXyjpJS7Pd0RVvHwHe1H4q8u5a9nJcZ0D0mZ9Bq1k7fJ0n2G6',
    'Демо Спортсмен',
    'Москва',
    'Россия',
    'male',
    'Люблю бег и велосипед. Тестирую Pulse.'
);

CREATE TABLE routes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(190) NOT NULL,
    description TEXT DEFAULT NULL,
    type ENUM('run','ride','swim','ski','walk','hike','other') NOT NULL DEFAULT 'run',
    distance_m DECIMAL(10,2) DEFAULT NULL,
    elevation_gain_m DECIMAL(8,2) DEFAULT NULL,
    track_json LONGTEXT NOT NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_public (is_public)
) ENGINE=InnoDB;

CREATE TABLE segments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    creator_id INT UNSIGNED NOT NULL,
    name VARCHAR(190) NOT NULL,
    type ENUM('run','ride','swim','ski','walk','hike','other') NOT NULL DEFAULT 'run',
    distance_m DECIMAL(10,2) NOT NULL,
    elevation_gain_m DECIMAL(8,2) DEFAULT NULL,
    track_json LONGTEXT NOT NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (creator_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_public (is_public)
) ENGINE=InnoDB;

CREATE TABLE segment_efforts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    segment_id INT UNSIGNED NOT NULL,
    activity_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    elapsed_time_sec INT UNSIGNED NOT NULL,
    started_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (segment_id) REFERENCES segments(id) ON DELETE CASCADE,
    FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_effort (segment_id, activity_id),
    INDEX idx_leaderboard (segment_id, elapsed_time_sec)
) ENGINE=InnoDB;

CREATE TABLE api_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    name VARCHAR(120) DEFAULT NULL,
    last_used_at DATETIME DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_hash (token_hash)
) ENGINE=InnoDB;