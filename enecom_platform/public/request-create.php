<?php
require_once __DIR__ . '/../config/config.php'; require_login();
$user = current_user(); $error = ''; $success = '';
$services = db()->query('SELECT id,name,category,description FROM services WHERE is_published=1 ORDER BY name')->fetchAll();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serviceId = filter_var($_POST['service_id'] ?? '', FILTER_VALIDATE_INT);
    $subject = trim((string)($_POST['subject'] ?? '')); $description = trim((string)($_POST['description'] ?? ''));
    $priority = (string)($_POST['priority'] ?? 'normal');
    if (!verify_csrf()) $error = 'Your session token expired. Refresh the page and try again.';
    elseif ($subject === '' || mb_strlen($subject) > 180) $error = 'Enter a request title of up to 180 characters.';
    elseif (mb_strlen($description) < 10 || mb_strlen($description) > 10000) $error = 'Describe what you need (10–10,000 characters).';
    elseif (!in_array($priority, ['low','normal','high','urgent'], true)) $error = 'Choose a valid priority.';
    elseif ($serviceId && !in_array((int)$serviceId, array_map(fn($s)=>(int)$s['id'],$services), true)) $error = 'Choose one of the listed services.';
    else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $code = 'E'.date('ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
            $stmt = $pdo->prepare("INSERT INTO service_requests (request_code,customer_id,service_id,subject,description,priority) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$code,(int)$user['id'],$serviceId ?: null,$subject,$description,$priority]);
            $requestId = (int)$pdo->lastInsertId();
            $log = $pdo->prepare('INSERT INTO activity_log (actor_user_id,activity_type,description,related_type,related_id) VALUES (?,?,?,?,?)');
            $log->execute([(int)$user['id'],'request_submitted','New service request '.$code.' submitted by '.$user['name'],'service_request',$requestId]);
            $pdo->commit(); $success = 'Your request has been submitted. Reference: '.$code;
        } catch (Throwable $ex) { if($pdo->inTransaction()) $pdo->rollBack(); $error = 'We could not submit your request. Please try again.'; }
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#0b3975"><link rel="manifest" href="<?= BASE_URL ?>/manifest.webmanifest"><link rel="icon" type="image/png" sizes="192x192" href="<?= BASE_URL ?>/assets/icons/icon-192.png"><link rel="apple-touch-icon" href="<?= BASE_URL ?>/assets/icons/icon-192.png"><title>New service request | Enecom IT Solutions GH</title><link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css"></head><body class="login-page"><main class="login-card request-card"><img class="company-logo" src="<?= BASE_URL ?>/assets/img/enecom-logo.png" alt="Enecom IT Solutions GH logo"><p class="eyebrow">CUSTOMER SUPPORT</p><h1>Submit a service request</h1><p class="muted">Tell us what you need. Our team will review your request and update its status.</p><?php if($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?><?php if($success): ?><div class="success-alert"><?= e($success) ?></div><p><a class="btn primary full" href="<?= BASE_URL ?>/dashboard.php">Return to dashboard</a></p><?php else: ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label for="service_id">Service category</label><select id="service_id" name="service_id"><option value="">Not sure / General enquiry</option><?php foreach($services as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (string)($_POST['service_id'] ?? '') === (string)$s['id']?'selected':'' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select><label for="subject">Request title</label><input id="subject" name="subject" maxlength="180" value="<?= e((string)($_POST['subject'] ?? '')) ?>" placeholder="e.g. Laptop repair or website design" required><label for="description">Describe what you need</label><textarea id="description" name="description" rows="5" maxlength="10000" placeholder="Include the problem, your goal, and the best time to contact you." required><?= e((string)($_POST['description'] ?? '')) ?></textarea><label for="priority">Priority</label><select id="priority" name="priority"><option value="low">Low</option><option value="normal" selected>Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select><button class="btn primary full" type="submit">Submit request →</button></form><?php endif; ?><p class="small muted"><a href="<?= BASE_URL ?>/dashboard.php">← Back to dashboard</a></p></main><script>window.ENE_ACTIVITY_HEARTBEAT={api:<?= json_encode(BASE_URL . '/activity-api.php') ?>,csrf:<?= json_encode(csrf_token()) ?>};</script><script src="<?= BASE_URL ?>/assets/js/activity-heartbeat.js" defer></script></body></html>
