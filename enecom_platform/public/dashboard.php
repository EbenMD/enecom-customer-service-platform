<?php
require_once __DIR__ . '/../config/config.php';
require_login();
$user = current_user();
$isAdmin = in_array($user['role'], ['admin','staff'], true);
$pdo = db();
$isSuperAdmin = (($user['role'] ?? '') === 'admin');
$staffCreateError = '';
if ($isSuperAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_worker'])) {
    $first = trim((string)($_POST['first_name'] ?? ''));
    $last = trim((string)($_POST['last_name'] ?? ''));
    $phone = trim((string)($_POST['worker_phone'] ?? ''));
    $department = trim((string)($_POST['department'] ?? ''));
    $deptCode = strtoupper(trim((string)($_POST['department_code'] ?? '')));
    $emailInput = strtolower(trim((string)($_POST['worker_email'] ?? '')));
    $digits = preg_replace('/\D+/', '', $phone);
    $deptOptions = ['SD'=>'Software Developer','IT'=>'IT Support','NW'=>'Networking','DA'=>'Data Analytics','AI'=>'AI & Automation','TR'=>'Training','DM'=>'Digital Marketing','AD'=>'Administration'];
    if (!verify_csrf()) $staffCreateError = 'Your security token expired. Refresh the page and try again.';
    elseif ($first === '' || $last === '' || mb_strlen($first) > 80 || mb_strlen($last) > 80) $staffCreateError = 'Enter a valid first name and last name.';
    elseif (strlen((string)$digits) < 4 || strlen((string)$digits) > 15) $staffCreateError = 'Enter a valid contact number with at least four digits.';
    elseif (!isset($deptOptions[$deptCode]) || $department !== $deptOptions[$deptCode]) $staffCreateError = 'Select a valid department.';
    elseif ($emailInput !== '' && !filter_var($emailInput, FILTER_VALIDATE_EMAIL)) $staffCreateError = 'Enter a valid email address, or leave it blank.';
    else {
        $firstTag = strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $first), 0, 4));
        if ($firstTag === '') $firstTag = 'USER';
        $lastFour = substr((string)$digits, -4);
        $staffCode = 'EITS/'.$deptCode.'-'.$firstTag.'-'.$lastFour;
        $username = $staffCode;
        $internalEmail = strtolower(str_replace(['/', '-'], ['-', '.'], $staffCode)).'@staff.enecom.local';
        $email = $emailInput !== '' ? $emailInput : $internalEmail;
        $check = $pdo->prepare('SELECT id FROM users WHERE username=? OR staff_code=? OR email=? LIMIT 1');
        $check->execute([$username, $staffCode, $email]);
        if ($check->fetch()) $staffCreateError = 'This generated staff code or email already exists. Check the contact number or enter a different work email.';
        else {
            $temporaryPassword = 'En'.bin2hex(random_bytes(5)).'!';
            try {
                $ins = $pdo->prepare("INSERT INTO users (full_name,email,username,phone,password_hash,role,department,staff_code,is_active) VALUES (?,?,?,?,?,'staff',?,?,1)");
                $ins->execute([trim($first.' '.$last),$email,$username,$phone,password_hash($temporaryPassword,PASSWORD_DEFAULT),$department,$staffCode]);
                $newId = (int)$pdo->lastInsertId();
                log_activity((int)$user['id'],'staff_account_created','Created staff account '.$staffCode.' for '.trim($first.' '.$last),'user',$newId);
                $_SESSION['new_staff_credentials'] = ['name'=>trim($first.' '.$last),'username'=>$username,'staff_code'=>$staffCode,'email'=>$email,'phone'=>$phone,'department'=>$department,'password'=>$temporaryPassword];
                header('Location: '.BASE_URL.'/dashboard.php?worker_created=1#staff'); exit;
            } catch (PDOException $ex) {
                $staffCreateError = 'Could not create the account. Check whether the username, staff code, or email is already in use and confirm the database migration was run.';
            }
        }
    }
}
$createdWorkerCredentials = null;
if ($isSuperAdmin && isset($_SESSION['new_staff_credentials'])) { $createdWorkerCredentials = $_SESSION['new_staff_credentials']; unset($_SESSION['new_staff_credentials']); }

function create_notification(int $recipientId, string $title, string $message, string $url, string $type = 'general', ?int $requestId = null): void {
    $stmt = db()->prepare('INSERT INTO user_notifications (recipient_user_id, title, message, target_url, notification_type, related_request_id) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$recipientId, mb_substr($title, 0, 160), mb_substr($message, 0, 500), $url, $type, $requestId]);
}
$paymentReviewError = '';
$ticketUpdateError = '';
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_request_id'])) {
    $requestId = filter_var($_POST['update_request_id'], FILTER_VALIDATE_INT);
    $assigneeRaw = (string)($_POST['assigned_to'] ?? '');
    $assignee = $assigneeRaw === '' ? null : filter_var($assigneeRaw, FILTER_VALIDATE_INT);
    $requestStatus = (string)($_POST['request_status'] ?? 'new');
    $allowedStatuses = ['new', 'in_progress', 'completed']; // Displayed as Pending, Assigned, Completed.
    if (!verify_csrf()) $ticketUpdateError = 'Your session token expired. Refresh and try again.';
    elseif (!$requestId || !in_array($requestStatus, $allowedStatuses, true) || ($assigneeRaw !== '' && !$assignee)) $ticketUpdateError = 'Choose a valid worker and status.';
    elseif ($requestStatus === 'in_progress' && !$assignee) $ticketUpdateError = 'Please assign a worker before setting the request to Assigned.';
    else {
        if ($assignee !== null) {
            $workerCheck = $pdo->prepare("SELECT id FROM users WHERE id=? AND role IN ('admin','staff') AND is_active=1");
            $workerCheck->execute([$assignee]);
            if (!$workerCheck->fetchColumn()) $ticketUpdateError = 'The selected worker is not an active staff member.';
        }
        if (!$ticketUpdateError) {
            $beforeStmt = $pdo->prepare('SELECT customer_id, assigned_to, status, request_code, subject FROM service_requests WHERE id=? LIMIT 1');
            $beforeStmt->execute([$requestId]); $before = $beforeStmt->fetch();
            if (!$before) { $ticketUpdateError = 'Request not found.'; }
            else {
                $update = $pdo->prepare('UPDATE service_requests SET assigned_to=?, status=? WHERE id=?');
                $update->execute([$assignee, $requestStatus, $requestId]);
                $requestCode = (string)$before['request_code'];
                $ticketUrl = BASE_URL.'/request-view.php?id='.$requestId;
                if ((int)($before['assigned_to'] ?? 0) !== (int)($assignee ?? 0) && $assignee !== null) {
                    create_notification((int)$assignee, 'New request assigned to you', 'Request '.$requestCode.': '.$before['subject'].' has been assigned to you.', $ticketUrl, 'request_assigned', $requestId);
                }
                if ((string)$before['status'] !== $requestStatus) {
                    $labels = ['new'=>'Pending','in_progress'=>'Assigned','completed'=>'Completed'];
                    create_notification((int)$before['customer_id'], 'Service request status updated', 'Request '.$requestCode.' is now '.$labels[$requestStatus].'.', $ticketUrl, 'status_changed', $requestId);
                }
                log_activity((int)$user['id'], 'ticket_assigned_status_updated', 'Ticket '.$requestCode.' assignment/status updated', 'service_request', $requestId);
                header('Location: '.BASE_URL.'/dashboard.php?ticket_updated=1#requests'); exit;
            }
        }
    }
}
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_payment_id'])) {
    $reviewId = filter_var($_POST['review_payment_id'], FILTER_VALIDATE_INT);
    $reviewStatus = (string)($_POST['review_status'] ?? '');
    if (!verify_csrf()) $paymentReviewError = 'Your session token expired. Refresh and try again.';
    elseif (!$reviewId || !in_array($reviewStatus, ['confirmed','rejected'], true)) $paymentReviewError = 'Invalid payment review request.';
    else {
        $review = $pdo->prepare("UPDATE payment_notifications SET status=?, reviewed_by=?, reviewed_at=NOW() WHERE id=? AND status='pending'");
        $review->execute([$reviewStatus, (int)$user['id'], $reviewId]);
        if ($review->rowCount()) { log_activity((int)$user['id'], 'payment_notice_reviewed', 'MoMo payment notice '.$reviewStatus, 'payment_notification', $reviewId); header('Location: '.BASE_URL.'/dashboard.php?payment_reviewed=1#payments'); exit; }
        $paymentReviewError = 'This payment notice may already have been reviewed.';
    }
}
if ($isAdmin) {
    $customers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn();
    $newRequests = (int)$pdo->query("SELECT COUNT(*) FROM service_requests WHERE status='new'")->fetchColumn();
    $activeRequests = (int)$pdo->query("SELECT COUNT(*) FROM service_requests WHERE status IN ('in_progress','waiting_customer')")->fetchColumn();
    $completed = (int)$pdo->query("SELECT COUNT(*) FROM service_requests WHERE status='completed'")->fetchColumn();
    $requests = $pdo->query("SELECT r.id,r.request_code,r.subject,r.status,r.created_at,r.assigned_to,u.full_name,u.phone,s.name AS service_name,a.full_name AS assignee_name FROM service_requests r JOIN users u ON u.id=r.customer_id LEFT JOIN services s ON s.id=r.service_id LEFT JOIN users a ON a.id=r.assigned_to ORDER BY CASE WHEN r.status='new' THEN 0 WHEN r.status='in_progress' THEN 1 ELSE 2 END, r.created_at DESC LIMIT 50")->fetchAll();
    $staffList = $pdo->query("SELECT id,full_name,username,department,staff_code FROM users WHERE role IN ('admin','staff') AND is_active=1 ORDER BY full_name")->fetchAll();
    $services = $pdo->query("SELECT id,name,category,description,internal_price,price_type FROM services WHERE is_published=1 ORDER BY name")->fetchAll();
} else {
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM service_requests WHERE customer_id=?"); $stmt->execute([$user['id']]); $customers=1; $newRequests=(int)$stmt->fetchColumn();
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM service_requests WHERE customer_id=? AND status IN ('in_progress','waiting_customer')"); $stmt->execute([$user['id']]); $activeRequests=(int)$stmt->fetchColumn();
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM service_requests WHERE customer_id=? AND status='completed'"); $stmt->execute([$user['id']]); $completed=(int)$stmt->fetchColumn();
    $stmt=$pdo->prepare("SELECT r.id,r.request_code,r.subject,r.status,r.created_at,u.full_name,s.name AS service_name FROM service_requests r JOIN users u ON u.id=r.customer_id LEFT JOIN services s ON s.id=r.service_id WHERE r.customer_id=? ORDER BY r.created_at DESC LIMIT 5"); $stmt->execute([$user['id']]); $requests=$stmt->fetchAll();
    $services=$pdo->query("SELECT id,name,category,description FROM services WHERE is_published=1 ORDER BY name")->fetchAll();
}
$activities = $pdo->query("SELECT l.description,l.activity_type,l.created_at,u.full_name AS actor_name,u.role AS actor_role FROM activity_log l LEFT JOIN users u ON u.id=l.actor_user_id ORDER BY l.id DESC LIMIT 8")->fetchAll();
$paymentNotices = []; $staffPaymentNotices = [];
if (!$isAdmin) { $pstmt=$pdo->prepare('SELECT amount,transaction_reference,payment_purpose,status,created_at FROM payment_notifications WHERE customer_id=? ORDER BY created_at DESC LIMIT 5'); $pstmt->execute([(int)$user['id']]); $paymentNotices=$pstmt->fetchAll(); } else { $staffPaymentNotices=$pdo->query("SELECT p.id,p.amount,p.transaction_reference,p.payment_purpose,p.status,p.created_at,u.full_name AS customer_name,u.phone AS customer_phone FROM payment_notifications p JOIN users u ON u.id=p.customer_id ORDER BY (p.status='pending') DESC,p.created_at DESC LIMIT 50")->fetchAll(); }
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0b3975"><title>Dashboard | Enecom IT Solutions GH</title>
<link rel="manifest" href="<?= BASE_URL ?>/manifest.webmanifest"><link rel="icon" type="image/png" sizes="192x192" href="<?= BASE_URL ?>/assets/icons/icon-192.png"><link rel="apple-touch-icon" href="<?= BASE_URL ?>/assets/icons/icon-192.png"><link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
</head><body>
<header class="topbar">
  <button class="icon-btn menu-toggle" id="menuToggle" aria-label="Toggle navigation">☰</button>
  <img class="header-logo" src="<?= BASE_URL ?>/assets/img/enecom-logo.png" alt="Enecom IT Solutions GH logo"><div class="brand-copy"><strong>ENECOM IT SOLUTIONS GH</strong><small>Technology • Training • Innovation • Solutions</small></div>
  <div class="top-actions"><span class="role-chip"><?= e(strtoupper($user['role'])) ?></span><span class="avatar"><?= e(strtoupper(substr($user['name'],0,1))) ?></span><a class="logout" href="<?= BASE_URL ?>/logout.php">Sign out</a></div>
</header>
<div class="app-layout">
<aside class="sidebar" id="sidebar">
  <nav>
    <a class="nav-link active" href="<?= BASE_URL ?>/dashboard.php">⌂ <span>Dashboard</span></a>
    <?php if($isAdmin): ?>
    <a class="nav-link" href="#customers">♙ <span>Customers</span></a>
    <a class="nav-link" href="#requests">▣ <span>Requests / Tickets</span><b><?= $newRequests ?></b></a>
    <a class="nav-link" href="#services">⚙ <span>Services & Pricing</span></a>
    <a class="nav-link" href="#payments">₵ <span>MoMo Payment Notices</span></a>
    <a class="nav-link" href="<?= BASE_URL ?>/support.php">▤ <span>Live Support Inbox</span></a>
    <a class="nav-link" href="#reports">▥ <span>Reports</span></a>
    <?php if($isSuperAdmin): ?><a class="nav-link" href="#staff">♙ <span>Staff Accounts</span></a><a class="nav-link" href="#live-activity">◉ <span>Live Staff Activity</span></a><?php endif; ?>
    <?php else: ?>
    <a class="nav-link" href="#services">◈ <span>Our Services</span></a>
    <a class="nav-link" href="#requests">▣ <span>My Requests</span></a><a class="nav-link" href="#payments">₵ <span>MoMo Payments</span></a><a class="nav-link" href="<?= BASE_URL ?>/support.php">☏ <span>Contact Support</span></a>
    <?php endif; ?>
  </nav>
  <div class="sidebar-footer"><img class="sidebar-logo" src="<?= BASE_URL ?>/assets/img/enecom-logo.png" alt="Enecom IT Solutions GH logo"><strong>ENECOM IT SOLUTIONS GH</strong><small>System online • Starter version</small></div>
</aside>
<main class="main-content">
  <section class="notification-center" data-notification-center data-api="<?= BASE_URL ?>/notifications-api.php" data-csrf="<?= e(csrf_token()) ?>" data-icon="<?= BASE_URL ?>/assets/icons/icon-192.png"><div class="notification-heading"><div><h2>Notifications <span class="notification-count" data-notification-count hidden>0</span></h2><p class="muted">Automatic updates about request assignments and progress.</p></div><div class="notification-tools"><button type="button" class="btn secondary" data-enable-notifications>Enable desktop notifications</button><button type="button" class="btn secondary" data-read-all>Mark all read</button></div></div><div class="notification-list" data-notification-list><p class="muted">Loading notifications…</p></div></section>
  <section class="welcome-row"><div><p class="eyebrow">YOUR BUSINESS AT A GLANCE</p><h1>Welcome back, <?= e(explode(' ', $user['name'])[0]) ?>!</h1><p class="muted">Here's what's happening with your customers and services today.</p></div><span class="today"><?= date('D, d M Y') ?></span></section>
  <section class="stat-grid">
    <article class="stat-card"><div class="stat-icon blue">♙</div><div><p><?= $isAdmin?'Total Customers':'Account' ?></p><strong><?= $isAdmin?$customers:'Active' ?></strong><small><?= $isAdmin?'Customer records':'Your Enecom account' ?></small></div></article>
    <article class="stat-card"><div class="stat-icon green">▤</div><div><p><?= $isAdmin?'New Requests':'My Requests' ?></p><strong><?= $newRequests ?></strong><small><?= $isAdmin?'Awaiting review':'Requests submitted' ?></small></div></article>
    <article class="stat-card"><div class="stat-icon orange">◷</div><div><p>In Progress</p><strong><?= $activeRequests ?></strong><small>Active requests</small></div></article>
    <article class="stat-card"><div class="stat-icon purple">✓</div><div><p>Completed</p><strong><?= $completed ?></strong><small>Completed requests</small></div></article>
  </section>
  <?php if($isSuperAdmin): ?>
  <section class="panel" id="staff"><div class="panel-heading"><div><h2>Create / Generate Worker Account</h2><p class="muted">The system generates a staff code using company initials, department code, first four letters of the first name and the last four contact digits.</p></div><span class="pill">Admin only</span></div>
  <?php if($staffCreateError): ?><div class="alert" style="margin-bottom:12px"><?= e($staffCreateError) ?></div><?php endif; ?>
  <?php if($createdWorkerCredentials): ?><div class="success-alert" style="margin-bottom:12px"><strong>Worker account created.</strong> Copy these credentials now and give them securely to the worker. The temporary password will not be shown again.<div style="display:grid;gap:7px;margin-top:10px"><div><strong>Name:</strong> <?= e($createdWorkerCredentials['name']) ?></div><div><strong>Staff username:</strong> <code><?= e($createdWorkerCredentials['username']) ?></code></div><div><strong>Staff code:</strong> <code><?= e($createdWorkerCredentials['staff_code']) ?></code></div><div><strong>Department:</strong> <?= e($createdWorkerCredentials['department']) ?></div><div><strong>Contact:</strong> <?= e($createdWorkerCredentials['phone']) ?></div><div><strong>Email:</strong> <?= e($createdWorkerCredentials['email']) ?></div><div><strong>Temporary password:</strong> <code><?= e($createdWorkerCredentials['password']) ?></code></div><button type="button" class="btn secondary" onclick="navigator.clipboard.writeText(<?= e(json_encode('Username: '.$createdWorkerCredentials['username'].'\nTemporary password: '.$createdWorkerCredentials['password'])) ?>).then(()=>this.textContent='Credentials copied').catch(()=>alert('Please copy the credentials manually.'))">Copy login credentials</button></div></div><?php endif; ?>
  <form method="post" class="staff-create-form" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="create_worker" value="1"><div><label for="first_name">First name</label><input id="first_name" name="first_name" maxlength="80" required placeholder="Ebenezer"></div><div><label for="last_name">Last name</label><input id="last_name" name="last_name" maxlength="80" required placeholder="Dadzie"></div><div><label for="worker_phone">Contact number</label><input id="worker_phone" name="worker_phone" type="tel" maxlength="40" required placeholder="0245867221"></div><div><label for="department_code">Department</label><select id="department_code" name="department_code" required><option value="SD" data-name="Software Developer">Software Developer (SD)</option><option value="IT" data-name="IT Support">IT Support (IT)</option><option value="NW" data-name="Networking">Networking (NW)</option><option value="DA" data-name="Data Analytics">Data Analytics (DA)</option><option value="AI" data-name="AI & Automation">AI &amp; Automation (AI)</option><option value="TR" data-name="Training">Training (TR)</option><option value="DM" data-name="Digital Marketing">Digital Marketing (DM)</option><option value="AD" data-name="Administration">Administration (AD)</option></select><input type="hidden" id="department_name" name="department" value="Software Developer"></div><div><label for="worker_email">Work email (optional)</label><input id="worker_email" name="worker_email" type="email" maxlength="190" placeholder="Leave blank to generate internal email"></div><div style="align-self:end"><button class="btn primary full" type="submit">Generate worker account</button></div></form>
  <p class="muted" style="font-size:12px;margin-top:10px">Example: <code>EITS/SD-EBEN-7221</code>. The worker signs in using this username and the temporary password. Give the password privately and ask the worker to change it after signing in; password-change enforcement is not yet built into this module.</p>
  <div class="table-wrap" style="margin-top:16px"><table><thead><tr><th>Staff code / username</th><th>Worker</th><th>Department</th><th>Phone</th><th>Account</th></tr></thead><tbody><?php $workersTable=$pdo->query("SELECT id,full_name,username,staff_code,department,phone,is_active FROM users WHERE role='staff' ORDER BY created_at DESC LIMIT 100")->fetchAll(); ?><?php if(!$workersTable): ?><tr><td colspan="5" class="empty">No worker accounts created yet.</td></tr><?php else: foreach($workersTable as $w): ?><tr><td><strong><?= e($w['staff_code'] ?: $w['username'] ?: '—') ?></strong></td><td><?= e($w['full_name']) ?></td><td><?= e($w['department'] ?: 'Not set') ?></td><td><?= e($w['phone'] ?: '—') ?></td><td><span class="status <?= (int)$w['is_active']===1?'completed':'cancelled' ?>"><?= (int)$w['is_active']===1?'Active':'Inactive' ?></span></td></tr><?php endforeach; endif; ?></tbody></table></div></section>
  <section class="panel" id="live-activity" data-live-activity data-api="<?= BASE_URL ?>/activity-api.php" data-csrf="<?= e(csrf_token()) ?>"><div class="panel-heading"><div><h2>Live Staff Activity Monitor</h2><p class="muted">Presence updates while staff have Enecom open; activity events refresh automatically every 15 seconds.</p></div><span class="pill" data-activity-updated>Connecting…</span></div><div class="activity-live-grid"><div><h3>Worker presence</h3><div class="table-wrap"><table><thead><tr><th>Worker</th><th>Department</th><th>Status</th><th>Last seen</th></tr></thead><tbody data-staff-presence><tr><td colspan="4" class="empty">Loading worker presence…</td></tr></tbody></table></div></div><div><h3>Recent activity</h3><div class="table-wrap"><table><thead><tr><th>Time</th><th>Employee</th><th>Activity</th></tr></thead><tbody data-activity-feed><tr><td colspan="3" class="empty">Loading activity…</td></tr></tbody></table></div></div></div><p class="muted" style="font-size:11px;margin-top:10px">“Online” means the worker's authenticated Enecom page sent a heartbeat within the last 90 seconds. This is an operational activity log, not screen recording or keystroke monitoring.</p></section>
  <?php endif; ?>
  <?php if(!$isAdmin): ?><section class="panel support-panel"><div class="support-copy"><span class="support-icon">＋</span><div><h2>Need a service?</h2><p class="muted">Submit a request and track progress from your dashboard. Service prices are not displayed to customers.</p></div></div><a class="btn primary" href="<?= BASE_URL ?>/request-create.php">Create service request →</a></section><?php else: ?><section class="panel support-panel"><div class="support-copy"><span class="support-icon">＋</span><div><h2>Customer service requests</h2><p class="muted">Review incoming requests in the list below and follow up with customers.</p></div></div><a class="btn primary" href="#requests">View requests ↓</a></section><?php endif; ?>
  <section class="panel" id="requests"><div class="panel-heading"><div><h2><?= $isAdmin?'Customer Requests / Ticket Assignment':'My Recent Requests' ?></h2><p class="muted"><?= $isAdmin?'Assign every request to a worker, check contact details, and track work status.':'Track service activity and progress.' ?></p></div><span class="pill"><?= $isAdmin?'Assignment desk':'Live database' ?></span></div>
  <?php if($isAdmin && isset($_GET['ticket_updated'])): ?><div class="success-alert" style="margin-bottom:12px">Request assignment and status saved.</div><?php endif; ?>
  <?php if($isAdmin && $ticketUpdateError): ?><div class="alert" style="margin-bottom:12px"><?= e($ticketUpdateError) ?></div><?php endif; ?>
  <div class="table-wrap"><table><thead><tr><th>Request ID</th><?php if($isAdmin):?><th>Customer / Contact</th><?php endif;?><th>Service / Subject</th><th>Status</th><?php if($isAdmin):?><th>Assigned worker</th><th>Quick actions</th><?php endif;?><th>Date</th></tr></thead><tbody>
  <?php if(!$requests): ?><tr><td colspan="<?= $isAdmin?7:4 ?>" class="empty">No requests yet. New customer submissions will appear here.</td></tr><?php else: foreach($requests as $r): ?><tr><td><a href="<?= BASE_URL ?>/request-view.php?id=<?= (int)$r['id'] ?>"><strong><?= e($r['request_code']) ?></strong></a></td><?php if($isAdmin):?><td><strong><?= e($r['full_name']) ?></strong><br><small><?= e($r['phone'] ?: 'No phone number saved') ?></small><?php if(!empty($r['phone'])): $customerDial=preg_replace('/[^0-9+]/','',$r['phone']); $customerWa=preg_replace('/\D/','',$customerDial); if(substr($customerWa,0,1)==='0' && strlen($customerWa)===10) $customerWa='233'.substr($customerWa,1); elseif(substr($customerWa,0,1)==='+') $customerWa=substr($customerWa,1); ?><div class="request-contact-actions"><a href="tel:<?= e($customerDial) ?>">☎ Call</a><a href="https://wa.me/<?= e($customerWa) ?>?text=<?= rawurlencode('Hello '.$r['full_name'].', I am contacting you from Enecom IT Solutions GH about request '.$r['request_code'].'.') ?>" target="_blank" rel="noopener">WhatsApp</a></div><?php endif; ?></td><?php endif;?><td><?= e($r['service_name'] ?: $r['subject']) ?><br><a class="muted" href="<?= BASE_URL ?>/request-view.php?id=<?= (int)$r['id'] ?>">Open ticket / reply →</a></td><td><span class="status <?= e($r['status']) ?>"><?= e(['new'=>'Pending','in_progress'=>'Assigned','waiting_customer'=>'Waiting for customer','completed'=>'Completed','cancelled'=>'Cancelled'][$r['status']] ?? ucwords(str_replace('_',' ',$r['status']))) ?></span></td><?php if($isAdmin):?><td><?= e($r['assignee_name'] ?: 'Not assigned') ?></td><td><form method="post" class="request-assign-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="update_request_id" value="<?= (int)$r['id'] ?>"><label class="sr-only" for="worker_<?= (int)$r['id'] ?>">Assign worker</label><select id="worker_<?= (int)$r['id'] ?>" name="assigned_to"><option value="">Unassigned</option><?php foreach($staffList as $worker): ?><option value="<?= (int)$worker['id'] ?>" <?= (int)$r['assigned_to']===(int)$worker['id']?'selected':'' ?>><?= e($worker['full_name']) ?></option><?php endforeach; ?></select><label class="sr-only" for="status_<?= (int)$r['id'] ?>">Request status</label><select id="status_<?= (int)$r['id'] ?>" name="request_status"><option value="new" <?= $r['status']==='new'?'selected':'' ?>>Pending</option><option value="in_progress" <?= $r['status']==='in_progress'?'selected':'' ?>>Assigned</option><option value="completed" <?= $r['status']==='completed'?'selected':'' ?>>Completed</option></select><button class="btn primary" type="submit">Save</button></form></td><?php endif;?><td><?= e(date('Y-m-d',strtotime($r['created_at']))) ?></td></tr><?php endforeach; endif; ?>
  </tbody></table></div><p class="muted" style="font-size:11px;margin-top:12px">Pending = awaiting assignment/work. Assigned = a worker is responsible for the request. Completed = work has been finished. Use “Open ticket / reply” to view the full description and conversation.</p></section>
  <?php if($isAdmin): ?>
  <section class="panel" id="payments"><div class="panel-heading"><div><h2>Customer MoMo payment notices</h2><p class="muted">Review transaction references against the company's actual MoMo records before confirming.</p></div><span class="pill">Manual review</span></div>
  <?php if(isset($_GET['payment_reviewed'])): ?><div class="success-alert" style="margin-bottom:12px">Payment notice review saved.</div><?php endif; ?><?php if($paymentReviewError): ?><div class="alert" style="margin-bottom:12px"><?= e($paymentReviewError) ?></div><?php endif; ?>
  <div class="table-wrap"><table><thead><tr><th>Customer</th><th>Phone</th><th>Amount</th><th>Transaction reference</th><th>Purpose</th><th>Status</th><th>Action</th></tr></thead><tbody><?php if(!$staffPaymentNotices): ?><tr><td colspan="7" class="empty">No payment notices have been submitted yet.</td></tr><?php else: foreach($staffPaymentNotices as $pn): ?><tr><td><?= e($pn['customer_name']) ?></td><td><?= e($pn['customer_phone'] ?: '—') ?></td><td>GHS <?= number_format((float)$pn['amount'],2) ?></td><td><?= e($pn['transaction_reference']) ?></td><td><?= e($pn['payment_purpose'] ?: 'Service payment') ?></td><td><span class="status <?= e($pn['status']) ?>"><?= e(ucfirst($pn['status'])) ?></span></td><td><?php if($pn['status']==='pending'): ?><form method="post" style="display:flex;gap:6px;flex-wrap:wrap"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="review_payment_id" value="<?= (int)$pn['id'] ?>"><button class="btn primary" name="review_status" value="confirmed" type="submit">Confirm</button><button class="btn secondary" name="review_status" value="rejected" type="submit">Reject</button></form><?php else: ?><span class="muted">Reviewed</span><?php endif; ?></td></tr><?php endforeach; endif; ?></tbody></table></div><p class="muted" style="font-size:11px;margin-top:12px">Only confirm after checking the transaction in the official company MoMo account. This dashboard does not connect directly to a payment provider.</p></section>
  <?php else: ?>
  <section class="panel" id="payments"><div class="panel-heading"><div><h2>Pay Enecom by Mobile Money</h2><p class="muted">Use these details for agreed service fees, then submit your transaction reference below.</p></div><span class="pill">Manual verification</span></div>
  <div class="service-grid" style="grid-template-columns:repeat(auto-fit,minmax(230px,1fr))"><article class="service-card"><div class="service-symbol">₵</div><div class="service-card-body"><span class="category">Mobile Money / MoMo</span><h3>0242288811</h3><p>Company payment number</p><button type="button" class="btn secondary" onclick="navigator.clipboard&&navigator.clipboard.writeText('0242288811').then(()=>{this.textContent='Number copied';}).catch(()=>{})">Copy MoMo number</button><p class="muted" style="font-size:12px;margin-top:10px">Confirm the exact amount and payment recipient before authorising a transfer. Do not pay a fee you have not agreed with Enecom.</p></div></article>
  <article class="service-card"><div class="service-card-body"><h3>Already paid?</h3><p class="muted">Submit the transaction reference. This records a payment notice only; it does not automatically verify or collect money.</p><?php if(isset($_GET['payment_submitted'])): ?><div class="success-alert">Payment notice submitted. Enecom will verify it manually.</div><?php elseif(isset($_GET['payment_error'])): ?><div class="alert"><?php echo e(['csrf'=>'Session expired. Refresh and try again.','invalid'=>'Enter a valid amount and transaction reference.','duplicate'=>'That transaction reference has already been submitted.','failed'=>'Could not submit the notice. Please try again.'][$_GET['payment_error']] ?? 'Could not submit payment notice.'); ?></div><?php endif; ?><form method="post" action="<?= BASE_URL ?>/payment-notify.php" style="display:grid;gap:9px"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label for="pay_amount">Amount paid (GHS)</label><input id="pay_amount" name="amount" type="number" min="0.01" step="0.01" required placeholder="e.g. 250.00"><label for="pay_ref">MoMo transaction reference</label><input id="pay_ref" name="transaction_reference" maxlength="120" required placeholder="Enter the transaction ID/reference"><label for="pay_purpose">Payment for (optional)</label><input id="pay_purpose" name="payment_purpose" maxlength="180" placeholder="e.g. Website development / ticket ID"><button class="btn primary" type="submit">Submit payment notice →</button></form></div></article></div>
  <?php if($paymentNotices): ?><h3 style="margin:20px 0 10px">Your recent payment notices</h3><div class="table-wrap"><table><thead><tr><th>Reference</th><th>Amount</th><th>Purpose</th><th>Status</th><th>Date</th></tr></thead><tbody><?php foreach($paymentNotices as $pn): ?><tr><td><?= e($pn['transaction_reference']) ?></td><td>GHS <?= number_format((float)$pn['amount'],2) ?></td><td><?= e($pn['payment_purpose'] ?: 'Service payment') ?></td><td><span class="status <?= e($pn['status']) ?>"><?= e(ucfirst($pn['status'])) ?></span></td><td><?= e(date('Y-m-d',strtotime($pn['created_at']))) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
  <?php endif; ?>
  <section class="panel" id="services"><div class="panel-heading"><div><h2>Services</h2><p class="muted"><?= $isAdmin?'Internal prices are visible only to authorized staff.':'Explore our services. Contact us for a tailored quotation.' ?></p></div></div>
  <div class="service-grid"><?php foreach($services as $s): ?><article class="service-card"><div class="service-symbol"><?= e(strtoupper(substr($s['name'],0,1))) ?></div><div class="service-card-body"><span class="category"><?= e($s['category']) ?></span><h3><?= e($s['name']) ?></h3><p><?= e($s['description']) ?></p><?php if($isAdmin): ?><div class="internal-price">Internal price: <?= $s['internal_price']===null?'Quote required':'GHS '.number_format((float)$s['internal_price'],2) ?></div><?php else: ?><span class="quote-label">Request a quotation</span><?php endif; ?></div></article><?php endforeach; ?></div></section>
  <section class="panel support-panel" id="inbox"><div class="support-copy"><span class="support-icon">☏</span><div><h2>Need support?</h2><p class="muted">Contact Enecom support. WhatsApp API and unified inbox integration can be enabled after provider setup.</p></div></div><div class="support-actions"><a class="btn primary" href="<?= BASE_URL ?>/support.php">Open Live Support ↗</a><a class="btn secondary" href="https://wa.me/233500111663?text=Hello%20Enecom%20IT%20Solutions%20GH%2C%20I%20need%20support." target="_blank" rel="noopener">WhatsApp ↗</a><a class="btn secondary" href="mailto:enecomitsolutiongh@gmail.com">Email support</a></div></section>
  <footer class="footer">© <?= date('Y') ?> Enecom IT Solutions GH • Technology • Training • Innovation • Solutions</footer>
</main></div>
<script>window.ENE_BASE_URL = <?= json_encode(BASE_URL) ?>;</script><script src="<?= BASE_URL ?>/assets/js/app.js"></script>
<script src="<?= BASE_URL ?>/assets/js/notifications.js" defer></script>

<script>
(function(){
 const deptSelect=document.getElementById('department_code'), deptName=document.getElementById('department_name');
 if(deptSelect&&deptName){const sync=()=>{deptName.value=deptSelect.options[deptSelect.selectedIndex].dataset.name||''};deptSelect.addEventListener('change',sync);sync();}
 const apiRoot=<?= json_encode(BASE_URL) ?>, csrf=<?= json_encode(csrf_token()) ?>;
 async function heartbeat(){try{await fetch(apiRoot+'/activity-api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf_token:csrf}),credentials:'same-origin',cache:'no-store'});}catch(e){}}
 heartbeat(); setInterval(heartbeat,20000);
 const panel=document.querySelector('[data-live-activity]'); if(!panel)return;
 const api=panel.dataset.api, token=panel.dataset.csrf, presence=panel.querySelector('[data-staff-presence]'), feed=panel.querySelector('[data-activity-feed]'), updated=panel.querySelector('[data-activity-updated]');
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 async function refresh(){try{const r=await fetch(api,{credentials:'same-origin',cache:'no-store'});if(!r.ok)throw new Error('Could not load');const d=await r.json();if(!d.ok)throw new Error('Could not load');
 presence.innerHTML=d.staff.length?d.staff.map(w=>`<tr><td><strong>${esc(w.full_name)}</strong><br><small>${esc(w.staff_code||w.username||'')}</small></td><td>${esc(w.department||'—')}</td><td><span class="status ${Number(w.is_online)===1?'completed':'cancelled'}">${Number(w.is_online)===1?'Online':'Offline'}</span>${Number(w.is_active)!==1?' · Disabled':''}</td><td>${esc(w.last_seen_at||'Never')}</td></tr>`).join(''):'<tr><td colspan="4" class="empty">No worker accounts yet.</td></tr>';
 feed.innerHTML=d.activities.length?d.activities.map(a=>`<tr><td><small>${esc(a.created_at)}</small></td><td>${esc(a.full_name||'System')}<br><small>${esc(a.role||'')}</small></td><td>${esc(a.description)}<br><small>${esc(a.activity_type)}</small></td></tr>`).join(''):'<tr><td colspan="3" class="empty">No activity recorded yet.</td></tr>';
 updated.textContent='Updated '+new Date().toLocaleTimeString();
 }catch(e){updated.textContent='Refresh failed';}}
 refresh();setInterval(refresh,15000);
})();
</script>

</body></html>
