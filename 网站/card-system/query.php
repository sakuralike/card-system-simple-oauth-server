<?php
/**
 * 卡密查询页面
 */

require_once __DIR__ . '/init.php';

$user = Auth::getUser();
if (!$user) {
    redirect(Auth::getLoginUrl());
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>查询卡密 - <?php echo e(SITE_NAME); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header class="header">
        <div class="container">
            <a href="<?php echo SITE_URL; ?>" class="logo">
                <span class="logo-icon">🎀</span>
                <span><?php echo e(SITE_NAME); ?></span>
            </a>
            
            <nav class="nav">
                <a href="<?php echo SITE_URL; ?>">首页</a>
                <a href="history.php">提取记录</a>
                <a href="query.php">查询卡密</a>
                <div class="user-info">
                    <span class="user-name"><?php echo e($user['username']); ?></span>
                    <a href="logout.php" class="btn btn-secondary btn-sm">退出</a>
                </div>
            </nav>
        </div>
    </header>
    
    <main class="container">
        <div class="card">
            <div class="card-header">
                <h2>🔍 查询卡密</h2>
            </div>
            <div class="card-body">
                <p style="color: #888; margin-bottom: 20px;">输入您已提取的卡密，查询详细信息</p>
                
                <form id="query-form">
                    <input type="hidden" id="csrf-token" value="<?php echo csrfToken(); ?>">
                    
                    <div class="form-group">
                        <label for="query-code">卡密</label>
                        <input type="text" id="query-code" class="form-control" placeholder="请输入要查询的卡密" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">🔍 查询</button>
                </form>
                
                <div id="query-result" style="margin-top: 30px;"></div>
            </div>
        </div>
    </main>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> <?php echo e(SITE_NAME); ?></p>
    </footer>
    
    <script src="assets/js/app.js"></script>
</body>
</html>
