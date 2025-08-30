<?php
declare(strict_types=1);

// env-test.php — diagnostics for loading /opt/catalog/.env
ini_set('display_errors', '1');
error_reporting(E_ALL);

echo "<pre>\n";

// path to .env
$envPath = '/opt/catalog';
$envFile = $envPath . '/.env';
echo "Checking .env file: $envFile\n";
echo "file_exists: " . (file_exists($envFile) ? 'yes' : 'no') . "\n";
echo "is_readable: " . (is_readable($envFile) ? 'yes' : 'no') . "\n";
echo "ls -l output:\n";
echo shell_exec("ls -l " . escapeshellarg($envFile) . " 2>&1") . "\n";
echo "stat (owner uid/gid):\n";
$st = @stat($envFile);
if ($st) {
    echo "uid=" . ($st['uid'] ?? '-') . " gid=" . ($st['gid'] ?? '-') . " mode=" . sprintf('%o', $st['mode'] & 0777) . "\n";
} else {
    echo "stat failed\n";
}

// composer autoload
$autoload = __DIR__ . '/../../vendor/autoload.php';
echo "Trying composer autoload: $autoload\n";
if (!file_exists($autoload)) {
    echo "ERROR: autoload not found at $autoload\n";
    echo "</pre>";
    exit;
}
require_once $autoload;
echo "autoload loaded\n";

// Try to use Dotenv
if (class_exists(\Dotenv\Dotenv::class)) {
    try {
        echo "Dotenv available, attempting to load from $envPath\n";
        $dotenv = \Dotenv\Dotenv::createImmutable($envPath);
        $dotenv->safeLoad();
        // Ensure env vars are present to getenv() by calling putenv for each $_ENV
        foreach ($_ENV as $k => $v) {
            if ($v !== null && $v !== '') {
                putenv("$k=$v");
            }
        }
        echo "Dotenv safeLoad() finished\n";
    } catch (Throwable $e) {
        echo "Dotenv exception: " . $e->getMessage() . "\n";
    }
} else {
    echo "Dotenv class not found\n";
}

// Show $_ENV and selected getenv
echo "\n\$_ENV (partial):\n";
$keys = ['FASTSALE_ENDPOINT','CLUB_ID','API_URL','API_USER_TOKEN','API_KEY','VOUCHERS_DIR','VOUCHER_SECRET'];
foreach ($keys as $k) {
    echo "$k => " . (isset($_ENV[$k]) ? $_ENV[$k] : '(not set in $_ENV)') . "\n";
}
echo "\ngetenv() values:\n";
foreach ($keys as $k) {
    echo "$k => " . var_export(getenv($k), true) . "\n";
}

echo "\nEnvironment from phpinfo (selected):\n";
if (function_exists('phpinfo')) {
    // print only relevant sections could be heavy; instead print variables_order
    echo "variables_order = " . ini_get('variables_order') . "\n";
} else {
    echo "phpinfo unavailable\n";
}

echo "\nDone.\n</pre>";