<?php
/**
 * 后台公共检查
 */

require_once __DIR__ . '/../init.php';

// 检查管理员权限
if (!Auth::isLoggedIn()) {
    redirect(SITE_URL);
}

if (!Auth::isAdmin()) {
    http_response_code(403);
    die('无权限访问后台');
}

// ---------------------------------------------------------------------------
// S5：后台所有写操作统一 CSRF 校验
// init.php 早就提供了 csrfToken()/verifyCsrf()，但后台原本一处都没调用，
// 攻击者可诱导已登录管理员跨站清空卡密、提权自己、篡改公告植入 XSS。
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!verifyCsrf($token)) {
        http_response_code(403);
        header('Content-Type: text/html; charset=UTF-8');
        die('<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"></head><body>'
            . '<h2>安全校验失败</h2><p>表单已过期或来源不可信，请刷新页面后重试。</p>'
            . '<a href="javascript:history.back()">返回</a></body></html>');
    }
}

/**
 * 输出 CSRF 隐藏字段
 */
function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

$adminUser = Auth::getUser();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

function adminHeader($title) {
    global $adminUser, $currentPage;
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo e($title); ?> - 后台管理</title>
        <link rel="stylesheet" href="admin.css">
    </head>
    <body>
        <div class="admin-layout">
            <aside class="admin-sidebar">
                <div class="sidebar-header">
                    <div class="sidebar-logo">🎀 卡密管理</div>
                </div>
                <nav class="sidebar-menu">
                    <a href="index.php" class="menu-item <?php echo $currentPage == 'index' ? 'active' : ''; ?>">
                        <span class="menu-icon">📊</span> 仪表盘
                    </a>
                    <a href="cards.php" class="menu-item <?php echo $currentPage == 'cards' ? 'active' : ''; ?>">
                        <span class="menu-icon">🎫</span> 卡密管理
                    </a>
                    <a href="categories.php" class="menu-item <?php echo $currentPage == 'categories' ? 'active' : ''; ?>">
                        <span class="menu-icon">📁</span> 分类管理
                    </a>
                    <a href="users.php" class="menu-item <?php echo $currentPage == 'users' ? 'active' : ''; ?>">
                        <span class="menu-icon">👥</span> 用户管理
                    </a>
                    <a href="logs.php" class="menu-item <?php echo $currentPage == 'logs' ? 'active' : ''; ?>">
                        <span class="menu-icon">📜</span> 提取记录
                    </a>
                    <a href="settings.php" class="menu-item <?php echo $currentPage == 'settings' ? 'active' : ''; ?>">
                        <span class="menu-icon">⚙️</span> 系统设置
                    </a>
                    <a href="<?php echo SITE_URL; ?>" class="menu-item">
                        <span class="menu-icon">🏠</span> 返回前台
                    </a>
                </nav>
            </aside>
            
            <main class="admin-main">
                <header class="admin-header">
                    <h1 class="admin-title"><?php echo e($title); ?></h1>
                    <div class="admin-user">
                        <span><?php echo e($adminUser['username']); ?></span>
                        <a href="<?php echo SITE_URL; ?>/logout.php" class="btn btn-sm btn-secondary">退出</a>
                    </div>
                </header>
    <?php
}

function adminFooter() {
    ?>
            </main>
        </div>
        <script>
            // 标签页切换
            document.querySelectorAll('.tab-link').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const tab = this.dataset.tab;
                    
                    document.querySelectorAll('.tab-link').forEach(l => l.classList.remove('active'));
                    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                    
                    this.classList.add('active');
                    document.getElementById('tab-' + tab).classList.add('active');
                });
            });
            
            // 全选
            document.querySelectorAll('.select-all').forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const table = this.closest('table');
                    table.querySelectorAll('.row-checkbox').forEach(cb => {
                        cb.checked = this.checked;
                    });
                });
            });
            
            // 确认删除
            document.querySelectorAll('.confirm-delete').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    if (!confirm('确定要删除吗？')) {
                        e.preventDefault();
                    }
                });
            });

            // L10：单条删除改用 data-id + 事件委托，
            // 不再用内联 onclick 拼 PHP 变量到 JS 代码里。
            document.addEventListener('click', function(e) {
                const btn = e.target.closest('.btn-delete-id');
                if (!btn) return;
                if (!confirm('确定要删除吗？')) {
                    e.preventDefault();
                    return;
                }
                const form = btn.closest('form');
                const input = form ? form.querySelector('[name="id"]') : null;
                if (input) {
                    input.value = btn.dataset.id;
                }
            });
        </script>
    </body>
    </html>
    <?php
}
