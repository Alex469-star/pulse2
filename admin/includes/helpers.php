<?php
declare(strict_types=1);

/**
 * Хелперы, специфичные для админки.
 * Основные (e, url, config, db) — из includes/helpers.php.
 */

function admin_format_bytes(int $bytes): string
{
    $units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
    return round($bytes, 2) . ' ' . $units[$i];
}

function admin_format_int(int $n): string
{
    return number_format($n, 0, '.', ' ');
}

function admin_time_ago(string $datetime): string
{
    $t = strtotime($datetime);
    if (!$t) return '—';
    $diff = time() - $t;
    if ($diff < 60) return 'только что';
    if ($diff < 3600) return floor($diff / 60) . ' мин назад';
    if ($diff < 86400) return floor($diff / 3600) . ' ч назад';
    if ($diff < 2592000) return floor($diff / 86400) . ' дн назад';
    return date('d.m.Y', $t);
}

/**
 * Список таблиц БД с базовой статистикой.
 */
function admin_list_tables(): array
{
    $rows = db()->query('SHOW TABLE STATUS')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'name'    => (string)$r['Name'],
            'rows'    => (int)($r['Rows'] ?? 0),
            'size'    => (int)($r['Data_length'] ?? 0) + (int)($r['Index_length'] ?? 0),
            'engine'  => (string)($r['Engine'] ?? ''),
            'comment' => (string)($r['Comment'] ?? ''),
        ];
    }
    usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $out;
}

/**
 * Существует ли таблица в текущей базе.
 */
function admin_table_exists(string $table): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Список колонок таблицы.
 * @return array<int,array{Field:string,Type:string,Null:string,Key:string,Default:mixed,Extra:string}>
 */
function admin_table_columns(string $table): array
{
    if (!admin_table_exists($table)) return [];
    $stmt = db()->query("SHOW COLUMNS FROM `$table`");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}