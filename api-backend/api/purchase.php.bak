<?php
declare(strict_types=1);

// purchase.php
// Принимает JSON { service_id, phone, email, ... }
// Генерирует docId, вызывает FastSales API, генерирует PDF-ваучер (mPDF),
// создаёт QR по public URL ваучера (с phone внутри ссылки),
// отправляет письмо через SMTP (PHPMailer) и возвращает JSON { ok: true, voucher_url: ... }

require __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Mpdf\Mpdf;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

// If chillerlan/php-qrcode is available (matches diff usage)
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\QRCode;

// ---- helpers ----
function respond(bool $ok, string $message = '', array $extra = []): void {
    http_response_code($ok ? 200 : 400);
    header('Content-Type: application/json; charset=utf-8');
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
    if ($v !== false && $v !== null) {
        return (string)$v;
    }
    if (array_key_exists($key, $_ENV) && $_ENV[$key] !== null) {
        return (string)$_ENV[$key];
    }
    return $fallback;
}

function log_debug(string $msg): void {
    $logPath = safe_getenv('LOG_PATH', __DIR__ . '/../../purchase.log');
    @file_put_contents($logPath, '[' . date('c') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// ---- load .env from /opt/catalog if present ----
function load_env(): void {
    $envPath = '/opt/catalog';
    if (is_dir($envPath) && is_readable($envPath . '/.env')) {
        try {
            $dotenv = Dotenv::createImmutable($envPath);
            $dotenv->safeLoad();
        } catch (Throwable $e) {
            // ignore
        }
        // ensure getenv() returns values from $_ENV
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

$serviceId = (string)($data['service_id'] ?? $data['serviceId'] ?? $data['id'] ?? '');
$serviceName = (string)($data['service_name'] ?? $data['serviceName'] ?? '');
$phone = (string)($data['phone'] ?? '');
$email = (string)($data['email'] ?? '');
$price = $data['price'] ?? null;
$visits = $data['visits'] ?? null;
$freezing = $data['freezing'] ?? null;

if ($serviceId === '' || $phone === '' || $email === '') {
    respond(false, 'Missing required fields (service_id, phone, email)');
}

// ---- load configuration from env (safe) ----
$FASTSALE_ENDPOINT = safe_getenv('FASTSALE_ENDPOINT', safe_getenv('FASTSALES_ENDPOINT', ''));
$CLUB_ID = safe_getenv('CLUB_ID', '');
$PUBLIC_BASE = rtrim(safe_getenv('PUBLIC_BASE', ''), '/');
$VOUCHERS_DIR = safe_getenv('VOUCHERS_DIR', __DIR__ . '/../../vouchers');
$VOUCHER_SECRET = safe_getenv('VOUCHER_SECRET', safe_getenv('SECRET', 'please-change-me'));
$API_USER_TOKEN = safe_getenv('API_USER_TOKEN', '');
$API_KEY = safe_getenv('API_KEY', '');
$BASIC_USER = safe_getenv('BASIC_USER', '');
$BASIC_PASS = safe_getenv('BASIC_PASS', '');

// SMTP
$SMTP_HOST = safe_getenv('SMTP_HOST', '');
$SMTP_PORT = intval(safe_getenv('SMTP_PORT', '0'));
$SMTP_USER = safe_getenv('SMTP_USER', '');
$SMTP_PASS = safe_getenv('SMTP_PASS', '');
$SMTP_FROM = safe_getenv('SMTP_FROM', 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
$SMTP_FROM_NAME = safe_getenv('SMTP_FROM_NAME', 'DVVS');

if ($FASTSALE_ENDPOINT === '' || $CLUB_ID === '') {
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
        'docId' => $docId,
        'date' => $date,
        'goods' => [
            [
                'code' => $serviceId,
                'name' => $serviceName,
                'price' => $price,
                'qty' => 1,
            ]
        ]
    ]
];

// ---- build headers to match Postman (usertoken, apikey, Basic auth) ----
$headers = ['Content-Type: application/json'];
if ($API_USER_TOKEN !== '') {
    $headers[] = 'usertoken: ' . $API_USER_TOKEN;
}
if ($API_KEY !== '') {
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
    error_log("FastSales error: http={$httpCode} curlErr={$curlErr} resp=" . substr((string)$response, 0, 500));
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

// build public base
if ($PUBLIC_BASE !== '') {
    $publicBase = $PUBLIC_BASE;
} else {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $publicBase = $scheme . '://' . $host;
}
$publicVoucherUrl = rtrim($publicBase, '/') . '/catalog/api-backend/api/voucher.php?doc=' . urlencode($docId);

// ---- prepare HTML template ----
$templatePath = __DIR__ . '/voucher_template.html';
$serviceEsc = htmlspecialchars($serviceName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
if (is_readable($templatePath)) {
    $html = file_get_contents($templatePath);
} else {
    $html = "<html><body><h1>Абонемент</h1><p>Документ: {$docId}</p><p>Услуга: {$serviceEsc}</p></body></html>";
}

// ---- generate QR as PNG data URI (public URL + phone param) ----
$qrDataUri = '';
try {
    $qrPayload = $publicVoucherUrl . '&phone=' . urlencode($normalizedPhone);
    // use chillerlan/php-qrcode if available (matches diff style)
    $options = new QROptions([
        'version'    => 5,
        'outputType' => QRCode::OUTPUT_IMAGE_PNG,
        'eccLevel'   => QRCode::ECC_L,
        'imageBase64' => false,
    ]);
    $qrBin = (new QRCode($options))->render($qrPayload);
    $qrDataUri = 'data:image/png;base64,' . base64_encode($qrBin);
} catch (Throwable $e) {
    $qrDataUri = '';
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
    '{{qr_code_data}}' => $qrDataUri,
    '{{voucher_url}}' => htmlspecialchars($publicVoucherUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
];
$html = strtr($html, $replacements);

// ---- render PDF via mPDF ----
$pdfCreated = false;
try {
    $tmpDir = safe_getenv('TMP_DIR', __DIR__ . '/../../tmp');
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
    $mpdf = new Mpdf(['tempDir' => $tmpDir]);
    $mpdf->SetTitle('Абонемент ' . $docId);
    $mpdf->WriteHTML($html);
    $mpdf->Output($voucherFile, \Mpdf\Output\Destination::FILE);
    $pdfCreated = file_exists($voucherFile);
} catch (Throwable $e) {
    error_log('mPDF error: ' . $e->getMessage());
    log_debug('mPDF error: ' . $e->getMessage());
    $pdfCreated = false;
}

// ---- send email with voucher link (or attach) ----
$mailSent = false;
try {
    $mail = new PHPMailer(true);

    if ($SMTP_HOST !== '') {
        $mail->isSMTP();
        $mail->Host = $SMTP_HOST;
        if ($SMTP_PORT > 0) $mail->Port = $SMTP_PORT;
        if ($SMTP_USER !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = $SMTP_USER;
            $mail->Password = $SMTP_PASS;
        }
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
                    'allow_self_signed' => true,
                ]
            ];
        }
    } else {
        // fallback to mail()
        $mail->isMail();
    }

    $mail->setFrom($SMTP_FROM, $SMTP_FROM_NAME);
    $mail->addAddress($email);
    $mail->Subject = 'Ваш абонемент';
    $bodyHtml = "<p>Спасибо за покупку.</p><p>Ссылка на ваучер: <a href=\"" . htmlspecialchars($publicVoucherUrl) . "\">скачать</a></p>";
    $mail->isHTML(true);
    $mail->Body = $bodyHtml;
    if ($pdfCreated && is_readable($voucherFile)) {
        $mail->addAttachment($voucherFile, basename($voucherFile));
    }
    $mail->send();
    $mailSent = true;
} catch (MailException $e) {
    error_log('PHPMailer error: ' . $e->getMessage());
    log_debug('PHPMailer error: ' . $e->getMessage());
    $mailSent = false;
}

// ---- simple order log ----
$logLine = sprintf("[%s] doc=%s service=%s phone=%s email=%s fastsales_http=%d mail=%s\n",
    date('Y-m-d H:i:s'), $docId, $serviceId, $normalizedPhone, $email, $httpCode, $mailSent ? 'ok' : 'no');
@file_put_contents(safe_getenv('LOG_PATH', __DIR__ . '/../../purchase.log'), $logLine, FILE_APPEND | LOCK_EX);

// ---- produce HMAC token for secure download ----
$token = hash_hmac('sha256', $docId . '|' . $email, $VOUCHER_SECRET);

// ---- final response ----
$voucherPublicWithToken = $publicVoucherUrl . '&token=' . $token;
respond(true, 'Покупка зарегистрирована', ['voucher_url' => $voucherPublicWithToken, 'doc' => $docId]);