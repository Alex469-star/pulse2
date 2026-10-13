Ниже — подробный `README.md` для текущего проекта Pulse. Он описывает структуру, схему БД, связи таблиц, поток данных и ключевые модули. Можно вставлять в корень проекта как есть.

---

```markdown
# Pulse

**Pulse** — социальная платформа для спортсменов. Позволяет
загружать тренировки (GPX/TCX/FIT), строить карты, создавать
маршруты и сегменты, соревноваться в лидербордах, вести инвентарь
(велосипеды, кроссовки, лыжи), подписываться на других атлетов,
ставить лайки и комментировать.

Реализация — MVP на чистом **PHP 8.1+** и **MySQL 8.0+**, без
фреймворков. Фронтенд — серверный рендеринг + vanilla JS.

---

## Содержание

1. [Возможности](#возможности)
2. [Технологии](#технологии)
3. [Структура проекта](#структура-проекта)
4. [Установка](#установка)
5. [Настройка](#настройка)
6. [Схема базы данных](#схема-базы-данных)
7. [Связи таблиц](#связи-таблиц)
8. [Потоки данных](#потоки-данных)
9. [Ключевые модули](#ключевые-модули)
10. [API](#api)
11. [Админка](#админка)
12. [Соглашения](#соглашения)
13. [Безопасность](#безопасность)

---

## Возможности

### Для пользователя

- Регистрация и вход (email + пароль, подтверждение email).
- Загрузка активностей из файлов **GPX**, **TCX**, **FIT** или
  вручную.
- Автоопределение типа активности (бег, велосипед, плавание,
  лыжи, ходьба, хайкинг) на основе данных трека и метаданных.
- Просмотр активности: карта трека (Leaflet), графики высоты,
  скорости, пульса, мощности, каденса.
- Экспорт активностей в **GPX** и **TCX**.
- Инвентарь: велосипеды, кроссовки, лыжи, с учётом пробега и
  износа.
- Маршруты: создание, редактирование, экспорт, просмотр на карте
  с путевыми точками.
- Сегменты: создание из участка трека, лидерборды, личные
  рекорды, автоматический матчинг при загрузке активности.
- Календарь и статистика: heatmap по дням, streak, рекорды,
  графики по 12 неделям и месяцам.
- Социальное: подписки, лайки, комментарии (с вложенными
  ответами), уведомления в реальном времени.
- Клубы: создание, участники с ролями, стена, события с
  attendees и комментариями.
- Лента: единая лента активностей, постов и событий клубов,
  с фильтрами по типу и вкладками (Всё / Подписки / Мои клубы /
  Мои / Записи).
- Посты с фото и видимостью (public / followers / private).
- Интеграция с **Wahoo** (OAuth 2.0 + PKCE, вебхуки).
- Задел под Strava, Garmin, Polar, Suunto.

### Для администратора

- Дашборд с метриками (пользователи, активности, дистанция,
  соцсвязи, клубы, геймификация, интеграции, вебхуки).
- Статистика: графики активности, разбивки по типам, источникам,
  дням недели, часам, топы пользователей и сегментов.
- Управление пользователями, активностями, постами, клубами,
  сегментами, инвентарём.
- Редактор БД: inline-редактирование ячеек, фильтры по колонкам,
  сортировка, превью тяжёлых колонок.
- SQL-консоль с dry-run, историей и защитой от опасных запросов.
- Рассылки: сегментация аудитории, предпросмотр, тест себе.
- Аудит действий администраторов.
- Администраторы с ролями (`super`, `moderator`, `readonly`),
  TOTP-секретом, `ip_allowlist`, счётчиком неудачных попыток и
  блокировкой.

---

## Технологии

| Слой | Технология |
|------|-----------|
| Backend | PHP 8.1+, без фреймворков |
| БД | MySQL 8.0 (InnoDB, utf8mb4) |
| Frontend | Серверный рендеринг + vanilla JS |
| Карты (интерактив) | Leaflet 1.9.4 + OpenStreetMap |
| Карты (превью) | MapBox Static Images API |
| Графики | Chart.js 4.4.1 |
| Аутентификация | Сессии (веб), Bearer-токены (API) |
| Хранение файлов | Локально, `assets/uploads/` |
| Кэш | Redis |
| Парсинг FIT | Собственный парсер (бинарный формат) |
| Парсинг GPX/TCX | SimpleXML |
| Очереди | Нет (все операции синхронные) |

---

## Структура проекта

```
pulse/
├── index.php                    # Лендинг
├── login.php                    # Вход
├── register.php                 # Регистрация
├── logout.php                   # Выход
├── forgot-password.php          # Запрос сброса пароля
├── reset-password.php           # Установка нового пароля
├── verify-email.php             # Подтверждение email
│
├── feed.php                     # Лента
├── post.php                     # Просмотр поста
├── post-create.php              # Создание поста
├── my-posts.php                 # Мои посты
│
├── activity.php                 # Просмотр активности
├── activity-upload.php          # Загрузка активности
├── activity-edit.php            # Редактирование активности
├── activity-create.php          # Ручное создание
│
├── calendar.php                 # Календарь и статистика
├── profile.php                  # Публичный профиль
├── profile-edit.php             # Настройки профиля
├── profile-followers.php        # Список подписчиков
├── profile-following.php        # Список подписок
├── notifications.php            # Уведомления
├── search.php                   # Поиск
│
├── gear.php                     # Инвентарь
├── routes.php                   # Каталог маршрутов
├── route.php                    # Просмотр маршрута
├── route-create.php             # Создание маршрута
├── route-edit.php               # Редактирование
├── segments.php                 # Каталог сегментов
├── segment.php                  # Просмотр сегмента
├── segment-create.php           # Создание сегмента
├── segment-edit.php             # Редактирование
│
├── clubs.php                    # Каталог клубов
├── club.php                     # Просмотр клуба
├── club-create.php              # Создание клуба
├── club-edit.php                # Редактирование
├── club-members.php             # Участники клуба
├── club-join.php                # Вступление в клуб
├── club-events.php              # События клуба
├── club-event.php               # Просмотр события
├── club-event-create.php        # Создание события
├── club-event-edit.php          # Редактирование события
├── club-event-delete.php        # Удаление события
│
├── heatmap.php                  # Тепловая карта активностей
├── about.php                    # О проекте
├── privacy.php                  # Политика конфиденциальности
├── terms.php                    # Условия использования
│
├── wahoo-connect.php            # Подключение Wahoo
├── wahoo-callback.php           # OAuth-callback Wahoo
├── wahoo-sync.php               # Синхронизация Wahoo
├── wahoo-disconnect.php         # Отключение Wahoo
│
├── api/                         # JSON API
│   ├── _bootstrap.php           # Общий загрузчик API
│   ├── auth.php                 # Логин по email + паролю
│   ├── me.php                   # Текущий пользователь
│   ├── feed.php                 # Лента для мобильного
│   ├── activities.php           # Список активностей
│   ├── activity.php             # Одна активность
│   ├── upload-activity.php      # Загрузка GPX/TCX/FIT
│   ├── upload-activity-photo.php
│   ├── add-activity-photo.php
│   ├── activity-photo-delete.php
│   ├── activity-trim.php        # Обрезка трека
│   ├── like.php                 # Лайк активности
│   ├── comment.php              # Комментарий к активности
│   ├── post-like.php
│   ├── post-comment.php
│   ├── post-photo-delete.php
│   ├── notifications.php        # Поллинг уведомлений
│   └── ...
│
├── models/                      # Модели (статические методы)
│   ├── Activity.php
│   ├── User.php
│   ├── Post.php
│   ├── Like.php
│   ├── Comment.php
│   ├── Follow.php
│   ├── Gear.php
│   ├── Route.php
│   ├── Segment.php
│   ├── Notification.php
│   ├── Token.php
│   └── Broadcast.php
│
├── services/                    # Сервисы (не зависят от HTTP)
│   ├── GpxParser.php
│   ├── TcxParser.php
│   ├── FitParser.php
│   ├── SegmentMatcher.php
│   ├── ActivityTypeDetector.php
│   └── TrackPreview.php
│
├── includes/                    # Общие модули
│   ├── auth.php                 # Обвязка авторизации
│   ├── header.php               # Общий хедер
│   ├── footer.php               # Общий футер
│   ├── helpers.php              # Хелперы
│   ├── mailer.php               # Обёртка над PHPMailer
│   └── Cache.php                # Обёртка над Redis
│
├── config/                      # Конфигурация
│   ├── config.php
│   └── database.php
│
├── admin/                       # Админка
│   ├── includes/                # Общие модули админки
│   │   ├── auth.php
│   │   ├── header.php
│   │   ├── footer.php
│   │   └── helpers.php
│   ├── index.php
│   ├── dashboard.php
│   ├── stats.php
│   ├── users.php
│   ├── user.php
│   ├── activities.php
│   ├── posts.php
│   ├── segments.php
│   ├── clubs.php
│   ├── gear.php
│   ├── broadcasts.php
│   ├── broadcast-create.php
│   ├── broadcast-edit.php
│   ├── database.php
│   ├── sql.php
│   ├── admins.php
│   ├── audit.php
│   ├── login.php
│   ├── logout.php
│   └── api/                     # API админки
│       ├── api-db-save.php
│       └── broadcast-audience.php
│
├── sql/
│   └── schema.sql               # Полная схема БД
│
├── assets/
│   ├── css/
│   │   ├── style.css
│   │   ├── admin.css
│   │   ├── calendar.css
│   │   ├── activity-trim.css
│   │   └── pulse-loader.css
│   ├── js/
│   │   ├── main.js
│   │   ├── upload-activity.js
│   │   ├── broadcast.js
│   │   ├── calendar.js
│   │   ├── pulse-loader.js
│   │   └── activity-trim.js
│   ├── img/
│   │   └── logo_pulse.png
│   └── uploads/
│       ├── activities/          # Фото активностей
│       ├── posts/               # Фото постов
│       ├── avatars/             # Аватары
│       ├── gear/                # Фото инвентаря
│       └── track-previews/      # WebP-превью треков
│
├── storage/                     # Логи и служебные данные
│   ├── mail.log
│   ├── uploads.log
│   ├── timing.log
│   ├── fit-debug.log
│   ├── segment-match.log
│   └── track-preview.log
│
├── bin/
│   └── generate-previews.php    # CLI-скрипт для превью треков
│
├── .htaccess                    # Защита config, красивые URL
├── composer.json                # Зависимости (PHPMailer)
└── README.md
```

---

## Установка

### Требования

- PHP 8.1+ с расширениями:
  - `pdo_mysql`
  - `mbstring`
  - `simplexml`
  - `zip`
  - `openssl`
  - `json` (встроено)
  - `gd` (для превью треков, с поддержкой WebP)
  - `curl` (для запросов к MapBox и Wahoo)
- MySQL 8.0+ или MariaDB 10.4+
- Composer
- Redis (опционально, но рекомендуется)
- Apache с `mod_rewrite` или Nginx

### Шаги

1. **Клонируйте проект:**

   ```bash
   git clone <repo> /var/www/pulse
   cd /var/www/pulse
   ```

2. **Установите зависимости:**

   ```bash
   composer install
   ```

3. **Создайте БД и импортируйте схему:**

   ```bash
   mysql -u root -p -e "CREATE DATABASE pulse CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u root -p pulse < sql/schema.sql
   ```

4. **Настройте `config/config.php`** (см. ниже).

5. **Создайте папки для загрузок и дайте права:**

   ```bash
   mkdir -p assets/uploads/{activities,posts,avatars,gear,track-previews}
   mkdir -p storage
   chmod -R 775 assets/uploads storage
   chown -R www-data:www-data assets/uploads storage
   ```

6. **Откройте проект в браузере:** `https://your-domain/pulse/`.

---

## Настройка

### `config/config.php`

```php
<?php
declare(strict_types=1);

return [
    'app_name' => 'Pulse',
    'app_url'  => 'https://your-domain/pulse',
    'base_url' => 'https://your-domain/pulse',

    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'pulse',
        'username' => 'pulse',
        'password' => '...',
        'charset'  => 'utf8mb4',
    ],

    'mail' => [
        'enabled'    => true,
        'host'       => 'smtp.example.com',
        'port'       => 587,
        'username'   => 'noreply@example.com',
        'password'   => '...',
        'encryption' => 'tls',
        'from_email' => 'noreply@example.com',
        'from_name'  => 'Pulse',
    ],

    'wahoo' => [
        'client_id'     => '...',
        'client_secret' => '...',
        'redirect_uri'  => 'https://your-domain/pulse/wahoo-callback.php',
        'authorize_url' => 'https://api.wahooligan.com/oauth/authorize',
        'token_url'     => 'https://api.wahooligan.com/oauth/token',
        'api_base'      => 'https://api.wahooligan.com',
        'scopes'        => [
            'user_read',
            'workouts_read',
            'power_zones_read',
            'offline_data',
        ],
        'webhook_token' => '...',
    ],

    'mapbox' => [
        'token' => 'pk.eyJ1Ijoi...',
        'style' => 'outdoors-v12',
    ],

    'redis' => [
        'enabled'  => true,
        'host'     => '127.0.0.1',
        'port'     => 6379,
        'password' => null,
        'database' => 0,
        'prefix'   => 'pulse:',
    ],

    'session_name' => 'pulse_sid',
];
```

### Доступ к `config/` защищён `.htaccess`

Прямой доступ к `config/config.php` и `config/database.php` через
браузер заблокирован. Если вы используете Nginx — добавьте
аналогичное правило в конфиг сервера.

---

## Схема базы данных

Всего **~40 таблиц**. Ниже — по группам.

### 1. Пользователи и аутентификация

| Таблица | Назначение | Ключевые поля |
|---------|-----------|---------------|
| `users` | Основная таблица пользователей | `id`, `email`, `username`, `password_hash`, `display_name`, `avatar_url`, `bio`, `city`, `country`, `units`, `is_public`, `email_verified` |
| `follows` | Подписки | `follower_id`, `following_id` (составной PK) |
| `tokens` | Одноразовые токены | `user_id`, `type` (email_verify / password_reset), `token_hash`, `expires_at`, `used_at` |
| `api_tokens` | Bearer-токены для API | `user_id`, `token_hash`, `name`, `last_used_at`, `expires_at` |
| `user_integrations` | OAuth-интеграции | `user_id`, `provider`, `external_user_id`, `access_token`, `refresh_token`, `token_expires_at`, `scope` |
| `user_saved_places` | Сохранённые места | `user_id`, `name`, `lat`, `lng`, `icon` |

### 2. Активности

| Таблица | Назначение | Ключевые поля |
|---------|-----------|---------------|
| `activities` | Тренировки | `user_id`, `type`, `title`, `description`, `started_at`, `duration_sec`, `distance_m`, `elevation_gain_m`, `avg_speed_mps`, `track_json` (longtext), `visibility`, `fingerprint`, `track_hash`, `gear_id` |
| `activity_likes` | Лайки | `user_id`, `activity_id` (составной PK) |
| `activity_comments` | Комментарии | `activity_id`, `parent_id`, `user_id`, `body` |
| `activity_photos` | Фото активностей | `activity_id`, `url`, `order_index` |

### 3. Посты

| Таблица | Назначение | Ключевые поля |
|---------|-----------|---------------|
| `posts` | Блог-посты | `user_id`, `title`, `body`, `visibility` |
| `post_likes` | Лайки постов | `user_id`, `post_id` |
| `post_comments` | Комментарии постов | `post_id`, `parent_id`, `user_id`, `body` |
| `post_photos` | Фото постов | `post_id`, `url`, `order_index` |

### 4. Маршруты и сегменты

| Таблица | Назначение | Ключевые поля |
|---------|-----------|---------------|
| `routes` | Маршруты | `user_id`, `name`, `description`, `type`, `distance_m`, `track_json`, `waypoints_json`, `is_public` |
| `segments` | Сегменты | `creator_id`, `name`, `type`, `distance_m`, `track_json`, `is_public`, `current_leader_id` |
| `segment_efforts` | Попытки прохождения | `segment_id`, `activity_id`, `user_id`, `elapsed_time_sec`, `is_auto`, `match_quality`, `started_at` |

### 5. Инвентарь

| Таблица | Назначение | Ключевые поля |
|---------|-----------|---------------|
| `gear` | Велосипеды, кроссовки, лыжи | `user_id`, `type`, `name`, `brand`, `model`, `purchase_date`, `is_retired` |

### 6. Клубы

| Таблица | Назначение | Ключевые поля |
|---------|-----------|---------------|
| `clubs` | Клубы | `name`, `slug`, `description`, `city`, `sport_type`, `visibility`, `join_policy`, `owner_id`, `member_count`, `is_verified`, `is_banned` |
| `club_members` | Участники | `club_id`, `user_id`, `role` (owner/admin/moderator/member), `status` (active/pending/invited/banned) |
| `club_posts` | Посты клуба | `club_id`, `parent_id`, `reply_to_user_id`, `user_id`, `body`, `is_pinned`, `is_deleted` |
| `club_events` | События | `club_id`, `creator_id`, `title`, `type`, `starts_at`, `location`, `lat`, `lng`, `max_attendees` |
| `club_event_attendees` | Участники события | `event_id`, `user_id`, `status` (going/maybe/declined) |
| `club_event_comments` | Комментарии событий | `event_id`, `parent_id`, `user_id`, `body` |
| `club_event_photos` | Фото событий | `event_id`, `user_id`, `url` |
| `club_invites` | Приглашения | `club_id`, `token`, `created_by`, `expires_at`, `max_uses`, `used_count` |
| `club_stats` | Денормализованная статистика | `club_id`, `total_distance_m`, `total_duration_sec`, `total_activities` |

### 7. Уведомления

| Таблица | Назначение | Ключевые поля |
|---------|-----------|---------------|
| `notifications` | Уведомления | `user_id`, `type`, `actor_id`, `target_type`, `target_id`, `message`, `url`, `is_read` |

### 8. Геймификация (территории)

| Таблица | Назначение | Ключевые поля |
|---------|-----------|---------------|
| `game_profiles` | Игровые профили | `user_id`, `xp`, `level`, `territories`, `area_m2_total`, `captured_cnt` |
| `game_clubs` | Игровые клубы | `name`, `tag`, `owner_id` |
| `game_club_members` | Участники игровых клубов | `club_id`, `user_id`, `role` |
| `game_territories` | Территории (полигоны) | `owner_user_id`, `owner_club_id`, `polygon` (GEOMETRY) |
| `game_capture_log` | История захватов | `territory_id`, `from_user_id`, `to_user_id`, `activity_id`, `kind` (capture/steal/release/revert) |
| `game_processed_activities` | Обработанные активности | `activity_id`, `result`, `reason` |

### 9. Интеграции и вебхуки

| Таблица | Назначение | Ключевые поля |
|---------|-----------|---------------|
| `webhook_events` | Входящие вебхуки | `provider`, `event_type`, `external_user_id`, `payload` (JSON), `status`, `error` |
| `integration_synced_items` | Синхронизированные элементы | `user_id`, `provider`, `external_id`, `activity_id` |
| `wahoo_synced_workouts` | Синхронизированные тренировки Wahoo | `user_id`, `wahoo_workout_id`, `activity_id` |

### 10. Тепловая карта

| Таблица | Назначение | Ключевые поля |
|---------|-----------|---------------|
| `heatmap_points` | Точки трека для тепловой карты | `activity_id`, `user_id`, `type`, `lat`, `lng`, `hour`, `recorded_at`, `is_public` |

### 11. Админка

| Таблица | Назначение | Ключевые поля |
|---------|-----------|---------------|
| `admins` | Администраторы | `user_id`, `email`, `username`, `password_hash`, `role` (super/moderator/readonly), `totp_secret`, `totp_enabled`, `is_active`, `ip_allowlist`, `failed_attempts`, `locked_until` |
| `admin_audit_log` | Аудит действий | `admin_id`, `action`, `target_type`, `target_id`, `payload` (JSON), `ip`, `user_agent` |
| `admin_broadcasts` | Рассылки | `admin_id`, `title`, `body`, `type`, `url`, `audience`, `audience_meta` (JSON), `status`, `sent_count`, `failed_count` |
| `admin_broadcast_recipients` | Получатели рассылок | `broadcast_id`, `user_id`, `sent_at`, `error` |
| `admin_saved_queries` | Сохранённые SQL-запросы | `admin_id`, `name`, `sql_text`, `is_shared` |

---

## Связи таблиц

Диаграмма связей (упрощённая):

```
                    ┌─────────────┐
                    │   users     │
                    └──────┬──────┘
                           │
       ┌───────────────────┼───────────────────┬───────────────────┐
       │                   │                   │                   │
       ▼                   ▼                   ▼                   ▼
  ┌─────────┐        ┌──────────┐        ┌───────────┐       ┌──────────┐
  │follows  │        │gear      │        │activities │       │posts     │
  └─────────┘        └──────────┘        └─────┬─────┘       └────┬─────┘
                                                │                  │
                   ┌────────────────────────────┤                  │
                   │                            │                  │
                   ▼                            ▼                  ▼
            ┌──────────────┐            ┌──────────────┐   ┌──────────────┐
            │activity_likes│            │activity_photos│   │post_likes    │
            └──────────────┘            └──────────────┘   └──────────────┘
                   │                            │                  │
                   ▼                            ▼                  ▼
            ┌──────────────────┐        ┌──────────────┐   ┌──────────────┐
            │activity_comments │        │segment_efforts│  │post_comments │
            └──────────────────┘        └───────┬───────┘  └──────────────┘
                                                │                  │
                                                ▼                  ▼
                                        ┌──────────────┐   ┌──────────────┐
                                        │  segments    │   │post_photos   │
                                        └──────┬───────┘   └──────────────┘
                                               │
                                               ▼
                                        ┌──────────────┐
                                        │   routes     │
                                        └──────────────┘

                    ┌─────────────┐
                    │   clubs     │
                    └──────┬──────┘
                           │
       ┌───────────────────┼───────────────────┬───────────────────┐
       │                   │                   │                   │
       ▼                   ▼                   ▼                   ▼
 ┌──────────────┐  ┌──────────────┐   ┌──────────────┐   ┌──────────────┐
 │club_members  │  │club_posts    │   │club_events   │   │club_invites  │
 └──────────────┘  └──────────────┘   └──────┬───────┘   └──────────────┘
                                             │
                            ┌────────────────┼────────────────┐
                            ▼                ▼                ▼
                    ┌──────────────┐  ┌──────────────┐  ┌──────────────┐
                    │event_attendees│ │event_comments│  │event_photos  │
                    └──────────────┘  └──────────────┘  └──────────────┘

                    ┌─────────────┐
                    │  territories│
                    └──────┬──────┘
                           │
                           ▼
                    ┌──────────────────┐
                    │game_capture_log  │
                    └──────────────────┘
```

### Ключевые связи

| Родитель | Ребёнок | Тип | ON DELETE |
|----------|---------|-----|-----------|
| `users` | `activities` | 1:N | CASCADE |
| `users` | `posts` | 1:N | CASCADE |
| `users` | `clubs` (owner) | 1:N | CASCADE |
| `users` | `follows` (follower) | 1:N | CASCADE |
| `users` | `follows` (following) | 1:N | CASCADE |
| `users` | `gear` | 1:N | CASCADE |
| `users` | `routes` | 1:N | CASCADE |
| `users` | `segments` (creator) | 1:N | CASCADE |
| `users` | `notifications` | 1:N | CASCADE |
| `activities` | `activity_likes` | 1:N | CASCADE |
| `activities` | `activity_comments` | 1:N | CASCADE |
| `activities` | `activity_photos` | 1:N | CASCADE |
| `activities` | `segment_efforts` | 1:N | CASCADE |
| `posts` | `post_likes` | 1:N | CASCADE |
| `posts` | `post_comments` | 1:N | CASCADE |
| `posts` | `post_photos` | 1:N | CASCADE |
| `segments` | `segment_efforts` | 1:N | CASCADE |
| `routes` | — | — | — |
| `clubs` | `club_members` | 1:N | CASCADE |
| `clubs` | `club_posts` | 1:N | CASCADE |
| `clubs` | `club_events` | 1:N | CASCADE |
| `clubs` | `club_invites` | 1:N | CASCADE |
| `club_events` | `club_event_attendees` | 1:N | CASCADE |
| `club_events` | `club_event_comments` | 1:N | CASCADE |
| `club_events` | `club_event_photos` | 1:N | CASCADE |
| `game_territories` | `game_capture_log` | 1:N | CASCADE |
| `gear` | `activities` (gear_id) | 1:N | SET NULL |

---

## Потоки данных

### 1. Регистрация

```
register.php
    │
    ├─→ валидация email/username/пароля
    ├─→ User::create()      INSERT users
    ├─→ Token::create()     INSERT tokens (email_verify)
    ├─→ send_mail()         PHPMailer → ссылка /verify-email.php?token=…
    └─→ redirect на feed.php

Пользователь кликает по ссылке:
verify-email.php?token=…
    │
    ├─→ Token::verify()     SELECT tokens WHERE token_hash = ?
    ├─→ UPDATE users SET email_verified = 1
    └─→ redirect на login.php
```

### 2. Загрузка активности

```
activity-upload.php (форма)
    │
    ├─→ JS: upload-activity.js
    │       │
    │       └─→ XHR POST на api/upload-activity.php
    │
api/upload-activity.php
    │
    ├─→ api_check_csrf()
    ├─→ api_require_user()
    ├─→ проверка расширения и размера
    ├─→ GpxParser::parse() / TcxParser::parse() / FitParser::parse()
    ├─→ ActivityTypeDetector::detect()      автоопределение типа
    ├─→ прореживание точек до 20000
    ├─→ Activity::fingerprint()             SHA1
    ├─→ Activity::trackHash()               SHA256
    ├─→ Activity::findDuplicate()           проверка дубликата
    ├─→ Activity::create()                  INSERT activities
    ├─→ TrackPreview::generate()            WebP-превью через MapBox
    ├─→ SegmentMatcher::matchAllForActivity() матчинг сегментов
    └─→ json_ok({activity_id, summary})
```

### 3. Лайк

```
Любая страница с активностью
    │
    ├─→ JS: клик по .js-like-btn
    ├─→ POST api/like.php {activity_id}
    │
api/like.php
    │
    ├─→ api_require_user()
    ├─→ Like::toggle()      INSERT/DELETE activity_likes
    └─→ json_ok({liked, count})
```

### 4. Просмотр активности

```
activity.php?id=42
    │
    ├─→ Activity::findById(42)             SELECT с JOIN users
    ├─→ проверка visibility (public/followers/private)
    ├─→ Comment::forActivity(42)
    ├─→ Segment::effortsForActivity(42)
    ├─→ Activity::photos(42)
    ├─→ json_decode(track_json)            построение графиков
    ├─→ рендер: Leaflet + Chart.js
    └─→ inline JS для лайков и комментариев
```

### 5. Лента

```
feed.php?tab=all&type=ride
    │
    ├─→ feed_fetch_unified()               UNION активностей, постов, событий
    ├─→ feed_fetch_unified_clubs()         для tab=clubs
    ├─→ загрузка фото, комментариев, усилий
    ├─→ feed_simplify_track()              прореживание треков
    └─→ рендер карточек

При скролле:
    ├─→ JS: fetch api/feed.php?page=N
    └─→ добавление карточек через insertAdjacentHTML
```

### 6. Синхронизация Wahoo

```
wahoo-connect.php
    │
    ├─→ генерация PKCE verifier/challenge
    ├─→ редирект на api.wahooligan.com/oauth/authorize

Пользователь даёт разрешение:
wahoo-callback.php?code=…
    │
    ├─→ обмен code на access_token
    ├─→ INSERT user_integrations (provider=wahoo)
    └─→ redirect на profile-edit.php

При новой тренировке:
    │
    ├─→ Wahoo → webhook на /wahoo-callback.php
    ├─→ INSERT webhook_events
    ├─→ скачивание FIT-файла
    ├─→ FitParser::parse()
    ├─→ Activity::create()
    └─→ INSERT integration_synced_items
```

### 7. Матчинг сегментов

```
После Activity::create():
    │
    └─→ SegmentMatcher::matchAllForActivity(activityId)
            │
            ├─→ SELECT все сегменты с тем же типом
            ├─→ для каждого сегмента:
            │       ├─→ поиск старта в треке активности
            │       ├─→ поиск финиша
            │       └─→ расчёт elapsed_time_sec
            ├─→ Segment::addEffort()       INSERT segment_efforts
            └─→ Segment::notifyLeadershipChange()
                    │
                    └─→ Notification::push()   уведомление лидеру
```

### 8. Генерация превью трека

```
api/upload-activity.php после Activity::create()
    │
    └─→ TrackPreview::generate(activityId, trackJson, type)
            │
            ├─→ json_decode(track_json)
            ├─→ прореживание до 90 точек
            ├─→ формирование URL MapBox Static Images
            ├─→ curl GET → PNG
            ├─→ imagecreatefromstring()
            ├─→ imageistruecolor() → конвертация в truecolor
            ├─→ imagewebp() → assets/uploads/track-previews/{id}.webp
            └─→ return URL
```

### 9. Уведомления в реальном времени

```
Каждая открытая страница:
    │
    └─→ JS: setInterval(poll, 20000)
            │
            └─→ GET api/notifications.php?since_id=N
                    │
                    ├─→ Notification::forUser()    новые уведомления
                    └─→ json_ok({unread, items})

При новом уведомлении:
    ├─→ всплывающий toast
    └─→ обновление бейджа в шапке
```

### 10. Админка

```
admin/login.php
    │
    ├─→ проверка email + password_hash
    ├─→ проверка ip_allowlist (если задан)
    ├─→ проверка totp_secret (если включён)
    └─→ сессия + redirect на dashboard.php

admin/dashboard.php
    │
    ├─→ агрегированные метрики из всех таблиц
    ├─→ графики Chart.js
    └─→ последние записи (users, activities, audit)

admin/database.php
    │
    ├─→ список таблиц через SHOW TABLES
    ├─→ метаданные через information_schema
    ├─→ для тяжёлых колонок: LEFT(col, 500) + LENGTH(col)
    ├─→ inline-редактирование через api-db-save.php
    └─→ SQL-консоль с dry-run и защитой от опасных запросов
```

---

## Ключевые модули

### `services/GpxParser.php`

Парсит GPX-файлы. Возвращает массив метрик:

- `points` — массив точек (lat, lng, ele, t, hr, cad, pwr, temp, speed, distance).
- `distance_m`, `duration_sec`, `moving_time_sec`, `stop_time_sec`.
- `avg_speed_mps`, `max_speed_mps`, `elevation_gain_m`.
- `avg_hr`, `max_hr`, `avg_cadence`, `max_cadence`, `avg_power_w`.
- `avg_temp_c`, `has_sensors`, `started_at`.

Метод `summarize()` используется также в `TcxParser` и `FitParser`.

### `services/FitParser.php`

Парсит бинарный FIT-формат. Читает:

- `RECORD` (global 20) — точки с координатами и датчиками.
- `SESSION` (global 18) — тип спорта (sport, sub_sport).
- `enhanced_altitude` (field 78) и `enhanced_speed` (field 73).

Поддерживает little-endian и big-endian.

### `services/SegmentMatcher.php`

Определяет, проходит ли активность через сегмент. Сравнивает трек с сегментом, находит старт и финиш, считает `elapsed_time_sec` и `match_quality`.

### `services/ActivityTypeDetector.php`

Определяет тип активности по:

1. `source_type` из файла (TCX `Sport`, FIT `sport`/`sub_sport`, GPX `<type>`).
2. Эвристика по метрикам: скорость, каденс, мощность, температура, набор высоты, наличие GPS.

Маппинг покрывает ~90 видов спорта.

### `services/TrackPreview.php`

Генерирует WebP-превью трека через MapBox Static Images API. Fallback — стилизованная сетка, если MapBox недоступен.

### `models/Activity.php`

Основная модель активностей. Методы:

- `create()`, `findById()`, `feed()`, `byUser()`, `delete()`.
- `photos()`, `addPhoto()`, `deletePhoto()`, `photoCount()`.
- `fingerprint()`, `trackHash()`, `findDuplicate()`.
- `recalcFromTrack()`, `update()`.
- `likers()`.
- Хуки в игровой модуль: `gameHookCapture()`, `gameHookRevert()`.

### `models/Segment.php`

Работа с сегментами и усилиями:

- `create()`, `findById()`, `delete()`.
- `findOverlapping()` — поиск существующего сегмента по треку.
- `leaderboard()`, `userRank()`, `userBestEffort()`.
- `elevationProfile()`.
- `notifyLeadershipChange()`.

### `models/Notification.php`

Создание и чтение уведомлений:

- `push()` — создание уведомления.
- `forUser()`, `unreadCount()`, `markRead()`, `markAllRead()`.

### `includes/auth.php`

Обвязка авторизации:

- `auth_start()`, `current_user()`, `require_login()`, `redirect()`.
- `flash()`, `csrf_token()`, `csrf_field()`, `csrf_check()`.

### `includes/helpers.php`

Хелперы:

- `e()` — экранирование HTML.
- `url()`, `app_url()`, `config()`.
- `format_distance()`, `format_duration()`, `format_pace()`, `time_ago()`.
- `upload_photo()`, `upload_photo_resize()`, `delete_uploaded_photo()`.
- `pkce_generate_verifier()`, `pkce_generate_challenge()`.
- `http_request()` — обёртка над cURL.

### `api/_bootstrap.php`

Общий загрузчик API:

- `json_input()`, `json_ok()`, `json_err()`.
- `api_user()`, `api_require_user()`.
- `api_check_csrf()`.

---

## API

Все эндпоинты возвращают JSON. Аутентификация — по сессии или по Bearer-токену. CSRF — через заголовок `X-CSRF-Token`.

| Эндпоинт | Метод | Назначение |
|----------|-------|-----------|
| `api/auth.php` | POST | Логин по email/паролю, возврат Bearer-токена |
| `api/me.php` | GET/PATCH | Текущий пользователь, обновление профиля |
| `api/feed.php` | GET | Лента для мобильного клиента |
| `api/activities.php` | GET/POST | Список активностей, создание |
| `api/activity.php` | GET | Одна активность с треком и комментариями |
| `api/upload-activity.php` | POST | Загрузка GPX/TCX/FIT |
| `api/upload-activity-photo.php` | POST | Загрузка фото к активности |
| `api/add-activity-photo.php` | POST | Добавление фото |
| `api/activity-photo-delete.php` | POST | Удаление фото |
| `api/activity-trim.php` | POST | Обрезка трека |
| `api/like.php` | POST | Поставить/снять лайк активности |
| `api/comment.php` | POST | Добавить комментарий к активности |
| `api/post-like.php` | POST | Лайк поста |
| `api/post-comment.php` | POST | Комментарий к посту |
| `api/post-photo-delete.php` | POST | Удаление фото поста |
| `api/notifications.php` | GET/POST | Поллинг и отметка прочитанных |

**Формат ответа:**

```json
{
  "ok": true,
  "data": { ... }
}
```

или

```json
{
  "ok": false,
  "error": "Текст ошибки"
}
```

---

## Админка

Доступна по адресу `/admin/`. Отдельная аутентификация через таблицу `admins`. Роли:

- `super` — полный доступ, включая редактор БД и SQL-консоль.
- `moderator` — управление пользователями, активностями, постами, клубами, рассылками.
- `readonly` — только просмотр дашборда и статистики.

### Ключевые страницы

| Страница | Назначение |
|----------|-----------|
| `dashboard.php` | Сводные метрики, графики, последние записи |
| `stats.php` | Детальная статистика с фильтрами по периоду |
| `users.php` | Управление пользователями (бан, редактирование) |
| `activities.php` | Управление активностями (скрыть, опубликовать, удалить) |
| `posts.php` | Управление постами |
| `segments.php` | Управление сегментами |
| `clubs.php` | Управление клубами |
| `gear.php` | Управление инвентарём |
| `broadcasts.php` | Рассылки |
| `database.php` | Редактор БД с inline-редактированием |
| `sql.php` | SQL-консоль |
| `admins.php` | Управление администраторами |
| `audit.php` | Аудит действий |

---

## Соглашения

### Именование

- **Файлы моделей** — с большой буквы, в единственном числе: `User.php`, `Activity.php`.
- **Таблицы** — во множественном числе, snake_case: `users`, `activity_likes`, `segment_efforts`.
- **Поля** — snake_case: `created_at`, `user_id`.
- **Методы моделей** — camelCase: `findById()`, `byUser()`, `allForUser()`.
- **Функции-хелперы** — snake_case: `format_distance()`, `time_ago()`.

### Структура страницы

Каждая страница следует паттерну:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/...';

auth_start();
$me = current_user();

// ... логика: загрузка данных, обработка POST, редиректы ...

$pageTitle = '...';
$extraCss = [...];
$extraJs  = [...];
$inlineJs = '...';

require __DIR__ . '/includes/header.php';
?>

<!-- HTML -->

<?php require __DIR__ . '/includes/footer.php'; ?>
```

### Модели

Все методы — статические. Внутри используют `db()`:

```php
public static function findById(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}
```

### API

Каждый эндпоинт начинается с:

```php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/...';

api_check_csrf();       // если POST
$me = api_require_user();

// ... логика ...
json_ok([...]);         // или json_err('...', 400)
```

---

## Безопасность

- **Пароли** — `password_hash()` + `password_verify()`.
- **SQL** — только prepared statements через PDO, `bindValue`/`execute`.
- **XSS** — весь вывод через `e()` = `htmlspecialchars($s, ENT_QUOTES, 'UTF-8')`.
- **CSRF** — токен в сессии, проверяется через `csrf_check()` и `api_check_csrf()`. В JS передаётся в `X-CSRF-Token`.
- **Приватность активностей** — три уровня: `public`, `followers`, `private`. Проверяется в `activity.php`, `api/activity.php`, экспорте GPX/TCX.
- **Приватность профиля** — `users.is_public = 0` скрывает активности от не-подписчиков.
- **Загрузка фото** — проверка MIME, размера (до 10 МБ), расширения. HEIC конвертируется на клиенте через `heic2any`.
- **Bearer-токены** — хранятся как хэш (`password_hash`), сравниваются через `password_verify()`. TTL — 180 дней.
- **`.htaccess`** — блокирует прямой доступ к `config/config.php` и `config/database.php`.
- **Админка** — отдельная таблица `admins`, роли, TOTP, `ip_allowlist`, счётчик неудачных попыток, блокировка.

---

## Что дальше

Идеи для развития:

- **Карта на странице активности** — добавить профиль высот, график скорости (частично реализовано).
- **Сегменты** — улучшить автоматический матчер (сейчас по совпадению трека).
- **Интеграции** — Garmin, Wahoo, Polar, Suunto, Strava.
- **Мобильное приложение** — API уже готов, нужен клиент на Flutter/React Native.
- **Экспорт статистики** — CSV, PDF-отчёт за период.
- **Друзья** — не только подписки, но и взаимные связи.
- **Челленджи** — соревнования по дистанции за месяц.
- **Клубы** — уже реализованы, можно расширить до полноценных сообществ.
- **Сообщения** — личные сообщения между пользователями.
- **Push-уведомления** — Web Push + FCM для мобильных.
- **Очереди** — вынести парсинг треков и генерацию превью в фоновые задачи.

---

## Лицензия

Проект распространяется под лицензией MIT. См. `vendor/phpmailer/phpmailer/LICENSE` для условий использования PHPMailer.

---

**Вопросы и багрепорты** — открывайте issue в репозитории.
```

---

## Что включено в README

1. **Возможности** — что умеет сервис с точки зрения пользователя и админа.
2. **Технологии** — таблица со стеком.
3. **Структура проекта** — дерево файлов и папок.
4. **Установка и настройка** — пошагово, с примером `config.php`.
5. **Схема БД** — все ~40 таблиц, разбитые на 11 групп, с описанием ключевых полей.
6. **Связи таблиц** — ASCII-диаграмма + таблица связей с ON DELETE.
7. **Потоки данных** — 10 ключевых сценариев (регистрация, загрузка, лайк, лента, Wahoo, матчинг, превью, уведомления, админка).
8. **Ключевые модули** — что делает каждый файл в `models/`, `services/`, `includes/`.
9. **API** — таблица эндпоинтов и формат ответа.
10. **Админка** — роли и ключевые страницы.
11. **Соглашения** — именование, структура, паттерны.
12. **Безопасность** — что реализовано и как.
13. **Что дальше** — идеи для развития.

Такой README даёт полное представление о проекте — как для нового разработчика, так и для ИИ-ассистента, который будет помогать с миграцией. Если нужно что-то дополнить (например, диаграммы в Mermaid вместо ASCII), скажите — переделаю.