<?php
/**
 * 系统设置
 */

require_once 'common.php';

$message = '';
$messageType = '';

// 处理表单
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    setSetting('site_notice', $_POST['site_notice'] ?? '');
    setSetting('draw_rules', $_POST['draw_rules'] ?? '');
    // L3：require_login 设置项已从数据模型中移除（无匿名提取模式，该开关从未生效）
    
    $message = '设置已保存';
    $messageType = 'success';
}

adminHeader('系统设置');
?>

<?php if ($message): ?>
<div class="alert alert-<?php echo $messageType; ?>"><?php echo e($message); ?></div>
<?php endif; ?>

<form method="post">
    <?php echo csrfField(); ?>
    <div class="admin-card">
        <div class="card-header">
            <h3 class="card-title">基本设置</h3>
        </div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">网站公告</label>
                <textarea name="site_notice" class="form-control" rows="4" placeholder="显示在首页的公告内容"><?php echo e(getSetting('site_notice')); ?></textarea>
            </div>
            
            <div class="form-group">
                <label class="form-label">提取规则说明</label>
                <textarea name="draw_rules" class="form-control" rows="6" placeholder="提取规则说明，每行一条"><?php echo e(getSetting('draw_rules')); ?></textarea>
            </div>
            
            <p style="color: #888; font-size: 0.9rem;">
                提取必须使用 WordPress 账号登录，无需配置（系统按 <code>used_by</code> 归属卡密，
                不存在匿名提取模式）。
            </p>
        </div>
    </div>
    
    <div class="admin-card">
        <div class="card-header">
            <h3 class="card-title">默认限制设置</h3>
        </div>
        <div class="card-body">
            <p style="color: #888; margin-bottom: 15px;">
                以下设置在 <code>config.php</code> 中配置，修改后需要重新上传文件：
            </p>
            
            <table class="admin-table" style="max-width: 500px;">
                <tr>
                    <td>默认每日提取次数</td>
                    <td><code><?php echo DEFAULT_DAILY_LIMIT; ?></code></td>
                </tr>
                <tr>
                    <td>默认每次提取数量</td>
                    <td><code><?php echo DEFAULT_CARDS_PER_DRAW; ?></code></td>
                </tr>
            </table>
            
            <p style="color: #888; margin-top: 15px;">
                💡 提示：您可以在"用户管理"中单独为每个用户设置不同的限制。
            </p>
        </div>
    </div>
    
    <div class="admin-card">
        <div class="card-header">
            <h3 class="card-title">系统信息</h3>
        </div>
        <div class="card-body">
            <table class="admin-table" style="max-width: 500px;">
                <tr>
                    <td>系统版本</td>
                    <td><code><?php echo e(appVersion()); ?></code></td>
                </tr>
                <tr>
                    <td>PHP版本</td>
                    <td><code><?php echo PHP_VERSION; ?></code></td>
                </tr>
                <tr>
                    <td>WordPress站点</td>
                    <td><code><?php echo WP_SITE_URL; ?></code></td>
                </tr>
                <tr>
                    <td>当前网址</td>
                    <td><code><?php echo SITE_URL; ?></code></td>
                </tr>
            </table>
        </div>
    </div>
    
    <button type="submit" class="btn btn-primary" style="margin-top: 10px;">保存设置</button>
</form>

<?php adminFooter(); ?>
