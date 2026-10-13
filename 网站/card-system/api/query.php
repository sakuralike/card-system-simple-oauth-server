<?php
/**
 * 卡密查询API
 */

require_once __DIR__ . '/../init.php';

header('Content-Type: application/json');

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

$cardCode = is_string($input['card_code'] ?? null) ? trim($input['card_code']) : '';

if (empty($cardCode)) {
    jsonResponse(['success' => false, 'message' => '请输入卡密']);
}

$user = Auth::getUser();
if (!$user) {
    jsonResponse(['success' => false, 'message' => '请重新登录'], 401);
}
$cardManager = new CardManager();
$card = $cardManager->queryCard($user['id'], $cardCode);

if ($card) {
    jsonResponse([
        'success' => true,
        'card' => $card
    ]);
} else {
    jsonResponse([
        'success' => false,
        'message' => '未找到该卡密，或该卡密不是您提取的'
    ]);
}
