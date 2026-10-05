<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
if (!defined('PUBLIC_ROOT')) {
    define('PUBLIC_ROOT', getenv('RMT_PUBLIC_PATH') ?: APP_ROOT . '/public');
}
if (!defined('PROFILE_UPLOAD_DIR')) {
    define('PROFILE_UPLOAD_DIR', PUBLIC_ROOT . '/uploads');
}
if (!defined('VERIFICATION_UPLOAD_DIR')) {
    define('VERIFICATION_UPLOAD_DIR', APP_ROOT . '/storage/verification');
}

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Africa/Dar_es_Salaam');

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $secure,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/database.php';
