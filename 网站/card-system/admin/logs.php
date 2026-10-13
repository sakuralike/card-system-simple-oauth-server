<?php
/**
 * 提取记录
 */

require_once 'common.php';

$db = Database::getInstance();
$db->ensureDrawSchema();

// 分页和筛选
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$userId = intval($_GET['user_id'] ?? 0);
$categoryId = intval($_GET['category_id'] ?? 0);
$date = $_GET['date'] ?? '';

$where = "1=1";
$params = [];

if ($userId) {
    $where .= " AND l.user_id = ?";
    $params[] = $userId;
}

if ($categoryId) {
    $where .= " AND l.category_id = ?";
    $params[] = $categoryId;
}

if ($date) {
    $where .= " AND DATE(l.created_at) = ?";
    $params[] = $date;
}

$total = $db->fetch(
    "SELECT COUNT(*) as cnt FROM " . DB_PREFIX . "draw_logs l WHERE $where",
    $params
)['cnt'];

$logs = $db->fetchAll(
    "SELECT l.*, u.username, COALESCE(l.card_code, c.card_code, '') AS card_code,
            COALESCE(l.content, c.content, '') AS content,
            COALESCE(l.category_name, cat.name, '') AS category_name
     FROM " . DB_PREFIX . "draw_logs l
     LEFT JOIN " . DB_PREFIX . "users u ON l.user_id = u.id
     LEFT JOIN " . DB_PREFIX . "cards c ON l.card_id = c.id
     LEFT JOIN " . DB_PREFIX . "categories cat ON l.category_id = cat.id
     WHERE $where
     ORDER BY l.created_at DESC
     LIMIT $offset, $perPage",
    $params
);

$totalPages = ceil($total / $perPage);

// 获取分类列表
$cardManager = new CardManager();
$categories = $cardManager->getCategories(false);

adminHeader('提取记录');
?>

<div class="admin-card">
    <div class="card-header">
        <h3 class="card-title">提取记录 (共 <?php echo $total; ?> 条)</h3>
    </div>
    <div class="card-body">
        <!-- 筛选 -->
        <form class="filter-bar" method="get">
            <select name="category_id" class="form-control">
                <option value="">全部分类</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?php echo $cat['id']; ?>" <?php echo $categoryId == $cat['id'] ? 'selected' : ''; ?>>
                    <?php echo e($cat['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="date" class="form-control" value="<?php echo e($date); ?>">
            <button type="submit" class="btn btn-primary">筛选</button>
            <a href="logs.php" class="btn btn-secondary">重置</a>
        </form>
        
        <table class="admin-table">
            <thead>
                <tr>
                    <th width="60">ID</th>
                    <th width="120">用户</th>
                    <th>卡密</th>
                    <th>兑换内容</th>
                    <th width="100">分类</th>
                    <th width="120">IP地址</th>
                    <th width="160">时间</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?php echo $log['id']; ?></td>
                    <td>
                        <a href="?user_id=<?php echo $log['user_id']; ?>"><?php echo e($log['username']); ?></a>
                    </td>
                    <td><code style="word-break: break-all;"><?php echo e($log['card_code']); ?></code></td>
                    <td><?php echo e($log['content'] ?: '-'); ?></td>
                    <td><?php echo e($log['category_name'] ?: '默认'); ?></td>
                    <td><?php echo e($log['ip_address']); ?></td>
                    <td><?php echo e($log['created_at']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php 
            $qs = http_build_query(['category_id' => $categoryId, 'date' => $date, 'user_id' => $userId]);
            ?>
            <?php if ($page > 1): ?>
            <a href="?<?php echo $qs; ?>&page=<?php echo $page - 1; ?>">上一页</a>
            <?php endif; ?>
            
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <?php if ($i == $page): ?>
                <span class="active"><?php echo $i; ?></span>
                <?php else: ?>
                <a href="?<?php echo $qs; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
            <a href="?<?php echo $qs; ?>&page=<?php echo $page + 1; ?>">下一页</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php adminFooter(); ?>
