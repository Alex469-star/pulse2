<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

function send_mail(string $to, string $subject, string $htmlBody): bool
{
    $cfg = config('mail');

    // Если почта отключена — пишем в лог
    if (empty($cfg['enabled'])) {
        log_to_file('mail.log', "TO: $to | SUBJECT: $subject | BODY: $htmlBody");
        return true;
    }

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) {
        log_to_file('mail.log', "PHPMailer не установлен. TO: $to | SUBJECT: $subject");
        return false;
    }
    require_once $autoload;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $cfg['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['username'];
        $mail->Password   = $cfg['password'];
        $mail->SMTPSecure = $cfg['encryption'];
        $mail->Port       = (int)$cfg['port'];
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);

// Временная отладка (убери на проде!)
if (config('debug')) {
    $mail->SMTPDebug = \PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;
    $mail->Debugoutput = function ($str, $level) {
        log_to_file('mail.log', '[SMTP DEBUG] ' . trim($str));
    };
}

$mail->SMTPOptions = [
    'ssl' => [
        'verify_peer'       => false,
        'verify_peer_name'  => false,
        'allow_self_signed' => true,
    ],
];

        $mail->send();
        return true;
    } catch (MailException $e) {
        log_to_file('mail.log', sprintf(
            "ERROR to %s | host=%s:%d | secure=%s | SMTPDebug:\n%s\nErrorInfo: %s",
            $to,
            $cfg['host'],
            (int)$cfg['port'],
            $cfg['encryption'],
            $mail->ErrorInfo,
            $e->getMessage()
        ));
        return false;
    }
}

function mail_template(string $title, string $bodyHtml, string $ctaText = '', string $ctaUrl = ''): string
{
    $appName = e((string)config('app_name'));
    $cta = '';
    if ($ctaText && $ctaUrl) {
        $cta = '<p style="margin-top:24px"><a href="' . e($ctaUrl) . '" '
             . 'style="background:#ff5a1f;color:#fff;padding:12px 24px;border-radius:999px;'
             . 'text-decoration:none;font-weight:600;display:inline-block">' . e($ctaText) . '</a></p>';
    }
    return <<<HTML
<!DOCTYPE html>
<html><body style="font-family:Inter,Arial,sans-serif;background:#f7f8fa;padding:40px 0;margin:0">
<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:16px;padding:32px;border:1px solid #e6e9ef">
    <div style="font-weight:800;font-size:20px;color:#0f1420;margin-bottom:16px">$appName</div>
    <h1 style="font-size:22px;color:#0f1420;margin:0 0 16px">{$title}</h1>
    <div style="color:#5b6473;line-height:1.6">{$bodyHtml}</div>
    {$cta}
    <p style="color:#8a93a3;font-size:12px;margin-top:32px">Это письмо отправлено автоматически. Не отвечайте на него.</p>
</div>
</body></html>
HTML;
}