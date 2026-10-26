<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/whatsapp.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
$user=current_user(); $staff=in_array($user['role'],['admin','staff'],true); $pdo=db();
function out(array $data,int $status=200): never { http_response_code($status); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function get_conversation(PDO $pdo,int $id,array $user,bool $staff): array|false {
  $q=$pdo->prepare('SELECT c.*,u.full_name AS customer_name,u.email AS customer_email FROM conversations c LEFT JOIN users u ON u.id=c.customer_id WHERE c.id=?');
  $q->execute([$id]); $c=$q->fetch();
  if(!$c || (!$staff && (int)$c['customer_id'] !== (int)$user['id'])) return false;
  return $c;
}
$action=(string)($_GET['action']??$_POST['action']??'list');
if($_SERVER['REQUEST_METHOD']==='POST' && !verify_csrf()) out(['error'=>'Session token expired. Refresh and try again.'],419);
if($action==='create' && $_SERVER['REQUEST_METHOD']==='POST') {
  if($staff) out(['error'=>'Staff should reply to an existing conversation or create one for a customer from the inbox.'],422);
  $subject=trim((string)($_POST['subject']??'')); $body=trim((string)($_POST['body']??''));
  if($subject===''||mb_strlen($subject)>180) out(['error'=>'Enter a subject up to 180 characters.'],422);
  if($body===''||mb_strlen($body)>5000) out(['error'=>'Enter a message up to 5,000 characters.'],422);
  $pdo->beginTransaction();
  try {
    $q=$pdo->prepare("INSERT INTO conversations (customer_id,channel,subject,status) VALUES (?,'web_chat',?,'open')"); $q->execute([$user['id'],$subject]); $id=(int)$pdo->lastInsertId();
    $q=$pdo->prepare("INSERT INTO messages (conversation_id,sender_user_id,direction,message_type,body,delivery_status) VALUES (?,?,'inbound','text',?,'received')"); $q->execute([$id,$user['id'],$body]);
    $pdo->commit(); log_activity((int)$user['id'],'support_conversation_created','Started support conversation: '.$subject,'conversation',$id);
    out(['ok'=>true,'conversation_id'=>$id]);
  } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); out(['error'=>'Could not create conversation. Please try again.'],500); }
}
if($action==='list') {
  if($staff) $q=$pdo->query("SELECT c.id,c.subject,c.status,c.channel,c.last_activity_at,COALESCE(u.full_name,c.subject,c.external_contact,'Unknown customer') AS customer_name,(SELECT body FROM messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_message,(SELECT id FROM messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_message_id,(SELECT direction FROM messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_message_direction,(SELECT sender_user_id FROM messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_message_sender_id,(SELECT COUNT(*) FROM messages m WHERE m.conversation_id=c.id AND m.direction='inbound' AND m.created_at>COALESCE((SELECT MAX(m2.created_at) FROM messages m2 WHERE m2.conversation_id=c.id AND m2.direction='outbound'), '1970-01-01')) AS unread FROM conversations c LEFT JOIN users u ON u.id=c.customer_id ORDER BY FIELD(c.status,'open','assigned','waiting','closed'),c.last_activity_at DESC LIMIT 100");
  else { $q=$pdo->prepare("SELECT c.id,c.subject,c.status,c.channel,c.last_activity_at,(SELECT body FROM messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_message,(SELECT id FROM messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_message_id,(SELECT direction FROM messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_message_direction,(SELECT sender_user_id FROM messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_message_sender_id FROM conversations c WHERE c.customer_id=? ORDER BY c.last_activity_at DESC LIMIT 100"); $q->execute([$user['id']]); }
  out(['conversations'=>$q->fetchAll()]);
}
if($action==='messages') {
  $id=filter_var($_GET['id']??0,FILTER_VALIDATE_INT); if(!$id || !($c=get_conversation($pdo,(int)$id,$user,$staff))) out(['error'=>'Conversation not found.'],404);
  $after=max(0,(int)($_GET['after']??0)); $q=$pdo->prepare('SELECT m.id,m.sender_user_id,m.direction,m.message_type,m.body,m.delivery_status,m.created_at,u.full_name AS sender_name FROM messages m LEFT JOIN users u ON u.id=m.sender_user_id WHERE m.conversation_id=? AND m.id>? ORDER BY m.id ASC LIMIT 200'); $q->execute([$id,$after]);
  out(['conversation'=>$c,'messages'=>$q->fetchAll()]);
}
if($action==='send' && $_SERVER['REQUEST_METHOD']==='POST') {
  $id=filter_var($_POST['conversation_id']??0,FILTER_VALIDATE_INT); $body=trim((string)($_POST['body']??''));
  if(!$id || !($c=get_conversation($pdo,(int)$id,$user,$staff))) out(['error'=>'Conversation not found.'],404);
  if($c['status']==='closed') out(['error'=>'This conversation is closed. Reopen it before replying.'],422);
  if($body===''||mb_strlen($body)>5000) out(['error'=>'Message must be between 1 and 5,000 characters.'],422);
  $direction=$staff?'outbound':'inbound';
  $delivery=$staff?'sent':'received'; $providerId=null;
  // Staff replies in WhatsApp conversations are sent to Meta first; local success is not faked.
  if ($staff && $c['channel']==='whatsapp') {
    $to=(string)($c['external_contact'] ?? '');
    $send=wa_send_text($to,$body);
    if (!$send['ok']) out(['error'=>'WhatsApp message was not sent. '.$send['error']],502);
    $providerId=$send['provider_message_id'] ?? null;
    $delivery='sent';
  }
  $q=$pdo->prepare("INSERT INTO messages (conversation_id,sender_user_id,direction,message_type,body,provider_message_id,delivery_status) VALUES (?,?,?,'text',?,?,?)");
  $q->execute([$id,$user['id'],$direction,$body,$providerId,$delivery]);
  $status=$staff?'waiting':'open'; $q=$pdo->prepare('UPDATE conversations SET status=?,last_activity_at=CURRENT_TIMESTAMP WHERE id=?'); $q->execute([$status,$id]);
  log_activity((int)$user['id'],'support_message_sent',($staff?'Replied to':'Sent message in').' support conversation','conversation',(int)$id);
  out(['ok'=>true,'message_id'=>(int)$pdo->lastInsertId(),'channel'=>$c['channel'],'delivery_status'=>$delivery]);
}
if($action==='status' && $_SERVER['REQUEST_METHOD']==='POST') {
  if(!$staff) out(['error'=>'Only support staff can change conversation status.'],403);
  $id=filter_var($_POST['conversation_id']??0,FILTER_VALIDATE_INT); $status=(string)($_POST['status']??'');
  if(!$id || !in_array($status,['open','assigned','waiting','closed'],true) || !get_conversation($pdo,(int)$id,$user,$staff)) out(['error'=>'Invalid conversation or status.'],422);
  $q=$pdo->prepare('UPDATE conversations SET status=?,assigned_to=?,last_activity_at=CURRENT_TIMESTAMP WHERE id=?'); $q->execute([$status,$user['id'],$id]); out(['ok'=>true]);
}
out(['error'=>'Unknown action.'],400);
