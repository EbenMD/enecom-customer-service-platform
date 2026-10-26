<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$user = current_user();
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if (!isset($raw['csrf_token'], $_SESSION['csrf_token']) || !is_string($raw['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $raw['csrf_token'])) {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Security token expired.']); exit;
    }
    $stmt = $pdo->prepare('UPDATE users SET last_seen_at=NOW() WHERE id=? AND is_active=1');
    $stmt->execute([(int)$user['id']]);
    echo json_encode(['ok'=>true]); exit;
}
if (($user['role'] ?? '') !== 'admin') { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Admin access required.']); exit; }
$staff = $pdo->query("SELECT id,full_name,phone,username,staff_code,department,last_seen_at,is_active,CASE WHEN is_active=1 AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 90 SECOND) THEN 1 ELSE 0 END AS is_online FROM users WHERE role='staff' ORDER BY is_active DESC,full_name")->fetchAll();
$logs = $pdo->query("SELECT l.id,l.activity_type,l.description,l.related_type,l.related_id,l.created_at,u.full_name,u.username,u.staff_code,u.role FROM activity_log l LEFT JOIN users u ON u.id=l.actor_user_id ORDER BY l.id DESC LIMIT 35")->fetchAll();
echo json_encode(['ok'=>true,'staff'=>$staff,'activities'=>$logs,'server_time'=>date('Y-m-d H:i:s')], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
