<?php
declare(strict_types=1);

/**
 * Возврат с Альфа-Банка.
 * Проверяем статус оплаты, при успехе вызываем purchase.php.
 * Затем сохраняем результат в localStorage и возвращаем пользователя на back URL.
 */

require __DIR__ . '/../api-backend/vendor/autoload.php';

use Dotenv\Dotenv;

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

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

function log_debug(string $msg): void {
    $path = getenv('LOG_PATH') ?: (__DIR__ . '/../purchase.log');
    @file_put_contents($path, '[' . date('c') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

load_alfa_env();

$orderId     = (string)($_GET['orderId'] ?? $_POST['orderId'] ?? '');
$orderNumber = (string)($_GET['orderNumber'] ?? $_POST['orderNumber'] ?? '');
$backUrl     = (string)($_GET['back'] ?? '');
if ($backUrl !== '') $backUrl = urldecode($backUrl);

// Если back не передали — по умолчанию вернём в каталог
if ($backUrl === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $backUrl = $scheme . '://' . $host . '/catalog/dist/';
}

$ALFA_BASE_URL        = rtrim(getenv('ALFA_BASE_URL') ?: 'https://alfa.rbsuat.com/payment', '/');
$ALFA_USER            = getenv('ALFA_USER') ?: '';
$ALFA_PASS            = getenv('ALFA_PASS') ?: '';
$ALFA_TOKEN           = getenv('ALFA_TOKEN') ?: '';
$ALFA_SKIP_SSL_VERIFY = (getenv('ALFA_SKIP_SSL_VERIFY') === '1' || strtolower((string)getenv('ALFA_SKIP_SSL_VERIFY')) === 'true');

$statusUrl = $ALFA_BASE_URL . '/rest/getOrderStatusExtended.do';

$fields = ['language' => 'ru'];
if ($orderId !== '') $fields['orderId'] = $orderId;
if ($orderNumber !== '') $fields['orderNumber'] = $orderNumber;
if ($ALFA_TOKEN !== '') { $fields['token'] = $ALFA_TOKEN; } else { $fields['userName'] = $ALFA_USER; $fields['password'] = $ALFA_PASS; }

$ch = curl_init($statusUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
if ($ALFA_SKIP_SSL_VERIFY) { curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0); }
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0;
$err  = curl_error($ch) ?: '';
curl_close($ch);

log_debug('ALFA getOrderStatusExtended.do POST=' . http_build_query($fields));
log_debug("ALFA getOrderStatusExtended.do HTTP={$http} err={$err} resp=" . substr((string)$resp, 0, 2000));

$j = json_decode((string)$resp, true);
$ok = is_array($j) && (string)($j['errorCode'] ?? '0') === '0';
$orderStatus = $j['orderStatus'] ?? null; // 2 — оплачено
$success = $ok && (string)$orderStatus === '2';

$ordersDir = '/opt/catalog/tmp/orders';
$meta = null;
if ($orderId !== '' && is_readable($ordersDir . '/' . $orderId . '.json')) {
    $meta = json_decode((string)file_get_contents($ordersDir . '/' . $orderId . '.json'), true);
} elseif ($orderNumber !== '' && is_readable($ordersDir . '/' . $orderNumber . '.json')) {
    $meta = json_decode((string)file_get_contents($ordersDir . '/' . $orderNumber . '.json'), true);
}

// Если оплата успешная — инициируем генерацию ваучера
$purchaseResult = null;
if ($success && is_array($meta)) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $purchaseUrl = $scheme . '://' . $host . '/catalog/api-backend/api/purchase.php';

    $body = [
        'service_id'   => $meta['service_id']   ?? '',
        'service_name' => $meta['service_name'] ?? '',
        'price'        => $meta['price_rub']    ?? 0,
        'visits'       => $meta['visits']       ?? null,
        'freezing'     => $meta['freezing']     ?? null,
        'phone'        => $meta['phone']        ?? '70000000000',
        'email'        => $meta['email']        ?? 'noreply@example.com',
    ];

    $pch = curl_init($purchaseUrl);
    curl_setopt($pch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($pch, CURLOPT_POST, true);
    curl_setopt($pch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    curl_setopt($pch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $skip = getenv('ALFA_SKIP_SSL_VERIFY');
    if ($skip === '1' || strtolower((string)$skip) === 'true') { curl_setopt($pch, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($pch, CURLOPT_SSL_VERIFYHOST, 0); }
    curl_setopt($pch, CURLOPT_TIMEOUT, 30);
    $presp = curl_exec($pch);
    $phttp = curl_getinfo($pch, CURLINFO_HTTP_CODE) ?: 0;
    $perr  = curl_error($pch) ?: '';
    curl_close($pch);

    log_debug("CALL purchase.php HTTP={$phttp} err={$perr} resp=" . substr((string)$presp, 0, 2000));
    $purchaseResult = json_decode((string)$presp, true);
}

$voucherUrl = is_array($purchaseResult) ? ($purchaseResult['voucher_url'] ?? '') : '';
$msg = is_array($purchaseResult) ? ($purchaseResult['message'] ?? ($success ? 'Оплата подтверждена' : 'Оплата не подтверждена')) : ($success ? 'Оплата подтверждена' : 'Оплата не подтверждена');
$okPayload = (bool)$success && (bool)($purchaseResult['ok'] ?? true);

// Страница ставит localStorage и уводит обратно на backUrl
$payload = [
    'ok'          => $okPayload,
    'voucher_url' => $voucherUrl,
    'message'     => $msg,
    'orderId'     => $orderId ?: $orderNumber,
];

?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Возврат после оплаты</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:24px;color:#111}
    .muted{color:#6b7280}
    .ok{color:#16a34a;font-weight:700}
    .err{color:#b91c1c;font-weight:700}
    a.btn{display:inline-block;margin-top:12px;padding:10px 16px;border-radius:8px;background:#7c3aed;color:#fff;text-decoration:none}
  </style>
</head>
<body>
  <div>
    <div class="<?php echo $okPayload ? 'ok':'err'; ?>">
      <?php echo $okPayload ? 'Оплата подтверждена' : 'Оплата не подтверждена'; ?>
    </div>
    <?php if ($voucherUrl): ?>
      <div><a class="btn" href="<?php echo h($voucherUrl) ?>" target="_blank" rel="noopener">Скачать ваучер</a></div>
    <?php endif; ?>
    <p class="muted">Сейчас вы будете автоматически возвращены на сайт.</p>
    <p><a class="btn" href="<?php echo h($backUrl) ?>">Вернуться</a></p>
  </div>
  <script>
    (function(){
      try{
        localStorage.setItem('alfaPaymentResult', JSON.stringify(<?php echo json_encode($payload, JSON_UNESCAPED_UNICODE); ?>));
        // Удалим "ожидаемую оплату", если сохраняли
        localStorage.removeItem('alfaPending');
      }catch(e){}
      // Возвращаемся на исходную страницу
      try{ window.location.replace(<?php echo json_encode($backUrl); ?>); }catch(e){ window.location.href = <?php echo json_encode($backUrl); ?>; }
    })();
  </script>
</body>
</html>