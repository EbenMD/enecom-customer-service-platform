<?php
require_once __DIR__ . '/../config/config.php';
if (!current_user()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
header('Location: ' . BASE_URL . '/dashboard.php');
exit;
