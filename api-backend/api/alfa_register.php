<?php
declare(strict_types=1);

// Регистрация заказа в Альфа-Банке и выдача formUrl
// POST JSON: { service_id, service_name, price, phone, email, visits?, freezing?, back_url? }
// Ответ: { ok, message, formUrl, orderId, orderNumber }

require __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;

header('Content-Type: application/json; charset=utf-8');

function json_response(bool $ok, string $message, array $extra = []): void {
    http_response_code($ok ? 200 : 400);
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function log_debug(string $msg): void {
    $path = getenv('LOG_PATH') ?: (__DIR__ . '/../../purchase.log');
    @file_put_contents($path, '[' . date('c') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function load_alfa_env(): void {
    $envPath = '/opt/catalog';
    if (is_dir($envPath) && is_readable($envPath . '/alfa.env')) {
        try {
            $dotenv = Dotenv::createImmutable($envPath, 'alfa.env');
            $dotenv->safeLoad();
        } catch (Throwable $e) {}
        foreach ($_ENV as $k => $v) {
            if ($v !== null && $v !== '') putenv("$k=$v");
        }
    }
}

/** Приводит рубли к целому int (6480, "6 480", "6480,00" -> 6480). */
function normalize_rub($v): int {
    $s = (string)$v;
    $s = str_replace(["\xC2\xA0", "\xA0", ' '], '', $s);
    $s = str_replace(',', '.', $s);
    if ($s === '' || !preg_match('/^-?\d+(\.\d+)?$/', $s)) return 0;
    return (int)round((float)$s, 0);
}

function ensure_dir(string $dir): void {
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
}

load_alfa_env();

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    json_response(false, 'Invalid JSON');
}

$serviceId   = (string)($data['service_id'] ?? $data['serviceId'] ?? $data['id'] ?? '');
$serviceName = (string)($data['service_name'] ?? $data['serviceName'] ?? '');
$priceInput  = $data['price'] ?? null;
$phone       = (string)($data['phone'] ?? '');
$email       = (string)($data['email'] ?? '');
$visits      = $data['visits'] ?? null;
$freezing    = $data['freezing'] ?? null;
$backUrlIn   = (string)($data['back_url'] ?? '');

if ($serviceId === '' || $serviceName === '' || $priceInput === null || $phone === '' || $email === '') {
    json_response(false, 'Missing required fields (service_id, service_name, price, phone, email)');
}

// Конфиг Альфы
$ALFA_BASE_URL        = rtrim(getenv('ALFA_BASE_URL') ?: 'https://alfa.rbsuat.com/payment', '/');
$ALFA_USER            = getenv('ALFA_USER') ?: '';
$ALFA_PASS            = getenv('ALFA_PASS') ?: '';
$ALFA_TOKEN           = getenv('ALFA_TOKEN') ?: '';
$ALFA_CLIENT_ID       = getenv('ALFA_CLIENT_ID') ?: '';
$ALFA_SKIP_SSL_VERIFY = (getenv('ALFA_SKIP_SSL_VERIFY') === '1' || strtolower((string)getenv('ALFA_SKIP_SSL_VERIFY')) === 'true');

// Базовый returnUrl
$DEFAULT_RETURN_URL  = getenv('DEFAULT_RETURN_URL') ?: '';
if ($DEFAULT_RETURN_URL === '' || stripos($DEFAULT_RETURN_URL, 'yourdomain') !== false) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $DEFAULT_RETURN_URL = $scheme . '://' . $host . '/catalog/public/return_alfa.php';
}

// Куда возвращаться в браузере после оплаты
$backUrl = $backUrlIn;
if ($backUrl === '') {
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref !== '') $backUrl = $ref;
}
if ($backUrl === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $backUrl = $scheme . '://' . $host . '/catalog/dist/';
}

// Добавим back параметр в returnUrl
$returnUrl = $DEFAULT_RETURN_URL . (strpos($DEFAULT_RETURN_URL, '?') === false ? '?' : '&') . 'back=' . rawurlencode($backUrl);

// Проверка наличия кредов
$hasToken    = ($ALFA_TOKEN !== '');
$hasUserPass = ($ALFA_USER !== '' && $ALFA_PASS !== '');
if (!$hasToken && !$hasUserPass) {
    $hint = 'Server misconfigured: ALFA_TOKEN or ALFA_USER/ALFA_PASS not set. '
          . 'Check /opt/catalog/alfa.env. Also ensure readable by PHP: chgrp www-data /opt/catalog/alfa.env && chmod 640 /opt/catalog/alfa.env';
    log_debug($hint);
    json_response(false, $hint);
}

// Нормализуем цену
$amountRub = normalize_rub($priceInput);
if ($amountRub <= 0) {
    json_response(false, 'Invalid amount');
}
$amountKop = $amountRub * 100;

// Уникальный orderNumber
$orderNumber = 'dvvs-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);

// Поля запроса register.do
$fields = [
    'amount'      => $amountKop,
    'currency'    => '810',
    'language'    => 'ru',
    'orderNumber' => $orderNumber,
    'returnUrl'   => $returnUrl,
];
if ($hasToken) {
    $fields['token'] = $ALFA_TOKEN;
} else {
    $fields['userName'] = $ALFA_USER;
    $fields['password'] = $ALFA_PASS;
}
if ($ALFA_CLIENT_ID !== '') $fields['clientId'] = $ALFA_CLIENT_ID;

$url = $ALFA_BASE_URL . '/rest/register.do';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
if ($ALFA_SKIP_SSL_VERIFY) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
}
curl_setopt($ch, CURLOPT_TIMEOUT, 20);

$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0;
$err  = curl_error($ch) ?: '';
curl_close($ch);

log_debug('ALFA register.do POST=' . substr(http_build_query($fields), 0, 1000));
log_debug("ALFA register.do HTTP={$http} err={$err} resp=" . substr((string)$resp, 0, 2000));

if ($resp === false || $http >= 400) {
    json_response(false, 'Alfa register error: ' . ($err ?: ('HTTP ' . $http)));
}

$j = json_decode((string)$resp, true);
if (!is_array($j)) {
    json_response(false, 'Alfa register invalid response');
}
if (isset($j['errorCode']) && (string)$j['errorCode'] !== '0') {
    $msg = $j['errorMessage'] ?? 'Alfa error';
    json_response(false, 'Alfa register failed: ' . $msg, ['raw' => $j]);
}

$orderId = (string)($j['orderId'] ?? '');
$formUrl = (string)($j['formUrl'] ?? '');
if ($orderId === '' || $formUrl === '') {
    json_response(false, 'Alfa register response incomplete', ['raw' => $j]);
}

// Сохраняем мета заказа, чтобы return_alfa.php знал, что покупать
$ordersDir = '/opt/catalog/tmp/orders';
ensure_dir($ordersDir);

$meta = [
    'orderId'      => $orderId,
    'orderNumber'  => $orderNumber,
    'service_id'   => $serviceId,
    'service_name' => $serviceName,
    'price_rub'    => $amountRub,
    'phone'        => $phone,
    'email'        => $email,
    'visits'       => $visits,
    'freezing'     => $freezing,
    'created'      => date('c'),
    'back_url'     => $backUrl,
];
@file_put_contents($ordersDir . '/' . $orderId . '.json', json_encode($meta, JSON_UNESCAPED_UNICODE), LOCK_EX);
@file_put_contents($ordersDir . '/' . $orderNumber . '.json', json_encode($meta, JSON_UNESCAPED_UNICODE), LOCK_EX);

json_response(true, 'Alfa order registered', [
    'formUrl'      => $formUrl,
    'orderId'      => $orderId,
    'orderNumber'  => $orderNumber,
]);