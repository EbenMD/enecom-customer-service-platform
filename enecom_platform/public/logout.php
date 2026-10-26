<?php
require_once __DIR__ . '/../config/config.php';
$loggedOutUser = current_user();
if ($loggedOutUser) { try { log_activity((int)$loggedOutUser['id'], 'logout', ($loggedOutUser['name'] ?? 'User').' signed out', 'user', (int)$loggedOutUser['id']); } catch (Throwable $e) { /* Keep logout available if audit logging fails. */ } }
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: ' . BASE_URL . '/login.php');
exit;
