<?php
declare(strict_types=1);

function db(): PDO
{
    static $connection;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $localConfigPath = __DIR__ . '/config.local.php';
    $localConfig = is_file($localConfigPath) ? require $localConfigPath : [];
    if (!is_array($localConfig)) {
        throw new RuntimeException('The local database configuration must return a PHP array.');
    }

    $host = getenv('DB_HOST') ?: ($localConfig['host'] ?? '127.0.0.1');
    $name = getenv('DB_NAME') ?: ($localConfig['name'] ?? 'rmt_ministry');
    $user = getenv('DB_USER') ?: ($localConfig['user'] ?? 'root');
    $password = getenv('DB_PASSWORD');
    if ($password === false) {
        $password = $localConfig['password'] ?? '';
    }
    $port = getenv('DB_PORT');
    $charset = 'utf8mb4';
    $portOption = $port !== false && $port !== '' ? ';port=' . (int) $port : '';
    $dsn = "mysql:host={$host}{$portOption};dbname={$name};charset={$charset}";

    $connection = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $connection;
}
