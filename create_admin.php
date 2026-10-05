<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/app/bootstrap.php';

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php create_admin.php \"Full Name\" email@example.com \"StrongPassword\"\n");
    exit(2);
}

[$script, $name, $email, $password] = $argv;
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
    fwrite(STDERR, "Provide a valid email and a password of at least 12 characters.\n");
    exit(2);
}

$statement = db()->prepare("INSERT INTO users (full_name, email, password_hash, date_of_birth, origin, ministry_service, rank, role) VALUES (?, ?, ?, '1970-01-01', 'RMT', 'Leadership', 'Kiongozi', 'admin')");
try {
    $statement->execute([$name, strtolower($email), password_hash($password, PASSWORD_DEFAULT)]);
    fwrite(STDOUT, "Administrator account created for {$email}.\n");
} catch (PDOException $exception) {
    if ($exception->getCode() === '23000') {
        fwrite(STDERR, "That email address is already registered.\n");
        exit(1);
    }
    throw $exception;
}
