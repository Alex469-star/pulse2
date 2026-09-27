<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/mailer.php';

$to = 'rrteam.club@gmail.com';  // ← замените
echo "Проверка отправки на: $to\n\n";

$autoload = __DIR__ . '/vendor/autoload.php';
echo 'PHPMailer: ' . (file_exists($autoload) ? 'ЕСТЬ' : 'НЕТ') . "\n";

$cfg = config('mail');
echo 'mail.enabled: ' . (!empty($cfg['enabled']) ? 'true' : 'false') . "\n";
echo 'mail.host: ' . $cfg['host'] . "\n";
echo 'mail.username: ' . $cfg['username'] . "\n";
echo "\n";

$body = mail_template('Тест', '<p>Это тестовое письмо от RoadRunner.</p>');
$ok = send_mail($to, 'Тестовое письмо', $body);

echo 'send_mail() вернул: ' . ($ok ? 'успех' : 'ошибка') . "\n\n";

$logFile = __DIR__ . '/storage/mail.log';
if (file_exists($logFile)) {
    echo "Последние 20 строк storage/mail.log:\n";
    $lines = file($logFile);
    echo implode('', array_slice($lines, -20));
}