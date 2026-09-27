<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/helpers.php';

// ⚠️ УДАЛИТЕ ЭТОТ ФАЙЛ ПОСЛЕ ИСПОЛЬЗОВАНИЯ

$dirs = [
    __DIR__ . '/assets/uploads/activities',
    __DIR__ . '/assets/uploads/posts',
    __DIR__ . '/assets/uploads/avatars',
    __DIR__ . '/assets/uploads/gear',
];

$totalBefore = 0;
$totalAfter = 0;

foreach ($dirs as $dir) {
    if (!is_dir($dir)) continue;

    echo "<h2>" . htmlspecialchars($dir) . "</h2>";

    foreach (glob($dir . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE) as $file) {
        $before = filesize($file);
        if ($before === false) continue;
        $totalBefore += $before;

        // Для аватаров уменьшаем сильнее
        $isAvatar = strpos($file, '/avatars/') !== false;
        $max = $isAvatar ? 400 : 1600;

        upload_photo_resize($file, $max, $max);

        clearstatcache(true, $file);
        $after = filesize($file);
        $totalAfter += $after;

        echo htmlspecialchars(basename($file))
            . ' — было ' . round($before / 1024) . ' КБ'
            . ', стало ' . round($after / 1024) . ' КБ'
            . '<br>';
    }
}

echo "<hr><strong>Итого:</strong> было " . round($totalBefore / 1024 / 1024, 2) . " МБ"
   . ", стало " . round($totalAfter / 1024 / 1024, 2) . " МБ"
   . " (экономия " . round((1 - $totalAfter / max(1, $totalBefore)) * 100, 1) . "%)";