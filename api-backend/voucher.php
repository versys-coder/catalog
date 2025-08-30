<?php
declare(strict_types=1);

// voucher.php
// GET params: doc, token
// Ожидает рядом с <doc>.pdf мета-файл <doc>.json содержащий {"email": "...", ...}
// Валидирует token = HMAC(doc|email, VOUCHER_SECRET) и отдаёт PDF.

function safe_getenv(string $k, string $fallback = ''): string {
    $v = getenv($k);
    if ($v !== false && $v !== null) return (string)$v;
    if (array_key_exists($k, $_ENV) && $_ENV[$k] !== null) return (string)$_ENV[$k];
    return $fallback;
}

$VOUCHERS_DIR = rtrim(safe_getenv('VOUCHERS_DIR', __DIR__ . '/../../vouchers'), '/');
$VOUCHER_SECRET = safe_getenv('VOUCHER_SECRET', safe_getenv('SECRET', 'please-change-me'));

$doc = $_GET['doc'] ?? '';
$token = $_GET['token'] ?? '';

if ($doc === '' || $token === '') {
    http_response_code(400);
    echo 'Missing doc or token';
    exit;
}

$docSafe = basename($doc);
$voucherFile = $VOUCHERS_DIR . '/' . $docSafe . '.pdf';
$metaFile = $VOUCHERS_DIR . '/' . $docSafe . '.json';

if (!is_readable($metaFile)) {
    http_response_code(404);
    echo 'Voucher metadata not found';
    exit;
}

$meta = json_decode(file_get_contents($metaFile), true);
if (!is_array($meta) || empty($meta['email'])) {
    http_response_code(404);
    echo 'Voucher metadata invalid';
    exit;
}

$email = (string)$meta['email'];
$expected = hash_hmac('sha256', $docSafe . '|' . $email, $VOUCHER_SECRET);
if (!hash_equals($expected, $token)) {
    http_response_code(403);
    echo 'Invalid token';
    exit;
}

if (!is_readable($voucherFile)) {
    http_response_code(404);
    echo 'Voucher not found';
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename($voucherFile) . '"');
header('Content-Length: ' . filesize($voucherFile));
readfile($voucherFile);
exit;