<?php
require_once __DIR__ . '/../config/config.php';
try {
    $count = (int) db()->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
    if ($count > 0) {
        exit('An admin already exists. Delete this file now.');
    }
    $stmt = db()->prepare("INSERT INTO users (full_name,email,phone,password_hash,role) VALUES (?,?,?,?, 'admin')");
    $stmt->execute([
        'Enecom Administrator',
        'admin@enecom.local',
        '',
        password_hash('ChangeMe!2026', PASSWORD_DEFAULT)
    ]);
    echo 'Admin created. Email: admin@enecom.local | Password: ChangeMe!2026. Sign in and change the password, then DELETE this file.';
} catch (Throwable $ex) {
    http_response_code(500);
    echo 'Setup failed. Confirm schema.sql was imported and config.php database settings are correct.';
}
