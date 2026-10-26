<?php
declare(strict_types=1);

define('APP_NAME', 'ENECOM IT SOLUTIONS GH');
define('APP_TAGLINE', 'Technology • Training • Innovation • Solutions');
define('BASE_URL', '/enecom_platform/public'); // Update when deployed or folder name changes.

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'enecom_platform');
define('DB_USER', 'root');
define('DB_PASS', '');

// WhatsApp Cloud API settings. Keep secrets in Windows environment variables or server environment.
define('WA_ACCESS_TOKEN', (string)(getenv('ENE_WA_ACCESS_TOKEN') ?: ''));
define('WA_PHONE_NUMBER_ID', (string)(getenv('ENE_WA_PHONE_NUMBER_ID') ?: ''));
define('WA_VERIFY_TOKEN', (string)(getenv('ENE_WA_VERIFY_TOKEN') ?: ''));
define('WA_APP_SECRET', (string)(getenv('ENE_WA_APP_SECRET') ?: ''));
define('WA_GRAPH_VERSION', (string)(getenv('ENE_WA_GRAPH_VERSION') ?: 'v23.0'));

if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $secure,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function require_login(): void {
    if (!current_user()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function require_role(string ...$roles): void {
    require_login();
    if (!in_array(current_user()['role'] ?? '', $roles, true)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
function verify_csrf(): bool {
    return isset($_POST['csrf_token'], $_SESSION['csrf_token']) && is_string($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}
function log_activity(?int $actorId, string $type, string $description, ?string $relatedType = null, ?int $relatedId = null): void {
    $stmt = db()->prepare('INSERT INTO activity_log (actor_user_id, activity_type, description, related_type, related_id) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$actorId, $type, $description, $relatedType, $relatedId]);
}

