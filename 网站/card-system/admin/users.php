<?php
/**
 * 用户管理
 */

require_once 'common.php';

$db = Database::getInstance();
$db->ensureDrawSchema();
$message = '';
$messageType = '';

// 处理表单
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // 更新用户设置
    if ($action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        // 不允许把自己降权，避免误操作把自己锁在后台外
        if ($id && $id === intval($adminUser['id'] ?? 0) && ($_POST['role'] ?? '') !== 'admin') {
            $message = '不能修改自己的管理员角色';
            $messageType = 'error';
            $id = 0;
        }
        if ($id) {
            $db->update('users', [
                'role' => $_POST['role'] === 'admin' ? 'admin' : 'user',
                'daily_limit' => max(0, intval($_POST['daily_limit'] ?? DEFAULT_DAILY_LIMIT)),
                'cards_per_draw' => max(1, intval($_POST['cards_per_draw'] ?? DEFAULT_CARDS_PER_DRAW)),
                'is_banned' => isset($_POST['is_banned']) ? 1 : 0,
            ], 'id = ?', [$id]);
            $message = '用户设置已更新';
            $messageType = 'success';
        }
    }
    
    // 批量设置
    if ($action === 'batch_update') {
        $ids = sanitizeIds($_POST['ids'] ?? []);
        if (!empty($ids)) {
            $dailyLimit = max(0, intval($_POST['batch_daily_limit'] ?? DEFAULT_DAILY_LIMIT));
            $cardsPerDraw = max(1, intval($_POST['batch_cards_per_draw'] ?? DEFAULT_CARDS_PER_DRAW));
            
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $db->query(
                "UPDATE " . DB_PREFIX . "users SET daily_limit = ?, cards_per_draw = ? WHERE id IN ($placeholders)",
                array_merge([$dailyLimit, $cardsPerDraw], $ids)
            );
            $message = '批量设置成功';
            $messageType = 'success';
        }
    }
}

// 分页和筛选
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$keyword = trim($_GET['keyword'] ?? '');
$where = "1=1";
$params = [];

if (!empty($keyword)) {
    $where .= " AND (username LIKE ? OR email LIKE ?)";
    $params[] = "%$keyword%";
    $params[] = "%$keyword%";
}

$total = $db->fetch(
    "SELECT COUNT(*) as cnt FROM " . DB_PREFIX . "users WHERE $where",
    $params
)['cnt'];

$today = date('Y-m-d');
$users = $db->fetchAll(
    "SELECT u.*, 
            (SELECT COUNT(DISTINCT COALESCE(request_id, CONCAT('legacy-', id))) FROM " . DB_PREFIX . "draw_logs WHERE user_id = u.id) as total_draws,
            (SELECT COUNT(DISTINCT COALESCE(request_id, CONCAT('legacy-', id))) FROM " . DB_PREFIX . "draw_logs WHERE user_id = u.id AND created_at >= ? AND created_at < ?) as today_draws
     FROM " . DB_PREFIX . "users u
     WHERE $where
     ORDER BY u.id DESC
     LIMIT $offset, $perPage",
    array_merge([$today . ' 00:00:00', date('Y-m-d', strtotime($today . ' +1 day')) . ' 00:00:00'], $params)
);

$totalPages = ceil($total / $perPage);

$editUser = null;
if (isset($_GET['edit'])) {
    $editUser = $db->fetch(
        "SELECT * FROM " . DB_PREFIX . "users WHERE id = ?",
        [intval($_GET['edit'])]
    );
}

adminHeader('用户管理');
?>

<?php if ($message): ?>
<div class="alert alert-<?php echo $messageType; ?>"><?php echo e($message); ?></div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr <?php echo $editUser ? '350px' : '0'; ?>; gap: 20px;">
    <!-- 用户列表 -->
    <div class="admin-card">
        <div class="card-header">
            <h3 class="card-title">用户列表 (共 <?php echo $total; ?> 人)</h3>
        </div>
        <div class="card-body">
            <!-- 搜索 -->
            <form class="filter-bar" method="get">
                <input type="text" name="keyword" class="form-control" placeholder="搜索用户名/邮箱" value="<?php echo e($keyword); ?>">
                <button type="submit" class="btn btn-primary">搜索</button>
                <a href="users.php" class="btn btn-secondary">重置</a>
            </form>
            
            <form method="post">
                <?php echo csrfField(); ?>
                <!-- 批量操作 -->
                <div class="bulk-actions">
                    <span>批量设置:</span>
                    <label>每日次数:</label>
                    <input type="number" name="batch_daily_limit" class="form-control" value="<?php echo DEFAULT_DAILY_LIMIT; ?>" style="width: 80px;">
                    <label>每次数量:</label>
                    <input type="number" name="batch_cards_per_draw" class="form-control" value="<?php echo DEFAULT_CARDS_PER_DRAW; ?>" style="width: 80px;">
                    <button type="submit" name="action" value="batch_update" class="btn btn-sm btn-primary">应用到选中</button>
                </div>
                
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th width="40"><input type="checkbox" class="select-all"></th>
                            <th width="60">ID</th>
                            <th>用户</th>
                            <th width="80">角色</th>
                            <th width="100">每日次数</th>
                            <th width="100">每次数量</th>
                            <th width="120">今日/总提取</th>
                            <th width="80">状态</th>
                            <th width="80">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td><input type="checkbox" name="ids[]" value="<?php echo $user['id']; ?>" class="row-checkbox"></td>
                            <td><?php echo $user['id']; ?></td>
                            <td>
                                <strong><?php echo e($user['username']); ?></strong>
                                <br><small style="color: #888;"><?php echo e($user['email']); ?></small>
                            </td>
                            <td>
                                <?php if ($user['role'] === 'admin'): ?>
                                <span class="badge badge-info">管理员</span>
                                <?php else: ?>
                                <span class="badge badge-success">用户</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $user['daily_limit'] ?? DEFAULT_DAILY_LIMIT; ?></td>
                            <td><?php echo $user['cards_per_draw'] ?: DEFAULT_CARDS_PER_DRAW; ?></td>
                            <td><?php echo $user['today_draws']; ?> / <?php echo $user['total_draws']; ?></td>
                            <td>
                                <?php if ($user['is_banned']): ?>
                                <span class="badge badge-danger">已禁止</span>
                                <?php else: ?>
                                <span class="badge badge-success">正常</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="?edit=<?php echo $user['id']; ?>" class="btn btn-sm btn-primary">编辑</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
            
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?>&keyword=<?php echo urlencode($keyword); ?>">上一页</a>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <?php if ($i == $page): ?>
                    <span class="active"><?php echo $i; ?></span>
                    <?php else: ?>
                    <a href="?page=<?php echo $i; ?>&keyword=<?php echo urlencode($keyword); ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                <a href="?page=<?php echo $page + 1; ?>&keyword=<?php echo urlencode($keyword); ?>">下一页</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if ($editUser): ?>
    <!-- 编辑用户 -->
    <div class="admin-card">
        <div class="card-header">
            <h3 class="card-title">编辑用户</h3>
        </div>
        <div class="card-body">
            <form method="post">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?php echo $editUser['id']; ?>">
                
                <div class="form-group">
                    <label class="form-label">用户名</label>
                    <input type="text" class="form-control" value="<?php echo e($editUser['username']); ?>" disabled>
                </div>
                
                <div class="form-group">
                    <label class="form-label">邮箱</label>
                    <input type="text" class="form-control" value="<?php echo e($editUser['email']); ?>" disabled>
                </div>
                
                <div class="form-group">
                    <label class="form-label">角色</label>
                    <select name="role" class="form-control">
                        <option value="user" <?php echo $editUser['role'] === 'user' ? 'selected' : ''; ?>>普通用户</option>
                        <option value="admin" <?php echo $editUser['role'] === 'admin' ? 'selected' : ''; ?>>管理员</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">每日提取次数</label>
                    <input type="number" name="daily_limit" class="form-control" value="<?php echo $editUser['daily_limit'] ?? DEFAULT_DAILY_LIMIT; ?>" min="0">
                </div>
                
                <div class="form-group">
                    <label class="form-label">每次提取数量</label>
                    <input type="number" name="cards_per_draw" class="form-control" value="<?php echo $editUser['cards_per_draw'] ?: DEFAULT_CARDS_PER_DRAW; ?>" min="1">
                </div>
                
                <div class="form-group">
                    <label class="form-check">
                        <input type="checkbox" name="is_banned" value="1" <?php echo $editUser['is_banned'] ? 'checked' : ''; ?>>
                        禁止提取
                    </label>
                </div>
                
                <button type="submit" class="btn btn-primary">保存设置</button>
                <a href="users.php" class="btn btn-secondary">取消</a>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php adminFooter(); ?>
