<?php
declare(strict_types=1);

// purchase.php
// Принимает JSON { service_id, phone, email }
// Генерирует docId, вызывает FastSales API, генерирует PDF-ваучер (mPDF),
// создаёт QR по public URL ваучера (по номеру телефона внутри ссылки),
// отправляет письмо через SMTP и возвращает JSON { ok: true, voucher_url: ... }

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
use chillerlan\QRCode\{QRCode, QROptions};
use Dotenv\Dotenv;

// ---- helpers ----
function respond($ok, $message = '', $extra = []) {
    http_response_code($ok ? 200 : 400);
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function uuid_v4(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ---- load .env from /opt/catalog ----
function load_env(): void {
    $envPath = '/opt/catalog';
    if (is_dir($envPath) && is_readable($envPath . '/.env')) {
        $dotenv = Dotenv::createImmutable($envPath);
        $dotenv->safeLoad();
    }
    // else: ничего, возможно переменные среды есть в окружении сервера
}
load_env();
error_log('DEBUG FASTSALE_ENDPOINT: ' . getenv('FASTSALE_ENDPOINT'));
error_log('DEBUG CLUB_ID: ' . getenv('CLUB_ID'));
error_log('DEBUG getenv FASTSALE_ENDPOINT: ' . getenv('FASTSALE_ENDPOINT'));
error_log('DEBUG _ENV FASTSALE_ENDPOINT: ' . ($_ENV['FASTSALE_ENDPOINT'] ?? ''));
error_log('DEBUG _SERVER FASTSALE_ENDPOINT: ' . ($_SERVER['FASTSALE_ENDPOINT'] ?? ''));

// ---- read request ----
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    respond(false, 'Invalid JSON body');
}

$serviceId = trim((string)($data['service_id'] ?? ''));
$phone     = trim((string)($data['phone'] ?? ''));
$email     = trim((string)($data['email'] ?? ''));

if ($serviceId === '' || $phone === '' || $email === '') {
    respond(false, 'service_id, phone and email are required');
}

// ---- load configuration from env ----
$FASTSALE_ENDPOINT = getenv('FASTSALE_ENDPOINT') ?: getenv('FASTSALES_ENDPOINT') ?: '';
$CLUB_ID = getenv('CLUB_ID') ?: '';
$PUBLIC_BASE = rtrim(getenv('PUBLIC_BASE') ?: '', '/');
$VOUCHERS_DIR = getenv('VOUCHERS_DIR') ?: __DIR__ . '/../../vouchers';
$VOUCHER_SECRET = getenv('VOUCHER_SECRET') ?: (getenv('SECRET') ?: 'please-change-me');

// SMTP
$SMTP_HOST = getenv('SMTP_HOST') ?: getenv('MAIL_HOST') ?: '';
$SMTP_PORT = intval(getenv('SMTP_PORT') ?: getenv('MAIL_PORT') ?: 0);
$SMTP_USER = getenv('SMTP_USER') ?: getenv('MAIL_USER') ?: '';
$SMTP_PASS = getenv('SMTP_PASS') ?: getenv('MAIL_PASS') ?: '';
$SMTP_FROM = getenv('SMTP_FROM') ?: 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$SMTP_FROM_NAME = getenv('SMTP_FROM_NAME') ?: 'DVVS';

// minimal validation
if (!$FASTSALE_ENDPOINT || !$CLUB_ID) {
    respond(false, 'Server misconfigured (FASTSALE_ENDPOINT/CLUB_ID missing)');
}

// ---- build docId and date ----
$docId = uuid_v4();
$date = (new DateTime('now', new DateTimeZone('Europe/Moscow')))->format('Y-m-d\TH:i:s');

// ---- call external FastSales API ----
$requestBody = [
    'club_id' => $CLUB_ID,
    'phone' => preg_replace('/\D+/', '', $phone),
    'email' => $email,
    'sale' => [
        'goods' => [
            [
                'id' => $serviceId,
                'qnt' => 1,
                'summ' => 0
            ]
        ],
        'cashless' => 1,
        'docId' => $docId,
        'date' => $date
    ]
];

$ch = curl_init($FASTSALE_ENDPOINT);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody, JSON_UNESCAPED_UNICODE));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($response === false || $httpCode >= 400) {
    // логируем и возвращаем ошибку
    error_log("FastSales error: http={$httpCode} curlErr={$curlErr} resp=" . substr((string)$response,0,500));
    respond(false, 'FastSales error: ' . ($curlErr ?: ('HTTP ' . $httpCode)));
}

// ---- create voucher directory ----
if (!is_dir($VOUCHERS_DIR)) {
    @mkdir($VOUCHERS_DIR, 0755, true);
}
$voucherFile = rtrim($VOUCHERS_DIR, '/') . '/' . $docId . '.pdf';
$publicVoucherBase = $PUBLIC_BASE ?: (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'].'://'.($_SERVER['HTTP_HOST'] ?? '') : '');
$publicVoucherUrl = rtrim($publicVoucherBase, '/') . '/catalog/api-backend/api/voucher.php?doc=' . urlencode($docId);

// ---- prepare HTML template replacements ----
// read service details optionally from your services storage if available
$serviceName = $data['service_name'] ?? $serviceId;
$price = $data['price'] ?? '';
$visits = $data['visits'] ?? '';
$freezing = $data['freezing'] ?? '';

$templatePath = __DIR__ . '/templates/voucher_mpdf.html';
$html = '';
if (is_readable($templatePath)) {
    $html = file_get_contents($templatePath);
} else {
    $html = "<h1>Абонемент</h1><p>Документ: {$docId}</p><p>Услуга: {$serviceName}</p>";
}

// ---- generate QR as data URI using public voucher URL + phone (phone used in generation implicitly) ----
try {
    $qrPayload = $publicVoucherUrl . '&phone=' . urlencode(preg_replace('/\D+/', '', $phone));
    $options = new QROptions([
        'version'    => 5,
        'outputType' => QRCode::OUTPUT_IMAGE_PNG,
        'eccLevel'   => QRCode::ECC_L,
        'scale'      => 5,
    ]);
    $qrBin = (new QRCode($options))->render($qrPayload);
    $qrDataUri = 'data:image/png;base64,' . base64_encode($qrBin);
} catch (Throwable $e) {
    $qrDataUri = '';
}

// ---- logo support: try ENV path or fallback to nothing ----
$logoPathEnv = getenv('VOUCHER_LOGO_PATH') ?: '';
$logoDataUri = '';
if ($logoPathEnv && @is_readable($logoPathEnv)) {
    $logoBin = file_get_contents($logoPathEnv);
    $ext = pathinfo($logoPathEnv, PATHINFO_EXTENSION) ?: 'png';
    $logoDataUri = 'data:image/' . $ext . ';base64,' . base64_encode($logoBin);
}

// ---- replace placeholders ----
$replacements = [
    '{{logo_src}}' => $logoDataUri,
    '{{service_name}}' => htmlspecialchars($serviceName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    '{{price}}' => htmlspecialchars((string)$price, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    '{{visits}}' => htmlspecialchars((string)$visits, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    '{{freezing}}' => htmlspecialchars((string)$freezing, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    '{{docId}}' => htmlspecialchars($docId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    '{{date}}' => htmlspecialchars($date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    '{{phone}}' => htmlspecialchars($phone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    '{{email}}' => htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    '{{qr_code_data}}' => $qrDataUri,
    '{{voucher_url}}' => htmlspecialchars($publicVoucherUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
];

$html = strtr($html, $replacements);

// ---- render PDF via mPDF ----
$pdfCreated = false;
try {
    $tmpDir = __DIR__ . '/../../tmp';
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
    $mpdf = new \Mpdf\Mpdf(['tempDir' => $tmpDir]);
    $mpdf->SetTitle('Абонемент ' . $docId);
    $mpdf->WriteHTML($html);
    $mpdf->Output($voucherFile, \Mpdf\Output\Destination::FILE);
    $pdfCreated = file_exists($voucherFile);
} catch (Throwable $e) {
    error_log('mPDF error: ' . $e->getMessage());
    $pdfCreated = false;
}

// ---- send email with attachment (if configured) ----
$mailSent = false;
try {
    if ($SMTP_HOST) {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = $SMTP_USER;
        $mail->Password = $SMTP_PASS;
        if ($SMTP_PORT > 0) $mail->Port = $SMTP_PORT;
        $enc = strtolower(getenv('SMTP_ENC') ?: getenv('MAIL_ENC') ?: '');
        if (in_array($enc, ['ssl','smtps'])) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif (in_array($enc, ['tls','starttls'])) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }
        if (!empty(getenv('SMTP_ALLOW_SELF_SIGNED'))) {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];
        }
        $mail->setFrom($SMTP_FROM, $SMTP_FROM_NAME);
        $mail->addAddress($email);
        $mail->Subject = 'Ваш абонемент — ' . ($serviceName ?: 'Покупка');
        $mail->isHTML(true);
        $mail->Body = "<p>Здравствуйте!</p><p>Ваша покупка оформлена. Во вложении — абонемент (документ: <b>{$docId}</b>).</p>";
        if ($pdfCreated && file_exists($voucherFile)) {
            $mail->addAttachment($voucherFile, "abonement_{$docId}.pdf");
        }
        $mail->send();
        $mailSent = true;
    }
} catch (MailException $e) {
    error_log('PHPMailer error: ' . $e->getMessage());
}

// ---- keep simple order log ----
$logLine = sprintf("[%s] doc=%s service=%s phone=%s email=%s fastsales_http=%d mail=%s\n",
    date('Y-m-d H:i:s'), $docId, $serviceId, $phone, $email, $httpCode, $mailSent ? 'ok' : 'no');
@file_put_contents(__DIR__ . '/../../purchase.log', $logLine, FILE_APPEND | LOCK_EX);

// ---- produce HMAC token for secure download ----
$token = hash_hmac('sha256', $docId . '|' . $email, $VOUCHER_SECRET);
$publicVoucherUrlWithToken = $publicVoucherUrl . '&token=' . urlencode($token) . '&email=' . urlencode($email);

// ---- response ----
respond(true, 'Оформлено', ['voucher_url' => $publicVoucherUrlWithToken]);