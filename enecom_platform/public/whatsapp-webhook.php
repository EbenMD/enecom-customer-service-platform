<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/whatsapp.php';

// Meta webhook verification (GET).
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = (string)($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
    $token = (string)($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
    $challenge = (string)($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');
    if ($mode === 'subscribe' && WA_VERIFY_TOKEN !== '' && hash_equals(WA_VERIFY_TOKEN, $token)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $challenge;
        exit;
    }
    http_response_code(403); echo 'Webhook verification failed.'; exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: GET, POST'); exit; }
$raw = file_get_contents('php://input') ?: '';
if (WA_VERIFY_TOKEN === '') { http_response_code(503); echo 'Webhook verify token is not configured.'; exit; }
// Verify authenticity when the Meta app secret is configured. Always set this in production.
if (WA_APP_SECRET !== '') {
    $signature = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
    $expected = 'sha256=' . hash_hmac('sha256', $raw, WA_APP_SECRET);
    if ($signature === '' || !hash_equals($expected, $signature)) { http_response_code(401); echo 'Invalid signature.'; exit; }
}
$payload = json_decode($raw, true);
if (!is_array($payload)) { http_response_code(400); echo 'Invalid JSON.'; exit; }
try {
    $pdo = db();
    foreach (($payload['entry'] ?? []) as $entry) {
        foreach (($entry['changes'] ?? []) as $change) {
            if (($change['field'] ?? '') !== 'messages') continue;
            $value = $change['value'] ?? [];
            $contacts = [];
            foreach (($value['contacts'] ?? []) as $contact) {
                $waid = (string)($contact['wa_id'] ?? '');
                if ($waid !== '') $contacts[$waid] = (string)($contact['profile']['name'] ?? 'WhatsApp customer');
            }
            foreach (($value['messages'] ?? []) as $incoming) {
                $providerId = (string)($incoming['id'] ?? '');
                $from = wa_normalize_phone((string)($incoming['from'] ?? ''));
                if ($providerId === '' || $from === '') continue;
                $dup = $pdo->prepare('SELECT id FROM messages WHERE provider_message_id=? LIMIT 1');
                $dup->execute([$providerId]);
                if ($dup->fetchColumn()) continue;
                $type = (string)($incoming['type'] ?? 'text');
                $body = '';
                $messageType = 'text';
                if ($type === 'text') $body = (string)($incoming['text']['body'] ?? '');
                elseif ($type === 'image') { $body = (string)($incoming['image']['caption'] ?? 'Image received in WhatsApp (media download not configured).'); $messageType = 'image'; }
                elseif ($type === 'document') { $body = (string)($incoming['document']['caption'] ?? 'Document received in WhatsApp (media download not configured).'); $messageType = 'file'; }
                else $body = 'Received a WhatsApp ' . $type . ' message. This message type is not yet displayed in detail.';
                $customerId = wa_find_customer_id($pdo, $from);
                $q = $pdo->prepare("SELECT id FROM conversations WHERE channel='whatsapp' AND external_contact=? AND status<>'closed' ORDER BY last_activity_at DESC LIMIT 1");
                $q->execute([$from]); $conversationId = (int)($q->fetchColumn() ?: 0);
                if (!$conversationId) {
                    $name = $contacts[$from] ?? 'WhatsApp customer';
                    $q = $pdo->prepare("INSERT INTO conversations (customer_id,channel,external_contact,subject,status) VALUES (?,'whatsapp',?,?, 'open')");
                    $q->execute([$customerId, $from, 'WhatsApp — ' . mb_substr($name, 0, 140)]);
                    $conversationId = (int)$pdo->lastInsertId();
                } elseif ($customerId) {
                    $q = $pdo->prepare('UPDATE conversations SET customer_id=COALESCE(customer_id,?) WHERE id=?'); $q->execute([$customerId,$conversationId]);
                }
                $q = $pdo->prepare('INSERT INTO messages (conversation_id,sender_user_id,direction,message_type,body,provider_message_id,delivery_status) VALUES (?,? ,\'inbound\',?,?,?,\'received\')');
                $q->execute([$conversationId,$customerId,$messageType,$body,$providerId]);
                $q = $pdo->prepare("UPDATE conversations SET status='open',last_activity_at=CURRENT_TIMESTAMP WHERE id=?"); $q->execute([$conversationId]);
                log_activity($customerId, 'whatsapp_message_received', 'Incoming WhatsApp message received', 'conversation', $conversationId);
            }
            foreach (($value['statuses'] ?? []) as $status) {
                $providerId = (string)($status['id'] ?? ''); $state = (string)($status['status'] ?? '');
                if ($providerId === '' || !in_array($state, ['sent','delivered','read','failed'], true)) continue;
                $q = $pdo->prepare('UPDATE messages SET delivery_status=? WHERE provider_message_id=?');
                $q->execute([$state,$providerId]);
            }
        }
    }
    http_response_code(200); echo 'EVENT_RECEIVED';
} catch (Throwable $e) {
    error_log('Enecom WhatsApp webhook error: ' . $e->getMessage());
    http_response_code(500); echo 'Webhook processing failed.';
}
