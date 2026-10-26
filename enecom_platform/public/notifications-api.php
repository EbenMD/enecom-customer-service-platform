<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$user = current_user(); $pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $raw = json_decode((string)file_get_contents('php://input'), true) ?: [];
  if (!isset($raw['csrf_token'], $_SESSION['csrf_token']) || !is_string($raw['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $raw['csrf_token'])) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Security token expired. Refresh the page.']); exit; }
  $id = filter_var($raw['id'] ?? 0, FILTER_VALIDATE_INT);
  if (($raw['action'] ?? '') === 'read_all') { $q=$pdo->prepare('UPDATE user_notifications SET is_read=1, read_at=NOW() WHERE recipient_user_id=? AND is_read=0'); $q->execute([(int)$user['id']]); }
  elseif (($raw['action'] ?? '') === 'read' && $id) { $q=$pdo->prepare('UPDATE user_notifications SET is_read=1, read_at=NOW() WHERE id=? AND recipient_user_id=?'); $q->execute([$id,(int)$user['id']]); }
  else { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid action.']); exit; }
  echo json_encode(['ok'=>true]); exit;
}
$after = filter_var($_GET['after'] ?? 0, FILTER_VALIDATE_INT); $after = max(0, (int)$after);
$q=$pdo->prepare('SELECT id,title,message,target_url,notification_type,related_request_id,is_read,created_at FROM user_notifications WHERE recipient_user_id=? ORDER BY id DESC LIMIT 30'); $q->execute([(int)$user['id']]); $items=$q->fetchAll();
$latest=0; foreach($items as &$item){ $item['id']=(int)$item['id']; $item['related_request_id']=$item['related_request_id']===null?null:(int)$item['related_request_id']; $item['is_read']=(bool)$item['is_read']; $latest=max($latest,$item['id']); } unset($item);
$unread=$pdo->prepare('SELECT COUNT(*) FROM user_notifications WHERE recipient_user_id=? AND is_read=0'); $unread->execute([(int)$user['id']]);
echo json_encode(['ok'=>true,'items'=>$items,'unread'=>(int)$unread->fetchColumn(),'latest_id'=>$latest,'new_items'=>array_values(array_filter($items,fn($n)=>$n['id']>$after))], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
