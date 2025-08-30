<?php
declare(strict_types=1);

// purchase.php
// Принимает JSON { service_id, phone, email, ... }
// Генерирует docId, вызывает FastSales API, генерирует PDF-ваучер (mPDF),
// создаёт QR по public URL ваучера, отправляет письмо через SMTP и возвращает JSON { ok: true, voucher_url: ... }

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
use chillerlan\QRCode\{QRCode, QROptions};
use Dotenv\Dotenv;

// ---- helpers ----
function respond(bool $ok, string $message = '', array $extra = []): void {
    http_response_code($ok ? 200 : 400);
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function uuid_v4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function safe_getenv(string $key, string $fallback = ''): string {
    $v = getenv($key);
    return ($v === false) ? $fallback : $v;
}

function log_debug(string $msg): void {
    // non-fatal debug log, append to configured LOG_PATH or default purchase.log
    $logPath = safe_getenv('LOG_PATH', __DIR__ . '/../../purchase.log');
    @file_put_contents($logPath, '[' . date('c') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// ---- load .env from /opt/catalog ----
function load_env(): void {
    $envPath = '/opt/catalog';
    if (is_dir($envPath) && is_readable($envPath . '/.env')) {
        $dotenv = Dotenv::createImmutable($envPath);
        $dotenv->safeLoad();
        // Прокидываем переменные из $_ENV в окружение сервера
        foreach ($_ENV as $k => $v) {
            if ($v !== null && $v !== '') {
                putenv("$k=$v");
            }
        }
    }
}
load_env();

// ---- read request ----
$raw = file_get_contents('php://input') ?: '';
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
$FASTSALE_ENDPOINT = safe_getenv('FASTSALE_ENDPOINT', safe_getenv('FASTSALES_ENDPOINT', ''));
$CLUB_ID = safe_getenv('CLUB_ID', '');
$PUBLIC_BASE = rtrim(safe_getenv('PUBLIC_BASE', ''), '/');
$VOUCHERS_DIR = safe_getenv('VOUCHERS_DIR', __DIR__ . '/../../vouchers');
$VOUCHER_SECRET = safe_getenv('VOUCHER_SECRET', safe_getenv('SECRET', 'please-change-me'));

$API_USER_TOKEN = safe_getenv('API_USER_TOKEN', safe_getenv('API_USER_TOKEN', ''));
$API_KEY = safe_getenv('API_KEY', safe_getenv('API_KEY', ''));

// Basic creds that Postman used in screenshots (BASIC_USER/BASIC_PASS)
$BASIC_USER = safe_getenv('BASIC_USER', '');
$BASIC_PASS = safe_getenv('BASIC_PASS', '');

// SMTP
$SMTP_HOST = safe_getenv('SMTP_HOST', '');
$SMTP_PORT = intval(safe_getenv('SMTP_PORT', '0'));
$SMTP_USER = safe_getenv('SMTP_USER', '');
$SMTP_PASS = safe_getenv('SMTP_PASS', '');
$SMTP_FROM = safe_getenv('SMTP_FROM', 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
$SMTP_FROM_NAME = safe_getenv('SMTP_FROM_NAME', 'DVVS');

// minimal validation
if (!$FASTSALE_ENDPOINT || !$CLUB_ID) {
    respond(false, 'Server misconfigured (FASTSALE_ENDPOINT and CLUB_ID required)');
}

// ---- build docId and date ----
$docId = uuid_v4();
$date = (new DateTime('now', new DateTimeZone('Europe/Moscow')))->format('Y-m-d\TH:i:s');

// ---- prepare request body for FastSales ----
$normalizedPhone = preg_replace('/\D+/', '', $phone);

$requestBody = [
    'club_id' => $CLUB_ID,
    'phone' => $normalizedPhone,
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

// ---- build headers to match Postman (usertoken, apikey, Basic auth) ----
$headers = [
    'Content-Type: application/json',
];

if ($API_USER_TOKEN !== '') {
    // header name in screenshots is "usertoken"
    $headers[] = 'usertoken: ' . $API_USER_TOKEN;
}
if ($API_KEY !== '') {
    // header name in screenshots is "apikey"
    $headers[] = 'apikey: ' . $API_KEY;
}
if ($BASIC_USER !== '' || $BASIC_PASS !== '') {
    $headers[] = 'Authorization: Basic ' . base64_encode($BASIC_USER . ':' . $BASIC_PASS);
}

// ---- execute curl to FastSales ----
$ch = curl_init($FASTSALE_ENDPOINT);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody, JSON_UNESCAPED_UNICODE));
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
// Verify SSL by default; can be disabled via env (not recommended)
$disableSslVerify = (bool)filter_var(safe_getenv('DISABLE_SSL_VERIFY', '0'), FILTER_VALIDATE_BOOLEAN);
if ($disableSslVerify) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
}

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0;
$curlErr = curl_error($ch) ?: '';
curl_close($ch);

// log request/response for debugging (do not keep secrets in logs long-term)
log_debug('FastSales request to ' . $FASTSALE_ENDPOINT . ' headers=' . json_encode($headers));
log_debug('FastSales request body=' . substr(json_encode($requestBody, JSON_UNESCAPED_UNICODE), 0, 2000));
log_debug("FastSales response HTTP={$httpCode} curlErr={$curlErr} resp=" . substr((string)$response, 0, 4000));

if ($response === false || $httpCode >= 400) {
    $msg = $curlErr ?: ('HTTP ' . $httpCode);
    respond(false, 'FastSales error: ' . $msg);
}

// try parse response JSON if any
$fastsalesResp = json_decode((string)$response, true);
if (json_last_error() === JSON_ERROR_NONE && isset($fastsalesResp['ok']) && $fastsalesResp['ok'] === false) {
    $fsMessage = $fastsalesResp['message'] ?? 'FastSales returned error';
    respond(false, 'FastSales error: ' . $fsMessage, ['raw' => $fastsalesResp]);
}

// ---- create vouchers dir if missing ----
if (!is_dir($VOUCHERS_DIR)) {
    @mkdir($VOUCHERS_DIR, 0755, true);
}
$voucherFile = rtrim($VOUCHERS_DIR, '/') . '/' . $docId . '.pdf';

// Build public base if not set
if ($PUBLIC_BASE !== '') {
    $publicBase = $PUBLIC_BASE;
} else {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $publicBase = $scheme . '://' . $host;
}
$publicVoucherUrl = rtrim($publicBase, '/') . '/catalog/api-backend/api/voucher.php?doc=' . urlencode($docId);

// ---- prepare HTML template ----
$serviceName = $data['service_name'] ?? $serviceId;
$price = $data['price'] ?? '';
$visits = $data['visits'] ?? '';
$freezing = $data['freezing'] ?? '';

$templatePath = __DIR__ . '/templates/voucher_mpdf.html';
$html = '';
if (is_readable($templatePath)) {
    $html = file_get_contents($templatePath);
} else {
    $html = "<html><body><h1>Абонемент</h1><p>Документ: {$docId}</p><p>Услуга: {$serviceName}</p></body></html>";
}

// ---- generate QR as PNG data URI (public URL + phone param) ----
$qrDataUri = '';
try {
    $qrPayload = $publicVoucherUrl . '&phone=' . urlencode($normalizedPhone);
    $options = new QROptions([
        'version'    => 5,
        'outputType' => QRCode::OUTPUT_IMAGE_PNG,
        'eccLevel'   => QRCode::ECC_L,
        'scale'      => 5,
    ]);
    $qrBin = (new QRCode($options))->render($qrPayload);
    $qrDataUri = 'data:image/png;base64,' . base64_encode($qrBin);
} catch (Throwable $e) {
    log_debug('QR generation error: ' . $e->getMessage());
}

// ---- embed logo if available ----
$logoDataUri = '';
$logoPathEnv = safe_getenv('VOUCHER_LOGO_PATH', '');
if ($logoPathEnv && is_readable($logoPathEnv)) {
    $logoBin = file_get_contents($logoPathEnv);
    $ext = pathinfo($logoPathEnv, PATHINFO_EXTENSION) ?: 'png';
    $logoDataUri = 'data:image/' . $ext . ';base64,' . base64_encode($logoBin);
}

// ---- replace placeholders in template ----
$replacements = [
    '{{logo_src}}' => $logoDataUri,
    '{{service_name}}' => htmlspecialchars((string)$serviceName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
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
    $tmpDir = safe_getenv('TMP_DIR', __DIR__ . '/../../tmp');
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
    $mpdf = new \Mpdf\Mpdf(['tempDir' => $tmpDir]);
    $mpdf->SetTitle('Абонемент ' . $docId);
    $mpdf->WriteHTML($html);
    $mpdf->Output($voucherFile, \Mpdf\Output\Destination::FILE);
    $pdfCreated = file_exists($voucherFile);
} catch (Throwable $e) {
    log_debug('mPDF error: ' . $e->getMessage());
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
        $enc = strtolower(safe_getenv('SMTP_ENC', safe_getenv('MAIL_ENC', '')));
        if (in_array($enc, ['ssl','smtps'], true)) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif (in_array($enc, ['tls','starttls'], true)) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }
        if ((bool)filter_var(safe_getenv('SMTP_ALLOW_SELF_SIGNED', '0'), FILTER_VALIDATE_BOOLEAN)) {
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
    log_debug('PHPMailer error: ' . $e->getMessage());
}

// ---- simple order log ----
$logLine = sprintf("[%s] doc=%s service=%s phone=%s email=%s fastsales_http=%d mail=%s\n",
    date('Y-m-d H:i:s'), $docId, $serviceId, $normalizedPhone, $email, $httpCode, $mailSent ? 'ok' : 'no');
@file_put_contents(safe_getenv('LOG_PATH', __DIR__ . '/../../purchase.log'), $logLine, FILE_APPEND | LOCK_EX);

// ---- produce HMAC token for secure download ----
$token = hash_hmac('sha256', $docId . '|' . $email, $VOUCHER_SECRET);
$publicVoucherUrlWithToken = $publicVoucherUrl . '&token=' . urlencode($token) . '&email=' . urlencode($email);

// ---- response ----
respond(true, 'Оформлено', ['voucher_url' => $publicVoucherUrlWithToken]);