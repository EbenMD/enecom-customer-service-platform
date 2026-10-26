<?php
require_once __DIR__ . '/../config/config.php';
if (current_user()) { header('Location: ' . BASE_URL . '/dashboard.php'); exit; }
$error = ''; $old = ['full_name'=>'','email'=>'','phone'=>''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = ['full_name'=>trim((string)($_POST['full_name'] ?? '')), 'email'=>strtolower(trim((string)($_POST['email'] ?? ''))), 'phone'=>trim((string)($_POST['phone'] ?? ''))];
    $password = (string)($_POST['password'] ?? ''); $confirm = (string)($_POST['confirm_password'] ?? '');
    if (!verify_csrf()) $error = 'Your session token expired. Refresh the page and try again.';
    elseif (mb_strlen($old['full_name']) < 2 || mb_strlen($old['full_name']) > 150) $error = 'Enter your full name (2–150 characters).';
    elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $error = 'Enter a valid email address.';
    elseif ($old['phone'] !== '' && mb_strlen($old['phone']) > 40) $error = 'Phone number is too long.';
    elseif (strlen($password) < 10) $error = 'Use a password with at least 10 characters.';
    elseif ($password !== $confirm) $error = 'The passwords do not match.';
    else {
        try {
            $pdo = db();
            $stmt = $pdo->prepare("INSERT INTO users (full_name,email,phone,password_hash,role) VALUES (?,?,?,?, 'customer')");
            $stmt->execute([$old['full_name'],$old['email'],$old['phone'] ?: null,password_hash($password, PASSWORD_DEFAULT)]);
            $id = (int)$pdo->lastInsertId();
            $_SESSION['user'] = ['id'=>$id,'name'=>$old['full_name'],'email'=>$old['email'],'phone'=>$old['phone'],'role'=>'customer'];
            session_regenerate_id(true);
            log_activity($id, 'customer_registered', 'New customer registered: '.$old['full_name'], 'user', $id);
            header('Location: '.BASE_URL.'/dashboard.php?welcome=1'); exit;
        } catch (PDOException $ex) {
            $error = $ex->getCode() === '23000' ? 'An account with this email already exists. Please sign in instead.' : 'Registration could not be completed. Please try again.';
        }
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#0b3975"><link rel="manifest" href="<?= BASE_URL ?>/manifest.webmanifest"><link rel="icon" type="image/png" sizes="192x192" href="<?= BASE_URL ?>/assets/icons/icon-192.png"><link rel="apple-touch-icon" href="<?= BASE_URL ?>/assets/icons/icon-192.png"><title>Create customer account | Enecom IT Solutions GH</title><link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css"></head><body class="login-page"><main class="login-card"><img class="company-logo" src="<?= BASE_URL ?>/assets/img/enecom-logo.png" alt="Enecom IT Solutions GH logo"><p class="eyebrow">TECHNOLOGY • TRAINING • INNOVATION • SOLUTIONS</p><h1>Create your account</h1><p class="muted">Register to view our services and track your support requests.</p><?php if($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?><form method="post" autocomplete="on"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label for="full_name">Full name</label><input id="full_name" name="full_name" maxlength="150" autocomplete="name" value="<?= e($old['full_name']) ?>" required><label for="email">Email address</label><input id="email" name="email" type="email" maxlength="190" autocomplete="email" value="<?= e($old['email']) ?>" required><label for="phone">Phone / WhatsApp number</label><input id="phone" name="phone" type="tel" maxlength="40" autocomplete="tel" value="<?= e($old['phone']) ?>" placeholder="e.g. 0500111663"><label for="password">Password (at least 10 characters)</label><input id="password" name="password" type="password" minlength="10" autocomplete="new-password" required><label for="confirm_password">Confirm password</label><input id="confirm_password" name="confirm_password" type="password" minlength="10" autocomplete="new-password" required><button class="btn primary full" type="submit">Create account →</button></form><p class="small muted">Already registered? <a href="<?= BASE_URL ?>/login.php">Sign in</a></p></main></body></html>
