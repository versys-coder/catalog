<?php
// alfa_status.php
// POST JSON or form: orderId (mdOrder) или orderNumber
// Возвращает getOrderStatusExtended.do ответ

require __DIR__ . '/alfa_lib.php';

$in = request_input();

$orderId = trim($in['orderId'] ?? '');
$orderNumber = trim($in['orderNumber'] ?? '');

if ($orderId === '' && $orderNumber === '') {
    send_json(['error' => 'missing_parameters', 'message' => 'orderId or orderNumber required'], 400);
}

$params = [];
if ($orderId !== '') $params['orderId'] = $orderId;
else $params['orderNumber'] = $orderNumber;

$result = alfa_post('rest/getOrderStatusExtended.do', $params);

if (!$result['ok']) {
    send_json(['error' => 'request_failed', 'details' => $result], 500);
}

if (isset($result['json'])) {
    send_json($result['json'], $result['httpCode'] ?? 200);
} else {
    send_json(['raw' => $result['raw'] ?? ''], $result['httpCode'] ?? 200);
}