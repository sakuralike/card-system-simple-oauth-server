<?php
/**
 * 卡密提取API
 */

require_once __DIR__ . '/../init.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    jsonResponse(['success' => false, 'message' => '请使用 POST 请求'], 405);
}

// 检查登录
if (!Auth::isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => '请先登录'], 401);
}

// 获取请求数据
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    jsonResponse(['success' => false, 'message' => '请求格式无效'], 400);
}

// 验证CSRF
if (!verifyCsrf($input['csrf_token'] ?? '')) {
    jsonResponse(['success' => false, 'message' => '请求验证失败，请刷新页面重试'], 403);
}

$user = Auth::getUser();
if (!$user) {
    jsonResponse(['success' => false, 'message' => '请重新登录'], 401);
}

$category = $input['category_id'] ?? null;
$categoryId = (is_int($category) || is_string($category))
    ? filter_var($category, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]])
    : false;
$requestId = $input['request_id'] ?? '';
if ($categoryId === false || !is_string($requestId) || !preg_match('/^[a-f0-9]{32}$/D', $requestId)) {
    jsonResponse(['success' => false, 'message' => '领取参数无效，请刷新页面'], 400);
}

session_write_close();
try {
    $cardManager = new CardManager();
    $result = $cardManager->drawCard($user['id'], $categoryId, $requestId);
} catch (Throwable $e) {
    error_log('Draw API failed: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => '领取暂不可用，请稍后重试本次领取', 'retryable' => true], 503);
}

jsonResponse($result, !empty($result['retryable']) ? 503 : 200);
