<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

auth_start();
header('Content-Type: application/json; charset=utf-8');

function ok($d = null) { echo json_encode(['ok'=>true,'data'=>$d], JSON_UNESCAPED_UNICODE); exit; }
function err(string $m, int $c = 400) { http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m], JSON_UNESCAPED_UNICODE); exit; }

$me = current_user();
if (!$me) err('Требуется вход', 401);

$method = $_SERVER['REQUEST_METHOD'];

// ---- Список ----
if ($method === 'GET') {
    $s = db()->prepare(
        'SELECT id, name, lat, lng, icon
           FROM user_saved_places
          WHERE user_id = ?
       ORDER BY created_at DESC'
    );
    $s->execute([(int)$me['id']]);
    $rows = $s->fetchAll();
    foreach ($rows as &$r) {
        $r['lat'] = (float)$r['lat'];
        $r['lng'] = (float)$r['lng'];
    }
    unset($r);
    ok(['places' => $rows]);
}

// ---- Создание ----
if ($method === 'POST') {
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;

    $csrf = $input['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    csrf_check($csrf);

    $name = trim((string)($input['name'] ?? ''));
    $lat  = (float)($input['lat'] ?? 0);
    $lng  = (float)($input['lng'] ?? 0);
    $icon = trim((string)($input['icon'] ?? 'place'));

    if ($name === '' || mb_strlen($name) > 120) err('Введите название');
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) err('Неверные координаты');

    $s = db()->prepare(
        'INSERT INTO user_saved_places (user_id, name, lat, lng, icon) VALUES (?, ?, ?, ?, ?)'
    );
    $s->execute([(int)$me['id'], $name, $lat, $lng, $icon]);

    ok(['id' => (int)db()->lastInsertId(), 'name' => $name, 'lat' => $lat, 'lng' => $lng, 'icon' => $icon]);
}

// ---- Удаление ----
if ($method === 'DELETE') {
    parse_str(file_get_contents('php://input'), $params);
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (is_array($input)) $params = $input;

    $csrf = $params['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    csrf_check($csrf);

    $id = (int)($params['id'] ?? 0);
    if ($id <= 0) err('id обязателен');

    db()->prepare('DELETE FROM user_saved_places WHERE id = ? AND user_id = ?')
        ->execute([$id, (int)$me['id']]);

    ok(['deleted' => $id]);
}

err('Метод не поддерживается', 405);