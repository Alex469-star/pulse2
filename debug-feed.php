<?php
declare(strict_types=1);

// ============================================================
// ЗАЩИТА — замените токен на свой и откройте с ?key=ВАШ_ТОКЕН
// ============================================================
$DEBUG_KEY = 'pulse-debug-2026';
if (($_GET['key'] ?? '') !== $DEBUG_KEY) {
    http_response_code(403);
    exit('403. Добавьте ?key=' . $DEBUG_KEY . ' к адресу.');
}

// Включаем всё
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Ловим фатальные ошибки
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo '<div style="padding:16px;background:#fff0ef;color:#b3261e;border:1px solid #ffcfcb;border-radius:10px;margin:12px 0;font-family:ui-monospace,monospace">';
        echo '<strong>FATAL:</strong> ' . htmlspecialchars($e['message'], ENT_QUOTES) . '<br>';
        echo 'File: ' . htmlspecialchars($e['file'], ENT_QUOTES) . '<br>';
        echo 'Line: ' . (int)$e['line'];
        echo '</div>';
    }
});

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Диагностика feed.php</title>
    <style>
        body { font-family: ui-monospace, monospace; background: #f7f8fa; color: #0f1420; margin: 0; padding: 24px; }
        h1 { font-size: 22px; margin: 0 0 16px; }
        h2 { font-size: 16px; margin: 24px 0 8px; }
        .box { background: #fff; border: 1px solid #e6e9ef; border-radius: 12px; padding: 16px; margin-bottom: 14px; }
        .ok { color: #0a7a3a; font-weight: 700; }
        .err { color: #b3261e; font-weight: 700; }
        .warn { color: #a36a00; font-weight: 700; }
        .row { padding: 6px 0; border-bottom: 1px solid #eef1f6; font-size: 13px; }
        .row:last-child { border-bottom: none; }
        .label { display: inline-block; min-width: 320px; color: #5b6473; }
        pre { background: #f7f8fa; padding: 10px; border-radius: 8px; overflow: auto; font-size: 12px; max-height: 300px; }
        .tabs { display: flex; gap: 6px; margin-bottom: 16px; }
        .tab { padding: 8px 16px; border-radius: 999px; background: #fff; border: 1px solid #e6e9ef; cursor: pointer; font-size: 13px; text-decoration: none; color: #0f1420; }
        .tab.on { background: #0f1420; color: #fff; border-color: #0f1420; }
        .mono { font-family: ui-monospace, monospace; font-size: 12px; }
    </style>
</head>
<body>

<h1>Диагностика <code>feed.php</code></h1>

<?php
// ============================================================
// 1. ОКРУЖЕНИЕ
// ============================================================
echo '<div class="box"><h2>1. Окружение</h2>';

$rows = [];
$rows[] = ['PHP version', PHP_VERSION, version_compare(PHP_VERSION, '8.1', '>=')];
$rows[] = ['PDO', extension_loaded('pdo') ? 'loaded' : 'MISSING', extension_loaded('pdo')];
$rows[] = ['pdo_mysql', extension_loaded('pdo_mysql') ? 'loaded' : 'MISSING', extension_loaded('pdo_mysql')];
$rows[] = ['mbstring', extension_loaded('mbstring') ? 'loaded' : 'MISSING', extension_loaded('mbstring')];
$rows[] = ['json', extension_loaded('json') ? 'loaded' : 'MISSING', extension_loaded('json')];
$rows[] = ['gd', extension_loaded('gd') ? 'loaded' : 'not loaded (фото работать не будут)', extension_loaded('gd')];
$rows[] = ['display_errors', ini_get('display_errors') ? 'On' : 'Off', true];
$rows[] = ['error_log', ini_get('error_log') ?: '(default)', true];
$rows[] = ['session.save_path', ini_get('session.save_path') ?: '(default)', true];

foreach ($rows as [$label, $value, $ok]) {
    $cls = $ok ? 'ok' : 'err';
    echo '<div class="row"><span class="label">' . htmlspecialchars($label) . '</span><span class="' . $cls . '">' . htmlspecialchars((string)$value) . '</span></div>';
}
echo '</div>';

// ============================================================
// 2. ФАЙЛЫ ПРОЕКТА
// ============================================================
echo '<div class="box"><h2>2. Файлы проекта</h2>';

$files = [
    'config/config.php',
    'includes/helpers.php',
    'includes/auth.php',
    'includes/header.php',
    'includes/footer.php',
    'models/Activity.php',
    'models/Post.php',
    'models/User.php',
    'models/Like.php',
    'models/Comment.php',
    'models/Follow.php',
    'models/Notification.php',
    'api/_bootstrap.php',
    'api/feed.php',
    'api/like.php',
    'api/comment.php',
    'api/post-like.php',
    'api/post-comment.php',
    'feed.php',
];

$baseDir = __DIR__;
foreach ($files as $f) {
    $path = $baseDir . '/' . $f;
    $exists = is_file($path);
    $size = $exists ? filesize($path) : 0;
    $cls = $exists ? 'ok' : 'err';
    $val = $exists ? ('OK · ' . number_format($size) . ' B') : 'НЕ НАЙДЕН';
    echo '<div class="row"><span class="label">' . htmlspecialchars($f) . '</span><span class="' . $cls . '">' . $val . '</span></div>';
}
echo '</div>';

// ============================================================
// 3. ПОДКЛЮЧЕНИЕ К БД
// ============================================================
echo '<div class="box"><h2>3. Подключение к БД</h2>';

$dbOk = false;
$pdo = null;
try {
    $cfg = require __DIR__ . '/config/config.php';
    $db = $cfg['db'];
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'], $db['port'], $db['database'], $db['charset']);
    $pdo = new PDO($dsn, $db['username'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $dbOk = true;
    echo '<div class="row"><span class="label">Пользователь</span><span class="ok">' . htmlspecialchars($db['username']) . '</span></div>';
    echo '<div class="row"><span class="label">База</span><span class="ok">' . htmlspecialchars($db['database']) . '</span></div>';
    echo '<div class="row"><span class="label">Хост</span><span class="ok">' . htmlspecialchars($db['host']) . ':' . (int)$db['port'] . '</span></div>';
} catch (Throwable $e) {
    echo '<div class="row"><span class="label">Ошибка подключения</span><span class="err">' . htmlspecialchars($e->getMessage()) . '</span></div>';
}
echo '</div>';

// ============================================================
// 4. ТАБЛИЦЫ БД
// ============================================================
echo '<div class="box"><h2>4. Таблицы</h2>';

if (!$dbOk) {
    echo '<div class="row"><span class="err">Пропущено — нет подключения к БД</span></div>';
} else {
    $need = ['users', 'activities', 'activity_likes', 'activity_comments', 'follows',
             'gear', 'notifications',
             'posts', 'post_photos', 'post_likes', 'post_comments'];

    try {
        $stmt = $pdo->query('SHOW TABLES');
        $existing = array_map('current', $stmt->fetchAll(PDO::FETCH_NUM));
        $existing = array_map('strtolower', $existing);

        foreach ($need as $t) {
            $ok = in_array(strtolower($t), $existing, true);
            $cls = $ok ? 'ok' : 'err';
            $val = $ok ? 'OK' : 'НЕ НАЙДЕНА';
            echo '<div class="row"><span class="label">' . htmlspecialchars($t) . '</span><span class="' . $cls . '">' . $val . '</span></div>';
        }
    } catch (Throwable $e) {
        echo '<div class="row"><span class="err">' . htmlspecialchars($e->getMessage()) . '</span></div>';
    }
}
echo '</div>';

// ============================================================
// 5. СТРУКТУРА POSTS
// ============================================================
echo '<div class="box"><h2>5. Структура таблицы posts</h2>';
if ($dbOk) {
    try {
        $stmt = $pdo->query('DESCRIBE posts');
        $cols = $stmt->fetchAll();
        if (!$cols) {
            echo '<div class="row"><span class="err">Таблица posts пуста или не существует</span></div>';
        } else {
            foreach ($cols as $c) {
                echo '<div class="row"><span class="label">' . htmlspecialchars($c['Field']) . '</span><span>' . htmlspecialchars($c['Type']) . '</span></div>';
            }
        }
    } catch (Throwable $e) {
        echo '<div class="row"><span class="err">' . htmlspecialchars($e->getMessage()) . '</span></div>';
    }
}
echo '</div>';

// ============================================================
// 6. ПРОВЕРКА ENUM В NOTIFICATIONS
// ============================================================
echo '<div class="box"><h2>6. Проверка ENUM notifications.type</h2>';
if ($dbOk) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'type'");
        $col = $stmt->fetch();
        $typeStr = $col['Type'] ?? '';
        $has = strpos($typeStr, 'post_like') !== false && strpos($typeStr, 'post_comment') !== false;
        echo '<div class="row"><span class="label">Значение ENUM</span><span class="' . ($has ? 'ok' : 'warn') . '">' . htmlspecialchars($typeStr) . '</span></div>';
        if (!$has) {
            echo '<div class="row"><span class="warn">Нужно выполнить ALTER TABLE notifications MODIFY COLUMN type ENUM(...) с post_like и post_comment</span></div>';
        }
    } catch (Throwable $e) {
        echo '<div class="row"><span class="err">' . htmlspecialchars($e->getMessage()) . '</span></div>';
    }
}
echo '</div>';

// ============================================================
// 7. ПРОВЕРКА СИНТАКСИСА ЧЕРЕЗ PHP -l
// ============================================================
echo '<div class="box"><h2>7. Синтаксис PHP (php -l)</h2>';

$phpBin = PHP_BINARY;
$check = [
    'feed.php',
    'api/feed.php',
    'models/Post.php',
    'api/post-like.php',
    'api/post-comment.php',
];

foreach ($check as $f) {
    $path = $baseDir . '/' . $f;
    if (!is_file($path)) {
        echo '<div class="row"><span class="label">' . htmlspecialchars($f) . '</span><span class="err">НЕТ ФАЙЛА</span></div>';
        continue;
    }
    $out = [];
    $code = 0;
    @exec(escapeshellcmd($phpBin) . ' -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    $text = implode(' ', $out);
    $cls = $code === 0 ? 'ok' : 'err';
    echo '<div class="row"><span class="label">' . htmlspecialchars($f) . '</span><span class="' . $cls . '">' . htmlspecialchars($text) . '</span></div>';
}
echo '</div>';

// ============================================================
// 8. ПОПЫТКА ЗАПУСТИТЬ feed.php
// ============================================================
echo '<div class="box"><h2>8. Пробный запуск feed.php</h2>';
echo '<div class="row"><span class="warn">Ниже — реальный вывод feed.php. Если увидите FATAL — это и есть причина 500.</span></div>';

// Симулируем запрос и сессию
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
// Найдём реального пользователя, чтобы не редиректило на login
if ($dbOk) {
    try {
        $stmt = $pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1');
        $uid = (int)$stmt->fetchColumn();
        if ($uid) {
            $_SESSION['user_id'] = $uid;
        }
    } catch (Throwable $e) {}
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/pulse/feed.php';
$_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'roadrunnersteam.ru';
$_GET = ['tab' => 'all', 'type' => ''];

ob_start();
try {
    require __DIR__ . '/feed.php';
    $html = ob_get_clean();
    echo '<div class="row"><span class="ok">feed.php выполнился без фатальных ошибок</span></div>';
    echo '<div class="row"><span class="label">Размер HTML:</span><span>' . number_format(strlen($html)) . ' B</span></div>';
    // Покажем только первые 2000 символов — чтобы не пугать
    echo '<pre>' . htmlspecialchars(mb_substr($html, 0, 2000)) . '…</pre>';
} catch (Throwable $e) {
    ob_end_clean();
    echo '<div class="row"><span class="err">EXCEPTION: ' . htmlspecialchars($e->getMessage()) . '</span></div>';
    echo '<div class="row"><span class="label">File:</span><span>' . htmlspecialchars($e->getFile()) . '</span></div>';
    echo '<div class="row"><span class="label">Line:</span><span>' . (int)$e->getLine() . '</span></div>';
    echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
}
echo '</div>';

// ============================================================
// 9. ПОСЛЕДНИЕ ОШИБКИ PHP
// ============================================================
echo '<div class="box"><h2>9. Последние ошибки в логе PHP</h2>';

$logPaths = [
    ini_get('error_log'),
    '/var/www/www-root/data/logs/roadrunnersteam.ru_error.log',
    '/var/log/php-fpm/error.log',
    '/var/log/php/error.log',
];

$shown = false;
foreach ($logPaths as $lp) {
    if (!$lp || !is_file($lp) || !is_readable($lp)) continue;
    $lines = @file($lp);
    if (!$lines) continue;
    $tail = array_slice($lines, -25);
    echo '<div class="row"><span class="label">' . htmlspecialchars($lp) . '</span></div>';
    echo '<pre>' . htmlspecialchars(implode('', $tail)) . '</pre>';
    $shown = true;
    break;
}
if (!$shown) {
    echo '<div class="row"><span class="warn">Лог не найден или недоступен. Проверьте path через php -i | grep error_log</span></div>';
}
echo '</div>';
?>

</body>
</html>