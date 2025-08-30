<?php
// alfa_register.php
// POST JSON or form: orderNumber, amount (в копейках), optional: returnUrl, currency, language, description
// Возвращает response банка (orderId, formUrl и т.д.)

require __DIR__ . '/alfa_lib.php';

$in = request_input();

$orderNumber = trim($in['orderNumber'] ?? '');
$amount = isset($in['amount']) ? (int)$in['amount'] : null;
$returnUrl = $in['returnUrl'] ?? env_or('DEFAULT_RETURN_URL', '');
$currency = $in['currency'] ?? null;
$language = $in['language'] ?? null;
$description = $in['description'] ?? null;

if ($orderNumber === '' || $amount === null) {
    send_json(['error' => 'missing_parameters', 'message' => 'orderNumber and amount required'], 400);
}

$params = [
    'orderNumber' => $orderNumber,
    'amount' => $amount,
    'returnUrl' => $returnUrl,
];

if ($currency) $params['currency'] = $currency;
if ($language) $params['language'] = $language;
if ($description) $params['description'] = $description;

$result = alfa_post('rest/register.do', $params);

if (!$result['ok']) {
    send_json(['error' => 'request_failed', 'details' => $result], 500);
}

if (isset($result['json'])) {
    send_json($result['json'], $result['httpCode'] ?? 200);
} else {
    send_json(['raw' => $result['raw'] ?? ''], $result['httpCode'] ?? 200);
}