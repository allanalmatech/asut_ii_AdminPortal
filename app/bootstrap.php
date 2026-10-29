<?php
declare(strict_types=1);
namespace Asut;

ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('UTC');
require_once __DIR__ . '/Core.php';
require_once __DIR__ . '/AdminStore.php';
require_once __DIR__ . '/Vouchers.php';
require_once __DIR__ . '/Patches.php';
require_once __DIR__ . '/ClientControl.php';
require_once __DIR__ . '/Product.php';
require_once __DIR__ . '/Store.php';
require_once __DIR__ . '/Installer.php';

function configuration(): array {
    $path = getenv('ASUT_PHP_CONFIG') ?: dirname(__DIR__) . '/config/local.php';
    if (!is_file($path)) throw new ApiError(503, 'setup_required', 'Configure the PHP portal and run setup.php before signing in.');
    $config = require $path;
    if (!is_array($config)) throw new \RuntimeException('Invalid portal configuration.');
    return $config;
}
