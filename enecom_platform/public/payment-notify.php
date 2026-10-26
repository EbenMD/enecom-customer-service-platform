<?php
require_once __DIR__ . '/../config/config.php';
require_login();
$user=current_user();
if (($user['role'] ?? '') !== 'customer') { header('Location: '.BASE_URL.'/dashboard.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) { header('Location: '.BASE_URL.'/dashboard.php?payment_error=csrf#payments'); exit; }
$amount=filter_var($_POST['amount'] ?? '',FILTER_VALIDATE_FLOAT);
$reference=trim((string)($_POST['transaction_reference'] ?? ''));
$purpose=trim((string)($_POST['payment_purpose'] ?? ''));
if ($amount===false || $amount<=0 || $amount>100000000 || $reference==='' || mb_strlen($reference)>120 || mb_strlen($purpose)>180) { header('Location: '.BASE_URL.'/dashboard.php?payment_error=invalid#payments'); exit; }
try {
  $stmt=db()->prepare('INSERT INTO payment_notifications (customer_id,amount,transaction_reference,payment_purpose) VALUES (?,?,?,?)');
  $stmt->execute([(int)$user['id'],$amount,$reference,$purpose!==''?$purpose:null]);
  log_activity((int)$user['id'],'payment_notice_submitted','MoMo payment notice submitted; reference '.$reference,'payment_notification',(int)db()->lastInsertId());
  header('Location: '.BASE_URL.'/dashboard.php?payment_submitted=1#payments'); exit;
} catch (PDOException $ex) {
  if ($ex->getCode()==='23000') header('Location: '.BASE_URL.'/dashboard.php?payment_error=duplicate#payments');
  else header('Location: '.BASE_URL.'/dashboard.php?payment_error=failed#payments');
  exit;
}
