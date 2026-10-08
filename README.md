# Pulse

**Pulse** — социальная платформа для спортсменов. Позволяет загружать тренировки (GPX/TCX/FIT), строить карты, создавать маршруты и сегменты, соревноваться в лидербордах, вести инвентарь (велосипеды, кроссовки, лыжи), подписываться на других атлетов, ставить лайки и комментировать.

MVP на чистом **PHP 8.1+** и **MySQL 8.0+**, без фреймворков.

---

## Содержание

1. [Требования](#требования)
2. [Установка](#установка)
3. [Настройка](#настройка)
4. [Структура проекта](#структура-проекта)
   - [Корень проекта](#корень-проекта)
   - [API](#api)
   - [Config](#config)
   - [Includes](#includes)
   - [Models](#models)
   - [Services](#services)
   - [SQL](#sql)
   - [Assets](#assets)
   - [Uploads](#uploads)
   - [Storage](#storage)
   - [Vendor](#vendor)
5. [Схема базы данных](#схема-базы-данных)
6. [Ключевые сценарии](#ключевые-сценарии)
7. [Соглашения](#соглашения)
8. [Безопасность](#безопасность)
9. [Что дальше](#что-дальше)

---

## Требования

- **PHP** 8.1 или выше
- **MySQL** 8.0 или выше (или MariaDB 10.4+)
- Расширения PHP:
  - `pdo_mysql` — работа с базой данных
  - `mbstring` — многобайтовые строки
  - `simplexml` — парсинг GPX/TCX
  - `zip` — работа с архивами (для FIT/фитнес-архивов)
  - `openssl` — HTTPS и подпись писем
  - `json` — по умолчанию включён
- **Composer** — для установки PHPMailer (или можно использовать готовый `composer.phar` из репозитория)
- Веб-сервер: Apache (с `mod_rewrite`) или Nginx

---

## Установка

1. Скопируйте проект в веб-корень:
   ```bash
   git clone <repo> /var/www/pulse
   cd /var/www/pulse
   ```

2. Установите зависимости Composer:
   ```bash
   composer install
   # или, если composer не установлен глобально:
   php composer.phar install
   ```

3. Создайте базу данных и импортируйте схему:
   ```bash
   mysql -u root -p -e "CREATE DATABASE pulse CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u root -p pulse < sql/schema.sql
   ```

4. Настройте `config/config.php` (см. следующий раздел).

5. Убедитесь, что папки `uploads/`, `storage/` доступны на запись веб-серверу:
   ```bash
   chmod -R 775 uploads storage
   chown -R www-data:www-data uploads storage
   ```

6. Откройте проект в браузере: `https://your-domain/pulse/`.

---

## Настройка

### `config/config.php`

Основной файл конфигурации. Здесь задаются:

- `base_url` — базовый URL проекта (используется функцией `url()`)
- `session_name` — имя сессии
- `db` — параметры подключения к MySQL (хост, порт, база, пользователь, пароль)
- `mail` — параметры SMTP для PHPMailer
- `uploads_dir` — путь к папке загрузок
- `debug` — включает/выключает показ ошибок

Пример структуры:
```php
return [
    'base_url'     => 'https://roadrunnersteam.ru/pulse',
    'session_name' => 'pulse_session',
    'debug'        => false,
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'pulse',
        'username' => 'pulse',
        'password' => '...',
        'charset'  => 'utf8mb4',
    ],
    'mail' => [
        'host'      => 'smtp.example.com',
        'port'      => 587,
        'username'  => 'noreply@example.com',
        'password'  => '...',
        'from'      => 'noreply@example.com',
        'from_name' => 'Pulse',
    ],
];
```

### `config/database.php`

Отвечает за создание PDO-подключения. Функция `db()` возвращает singleton-соединение.

---

## Структура проекта

### Корень проекта

Файлы в корне — это **страницы**, доступные пользователю напрямую через браузер.

#### Авторизация и аккаунт

| Файл | Назначение |
|---|---|
| `index.php` | Лендинг. Показывает возможности платформы, кнопки «Войти» / «Регистрация». |
| `register.php` | Форма регистрации. Валидация email/username/пароля, создание пользователя, отправка письма с подтверждением email. |
| `login.php` | Форма входа. Проверка email + пароля через `password_verify()`. Создаёт сессию. |
| `logout.php` | Уничтожает сессию и редиректит на главную. |
| `forgot-password.php` | Форма «забыли пароль». Генерирует токен `password_reset`, отправляет письмо со ссылкой. |
| `reset-password.php` | Форма установки нового пароля по токену из письма. Проверяет `token_hash`, `expires_at`, `used_at`. |
| `verify-email.php` | Обрабатывает ссылку из письма подтверждения. Проверяет токен `email_verify`, ставит `users.email_verified = 1`. |
| `test-mail.php` | Отладочный скрипт для проверки работы SMTP. **Удалите или закройте доступ в продакшене.** |

#### Лента и посты

| Файл | Назначение |
|---|---|
| `feed.php` | Лента. Показывает активности и посты пользователей, на которых вы подписаны, плюс свои. Поддерживает табы `all` / `following` / `mine`, фильтры по типу активности, бесконечную подгрузку. |
| `post.php` | Просмотр одного поста (блог-запись) с фото, лайками и комментариями. |
| `post-create.php` | Форма создания нового поста. Загрузка до 10 фото, выбор видимости. |
| `my-posts.php` | Список своих постов с фильтрами, редактированием и удалением. |
| `debug-feed.php` | Утилита для отладки выборки ленты. **Не для продакшена.** |

#### Активности

| Файл | Назначение |
|---|---|
| `activity.php` | Просмотр одной активности: карта Leaflet, статистика, фото с лайтбоксом, комментарии, лайк, кнопка «Кто лайкнул» (модалка), экспорт в GPX/TCX. |
| `activity-upload.php` | Массовая загрузка GPX/TCX/FIT. Парсинг, сохранение трека, статистика. Возможность сразу привязать к инвентарю, загрузить фото. |
| `activity-edit.php` | Редактирование активности: заголовок, описание, тип, видимость, привязка к инвентарю, добавление/удаление фото. Опасная зона для удаления. |
| `gear.php` | Инвентарь пользователя: велосипеды, кроссовки, лыжи. Учёт пробега, износа, добавление/редактирование, привязка к активностям. |

#### Профиль и социальные связи

| Файл | Назначение |
|---|---|
| `profile.php` | Публичный профиль: аватар, био, статистика, подписчики/подписки, сегменты-лидеры, последние активности с картами и каруселью фото. |
| `profile-edit.php` | Настройки профиля: аватар, имя, био, город/страна, единицы измерения, приватность. |
| `profile-followers.php` | Список подписчиков пользователя. |
| `profile-following.php` | Список тех, на кого подписан пользователь. |
| `notifications.php` | Лента уведомлений: лайки, комментарии, новые подписчики, системные сообщения. |

#### Маршруты и сегменты

| Файл | Назначение |
|---|---|
| `routes.php` | Каталог публичных маршрутов сообщества. Фильтры, карточки с превью-картами. |
| `route.php` | Просмотр одного маршрута: карта, путевые точки, описание, экспорт. |
| `route-create.php` | Полноэкранный редактор маршрута: рисование трека на карте, добавление путевых точек, сохранение. |
| `segments.php` | Каталог сегментов. Фильтры по типу, лидерборд. |
| `segment.php` | Просмотр сегмента: карта, лидерборд, форма добавления результата вручную, блок «мой результат». |
| `segment-create.php` | Создание сегмента на основе существующей активности: выделение участка трека, сохранение. |

#### Календарь и статистика

| Файл | Назначение |
|---|---|
| `calendar.php` | Годовой календарь активностей в стиле GitHub-heatmap: streak, недельная/месячная/годовая статистика, график по 12 неделям, распределение по типам. |

#### Служебное

| Файл | Назначение |
|---|---|
| `resize-existing.php` | Утилита для пересжатия уже загруженных фото. Одноразовый скрипт для миграции. |
| `.htaccess` | Apache-конфиг: защита `config/`, красивые URL для API (`/api/likes` → `/api/likes.php`). |
| `README.md` | Этот файл. |
| `composer.json` | Зависимости проекта: PHPMailer. |
| `composer.lock` | Фиксация версий зависимостей. |
| `composer.phar` | Локальный Composer для установки без глобальной установки. |

---

### `api/`

Все эндпоинты JSON-API. Вызываются через `fetch()` из JS, работают с сессией или Bearer-токеном. Общий бутстрап — `api/_bootstrap.php`.

| Файл | Назначение |
|---|---|
| `_bootstrap.php` | Общий загрузчик: подключает `helpers.php`, `database.php`, стартует сессию, ставит JSON-заголовки, содержит функции `json_ok()`, `json_err()`, `json_input()`, `api_user()`, `api_require_user()`, `api_check_csrf()`. |
| `auth.php` | Логин по email/паролю для мобильного приложения. Возвращает Bearer-токен (хранится в `api_tokens`). |
| `me.php` | GET — данные текущего пользователя + статистика; PATCH/POST — обновление профиля. |
| `feed.php` | Единая лента (активности + посты) для мобильного клиента. Объединяет две таблицы через UNION, отдаёт готовые структуры с комментариями, фото, лайками. |
| `activities.php` | GET — список активностей пользователя. POST — создание активности без файла (программно). |
| `activity.php` | GET — одна активность + её трек + комментарии. |
| `upload-activity.php` | POST — загрузка GPX/TCX с парсингом (для мобильного клиента). |
| `like.php` | POST — поставить/снять лайк активности. Возвращает `{ liked, count }`. |
| `comment.php` | POST — добавить комментарий к активности. Возвращает данные комментария + новый count. |
| `post-like.php` | POST — поставить/снять лайк посту. |
| `post-comment.php` | POST — добавить комментарий к посту. |
| `post-photo-delete.php` | POST — удалить фото поста (только владелец). |
| `activity-photo-delete.php` | POST — удалить фото активности (только владелец). |

---

### `config/`

| Файл | Назначение |
|---|---|
| `config.php` | Основной конфиг проекта. Возвращает массив с настройками. |
| `database.php` | Функция `db()` — singleton PDO-подключение к MySQL. |

Эти файлы защищены `.htaccess` и недоступны напрямую из браузера.

---

### `includes/`

Общие части страниц и вспомогательные модули.

| Файл | Назначение |
|---|---|
| `auth.php` | Обвязка авторизации: `auth_start()`, `current_user()`, `require_login()`, `redirect()`, `flash()`, работа с CSRF. Подключается в начале каждой страницы. |
| `header.php` | Общий HTML-хедер: `<head>`, метатеги, логотип, навигация, поиск, аватарка пользователя с выпадающим меню, мобильный бургер, подключение CSS/JS. Подключается после логики страницы. |
| `footer.php` | Общий футер. Закрывает `<body>`, подключает `main.js`. |
| `helpers.php` | Функции-хелперы: `e()` (htmlspecialchars), `url()`, `app_url()`, `config()`, `time_ago()`, `format_distance()`, `format_duration()`, `format_pace()`, `upload_photo()`, `csrf_token()`, `csrf_field()`, `csrf_check()`. |
| `mailer.php` | Обёртка над PHPMailer: `send_mail()` — единая точка отправки писем (подтверждение email, сброс пароля). |

---

### `models/`

Модели — классы для работы с сущностями. Все методы статические, работают через `db()`.

| Файл | Назначение | Основные методы |
|---|---|---|
| `User.php` | Пользователи | `findById()`, `findByEmail()`, `findByUsername()`, `create()`, `update()`, `stats()`, `search()` |
| `Activity.php` | Активности | `findById()`, `create()`, `byUser()`, `feed()`, `delete()`, `photos()`, `addPhoto()`, `deletePhoto()`, `photoCount()`, `likers()` |
| `Post.php` | Блог-посты | `findById()`, `create()`, `delete()`, `addComment()`, `toggleLike()`, `deletePhoto()` |
| `Like.php` | Лайки активностей | `toggle()`, `count()`, `isLiked()` |
| `Comment.php` | Комментарии активностей | `forActivity()`, `add()`, `delete()` |
| `Follow.php` | Подписки | `follow()`, `unfollow()`, `isFollowing()`, `followers()`, `following()`, `followersCount()`, `followingCount()` |
| `Gear.php` | Инвентарь | `findById()`, `allForUser()`, `create()`, `update()`, `delete()` |
| `Route.php` | Маршруты | `findById()`, `create()`, `byUser()`, `publicList()`, `delete()` |
| `Segment.php` | Сегменты и попытки | `findById()`, `create()`, `effortsForActivity()`, `leaderboard()`, `personalBest()` |
| `Notification.php` | Уведомления | `forUser()`, `create()`, `markAsRead()`, `unreadCount()` |
| `Token.php` | Токены (email/password/api) | `create()`, `verify()`, `markUsed()` |

---

### `services/`

Парсеры фитнес-файлов и матчер сегментов. Не зависят от HTTP-контекста.

| Файл | Назначение |
|---|---|
| `GpxParser.php` | Парсит GPX. Возвращает `points` (lat/lng/ele/t), `started_at`, `duration_sec`, `distance_m`, `elevation_gain_m`, `avg_speed_mps`, `max_speed_mps`. |
| `TcxParser.php` | Парсит TCX (Garmin Training Center XML). Аналогичный интерфейс. |
| `FitParser.php` | Парсит бинарный FIT-формат (Garmin). Работает через `bin2hex`/`unpack`. |
| `SegmentMatcher.php` | Определяет, проходит ли трек активности через заданный сегмент. Считает `elapsed_time_sec`, `matched_distance_m`, `match_quality`. |

---

### `sql/`

| Файл | Назначение |
|---|---|
| `schema.sql` | Полная схема БД: все `CREATE TABLE` со всеми полями, индексами, внешними ключами. Импортируется один раз при установке. |

---

### `assets/`

| Файл | Назначение |
|---|---|
| `css/style.css` | Все стили проекта. Дизайн-токены (цвета, тени, радиусы), сетки, компоненты (кнопки, карточки, формы, модалки, календарь, сегменты, профиль, лента, gear, routes, feed-gallery, lightbox, likers-modal). |
| `js/main.js` | Общий JS: плавный скролл по якорям, анимация появления карточек, мобильное меню, выпадающее меню пользователя. |

---

### `uploads/`

Папка загруженных пользователем файлов. Имя файла генерируется хэшем, чтобы избежать коллизий.

| Папка | Что хранит |
|---|---|
| `uploads/activities/` | Фото активностей |
| `uploads/avatars/` | Аватарки пользователей |
| `uploads/gear/` | Фото инвентаря |
| `uploads/posts/` | Фото постов |

Папка должна быть доступна на запись. Прямая отдача файлов — через веб-сервер (или через PHP-скрипт, если требуется разграничение доступа).

---

### `storage/`

Логи и служебные данные.

| Файл | Назначение |
|---|---|
| `storage/mail.log` | Лог отправленных писем (когда SMTP не сработал, письмо пишется сюда). |
| `storage/uploads.log` | Лог загрузок файлов (для отладки). |

---

### `vendor/`

Зависимости Composer. **Не редактируется вручную.**

| Папка | Назначение |
|---|---|
| `vendor/autoload.php` | Точка входа автозагрузчика. |
| `vendor/composer/` | Сгенерированные классы автозагрузки. |
| `vendor/phpmailer/phpmailer/` | Библиотека PHPMailer. |
| `vendor/…` | Другие зависимости (если добавлялись). |

---

## Схема базы данных

Всего 13 таблиц. Полное описание — в `sql/schema.sql`.

### Пользователи и социальные связи

**`users`** — основная таблица пользователей.

| Поле | Тип | Описание |
|---|---|---|
| `id` | int unsigned PK | Идентификатор |
| `email` | varchar(190) UNIQUE | Email |
| `username` | varchar(60) UNIQUE | Логин |
| `password_hash` | varchar(255) | Хэш пароля (`password_hash`) |
| `display_name` | varchar(120) | Отображаемое имя |
| `avatar_url` | varchar(255) | URL аватарки |
| `bio` | text | О себе |
| `city` | varchar(120) | Город |
| `country` | varchar(120) | Страна |
| `gender` | enum | Пол |
| `birth_date` | date | Дата рождения |
| `weight_kg` | decimal(5,2) | Вес |
| `height_cm` | decimal(5,2) | Рост |
| `units` | enum | `metric` или `imperial` |
| `is_public` | tinyint(1) | Публичный профиль или нет |
| `email_verified` | tinyint(1) | Email подтверждён |
| `created_at`, `updated_at` | timestamp | Даты |

**`follows`** — подписки. Составной PK `(follower_id, following_id)`.

**`tokens`** — одноразовые токены для email_verify и password_reset. Хранит `token_hash`, `expires_at`, `used_at`.

**`api_tokens`** — долгоживущие Bearer-токены для мобильного приложения. Хранит `token_hash`, `expires_at`, `last_used_at`.

### Активности

**`activities`** — тренировки.

| Поле | Тип | Описание |
|---|---|---|
| `id` | int unsigned PK | ID |
| `user_id` | int unsigned FK | Автор |
| `type` | enum | `run`, `ride`, `swim`, `ski`, `walk`, `hike`, `other` |
| `title` | varchar(190) | Название |
| `description` | text | Описание |
| `started_at` | datetime | Время старта |
| `duration_sec` | int unsigned | Длительность |
| `distance_m` | decimal(10,2) | Дистанция |
| `elevation_gain_m` | decimal(8,2) | Набор высоты |
| `avg_speed_mps`, `max_speed_mps` | decimal(6,3) | Скорости |
| `calories` | int unsigned | Калории |
| `gear_id` | int unsigned FK | Привязка к инвентарю |
| `track_json` | longtext | Точки трека в JSON |
| `visibility` | enum | `public`, `followers`, `private` |
| `created_at` | timestamp | Дата создания |

**`activity_likes`** — лайки активностей. PK `(user_id, activity_id)`.

**`activity_comments`** — комментарии активностей.

**`activity_photos`** — фото активностей с `order_index`.

### Посты (блог)

**`posts`** — записи.

| Поле | Тип |
|---|---|
| `id`, `user_id` | PK, FK |
| `title` | varchar(190) |
| `body` | longtext |
| `visibility` | enum |
| `created_at`, `updated_at` | timestamp |

**`post_likes`**, **`post_comments`**, **`post_photos`** — аналогично активностям.

### Маршруты, сегменты, инвентарь

**`routes`** — маршруты: `name`, `description`, `type`, `distance_m`, `elevation_gain_m`, `track_json`, `waypoints_json`, `is_public`.

**`segments`** — сегменты: `creator_id`, `name`, `type`, `distance_m`, `elevation_gain_m`, `track_json`, `is_public`.

**`segment_efforts`** — попытки прохождения: `segment_id`, `activity_id`, `user_id`, `elapsed_time_sec`, `is_auto`, `matched_distance_m`, `match_quality`, `started_at`.

**`gear`** — инвентарь: `user_id`, `type` (`bike`/`shoes`/`skis`/`other`), `name`, `brand`, `model`, `purchase_date`, `notes`, `photo_url`, `is_retired`.

### Уведомления

**`notifications`** — тип (`like`, `comment`, `follow`, `mention`, `system`, …), `actor_id`, `target_type` + `target_id`, `message`, `is_read`.

---

## Ключевые сценарии

### 1. Регистрация

1. `register.php` → валидация.
2. `User::create()` — INSERT в `users`.
3. `Token::create()` — генерирует токен `email_verify`, пишет в `tokens`.
4. `send_mail()` — PHPMailer отправляет письмо со ссылкой `/verify-email.php?token=...`.
5. Пользователь кликает ссылку → `verify-email.php` проверяет токен, ставит `users.email_verified = 1`.

### 2. Загрузка активности

1. `activity-upload.php` → `$_FILES['files']`.
2. Для каждого файла:
   - `GpxParser::parse()` / `TcxParser::parse()` / `FitParser::parse()` — парсинг.
   - `SegmentMatcher::match()` — поиск пройденных сегментов.
   - `Activity::create()` — INSERT в `activities`.
   - `Activity::addPhoto()` — фото.
3. Редирект на `feed.php` или на `activity.php?id=…`.

### 3. Лайк

1. JS на `activity.php` / `feed.php` / `profile.php` шлёт POST на `api/like.php` с `activity_id`.
2. `Like::toggle()` — INSERT или DELETE в `activity_likes`.
3. Ответ JSON: `{ liked: true, count: 5 }`.
4. JS обновляет счётчик и класс кнопки без перезагрузки.

### 4. Просмотр лайкнувших

1. Клик по кнопке «Кто лайкнул» → JS шлёт GET на `api/activity-likers.php?activity_id=…`.
2. `Activity::likers()` — SELECT с JOIN `users`.
3. JSON с `profile_url` для каждой записи.
4. JS рендерит модалку.

### 5. Создание сегмента

1. `segment-create.php?activity_id=…`.
2. Загружается трек активности, отрисовывается на карте.
3. Пользователь выделяет диапазон точек → сохраняет.
4. `Segment::create()` — INSERT в `segments`.
5. `SegmentMatcher` перепроверяет все активности на совпадение.

### 6. Подписка

1. POST на `profile.php` с `action=follow` или `action=unfollow`.
2. `Follow::follow()` / `Follow::unfollow()` — INSERT/DELETE в `follows`.
3. `Notification::create()` — уведомление пользователю.
4. Редирект обратно.

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
$extraCss = [...];  // дополнительные CSS
$extraJs  = [...];  // дополнительные JS
$inlineJs = '...';  // inline JS

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

---

## Что дальше

Идеи для развития:

- **Карта на странице активности** — уже есть Leaflet. Можно добавить профиль высот, график скорости.
- **Сегменты** — автоматический матчер улучшить (сейчас по совпадению трека).
- **Интеграции** — Garmin, Wahoo, Polar, Suunto, Strava.
- **Мобильное приложение** — API уже готово, нужен клиент на Flutter/React Native.
- **Экспорт статистики** — CSV, PDF-отчёт за период.
- **Друзья** — не только подписки, но и взаимные связи.
- **Челленджи** — соревнования по дистанции за месяц.
- **Клубы** — группировка атлетов по интересам/городу.
- **Сообщения** — личные сообщения между пользователями.
- **Push-уведомления** — Web Push + FCM для мобильных.

---

## Лицензия

Проект распространяется под лицензией MIT. См. `vendor/phpmailer/phpmailer/LICENSE` для условий использования PHPMailer.

---

**Вопросы и багрепорты** — открывайте issue в репозитории.