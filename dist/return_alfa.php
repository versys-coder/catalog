<?php
// public/return_alfa.php
// Публичная страница возврата (returnUrl). Вызывает server-side alfa_status.php и выводит результат.
// Положи в catalog/public (или в ту public-директорию, которую используешь)

$statusPath = '/catalog/api-backend/api/alfa_status.php'; // если полный URL нужен, укажи https://yourdomain/catalog/...
$orderId = $_GET['orderId'] ?? $_GET['mdOrder'] ?? null;
$orderNumber = $_GET['orderNumber'] ?? null;

function h($s){ return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Payment return</title></head>
<body>
<h1>Payment return</h1>

<?php if (!$orderId && !$orderNumber): ?>
    <p>No orderId or orderNumber in query.</p>
    <pre><?php echo h($_SERVER['QUERY_STRING']); ?></pre>
<?php else: ?>
    <p>Checking payment for <?php echo $orderId ? 'orderId=' . h($orderId) : 'orderNumber=' . h($orderNumber); ?></p>
    <?php
    $post = $orderId ? ['orderId' => $orderId] : ['orderNumber' => $orderNumber];
    $url = (strpos($statusPath, 'http') === 0) ? $statusPath : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $statusPath);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    $skip = getenv('ALFA_SKIP_SSL_VERIFY') ?: '0';
    if ($skip === '1' || $skip === 'true') {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        echo '<p>Error: ' . h($err) . '</p>';
    } else {
        echo '<h2>Status response</h2><pre>' . h($resp) . '</pre>';
    }
    ?>
<?php endif; ?>

</body>
</html>