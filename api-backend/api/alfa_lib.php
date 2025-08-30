<?php
// alfa_lib.php
// Общие функции для работы с Alfa API.
// Положи рядом с purchase.php/voucher.php в catalog/api-backend/api

function env_or($name, $default = null) {
    $v = getenv($name);
    return ($v === false) ? $default : $v;
}

function alfa_base_url() {
    // sandbox: https://alfa.rbsuat.com/payment
    // prod: https://alfa.rbsuat.com/payment  или https://payment.alfabank.ru/payment
    return rtrim(env_or('ALFA_BASE_URL', 'https://alfa.rbsuat.com/payment'), '/');
}

function alfa_auth_params() {
    $token = env_or('ALFA_TOKEN', '');
    if (!empty($token)) {
        return ['token' => $token];
    }
    return [
        'userName' => env_or('ALFA_USER', ''),
        'password' => env_or('ALFA_PASS', '')
    ];
}

function alfa_post($endpoint, array $params = []) {
    $url = alfa_base_url() . '/' . ltrim($endpoint, '/');
    $params = array_merge(alfa_auth_params(), $params);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

    $skip = env_or('ALFA_SKIP_SSL_VERIFY', '0');
    if ($skip === '1' || $skip === 'true') {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    } else {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    }

    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'error' => $err, 'httpCode' => $httpCode];
    }

    $json = json_decode($resp, true);
    if ($json === null) {
        return ['ok' => true, 'raw' => $resp, 'httpCode' => $httpCode];
    }
    return ['ok' => true, 'json' => $json, 'httpCode' => $httpCode];
}

function request_input() {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $j = json_decode($raw, true);
        return is_array($j) ? $j : [];
    }
    return $_POST ?: [];
}

function send_json($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}