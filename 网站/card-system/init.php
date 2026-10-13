<?php
/**
 * 初始化文件
 */

// 启动会话（设置更兼容的cookie参数）
if (session_status() === PHP_SESSION_NONE) {
    // 设置session cookie参数
    // 注意：SameSite=None 需要 Secure=true
    $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        // 使用 Lax 而不是 Strict，允许跨站点 GET 请求携带 cookie
        'samesite' => 'Lax'
    ]);
    session_start();
}

// 确保session正常工作
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// 加载配置
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    die('请先配置 config.php 文件');
}
require_once $configFile;

// 加载类文件
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/CardManager.php';
require_once __DIR__ . '/includes/BrowserCheck.php';

// 浏览器检测（仅 API 目录跳过）
// M13：旧版用 strpos(REQUEST_URI, '/api/') 判断，访问 /query.php?ref=/api/x 即可绕过。
// 改为依据脚本所在目录判断，查询参数无法影响结果。
$scriptDir = basename(dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$skipBrowserCheck = ('api' === $scriptDir);

if (!$skipBrowserCheck) {
    BrowserCheck::check();
}

// 辅助函数
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function redirect($url) {
    header('Location: ' . $url);
    exit;
}

function e($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function getSetting($key, $default = '') {
    static $settings = null;
    
    if ($settings === null) {
        $db = Database::getInstance();
        $rows = $db->fetchAll("SELECT setting_key, setting_value FROM " . DB_PREFIX . "settings");
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    
    return $settings[$key] ?? $default;
}

function setSetting($key, $value) {
    $db = Database::getInstance();
    $db->query(
        "INSERT INTO " . DB_PREFIX . "settings (setting_key, setting_value) 
         VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?",
        [$key, $value, $value]
    );
}

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf($token) {
    return is_string($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * 将批量操作的 ID 数组规范为「正整数数组」（M12）
 *
 * @param mixed $value
 * @return int[]
 */
function sanitizeIds($value) {
    $ids = array_map('intval', (array) $value);
    $ids = array_filter($ids, function ($v) { return $v > 0; });
    return array_values($ids);
}

/**
 * 取当前应用版本号（老版本 config.php 可能未定义 APP_VERSION）
 */
function appVersion() {
    return defined('APP_VERSION') ? APP_VERSION : '1.0.6';
}
