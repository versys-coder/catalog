<?php
// Snippet: как использовать шаблон voucher_mpdf.html + mPDF + QR через Google Chart
// Вставьте (или адаптируйте) этот фрагмент в ваш purchase.php после успешного вызова FastSales,
// вместо простого $voucherHtml создания.

use Mpdf\Mpdf;

$config = read_config(); // ваша функция чтения config.json
$publicBase = rtrim($config['public_base'] ?? '', '/');
$templatePath = __DIR__ . '/templates/voucher_mpdf.html';
$logoPath = $config['voucher']['logo_path'] ?? (__DIR__ . '/../assets/logo.png');
$vouchersDir = $config['voucher']['vouchers_dir'] ?? (__DIR__ . '/../../vouchers');

if (!is_dir($vouchersDir)) @mkdir($vouchersDir, 0755, true);
$docId = $docId ?? uuid_v4(); // уже сгенерирован ранее
$voucherFile = rtrim($vouchersDir, '/') . '/' . $docId . '.pdf';
$publicVoucherUrl = ($publicBase ?: '') . '/catalog/api-backend/api/voucher.php?doc=' . urlencode($docId);

// 1) Считаем шаблон
if (!is_readable($templatePath)) {
    // fallback simple html
    $html = "<h1>Абонемент</h1><p>Документ: {$docId}</p>";
} else {
    $html = file_get_contents($templatePath);
}

// 2) Подготовим данные и QR (QR код — ссылка на публичный URL ваучера)
$qrUrl = 'https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' . urlencode($publicVoucherUrl) . '&chld=L|1';
// Получаем картинку QR (функция file_get_contents должна разрешена)
$qrData = '';
$qrContent = @file_get_contents($qrUrl);
if ($qrContent !== false) {
    $qrData = 'data:image/png;base64,' . base64_encode($qrContent);
} else {
    $qrData = ''; // оставить пустым — шаблон покажет пустой img
}

// 3) Logo: mPDF может вставлять картинки по абсолютному пути или по data URI
$logoDataUri = '';
if (is_readable($logoPath)) {
    $logoBin = file_get_contents($logoPath);
    $logoExt = pathinfo($logoPath, PATHINFO_EXTENSION);
    $logoDataUri = 'data:image/' . ($logoExt ?: 'png') . ';base64,' . base64_encode($logoBin);
} else {
    // попробовать public URL (если logo_path содержит URL)
    $maybeUrl = $logoPath;
    if (filter_var($maybeUrl, FILTER_VALIDATE_URL)) {
        $logoBin = @file_get_contents($maybeUrl);
        if ($logoBin !== false) $logoDataUri = 'data:image/png;base64,' . base64_encode($logoBin);
    }
}

// 4) Заменяем плейсхолдеры в шаблоне
$replacements = [
    '{{logo_src}}' => $logoDataUri ?: '',
    '{{service_name}}' => htmlspecialchars($serviceName ?? $serviceId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    '{{price}}' => htmlspecialchars((string)($price ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    '{{visits}}' => htmlspecialchars((string)($visits ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    '{{freezing}}' => htmlspecialchars((string)($freezing ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    '{{docId}}' => htmlspecialchars($docId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    '{{date}}' => htmlspecialchars($date ?? (new DateTime())->format('Y-m-d H:i:s'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    '{{phone}}' => htmlspecialchars($phone ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    '{{email}}' => htmlspecialchars($email ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    '{{qr_code_data}}' => $qrData,
    '{{voucher_url}}' => htmlspecialchars($publicVoucherUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
];

$html = strtr($html, $replacements);

// 5) Генерация PDF через mPDF
try {
    $mpdf = new Mpdf(['tempDir' => __DIR__ . '/../../tmp']); // убедитесь что tmp доступен
    $mpdf->SetTitle('Абонемент ' . $docId);
    $mpdf->WriteHTML($html);
    $mpdf->Output($voucherFile, \Mpdf\Output\Destination::FILE);
    $pdfCreated = file_exists($voucherFile);
} catch (\Throwable $e) {
    error_log('mPDF error: ' . $e->getMessage());
    $pdfCreated = false;
}

// После этого можно прикрепить $voucherFile к письму PHPMailer как в вашем purchase.php