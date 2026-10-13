<?php
/**
 * WordPress OAuth 回调处理
 */

require_once __DIR__ . '/init.php';

try {
    // ★ 关键修复：如果用户已经登录，直接跳转到首页
    // 这可以防止浏览器重复请求导致的错误显示
    if (Auth::isLoggedIn()) {
        redirect(SITE_URL);
    }
    
    $code = $_GET['code'] ?? '';
    $state = $_GET['state'] ?? '';
    $error = $_GET['error'] ?? '';

    if (!is_string($code) || !is_string($state) || !is_string($error)) {
        throw new Exception('无效的授权参数');
    }
    
    if ($error) {
        throw new Exception('授权失败: ' . $error);
    }
    
    if (empty($code)) {
        throw new Exception('无效的授权码');
    }
    
    // ★ 防止重复处理：使用session标记
    // L7：改用 sha256（md5 已不推荐）；失败路径会在 catch 中清除该标记，
    // 避免同一 code 出错后重试被误判为"已处理"而直接跳转。
    $codeHash = hash('sha256', $code);
    if (isset($_SESSION['processed_code']) && hash_equals((string) $_SESSION['processed_code'], $codeHash)) {
        // 这个code已经处理过了，直接跳转
        redirect(SITE_URL);
    }
    $_SESSION['processed_code'] = $codeHash;
    
    // 处理OAuth回调
    $user = Auth::handleCallback($code, $state);
    
    if (!$user) {
        throw new Exception('登录失败，请重试');
    }
    
    // 登录用户
    Auth::login($user);
    
    // 清除处理标记
    unset($_SESSION['processed_code']);
    
    // 跳转到首页
    redirect(SITE_URL);
    
} catch (Exception $e) {
    // L7：失败时必须清除去重标记，否则用户重试会直接被跳转、看不到错误原因
    unset($_SESSION['processed_code']);
    error_log('OAuth callback failed: ' . $e->getMessage());
    $loginUrl = Auth::getLoginUrl();

    // 显示错误页面
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>登录失败 - <?php echo e(SITE_NAME); ?></title>
        <link rel="stylesheet" href="assets/css/style.css">
    </head>
    <body>
        <div class="container">
            <div class="login-container">
                <div class="card">
                    <div class="card-body" style="text-align: center;">
                        <div style="font-size: 4rem; margin-bottom: 20px;">😢</div>
                        <h2>登录失败</h2>
                        <p style="color: #888; margin: 20px 0;"><?php echo e($e->getMessage()); ?></p>
                        <a href="<?php echo SITE_URL; ?>" class="btn btn-primary">返回首页</a>
                        <a href="<?php echo e($loginUrl); ?>" class="btn btn-secondary">重新登录</a>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
}
