<?php
/**
 * 卡密管理
 */

require_once 'common.php';

$cardManager = new CardManager();
$db = Database::getInstance();
$message = '';
$messageType = '';

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // 添加单个卡密
    if ($action === 'add') {
        $cardCode = trim($_POST['card_code'] ?? '');
        $categoryId = intval($_POST['category_id'] ?? 1);
        $content = trim($_POST['content'] ?? '');
        
        if (!empty($cardCode)) {
            try {
                $cardId = $cardManager->addCard([
                    'card_code' => $cardCode,
                    'category_id' => $categoryId,
                    'content' => $content,
                ]);
                $message = $cardId === false ? '该分类已存在或已发放过相同卡密，未重复添加' : '卡密添加成功';
                $messageType = $cardId === false ? 'error' : 'success';
            } catch (InvalidArgumentException $e) {
                $message = $e->getMessage();
                $messageType = 'error';
            }
        }
    }
    
    // 批量导入
    if ($action === 'import') {
        $categoryId = intval($_POST['category_id'] ?? 1);
        $content = trim($_POST['content'] ?? '');
        $cardsText = trim($_POST['cards'] ?? '');
        
        if (!empty($cardsText)) {
            $cards = preg_split('/[\r\n]+/', $cardsText);
            try {
                $count = $cardManager->importCards($categoryId, $cards, $content);
                $message = "成功导入 {$count} 个卡密，同分类重复或已发放的卡密已跳过";
                $messageType = 'success';
            } catch (InvalidArgumentException $e) {
                $message = $e->getMessage();
                $messageType = 'error';
            }
        }
    }
    
    // 批量设置内容
    if ($action === 'batch_content') {
        $ids = sanitizeIds($_POST['ids'] ?? []);
        $content = trim($_POST['batch_content'] ?? '');
        
        if (!empty($ids)) {
            $cardManager->batchUpdateContent($ids, $content);
            $message = '批量设置成功';
            $messageType = 'success';
        }
    }
    
    // 批量设置分类
    if ($action === 'batch_category') {
        $ids = sanitizeIds($_POST['ids'] ?? []);
        $categoryId = intval($_POST['batch_category_id'] ?? 1);
        
        if (!empty($ids)) {
            $cardManager->batchUpdateCategory($ids, $categoryId);
            $message = '批量设置成功';
            $messageType = 'success';
        }
    }
    
    // 批量删除
    if ($action === 'batch_delete') {
        $ids = sanitizeIds($_POST['ids'] ?? []);
        
        if (!empty($ids)) {
            $cardManager->batchDeleteCards($ids);
            $message = '批量删除成功';
            $messageType = 'success';
        }
    }
    
    // 删除单个
    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            $cardManager->deleteCard($id);
            $message = '删除成功';
            $messageType = 'success';
        }
    }
}

// 获取分类列表
$categories = $cardManager->getCategories(false);

// 筛选和分页
$page = max(1, intval($_GET['page'] ?? 1));
$filters = [
    'category_id' => $_GET['category_id'] ?? '',
    'status' => $_GET['status'] ?? '',
    'keyword' => $_GET['keyword'] ?? '',
];
$result = $cardManager->getCards($filters, $page, 30);

adminHeader('卡密管理');
?>

<?php if ($message): ?>
<div class="alert alert-<?php echo $messageType; ?>"><?php echo e($message); ?></div>
<?php endif; ?>

<div class="tabs">
    <a href="#" class="tab-link active" data-tab="list">卡密列表</a>
    <a href="#" class="tab-link" data-tab="add">添加卡密</a>
    <a href="#" class="tab-link" data-tab="import">批量导入</a>
</div>

<!-- 卡密列表 -->
<div class="tab-content active" id="tab-list">
    <div class="admin-card">
        <div class="card-header">
            <h3 class="card-title">卡密列表 (共 <?php echo $result['total']; ?> 个)</h3>
        </div>
        <div class="card-body">
            <!-- 筛选 -->
            <form class="filter-bar" method="get">
                <select name="category_id" class="form-control">
                    <option value="">全部分类</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo $filters['category_id'] == $cat['id'] ? 'selected' : ''; ?>>
                        <?php echo e($cat['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <select name="status" class="form-control">
                    <option value="">全部状态</option>
                    <option value="available" <?php echo $filters['status'] == 'available' ? 'selected' : ''; ?>>可用</option>
                    <option value="used" <?php echo $filters['status'] == 'used' ? 'selected' : ''; ?>>已使用</option>
                    <option value="disabled" <?php echo $filters['status'] == 'disabled' ? 'selected' : ''; ?>>已禁用</option>
                </select>
                <input type="text" name="keyword" class="form-control" placeholder="搜索卡密/内容" value="<?php echo e($filters['keyword']); ?>">
                <button type="submit" class="btn btn-primary">筛选</button>
                <a href="cards.php" class="btn btn-secondary">重置</a>
            </form>
            
            <form method="post" id="batch-form">
                <?php echo csrfField(); ?>
                <!-- 批量操作 -->
                <div class="bulk-actions">
                    <span>批量操作:</span>
                    <select name="batch_category_id" class="form-control" style="width: 150px;">
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>"><?php echo e($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" name="action" value="batch_category" class="btn btn-sm btn-primary">设为此分类</button>
                    <input type="text" name="batch_content" class="form-control" placeholder="兑换内容" style="width: 200px;">
                    <button type="submit" name="action" value="batch_content" class="btn btn-sm btn-primary">设置内容</button>
                    <button type="submit" name="action" value="batch_delete" class="btn btn-sm btn-danger confirm-delete">删除选中</button>
                </div>
                
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th width="40"><input type="checkbox" class="select-all"></th>
                            <th width="60">ID</th>
                            <th>卡密</th>
                            <th>兑换内容</th>
                            <th width="100">分类</th>
                            <th width="80">状态</th>
                            <th width="120">使用者</th>
                            <th width="100">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($result['cards'] as $card): ?>
                        <tr>
                            <td><input type="checkbox" name="ids[]" value="<?php echo $card['id']; ?>" class="row-checkbox"></td>
                            <td><?php echo $card['id']; ?></td>
                            <td><code style="word-break: break-all;"><?php echo e($card['card_code']); ?></code></td>
                            <td><?php echo e($card['content'] ?: '-'); ?></td>
                            <td><?php echo e($card['category_name'] ?: '默认'); ?></td>
                            <td>
                                <?php if ($card['status'] == 'available'): ?>
                                <span class="badge badge-success">可用</span>
                                <?php elseif ($card['status'] == 'used'): ?>
                                <span class="badge badge-warning">已使用</span>
                                <?php else: ?>
                                <span class="badge badge-danger">已禁用</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo e($card['used_by_name'] ?: '-'); ?></td>
                            <td>
                                <button type="submit" name="action" value="delete"
                                        class="btn btn-sm btn-danger btn-delete-id"
                                        data-id="<?php echo $card['id']; ?>">删除</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <input type="hidden" name="id" value="">
            </form>
            
            <?php if ($result['totalPages'] > 1): ?>
            <div class="pagination">
                <?php 
                $queryString = http_build_query(array_merge($filters, ['page' => '']));
                ?>
                <?php if ($page > 1): ?>
                <a href="?<?php echo $queryString; ?>page=<?php echo $page - 1; ?>">上一页</a>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page - 2); $i <= min($result['totalPages'], $page + 2); $i++): ?>
                    <?php if ($i == $page): ?>
                    <span class="active"><?php echo $i; ?></span>
                    <?php else: ?>
                    <a href="?<?php echo $queryString; ?>page=<?php echo $i; ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $result['totalPages']): ?>
                <a href="?<?php echo $queryString; ?>page=<?php echo $page + 1; ?>">下一页</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 添加卡密 -->
<div class="tab-content" id="tab-add">
    <div class="admin-card">
        <div class="card-header">
            <h3 class="card-title">添加单个卡密</h3>
        </div>
        <div class="card-body">
            <form method="post">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add">
                
                <div class="form-group">
                    <label class="form-label">分类</label>
                    <select name="category_id" class="form-control" style="max-width: 300px;">
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>"><?php echo e($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">卡密</label>
                    <input type="text" name="card_code" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">兑换内容（可选）</label>
                    <input type="text" name="content" class="form-control" placeholder="如：某游戏月卡">
                </div>
                
                <button type="submit" class="btn btn-primary">添加卡密</button>
            </form>
        </div>
    </div>
</div>

<!-- 批量导入 -->
<div class="tab-content" id="tab-import">
    <div class="admin-card">
        <div class="card-header">
            <h3 class="card-title">批量导入卡密</h3>
        </div>
        <div class="card-body">
            <form method="post">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="import">
                
                <div class="form-group">
                    <label class="form-label">分类</label>
                    <select name="category_id" class="form-control" style="max-width: 300px;">
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>"><?php echo e($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">兑换内容（可选，所有卡密共用）</label>
                    <input type="text" name="content" class="form-control" placeholder="如：某游戏月卡">
                </div>
                
                <div class="form-group">
                    <label class="form-label">卡密列表（每行一个）</label>
                    <textarea name="cards" class="form-control" rows="15" placeholder="XXXX-XXXX-XXXX-XXXX
YYYY-YYYY-YYYY-YYYY
ZZZZ-ZZZZ-ZZZZ-ZZZZ" required></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary">批量导入</button>
            </form>
        </div>
    </div>
</div>

<?php adminFooter(); ?>
