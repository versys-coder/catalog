<?php
declare(strict_types=1);

// Proxy + загрузка окружения, чтобы пути совпадали с purchase.php
require __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;

function load_env_proxy(): void {
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
load_env_proxy();

require __DIR__ . '/../voucher.php';