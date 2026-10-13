<?php
/**
 * 提取记录页面
 */

require_once __DIR__ . '/init.php';

$user = Auth::getUser();
if (!$user) {
    redirect(Auth::getLoginUrl());
}

$cardManager = new CardManager();
$page = max(1, intval($_GET['page'] ?? 1));
$result = $cardManager->getUserDrawLogs($user['id'], $page, 20);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>提取记录 - <?php echo e(SITE_NAME); ?></title>
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
                <h2>📜 我的提取记录</h2>
            </div>
            <div class="card-body">
                <?php if (empty($result['logs'])): ?>
                <div style="text-align: center; padding: 40px; color: #888;">
                    <div style="font-size: 3rem; margin-bottom: 15px;">📭</div>
                    <p>暂无提取记录</p>
                    <a href="<?php echo SITE_URL; ?>" class="btn btn-primary" style="margin-top: 15px;">去提取</a>
                </div>
                <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>卡密</th>
                                <th>兑换内容</th>
                                <th>分类</th>
                                <th>提取时间</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result['logs'] as $log): ?>
                            <tr>
                                <td>
                                    <div class="card-code" id="code-<?php echo $log['id']; ?>" style="padding: 8px 12px; font-size: 0.9rem;">
                                        <?php echo e($log['card_code']); ?>
                                    </div>
                                </td>
                                <td><?php echo e($log['content'] ?: '-'); ?></td>
                                <td><?php echo e($log['category_name'] ?: '默认分类'); ?></td>
                                <td><?php echo e($log['created_at']); ?></td>
                                <td>
                                    <button class="btn btn-secondary btn-sm" onclick="copyCode('code-<?php echo $log['id']; ?>')">
                                        复制
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if ($result['totalPages'] > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>">上一页</a>
                    <?php endif; ?>
                    
                    <?php for ($i = 1; $i <= $result['totalPages']; $i++): ?>
                        <?php if ($i == $page): ?>
                        <span class="active"><?php echo $i; ?></span>
                        <?php else: ?>
                        <a href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $result['totalPages']): ?>
                    <a href="?page=<?php echo $page + 1; ?>">下一页</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> <?php echo e(SITE_NAME); ?></p>
    </footer>
    
    <script src="assets/js/app.js"></script>
</body>
</html>
