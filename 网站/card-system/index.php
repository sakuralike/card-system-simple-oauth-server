<?php
/**
 * 卡密提取系统 - 首页
 */

require_once __DIR__ . '/init.php';

$user = Auth::getUser();
$loginUrl = $user ? '' : Auth::getLoginUrl();
$cardManager = new CardManager();

$categories = $cardManager->getCategories(true);
$remainingDraws = $user ? $cardManager->getUserRemainingDraws($user['id']) : 0;
$selectedCategoryId = (int) ($categories[0]['id'] ?? 0);
$cardsPerDraw = $user['cards_per_draw'] ?? DEFAULT_CARDS_PER_DRAW;
$cardsPerDraw = max(1, (int) ($cardsPerDraw === '' ? DEFAULT_CARDS_PER_DRAW : $cardsPerDraw));
$drawBlockedReason = '';
if (!empty($user['is_banned'])) {
    $drawBlockedReason = '您的账号已被禁止提取';
} elseif (!$categories) {
    $drawBlockedReason = '暂无开放的卡密分类';
} elseif ($remainingDraws <= 0) {
    $drawBlockedReason = '今日提取次数已用完';
}

// 获取每个分类的可用卡密数量
foreach ($categories as &$cat) {
    $cat['available_count'] = $cardManager->getAvailableCardCount($cat['id']);
}
unset($cat);

$notice = getSetting('site_notice');
$rules = getSetting('draw_rules');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(SITE_NAME); ?></title>
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
                <?php if ($user): ?>
                <a href="history.php">提取记录</a>
                <a href="query.php">查询卡密</a>
                <div class="user-info">
                    <?php if (!empty($user['avatar'])): ?>
                    <img src="<?php echo e($user['avatar']); ?>" alt="" class="user-avatar">
                    <?php endif; ?>
                    <span class="user-name"><?php echo e($user['username']); ?></span>
                    <a href="logout.php" class="btn btn-secondary btn-sm">退出</a>
                </div>
                <?php else: ?>
                <a href="<?php echo e($loginUrl); ?>" class="btn btn-primary btn-sm">登录提取</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>
    
    <section class="hero">
        <div class="container">
            <h1>🎁 <?php echo e(SITE_NAME); ?></h1>
            <p>登录后即可免费提取卡密，每日限量，先到先得！</p>
            <?php if (!$user): ?>
            <a href="<?php echo e($loginUrl); ?>" class="btn btn-lg" style="background: white; color: var(--primary);">
                🔐 使用 <?php echo e((string) parse_url(WP_SITE_URL, PHP_URL_HOST)); ?> 账号登录
            </a>
            <?php endif; ?>
        </div>
    </section>
    
    <main class="container">
        <?php if ($notice): ?>
        <div class="notice">
            <div class="notice-title">📢 公告</div>
            <div><?php echo nl2br(e($notice)); ?></div>
        </div>
        <?php endif; ?>
        
        <?php if ($user): ?>
        
        <!-- 分类选择 -->
        <div class="card">
            <div class="card-header">
                <h2>📁 选择分类</h2>
            </div>
            <div class="card-body">
                <div class="category-grid">
                    <?php foreach ($categories as $cat): ?>
                    <div class="category-card <?php echo (int) $cat['id'] === $selectedCategoryId ? 'active' : ''; ?>" data-id="<?php echo (int) $cat['id']; ?>">
                        <div class="category-icon"><?php echo e($cat['icon']); ?></div>
                        <div class="category-name"><?php echo e($cat['name']); ?></div>
                        <div class="category-count">可用: <?php echo $cat['available_count']; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" id="selected-category" value="<?php echo $selectedCategoryId; ?>">
            </div>
        </div>
        
        <!-- 提取区域 -->
        <div class="draw-section">
            <input type="hidden" id="csrf-token" value="<?php echo csrfToken(); ?>">
            
            <button id="draw-btn" class="btn btn-primary draw-btn" data-user-id="<?php echo (int) $user['id']; ?>" data-banned="<?php echo !empty($user['is_banned']) ? '1' : '0'; ?>" <?php echo $drawBlockedReason !== '' ? 'disabled' : ''; ?>>
                🎁 立即提取
            </button>
            
            <div class="draw-info">
                今日剩余次数: <span class="remaining-count" id="remaining-count"><?php echo $remainingDraws; ?></span> 次<br>
                每次提取 <?php echo $cardsPerDraw; ?> 张，仅消耗 1 次；库存不足时整次不发卡、不扣次数。<br>
                <a href="history.php">查看已领取的卡密</a>
            </div>
            <div id="draw-status" class="draw-info" role="status"><?php echo e($drawBlockedReason); ?></div>
            
            <div id="draw-result" style="margin-top: 30px;"></div>
        </div>
        
        <!-- 规则说明 -->
        <?php if ($rules): ?>
        <div class="card" style="margin-top: 30px;">
            <div class="card-header">
                <h2>📋 提取规则</h2>
            </div>
            <div class="card-body">
                <?php echo nl2br(e($rules)); ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php else: ?>
        
        <!-- 未登录提示 -->
        <div class="login-container">
            <div class="card login-card">
                <div class="card-body">
                    <div class="login-icon">🔐</div>
                    <h2 class="login-title">请先登录</h2>
                    <p class="login-desc">使用 <?php echo e((string) parse_url(WP_SITE_URL, PHP_URL_HOST)); ?> 账号登录后即可提取卡密</p>
                    <a href="<?php echo e($loginUrl); ?>" class="btn btn-primary btn-lg">
                        立即登录
                    </a>
                </div>
            </div>
        </div>
        
        <?php endif; ?>
    </main>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> <?php echo e(SITE_NAME); ?> - Powered by Card System</p>
    </footer>
    
    <script src="assets/js/app.js?v=<?php echo filemtime(__DIR__ . '/assets/js/app.js'); ?>"></script>
</body>
</html>
