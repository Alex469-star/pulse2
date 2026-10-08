<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// Разрешаем только POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method Not Allowed', 405);
}

$input = json_input();
$points = $input['points'] ?? null;

if (!is_array($points) || count($points) < 2) {
    json_err('Необходимо передать минимум 2 точки', 400);
}

if (count($points) > 500) {
    json_err('Слишком много точек (максимум 500)', 400);
}

// Валидация
$validPoints = [];
foreach ($points as $p) {
    if (!isset($p['lat'], $p['lng'])) continue;
    $lat = (float)$p['lat'];
    $lng = (float)$p['lng'];
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
    $validPoints[] = ['lat' => $lat, 'lng' => $lng];
}

if (count($validPoints) < 2) {
    json_err('Недостаточно корректных точек', 400);
}

// ---- Проверка cURL ----
if (!function_exists('curl_init')) {
    json_err('cURL не установлен на сервере', 500);
}

$elevations = [];
// Open-Meteo: 100 точек за один запрос
$chunkSize = 100;
$chunks = array_chunk($validPoints, $chunkSize);

foreach ($chunks as $ci => $chunk) {
    $lats = [];
    $lngs = [];
    foreach ($chunk as $p) {
        $lats[] = number_format($p['lat'], 6, '.', '');
        $lngs[] = number_format($p['lng'], 6, '.', '');
    }

    $url = 'https://api.open-meteo.com/v1/elevation'
         . '?latitude=' . implode(',', $lats)
         . '&longitude=' . implode(',', $lngs);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: Pulse/1.0',
        ],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlErrno) {
        json_err('Ошибка соединения с сервисом высот: [' . $curlErrno . '] ' . $curlError, 502);
    }

    if ($httpCode === 429) {
        json_err('Сервис высот временно перегружен (429). Попробуйте позже.', 429);
    }

    if ($httpCode !== 200) {
        json_err('Сервис высот вернул HTTP ' . $httpCode, 502);
    }

    $data = json_decode((string)$response, true);
    if (!isset($data['elevation']) || !is_array($data['elevation'])) {
        json_err('Некорректный ответ от сервиса высот', 502);
    }

    foreach ($chunk as $i => $p) {
        $ele = isset($data['elevation'][$i]) ? (float)$data['elevation'][$i] : 0.0;
        $elevations[] = [
            'lat' => $p['lat'],
            'lng' => $p['lng'],
            'ele' => $ele,
        ];
    }
}

json_ok(['points' => $elevations]);