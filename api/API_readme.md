# API.md — документация по API Pulse

Разберу все эндпоинты с примерами запросов/ответов, кодами ошибок и особенностями. Документ длинный — это нормально для API большого проекта.

```markdown
# API Pulse

JSON-API для мобильного приложения и сторонних клиентов.

**Base URL:** `https://roadrunnersteam.ru/pulse/api/`

**Формат:** JSON (`Content-Type: application/json`).

**Аутентификация:** Bearer-токен или сессия (cookie).

---

## Содержание

1. [Общие принципы](#общие-принципы)
2. [Аутентификация](#аутентификация)
3. [Формат ответов](#формат-ответов)
4. [Коды ошибок](#коды-ошибок)
5. [Rate limiting](#rate-limiting)
6. [Эндпоинты](#эндпоинты)
   - [Auth](#auth)
   - [Me](#me)
   - [Feed](#feed)
   - [Activities](#activities)
   - [Posts](#posts)
   - [Comments](#comments)
   - [Likes](#likes)
   - [Clubs](#clubs)
   - [Segments](#segments)
   - [Calendar](#calendar)
7. [Примеры сценариев](#примеры-сценариев)
8. [Версионирование](#версионирование)

---

## Общие принципы

### Формат запросов

**GET** — query-параметры:
```
GET /api/feed.php?tab=following&page=1
```

**POST** — JSON в теле:
```
POST /api/like.php
Content-Type: application/json
X-CSRF-Token: <token>

{"activity_id": 42}
```

**Загрузка файлов** — `multipart/form-data`:
```
POST /api/upload-activity.php
Content-Type: multipart/form-data

file: <binary>
```

### Base URL

Все эндпоинты относительно `https://roadrunnersteam.ru/pulse/api/`.

В примерах ниже я указываю относительный путь, например `POST /auth.php` — это значит `https://roadrunnersteam.ru/pulse/api/auth.php`.

### Content-Type

- **Запрос:** `application/json` для JSON, `multipart/form-data` для файлов.
- **Ответ:** всегда `application/json; charset=utf-8`.

### Кодировка

UTF-8. Все тексты в ответах — на русском.

---

## Аутентификация

API поддерживает **два способа**:

### 1. Bearer-токен (для мобильных клиентов)

Получить токен можно через `POST /auth.php`:

```json
{
  "email": "user@example.com",
  "password": "secret",
  "device_name": "iPhone 15 Pro"
}
```

Ответ:

```json
{
  "ok": true,
  "data": {
    "token": "eyJ0eXAiOiJKV1Qi...",
    "expires_at": "2027-04-08 12:00:00",
    "user": {
      "id": 42,
      "username": "athlete",
      "display_name": "Иван Иванов",
      "avatar_url": "https://.../avatar.jpg"
    }
  }
}
```

Использование:

```
GET /me.php
Authorization: Bearer eyJ0eXAiOiJKV1Qi...
```

**Особенности:**
- Токен хранится в БД как хэш (`password_hash()`).
- TTL — **180 дней**.
- Отзыв: `DELETE FROM api_tokens WHERE ...` (пока нет эндпоинта для отзыва, добавьте в мобильном клиенте).

### 2. Сессионная cookie (для веб-клиента)

API работает и в браузере — использует ту же сессию, что и страницы. Автоматически, если пользователь залогинен.

### CSRF

Для POST-запросов из браузера — обязателен заголовок `X-CSRF-Token`:

```
POST /like.php
X-CSRF-Token: <token>
```

Токен можно получить на любой странице в мета-теге:

```html
<meta name="csrf-token" content="...">
```

**Для мобильного клиента с Bearer-токеном CSRF не нужен** — только сессионная аутентификация требует CSRF.

### Если не аутентифицирован

```
HTTP/1.1 401 Unauthorized

{
  "ok": false,
  "error": "Authentication required"
}
```

---

## Формат ответов

### Успех

```json
{
  "ok": true,
  "data": { ... }
}
```

### Ошибка

```json
{
  "ok": false,
  "error": "Описание ошибки на русском"
}
```

### Пагинация

Для эндпоинтов, возвращающих списки:

```json
{
  "ok": true,
  "data": {
    "items": [ ... ],
    "page": 1,
    "per_page": 20,
    "total": 145,
    "has_more": true
  }
}
```

- **`page`** — текущая страница (с 1).
- **`per_page`** — количество на странице.
- **`total`** — всего записей (если дешево посчитать; иначе `null`).
- **`has_more`** — есть ли следующая страница (быстрая проверка).

Запрос пагинации:

```
GET /feed.php?page=2&per_page=20
```

**Лимит:** `per_page` максимум 100. Дефолт — 20.

### Даты

Все даты в ответах — **строки в формате `YYYY-MM-DD HH:MM:SS`** (локальное время сервера).

```json
{
  "created_at": "2026-10-08 14:30:00"
}
```

### Расстояния и дистанции

- **`distance_m`** — метры (число с плавающей точкой).
- **`distance_km`** — километры (тоже есть в некоторых ответах, если удобно).

Пример:

```json
{
  "distance_m": 5234.56,
  "distance_km": 5.23
}
```

### Длительности

- **`duration_sec`** — секунды (int).
- Форматированная строка (`"1:23:45"`) — в некоторых эндпоинтах, если нужна для UI.

---

## Коды ошибок

| HTTP | `error` (строка) | Когда |
|---|---|---|
| 200 | — | Успех |
| 400 | `Invalid input` | Некорректные данные |
| 401 | `Authentication required` | Нет токена или сессии |
| 403 | `Forbidden` | Нет прав (например, не владелец) |
| 404 | `Not found` | Ресурс не найден |
| 409 | `Conflict` | Дубликат (например, уже подписан) |
| 419 | `CSRF token mismatch` | Неверный CSRF |
| 422 | `Validation failed` | Ошибки валидации (см. ниже) |
| 429 | `Too many requests` | Rate limit |
| 500 | `Internal server error` | Ошибка сервера |

### Ошибки валидации (422)

```json
{
  "ok": false,
  "error": "Validation failed",
  "errors": {
    "email": "Некорректный email",
    "password": "Минимум 8 символов"
  }
}
```

---

## Rate limiting

**Пока не реализован.** Планируется:

- `POST /auth.php` — 10 попыток / 15 мин на IP.
- `POST /*` — 100 запросов / 15 мин на пользователя.

При превышении:

```
HTTP/1.1 429 Too Many Requests
Retry-After: 300

{
  "ok": false,
  "error": "Too many requests. Try again in 5 minutes."
}
```

---

## Эндпоинты

### Auth

#### `POST /auth.php`

Аутентификация по email + пароль. Возвращает Bearer-токен.

**Запрос:**

```json
{
  "email": "user@example.com",
  "password": "secret123",
  "device_name": "iPhone 15 Pro"
}
```

**Ответ 200:**

```json
{
  "ok": true,
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOi...",
    "expires_at": "2027-04-08 12:00:00",
    "user": {
      "id": 42,
      "username": "athlete",
      "display_name": "Иван Иванов",
      "avatar_url": "https://roadrunnersteam.ru/pulse/uploads/avatars/u42_abc.jpg",
      "email": "user@example.com",
      "email_verified": true
    }
  }
}
```

**Ошибки:**

- **400** — не указан email или password.
- **401** — неверный email/пароль (не говорим, что именно, чтобы не помогать брутфорсу).

**Особенности:**

- `device_name` — опционально, сохраняется в `api_tokens.name` для отображения в списке устройств.

---

#### `POST /register.php`

Регистрация. Отправляет письмо с подтверждением email.

**Запрос:**

```json
{
  "email": "newuser@example.com",
  "username": "newathlete",
  "password": "secret123",
  "display_name": "Новый Спортсмен"
}
```

**Ответ 201:**

```json
{
  "ok": true,
  "data": {
    "user_id": 43,
    "message": "Проверьте почту для подтверждения"
  }
}
```

**Ошибки:**

- **422** — email занят, username занят, пароль слишком короткий.

**Валидация:**

- `email` — валидный email, max 190 символов, уникальный.
- `username` — латиница + цифры + `_`, 3–60 символов, уникальный.
- `password` — минимум 8 символов.
- `display_name` — 1–120 символов.

---

#### `POST /forgot-password.php`

Запрос сброса пароля.

**Запрос:**

```json
{ "email": "user@example.com" }
```

**Ответ 200:**

```json
{ "ok": true, "data": { "message": "Если email зарегистрирован, письмо отправлено" } }
```

**Особенность:** всегда возвращаем 200, даже если email не найден — чтобы нельзя было перебором узнать, какие email зарегистрированы.

---

#### `POST /reset-password.php`

Установка нового пароля по токену из письма.

**Запрос:**

```json
{
  "token": "abc123...",
  "password": "newsecret123"
}
```

**Ответ 200:**

```json
{ "ok": true, "data": { "message": "Пароль изменён" } }
```

**Ошибки:**

- **400** — токен невалиден или истёк.
- **422** — пароль слишком короткий.

---

### Me

#### `GET /me.php`

Текущий пользователь + статистика.

**Ответ 200:**

```json
{
  "ok": true,
  "data": {
    "user": {
      "id": 42,
      "username": "athlete",
      "display_name": "Иван Иванов",
      "avatar_url": "https://...",
      "bio": "Бегаю по утрам",
      "city": "Москва",
      "country": "Россия",
      "units": "metric",
      "is_public": true,
      "email_verified": true,
      "created_at": "2024-01-15 10:00:00"
    },
    "stats": {
      "activities": 245,
      "distance_m": 1234567.89,
      "followers": 42,
      "following": 15
    }
  }
}
```

---

#### `PATCH /me.php`

Обновление профиля.

**Запрос:**

```json
{
  "display_name": "Иван И.",
  "bio": "Новая биография",
  "city": "Санкт-Петербург",
  "units": "metric",
  "is_public": true
}
```

**Ответ 200:**

```json
{
  "ok": true,
  "data": {
    "user": { ... }  // обновлённый объект
  }
}
```

**Разрешённые поля:**

- `display_name`
- `bio`
- `city`
- `country`
- `gender` — `male`, `female`, `other`
- `birth_date` — `YYYY-MM-DD`
- `weight_kg`
- `height_cm`
- `units` — `metric`, `imperial`
- `is_public` — bool

**Нельзя менять через этот эндпоинт:**

- `email` — отдельный флоу с подтверждением.
- `password_hash` — через `reset-password.php`.
- `avatar_url` — через отдельный эндпоинт.

---

### Feed

#### `GET /feed.php`

Лента пользователя: активности + посты.

**Параметры:**

| Параметр | Тип | Дефолт | Описание |
|---|---|---|---|
| `tab` | string | `all` | `all`, `following`, `clubs`, `mine` |
| `type` | string | `` | Фильтр по типу: `run`, `ride`, `swim`, `ski`, `walk`, `hike`, `other` |
| `page` | int | 1 | Номер страницы |
| `per_page` | int | 20 | Элементов на странице (макс. 100) |

**Ответ 200:**

```json
{
  "ok": true,
  "data": {
    "items": [
      {
        "kind": "activity",
        "id": 123,
        "user": {
          "id": 42,
          "username": "athlete",
          "display_name": "Иван Иванов",
          "avatar_url": "https://..."
        },
        "type": "run",
        "title": "Утренняя пробежка",
        "description": "5 км в парке",
        "started_at": "2026-10-08 07:30:00",
        "duration_sec": 1800,
        "distance_m": 5234.5,
        "elevation_gain_m": 45,
        "avg_speed_mps": 2.9,
        "visibility": "public",
        "track": [{"lat": 55.75, "lng": 37.61}, ...],
        "photos": [
          {"url": "https://.../photo1.jpg", "order_index": 0}
        ],
        "likes_count": 12,
        "comments_count": 3,
        "liked_by_me": false,
        "created_at": "2026-10-08 07:35:00"
      },
      {
        "kind": "post",
        "id": 45,
        "user": { ... },
        "title": "Новости тренировок",
        "body": "Рассказал про подготовку к марафону...",
        "visibility": "public",
        "photos": [ ... ],
        "likes_count": 5,
        "comments_count": 1,
        "liked_by_me": true,
        "created_at": "2026-10-08 09:00:00"
      }
    ],
    "page": 1,
    "per_page": 20,
    "has_more": true
  }
}
```

**Особенности:**

- **`track`** — массив точек, упрощённый до 200. Для полного трека — `GET /activity.php?id=`.
- **`kind`** — `activity` или `post`. Фронт рендерит по-разному.
- **`liked_by_me`** — учитывает текущего пользователя.

**Приватность:**

- `tab=all` — видны публичные + свои + подписки (followers).
- `tab=following` — только подписки.
- `tab=clubs` — активности участников клубов, где я состою.
- `tab=mine` — только мои.

---

### Activities

#### `GET /activities.php`

Список активностей пользователя.

**Параметры:**

| Параметр | Тип | Описание |
|---|---|---|
| `user_id` | int | ID пользователя (если не свой) |
| `type` | string | Фильтр по типу |
| `from` | date | Начало периода (`YYYY-MM-DD`) |
| `to` | date | Конец периода |
| `page`, `per_page` | int | Пагинация |

**Ответ 200:** аналогичен `feed.php`, только `kind` всегда `activity`.

---

#### `GET /activity.php`

Одна активность с полными данными.

**Параметры:**

- `id` — ID активности (обязательно).

**Ответ 200:**

```json
{
  "ok": true,
  "data": {
    "activity": {
      "id": 123,
      "user": { ... },
      "type": "run",
      "title": "Утренняя пробежка",
      "description": "5 км в парке",
      "started_at": "2026-10-08 07:30:00",
      "duration_sec": 1800,
      "distance_m": 5234.5,
      "elevation_gain_m": 45,
      "avg_speed_mps": 2.9,
      "max_speed_mps": 4.2,
      "avg_hr": 145,
      "max_hr": 172,
      "avg_cadence": 175,
      "calories": 320,
      "visibility": "public",
      "track": [{"lat": 55.75, "lng": 37.61, "ele": 150, "t": 0}, ...],
      "photos": [ ... ],
      "likes_count": 12,
      "comments_count": 3,
      "liked_by_me": false,
      "created_at": "2026-10-08 07:35:00"
    },
    "comments": [
      {
        "id": 1,
        "user": { ... },
        "body": "Отличная работа!",
        "parent_id": null,
        "created_at": "2026-10-08 08:00:00"
      }
    ]
  }
}
```

**Ошибки:**

- **404** — активность не найдена.
- **403** — приватная активность, нет прав.

**Приватность:**

- `public` — видят все.
- `followers` — только подписчики.
- `private` — только автор.

---

#### `POST /upload-activity.php`

Загрузка GPX/TCX/FIT файла.

**Запрос:** `multipart/form-data`

| Поле | Тип | Обязательно |
|---|---|---|
| `file` | file | Да |
| `title` | string | Нет (иначе возьмётся из файла) |
| `description` | string | Нет |
| `visibility` | string | Нет (default `public`) |
| `gear_id` | int | Нет |

**Ответ 201:**

```json
{
  "ok": true,
  "data": {
    "activity_id": 124,
    "parsed": {
      "distance_m": 10234.5,
      "duration_sec": 3600,
      "started_at": "2026-10-08 07:30:00",
      "elevation_gain_m": 120,
      "points_count": 1250
    }
  }
}
```

**Ошибки:**

- **400** — файл не загружен или неподдерживаемый формат.
- **422** — ошибка парсинга.

**Особенности:**

- Поддерживаемые форматы: GPX, TCX, FIT.
- Максимальный размер: **20 МБ**.
- После парсинга автоматически вызывается `SegmentMatcher` для поиска сегментов.
- `Club::recalcStatsForUser()` — пересчёт статистики клубов.

---

#### `POST /activities.php`

Создание активности вручную (без файла).

**Запрос:**

```json
{
  "type": "run",
  "title": "Пробежка",
  "description": "5 км",
  "started_at": "2026-10-08 07:30:00",
  "duration_sec": 1800,
  "distance_m": 5234.5,
  "visibility": "public"
}
```

**Ответ 201:**

```json
{ "ok": true, "data": { "activity_id": 125 } }
```

---

### Posts

#### `GET /post.php`

Один пост.

**Параметры:**

- `id` — ID поста.

**Ответ 200:**

```json
{
  "ok": true,
  "data": {
    "post": {
      "id": 45,
      "user": { ... },
      "title": "Новости тренировок",
      "body": "...",
      "visibility": "public",
      "photos": [ ... ],
      "likes_count": 5,
      "comments_count": 1,
      "liked_by_me": false,
      "created_at": "2026-10-08 09:00:00"
    },
    "comments": [ ... ]
  }
}
```

---

#### `POST /post-create.php`

Создание поста.

**Запрос:** `multipart/form-data`

| Поле | Тип | Обязательно |
|---|---|---|
| `title` | string | Да |
| `body` | string | Да |
| `visibility` | string | Нет (default `public`) |
| `photos[]` | file[] | Нет (до 10) |

**Ответ 201:**

```json
{ "ok": true, "data": { "post_id": 46 } }
```

---

### Comments

#### `POST /comment.php`

Добавить комментарий к активности.

**Запрос:**

```json
{
  "activity_id": 123,
  "body": "Отличная работа!",
  "parent_id": null
}
```

- **`parent_id`** — если это ответ на комментарий, укажите ID родителя.

**Ответ 201:**

```json
{
  "ok": true,
  "data": {
    "comment": {
      "id": 5,
      "user": { ... },
      "body": "Отличная работа!",
      "parent_id": null,
      "created_at": "2026-10-08 08:00:00"
    },
    "count": 4
  }
}
```

- **`count`** — новое общее количество комментариев у активности (для обновления счётчика в UI).

**Ошибки:**

- **404** — активность не найдена.
- **422** — пустой body.

---

#### `POST /post-comment.php`

Аналогично `comment.php`, но для постов.

**Запрос:**

```json
{
  "post_id": 45,
  "body": "Спасибо за рассказ!",
  "parent_id": null
}
```

---

### Likes

#### `POST /like.php`

Поставить/снять лайк активности. **Toggle** — если лайк есть, снимается; если нет — ставится.

**Запрос:**

```json
{ "activity_id": 123 }
```

**Ответ 200:**

```json
{
  "ok": true,
  "data": {
    "liked": true,
    "count": 13
  }
}
```

- **`liked`** — новое состояние (true = лайк поставлен).
- **`count`** — новое общее количество лайков.

**Особенности:**

- Требует аутентификации.
- Создаёт уведомление автору (кроме случая, когда лайкаешь себя).

---

#### `POST /post-like.php`

Аналогично, но для постов.

**Запрос:**

```json
{ "post_id": 45 }
```

---

#### `GET /activity-likers.php`

Список тех, кто лайкнул активность.

**Параметры:**

- `activity_id` — обязательно.

**Ответ 200:**

```json
{
  "ok": true,
  "data": {
    "likers": [
      {
        "id": 43,
        "username": "friend",
        "display_name": "Друг",
        "avatar_url": "https://...",
        "profile_url": "https://roadrunnersteam.ru/pulse/profile.php?u=friend"
      }
    ],
    "count": 13
  }
}
```

---

### Clubs

#### `GET /clubs.php`

Каталог публичных клубов.

**Параметры:**

| Параметр | Тип | Описание |
|---|---|---|
| `q` | string | Поиск по названию/описанию |
| `type` | string | `run`, `ride`, `swim`, `ski`, `walk`, `hike`, `mixed`, `other` |
| `city` | string | Фильтр по городу |
| `sort` | string | `popular`, `new`, `distance`, `alpha` |
| `page`, `per_page` | int | Пагинация |

**Ответ 200:**

```json
{
  "ok": true,
  "data": {
    "items": [
      {
        "id": 5,
        "name": "Бегуны Москвы",
        "slug": "beguny-moskvy",
        "description": "...",
        "city": "Москва",
        "sport_type": "run",
        "member_count": 145,
        "avatar_url": "https://...",
        "cover_url": "https://...",
        "is_verified": true
      }
    ],
    "page": 1,
    "per_page": 24,
    "total": 68,
    "has_more": true
  }
}
```

---

#### `GET /club.php`

Один клуб: информация + статистика.

**Параметры:**

- `id` или `slug` — обязательно одно из.

**Ответ 200:**

```json
{
  "ok": true,
  "data": {
    "club": {
      "id": 5,
      "name": "Бегуны Москвы",
      "slug": "beguny-moskvy",
      "description": "...",
      "city": "Москва",
      "sport_type": "run",
      "visibility": "public",
      "member_count": 145,
      "avatar_url": "https://...",
      "cover_url": "https://...",
      "owner": {
        "id": 42,
        "username": "athlete",
        "display_name": "Иван Иванов"
      },
      "stats": {
        "total_activities": 1234,
        "total_distance_m": 5234567.89,
        "week_distance_m": 123456.78,
        "month_distance_m": 456789.01
      },
      "my_role": "member",
      "is_member": true
    }
  }
}
```

**`my_role`:** `owner`, `admin`, `moderator`, `member` или `null`, если не состою.

---

#### `GET /club-feed.php`

Активности участников клуба.

**Параметры:**

| Параметр | Тип | Описание |
|---|---|---|
| `club_id` | int | Обязательно |
| `page`, `per_page` | int | Пагинация |

**Ответ 200:** аналогичен `feed.php`, только `kind` всегда `activity`.

**Особенности:**

- Фильтр по `sport_type` клуба (например, в клубе бега только `run`).
- Только активности **после** `joined_at` пользователя.
- Кэшируется (TTL 60 сек).

---

#### `POST /club-join.php`

Вступление в клуб.

**Запрос:**

```json
{ "club_id": 5 }
```

**Ответ 200:**

```json
{
  "ok": true,
  "data": {
    "status": "active",
    "message": "Вы вступили в клуб"
  }
}
```

**`status`:**

- `active` — вступил сразу (join_policy = open).
- `pending` — заявка отправлена (join_policy = request).
- `invited` — нужно приглашение (join_policy = invite).

---

#### `POST /club-leave.php`

Выход из клуба.

**Запрос:**

```json
{ "club_id": 5 }
```

**Ответ 200:**

```json
{ "ok": true, "data": { "message": "Вы вышли из клуба" } }
```

**Ошибки:**

- **403** — владелец не может выйти (нужно передать клуб другому).

---

#### `GET /club-members.php`

Участники клуба.

**Параметры:**

- `club_id` — обязательно.
- `status` — `active` (дефолт), `pending`, `banned`.
- `page`, `per_page`.

**Ответ 200:**

```json
{
  "ok": true,
  "data": {
    "items": [
      {
        "id": 42,
        "username": "athlete",
        "display_name": "Иван Иванов",
        "avatar_url": "https://...",
        "role": "member",
        "joined_at": "2024-01-15 10:00:00",
        "activities_count": 245
      }
    ],
    "page": 1,
    "has_more": false
  }
}
```

---

### Segments

#### `GET /segments.php`

Каталог сегментов.

**Параметры:**

| Параметр | Тип | Описание |
|---|---|---|
| `type` | string | Тип активности |
| `q` | string | Поиск |
| `page`, `per_page` | int | Пагинация |

**Ответ 200:**

```json
{
  "ok": true,
  "data": {
    "items": [
      {
        "id": 12,
        "name": "Подъём на Воробьёвы горы",
        "type": "run",
        "distance_m": 1200,
        "elevation_gain_m": 45,
        "creator": { ... },
        "attempts_count": 234,
        "athletes_count": 89
      }
    ],
    "page": 1,
    "has_more": true
  }
}
```

---

#### `GET /segment.php`

Один сегмент + лидерборд.

**Параметры:**

- `id` — обязательно.

**Ответ 200:**

```json
{
  "ok": true,
  "data": {
    "segment": {
      "id": 12,
      "name": "Подъём на Воробьёвы горы",
      "type": "run",
      "distance_m": 1200,
      "elevation_gain_m": 45,
      "track": [{"lat": 55.75, "lng": 37.61}, ...],
      "creator": { ... },
      "created_at": "2024-05-10 12:00:00"
    },
    "stats": {
      "efforts": 234,
      "athletes": 89
    },
    "leaderboard": [
      {
        "rank": 1,
        "user": {
          "id": 43,
          "username": "fastrunner",
          "display_name": "Быстрый Бегун",
          "avatar_url": "https://..."
        },
        "elapsed_time_sec": 245,
        "activity_id": 567,
        "started_at": "2026-09-15 08:00:00"
      }
    ],
    "my_rank": 15,
    "my_best": {
      "elapsed_time_sec": 312,
      "activity_id": 890,
      "started_at": "2026-09-20 08:00:00"
    }
  }
}
```

- **`my_rank`** — `null`, если пользователь не проходил.
- **`my_best`** — `null`, если нет попытки.

**Лимит лидерборда:** первые 100 результатов.

---

#### `POST /segment-rematch.php`

Пересчёт усилий пользователя на сегменте.

**Запрос:**

```json
{ "segment_id": 12 }
```

**Ответ 200:**

```json
{
  "ok": true,
  "data": {
    "processed": 245,
    "matched": 8,
    "new_efforts": 2
  }
}
```

**⚠️ Осторожно:** может выполняться долго (секунды). Планируется перенос в cron.

---

### Calendar

#### `GET /calendar-month.php`

Данные для календаря за месяц.

**Параметры:**

| Параметр | Тип | Описание |
|---|---|---|
| `year` | int | Год (например, 2026) |
| `month` | int | Месяц (1–12) |

**Ответ 200:**

```json
{
  "ok": true,
  "data": {
    "year": 2026,
    "month": 10,
    "month_label": "Октябрь 2026",
    "days_in_month": 31,
    "start_weekday": 4,
    "days": {
      "2026-10-01": {
        "date": "2026-10-01",
        "count": 2,
        "distance_m": 12345.6,
        "duration_sec": 5400,
        "types": ["run", "ride"],
        "activities": [
          {
            "id": 123,
            "type": "run",
            "title": "Утренняя пробежка",
            "distance_m": 5234.5,
            "duration_sec": 1800,
            "avg_speed_mps": 2.9,
            "started_at": "2026-10-01 07:30:00"
          }
        ]
      }
    }
  }
}
```

- **`start_weekday`** — 1 (Пн) — 7 (Вс). Используется для пустых ячеек в начале месяца.

---

## Примеры сценариев

### Полный флоу мобильного клиента

**1. Логин:**

```
POST /auth.php
{
  "email": "user@example.com",
  "password": "secret123",
  "device_name": "iPhone 15 Pro"
}

→ token = "eyJ..."
```

**2. Сохранить токен в keychain.**

**3. Загрузить профиль:**

```
GET /me.php
Authorization: Bearer eyJ...
```

**4. Загрузить ленту:**

```
GET /feed.php?tab=following&page=1
Authorization: Bearer eyJ...
```

**5. Лайкнуть активность:**

```
POST /like.php
Authorization: Bearer eyJ...
Content-Type: application/json

{ "activity_id": 123 }

→ { "liked": true, "count": 13 }
```

**6. Загрузить GPX:**

```
POST /upload-activity.php
Authorization: Bearer eyJ...
Content-Type: multipart/form-data

file: <binary>
title: "Утренняя пробежка"
visibility: "public"

→ { "activity_id": 124, "parsed": {...} }
```

---

### Фронтенд: fetch с CSRF

```javascript
async function likeActivity(id) {
    const res = await fetch('/pulse/api/like.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': getCsrfToken(),
            'Accept': 'application/json'
        },
        body: JSON.stringify({ activity_id: id })
    });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error);
    return data.data;
}
```

`getCsrfToken()` — из `<meta name="csrf-token">` или из `window.__CSRF__`.

---

### Обработка ошибок

```javascript
async function apiCall(url, options = {}) {
    const res = await fetch(url, { credentials: 'same-origin', ...options });
    const data = await res.json();
    if (!data.ok) {
        if (res.status === 401) {
            // Токен истёк — перелогин
            redirectToLogin();
        } else if (res.status === 422) {
            // Ошибки валидации
            showFieldErrors(data.errors);
        } else {
            showToast(data.error || 'Ошибка');
        }
        throw new Error(data.error);
    }
    return data.data;
}
```

---

## Версионирование

**Пока нет версий.** API доступен по `/api/` без версии.

**Планы:**

- `/api/v1/`, `/api/v2/` — если будут breaking changes.
- Обратная совместимость сохраняется минимум **6 месяцев**.

**Breaking changes:**

- Удаление поля из ответа.
- Изменение типа поля (например, `int` → `string`).
- Изменение структуры (например, `data.items` → `data.results`).

**Non-breaking:**

- Добавление новых полей в ответ.
- Добавление новых эндпоинтов.
- Добавление новых параметров (с дефолтом).

---

## Roadmap

- **Rate limiting** — планируется.
- **Webhooks** для интеграций (Wahoo, Strava).
- **Push-уведомления** — FCM для мобильных.
- **OAuth** — для сторонних приложений (сейчас только Bearer-токен от логина).
- **Ревизия API** — унификация: сейчас часть эндпоинтов GET, часть POST для одинаковых действий.
- **GraphQL** — обсуждается, но пока нет.

---

## Поддержка

- **Issues:** [GitHub Issues](https://github.com/Alex469-star/pulse2/issues)
- **Email:** (если есть)

---

**Последнее обновление:** 2026-10-08
```

---

## Что я добавил, но стоит дополнить/проверить

1. **Реальные пути и параметры.** Я описывал по тому, что видел в коде. Если что-то отличается (например, `feed.php` принимает другие параметры), поправь.

2. **Примеры ответов.** Я дал «идеальные» JSON. Стоит **проверить фактический ответ** каждого эндпоинта через `curl` или Postman и скорректировать.

3. **`register.php` и `forgot-password.php`.** Есть ли они в `api/`? В твоей структуре я видел `api/auth.php`, но не видел `api/register.php`. Если нет — либо удали секции из документа, либо создай эндпоинты.

4. **`activity-likers.php`.** Тоже стоит проверить — есть ли этот эндпоинт.

5. **`/api/club-feed.php`** — это я предположил. У тебя может быть `/api/club-activities.php`.

6. **`/api/segment-rematch.php`** — тоже предположение. В `segment.php` ты вызываешь `SegmentMatcher` через POST на ту же страницу, не через API.

7. **OpenAPI/Swagger.** Если хочешь автогенерируемую документацию — стоит добавить `openapi.yaml`. Тогда можно будет генерировать клиенты для мобильного приложения.

8. **Реальные примеры curl.** В конце можно добавить:

```bash
# Логин
curl -X POST https://roadrunnersteam.ru/pulse/api/auth.php \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"secret"}'

# Получить профиль
curl https://roadrunnersteam.ru/pulse/api/me.php \
  -H "Authorization: Bearer eyJ..."
```

---

## Что нужно от тебя

1. **Проверить, какие эндпоинты реально существуют** в `api/`. Скинь `ls api/` — я подгоню документ.

2. **Скинуть реальные ответы** от нескольких эндпоинтов (`auth.php`, `me.php`, `feed.php`). Через `curl` или DevTools → Network. Тогда я точно опишу формат.

3. **Определить, нужен ли `openapi.yaml`.** Если да — сделаю. Тогда можно будет генерировать клиенты автоматически.

4. **Дописать/убрать** секции, которых у тебя нет (register, forgot-password, activity-likers и т.д.).

Скажи, что делать — уточню документ.