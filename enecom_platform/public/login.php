<?php
require_once __DIR__ . '/../config/config.php';
if (current_user()) { header('Location: ' . BASE_URL . '/dashboard.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim((string)($_POST['identifier'] ?? ''));
    $email = strtolower($identifier);
    $password = (string)($_POST['password'] ?? '');
    if ($identifier === '' || $password === '') {
        $error = 'Enter your email address or staff username, and password.';
    } else {
        $stmt = db()->prepare('SELECT id, full_name, email, phone, password_hash, role, is_active, username, department, staff_code FROM users WHERE email = ? OR username = ? LIMIT 1');
        $stmt->execute([$email, $identifier]);
        $user = $stmt->fetch();
        if ($user && (int)$user['is_active'] === 1 && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => (int)$user['id'], 'name' => $user['full_name'],
                'email' => $user['email'], 'phone' => $user['phone'], 'role' => $user['role'],
                'username' => $user['username'], 'department' => $user['department'], 'staff_code' => $user['staff_code']
            ];
            db()->prepare('UPDATE users SET last_seen_at=NOW() WHERE id=?')->execute([(int)$user['id']]);
            log_activity((int)$user['id'], 'login_success', $user['full_name'].' signed in', 'user', (int)$user['id']);
            header('Location: ' . BASE_URL . '/dashboard.php');
            exit;
        }
        $error = 'The email/username or password is incorrect, or the account is inactive.';
    }
}
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0b3975"><title>Sign in | Enecom IT Solutions GH</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
<link rel="manifest" href="<?= BASE_URL ?>/manifest.webmanifest"><link rel="icon" type="image/png" sizes="192x192" href="<?= BASE_URL ?>/assets/icons/icon-192.png"><link rel="apple-touch-icon" href="<?= BASE_URL ?>/assets/icons/icon-192.png">
</head><body class="login-page">
<main class="login-card">
  <img class="company-logo" src="<?= BASE_URL ?>/assets/img/enecom-logo.png" alt="Enecom IT Solutions GH logo"><p class="eyebrow">TECHNOLOGY • TRAINING • INNOVATION • SOLUTIONS</p>
  <h1>Welcome back</h1><p class="muted">Sign in to your Enecom account.</p>
  <?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" autocomplete="on">
    <label for="identifier">Email address or staff username</label><input id="identifier" name="identifier" type="text" autocomplete="username" required>
    <label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required>
    <button class="btn primary full" type="submit">Sign in <span>→</span></button>
  </form>
  <p class="small muted">New to Enecom? <a href="<?= BASE_URL ?>/register.php">Create a customer account</a>.</p>
</main>
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
</body></html>
