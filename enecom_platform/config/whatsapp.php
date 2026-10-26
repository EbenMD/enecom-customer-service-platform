<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

/** Keep phone numbers in international format, digits only (e.g. 233501234567). */
function wa_normalize_phone(string $phone): string {
    return preg_replace('/\D+/', '', $phone) ?? '';
}

function wa_is_configured(): bool {
    return WA_ACCESS_TOKEN !== '' && WA_PHONE_NUMBER_ID !== '';
}

/** Sends a text message through the official WhatsApp Cloud API. */
function wa_send_text(string $to, string $body): array {
    if (!wa_is_configured()) {
        return ['ok' => false, 'error' => 'WhatsApp Cloud API is not configured. Add ENE_WA_ACCESS_TOKEN and ENE_WA_PHONE_NUMBER_ID to the server environment.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'PHP cURL is not enabled. Enable the curl extension in php.ini and restart Apache.'];
    }
    $to = wa_normalize_phone($to);
    if (strlen($to) < 8 || strlen($to) > 15) {
        return ['ok' => false, 'error' => 'The WhatsApp contact number is not in a valid international format.'];
    }
    $url = 'https://graph.facebook.com/' . rawurlencode(WA_GRAPH_VERSION) . '/' . rawurlencode(WA_PHONE_NUMBER_ID) . '/messages';
    $payload = [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $to,
        'type' => 'text',
        'text' => ['preview_url' => false, 'body' => $body],
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . WA_ACCESS_TOKEN, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return ['ok' => false, 'error' => 'WhatsApp request failed: ' . ($curlError ?: 'network error')];
    $json = json_decode((string)$raw, true);
    if ($http < 200 || $http >= 300) {
        $message = $json['error']['message'] ?? ('WhatsApp API returned HTTP ' . $http . '.');
        return ['ok' => false, 'error' => (string)$message, 'http' => $http];
    }
    $messageId = $json['messages'][0]['id'] ?? null;
    return ['ok' => true, 'provider_message_id' => is_string($messageId) ? $messageId : null, 'http' => $http];
}

/** Match a WhatsApp sender to an existing customer using normalized phone suffixes. */
function wa_find_customer_id(PDO $pdo, string $waPhone): ?int {
    $digits = wa_normalize_phone($waPhone);
    if ($digits === '') return null;
    $stmt = $pdo->query("SELECT id, phone FROM users WHERE phone IS NOT NULL AND phone <> '' ORDER BY id DESC LIMIT 10000");
    foreach ($stmt->fetchAll() as $row) {
        $candidate = wa_normalize_phone((string)$row['phone']);
        if ($candidate !== '' && ($candidate === $digits || (strlen($candidate) >= 9 && strlen($digits) >= 9 && substr($candidate, -9) === substr($digits, -9)))) {
            return (int)$row['id'];
        }
    }
    return null;
}
