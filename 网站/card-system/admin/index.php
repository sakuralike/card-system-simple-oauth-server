<?php
/**
 * 后台仪表盘
 */

require_once 'common.php';

$cardManager = new CardManager();
$stats = $cardManager->getStats();

// 最近提取记录
$db = Database::getInstance();
$recentLogs = $db->fetchAll(
    "SELECT l.*, u.username, COALESCE(l.card_code, c.card_code, '') AS card_code,
            COALESCE(l.category_name, cat.name, '') AS category_name
     FROM " . DB_PREFIX . "draw_logs l
     LEFT JOIN " . DB_PREFIX . "users u ON l.user_id = u.id
     LEFT JOIN " . DB_PREFIX . "cards c ON l.card_id = c.id
     LEFT JOIN " . DB_PREFIX . "categories cat ON l.category_id = cat.id
     ORDER BY l.created_at DESC
     LIMIT 10"
);

adminHeader('仪表盘');
?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">🎫</div>
        <div class="stat-value"><?php echo $stats['total_cards']; ?></div>
        <div class="stat-label">总卡密数</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">✅</div>
        <div class="stat-value"><?php echo $stats['available_cards']; ?></div>
        <div class="stat-label">可用卡密</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">📤</div>
        <div class="stat-value"><?php echo $stats['used_cards']; ?></div>
        <div class="stat-label">已使用</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">👥</div>
        <div class="stat-value"><?php echo $stats['total_users']; ?></div>
        <div class="stat-label">用户数</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">📊</div>
        <div class="stat-value"><?php echo $stats['total_draws']; ?></div>
        <div class="stat-label">总提取次数</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">📅</div>
        <div class="stat-value"><?php echo $stats['today_draws']; ?></div>
        <div class="stat-label">今日提取</div>
    </div>
</div>

<div class="admin-card">
    <div class="card-header">
        <h3 class="card-title">📜 最近提取记录</h3>
        <a href="logs.php" class="btn btn-sm btn-primary">查看全部</a>
    </div>
    <div class="card-body">
        <?php if (empty($recentLogs)): ?>
        <p style="text-align: center; color: #888; padding: 30px;">暂无提取记录</p>
        <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>用户</th>
                    <th>卡密</th>
                    <th>分类</th>
                    <th>时间</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentLogs as $log): ?>
                <tr>
                    <td><?php echo e($log['username']); ?></td>
                    <td><code><?php echo e(substr($log['card_code'], 0, 20)); ?>...</code></td>
                    <td><?php echo e($log['category_name'] ?: '默认'); ?></td>
                    <td><?php echo e($log['created_at']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php adminFooter(); ?>
