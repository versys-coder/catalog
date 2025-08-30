<?php
declare(strict_types=1);

// purchase.php — версия с гарантированной отправкой писем в UTF-8 без кракозябр
require __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Mpdf\Mpdf;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
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
    if ($v !== false && $v !== null) return (string)$v;
    if (array_key_exists($key, $_ENV) && $_ENV[$key] !== null) return (string)$_ENV[$key];
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
        } catch (Throwable $e) {}
        foreach ($_ENV as $k => $v) {
            if ($v !== null && $v !== '') putenv("$k=$v");
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

// External notify (optional)
$EXTERNAL_NOTIFY_URL = safe_getenv('EXTERNAL_NOTIFY_URL', '');
$EXTERNAL_NOTIFY_AUTH = safe_getenv('EXTERNAL_NOTIFY_AUTH', '');

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
if ($API_USER_TOKEN !== '') $headers[] = 'usertoken: ' . $API_USER_TOKEN;
if ($API_KEY !== '') $headers[] = 'apikey: ' . $API_KEY;
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

$fastsalesResp = json_decode((string)$response, true);
if (json_last_error() === JSON_ERROR_NONE && isset($fastsalesResp['ok']) && $fastsalesResp['ok'] === false) {
    $fsMessage = $fastsalesResp['message'] ?? 'FastSales returned error';
    respond(false, 'FastSales error: ' . $fsMessage, ['raw' => $fastsalesResp]);
}

// ---- create vouchers dir if missing ----
if (!is_dir($VOUCHERS_DIR)) @mkdir($VOUCHERS_DIR, 0755, true);
$voucherFile = rtrim($VOUCHERS_DIR, '/') . '/' . $docId . '.pdf';

// ---- build public base + voucher public url (voucher.php will validate token) ----
if ($PUBLIC_BASE !== '') {
    $publicBase = $PUBLIC_BASE;
} else {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $publicBase = $scheme . '://' . $host;
}
$publicVoucherUrlBase = rtrim($publicBase, '/') . '/catalog/api-backend/api/voucher.php?doc=' . urlencode($docId);

// ---- prepare HTML template ----
$templatePath = __DIR__ . '/../templates/voucher_mpdf.html';
$serviceEsc = htmlspecialchars($serviceName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

if (is_readable($templatePath)) {
    $htmlTpl = file_get_contents($templatePath);
} else {
    $htmlTpl = "<html><body><h1>Абонемент</h1><p>Документ: {{docId}}</p><p>Услуга: {{service_name}}</p></body></html>";
}

// ---- generate QR ONLY by phone ----
$qrDataUri = '';
try {
    $qrPayload = $normalizedPhone; // Только номер телефона!
    $options = new QROptions([
        'version'=>5,
        'outputType'=>QRCode::OUTPUT_IMAGE_PNG,
        'eccLevel'=>QRCode::ECC_L,
        'imageBase64'=>false
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
    '{{docId}}' => htmlspecialchars($docId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    '{{date}}' => htmlspecialchars($date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    '{{service_name}}' => $serviceEsc,
    '{{price}}' => htmlspecialchars((string)$price, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    '{{visits}}' => htmlspecialchars((string)$visits, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    '{{freezing}}' => htmlspecialchars((string)$freezing, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    '{{phone}}' => htmlspecialchars($phone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    '{{email}}' => htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    '{{qr_code_data}}' => $qrDataUri,
    '{{voucher_url}}' => htmlspecialchars($publicVoucherUrlBase, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
];

$voucherHtml = strtr($htmlTpl, $replacements);

// ---- render PDF via mPDF ----
$pdfCreated = false;
try {
    $tmpDir = safe_getenv('TMP_DIR', __DIR__ . '/../../tmp');
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
    $mpdf = new Mpdf(['tempDir' => $tmpDir]);
    $mpdf->SetTitle('Абонемент ' . $docId);
    $mpdf->WriteHTML($voucherHtml);
    $mpdf->Output($voucherFile, \Mpdf\Output\Destination::FILE);
    $pdfCreated = file_exists($voucherFile);
} catch (Throwable $e) {
    error_log('mPDF error: ' . $e->getMessage());
    log_debug('mPDF error: ' . $e->getMessage());
    $pdfCreated = false;
}

// ---- Save metadata for voucher verification ----
$metaPath = rtrim($VOUCHERS_DIR, '/') . '/' . $docId . '.json';
$metaContent = json_encode([
    'doc' => $docId,
    'email' => $email,
    'phone' => $normalizedPhone,
    'service' => $serviceId,
    'price' => $price,
    'created' => date('c')
], JSON_UNESCAPED_UNICODE);
@file_put_contents($metaPath, $metaContent, LOCK_EX);
@chmod($metaPath, 0644);

// ---- prepare token and public url with token ----
$token = hash_hmac('sha256', $docId . '|' . $email, $VOUCHER_SECRET);
$voucherPublicWithToken = $publicVoucherUrlBase . '&token=' . $token;

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
        $mail->isMail();
    }

    // ОБЯЗАТЕЛЬНО: чтобы не было кракозябр
    $mail->CharSet = 'UTF-8';
    $mail->Encoding = PHPMailer::ENCODING_BASE64;
    $mail->setFrom($SMTP_FROM, $SMTP_FROM_NAME);
    $mail->addAddress($email);
    $mail->Subject = 'Ваш абонемент — ' . htmlspecialchars($serviceName);
    $mail->isHTML(true);
    $mailBody = '<p>Здравствуйте!</p>';
    $mailBody .= '<p>Спасибо за покупку: <strong>' . htmlspecialchars($serviceName) . '</strong></p>';
    $mailBody .= '<p>Документ: <strong>' . htmlspecialchars($docId) . '</strong></p>';
    $mailBody .= '<p>Ссылка для скачивания ваучера: <a href="' . htmlspecialchars($voucherPublicWithToken) . '">скачать ваучер</a></p>';
    $mailBody .= '<p>Если у вас возникают проблемы, свяжитесь с поддержкой.</p>';
    $mail->Body = $mailBody;
    $mail->AltBody = 'Спасибо за покупку. Ссылка на ваучер: ' . $voucherPublicWithToken;

    if ($pdfCreated && is_readable($voucherFile)) {
        $mail->addAttachment($voucherFile, 'abonement-' . $docId . '.pdf');
    }

    $mail->send();
    $mailSent = true;
} catch (MailException $e) {
    error_log('PHPMailer error: ' . $e->getMessage());
    log_debug('PHPMailer error: ' . $e->getMessage());
    $mailSent = false;
}

// ---- log ----
$logLine = sprintf("[%s] doc=%s service=%s phone=%s email=%s fastsales_http=%d mail=%s\n",
    date('Y-m-d H:i:s'), $docId, $serviceId, $normalizedPhone, $email, $httpCode, $mailSent ? 'ok' : 'no');
@file_put_contents(safe_getenv('LOG_PATH', __DIR__ . '/../../purchase.log'), $logLine, FILE_APPEND | LOCK_EX);

// ---- optional: notify external system of purchase ----
if ($EXTERNAL_NOTIFY_URL !== '') {
    $notifyPayload = [
        'doc' => $docId,
        'service_id' => $serviceId,
        'service_name' => $serviceName,
        'phone' => $normalizedPhone,
        'email' => $email,
        'price' => $price,
        'voucher_url' => $voucherPublicWithToken,
        'fastsales_http' => $httpCode,
    ];
    $nc = curl_init($EXTERNAL_NOTIFY_URL);
    curl_setopt($nc, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($nc, CURLOPT_POST, true);
    curl_setopt($nc, CURLOPT_POSTFIELDS, json_encode($notifyPayload, JSON_UNESCAPED_UNICODE));
    $nheaders = ['Content-Type: application/json'];
    if ($EXTERNAL_NOTIFY_AUTH !== '') $nheaders[] = 'Authorization: ' . $EXTERNAL_NOTIFY_AUTH;
    curl_setopt($nc, CURLOPT_HTTPHEADER, $nheaders);
    curl_setopt($nc, CURLOPT_TIMEOUT, 10);
    $notifyResp = curl_exec($nc);
    $notifyCode = curl_getinfo($nc, CURLINFO_HTTP_CODE) ?: 0;
    $notifyErr = curl_error($nc) ?: '';
    curl_close($nc);
    log_debug("External notify to {$EXTERNAL_NOTIFY_URL} HTTP={$notifyCode} err={$notifyErr} resp=" . substr((string)$notifyResp,0,400));
}

// ---- final response ----
respond(true, 'Покупка зарегистрирована', ['voucher_url' => $voucherPublicWithToken, 'doc' => $docId]);