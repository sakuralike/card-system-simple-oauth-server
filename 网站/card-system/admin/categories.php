<?php
/**
 * 分类管理
 */

require_once 'common.php';

$cardManager = new CardManager();
$db = Database::getInstance();
$message = '';
$messageType = '';

// 处理表单
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $cardManager->addCategory([
            'name' => trim($_POST['name'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            // M3：图标限制长度，避免写入 HTML/脚本
            'icon' => mb_substr(trim($_POST['icon'] ?? '🎁'), 0, 10),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'sort_order' => intval($_POST['sort_order'] ?? 0),
        ]);
        $message = '分类添加成功';
        $messageType = 'success';
    }
    
    if ($action === 'edit') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            $cardManager->updateCategory($id, [
                'name' => trim($_POST['name'] ?? ''),
                'description' => trim($_POST['description'] ?? ''),
                // M3：图标限制长度，避免写入 HTML/脚本
                'icon' => mb_substr(trim($_POST['icon'] ?? '🎁'), 0, 10),
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
                'sort_order' => intval($_POST['sort_order'] ?? 0),
            ]);
            $message = '分类更新成功';
            $messageType = 'success';
        }
    }
    
    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 1) {
            $cardManager->deleteCategory($id);
            $message = '分类删除成功';
            $messageType = 'success';
        } else {
            $message = '默认分类不能删除';
            $messageType = 'error';
        }
    }
}

$categories = $cardManager->getCategories(false);

// 统计每个分类的卡密数量
foreach ($categories as &$cat) {
    $cat['total_cards'] = $db->count('cards', 'category_id = ?', [$cat['id']]);
    $cat['available_cards'] = $cardManager->getAvailableCardCount($cat['id']);
}
unset($cat);

$editCategory = null;
if (isset($_GET['edit'])) {
    $editCategory = $cardManager->getCategory(intval($_GET['edit']));
}

adminHeader('分类管理');
?>

<?php if ($message): ?>
<div class="alert alert-<?php echo $messageType; ?>"><?php echo e($message); ?></div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 350px; gap: 20px;">
    <!-- 分类列表 -->
    <div class="admin-card">
        <div class="card-header">
            <h3 class="card-title">分类列表</h3>
        </div>
        <div class="card-body">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th width="60">图标</th>
                        <th>名称</th>
                        <th>描述</th>
                        <th width="100">卡密数</th>
                        <th width="80">状态</th>
                        <th width="80">排序</th>
                        <th width="120">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $cat): ?>
                    <tr>
                        <td style="font-size: 1.5rem;"><?php echo e($cat['icon']); ?></td>
                        <td><strong><?php echo e($cat['name']); ?></strong></td>
                        <td><?php echo e($cat['description'] ?: '-'); ?></td>
                        <td>
                            <span class="badge badge-success"><?php echo $cat['available_cards']; ?></span> /
                            <?php echo $cat['total_cards']; ?>
                        </td>
                        <td>
                            <?php if ($cat['is_active']): ?>
                            <span class="badge badge-success">启用</span>
                            <?php else: ?>
                            <span class="badge badge-danger">禁用</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $cat['sort_order']; ?></td>
                        <td>
                            <div class="btn-group">
                                <a href="?edit=<?php echo $cat['id']; ?>" class="btn btn-sm btn-primary">编辑</a>
                                <?php if ($cat['id'] > 1): ?>
                                <form method="post" style="display: inline;">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $cat['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger confirm-delete">删除</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- 添加/编辑表单 -->
    <div class="admin-card">
        <div class="card-header">
            <h3 class="card-title"><?php echo $editCategory ? '编辑分类' : '添加分类'; ?></h3>
        </div>
        <div class="card-body">
            <form method="post">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="<?php echo $editCategory ? 'edit' : 'add'; ?>">
                <?php if ($editCategory): ?>
                <input type="hidden" name="id" value="<?php echo $editCategory['id']; ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label class="form-label">图标（Emoji）</label>
                    <input type="text" name="icon" class="form-control" value="<?php echo e($editCategory['icon'] ?? '🎁'); ?>" style="width: 80px;">
                </div>
                
                <div class="form-group">
                    <label class="form-label">名称</label>
                    <input type="text" name="name" class="form-control" value="<?php echo e($editCategory['name'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">描述</label>
                    <textarea name="description" class="form-control" rows="3"><?php echo e($editCategory['description'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">排序（数字越小越靠前）</label>
                    <input type="number" name="sort_order" class="form-control" value="<?php echo intval($editCategory['sort_order'] ?? 0); ?>" style="width: 100px;">
                </div>
                
                <div class="form-group">
                    <label class="form-check">
                        <input type="checkbox" name="is_active" value="1" <?php echo ($editCategory['is_active'] ?? 1) ? 'checked' : ''; ?>>
                        启用此分类
                    </label>
                </div>
                
                <button type="submit" class="btn btn-primary"><?php echo $editCategory ? '保存修改' : '添加分类'; ?></button>
                <?php if ($editCategory): ?>
                <a href="categories.php" class="btn btn-secondary">取消</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>

<?php adminFooter(); ?>
