<?php
/**
 * 系统安装向导
 *
 * 安全加固（v1.0.6）：
 *  - S1：输入全部白名单校验；配置用 var_export() 生成，杜绝单引号逃逸导致的 RCE。
 *  - S1：安装成功后写 install/.locked 自锁；config.php 存在时直接拒绝再次安装。
 *  - S2：数据库名/表前缀/主机走正则白名单，反引号无法闭合。
 *  - S7：自带 e()，不再依赖尚未生成的 config.php（原代码会 Fatal error 白屏）。
 *  - 附加：安装表单加 CSRF，自动生成保护 config.php 的 .htaccess。
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------------------------------------------------------------------------
// 本地辅助函数（不依赖 config.php / init.php）
// ---------------------------------------------------------------------------
if (!function_exists('e')) {
    function e($str) {
        return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
    }
}

$rootDir    = dirname(__DIR__);
$configFile = $rootDir . '/config.php';
$lockFile   = __DIR__ . '/.locked';

$error          = '';
$success        = '';
$justInstalled  = false;

// CSRF 令牌（安装表单专用）
if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}

// ---------------------------------------------------------------------------
// 安装锁：config.php 或 install/.locked 任一存在即视为已安装
// ---------------------------------------------------------------------------
$locked = file_exists($lockFile) || file_exists($configFile);

if ($locked) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(403);
        header('Content-Type: text/html; charset=UTF-8');
        die('<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>已锁定</title></head>'
            . '<body style="font-family:sans-serif;text-align:center;padding:60px;">'
            . '<h2>🔒 系统已安装，安装程序已锁定</h2>'
            . '<p style="color:#888;">如需重新安装，请先删除 <code>config.php</code> 与 <code>install/.locked</code>。</p>'
            . '</body></html>');
    }
    $step             = 3;
    $alreadyInstalled = true;
} else {
    $step             = max(1, intval($_GET['step'] ?? 1));
    $alreadyInstalled = false;
}

// ---------------------------------------------------------------------------
// 处理安装提交
// ---------------------------------------------------------------------------
if (!$locked && $_SERVER['REQUEST_METHOD'] === 'POST' && $step == 2) {

    // --- CSRF ---
    if (!hash_equals($_SESSION['install_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
        $error = '表单已过期，请刷新页面后重试。';
    } else {
        $dbHost   = trim($_POST['db_host'] ?? 'localhost');
        $dbName   = trim($_POST['db_name'] ?? '');
        $dbUser   = trim($_POST['db_user'] ?? '');
        $dbPass   = (string) ($_POST['db_pass'] ?? '');
        $dbPrefix = trim($_POST['db_prefix'] ?? 'cs_');

        $siteUrl  = rtrim(trim($_POST['site_url'] ?? ''), '/');
        $siteName = trim($_POST['site_name'] ?? '卡密提取中心');

        $wpSiteUrl      = rtrim(trim($_POST['wp_site_url'] ?? ''), '/');
        $wpClientId     = trim($_POST['wp_client_id'] ?? '');
        $wpClientSecret = trim($_POST['wp_client_secret'] ?? '');

        $adminWpId = intval($_POST['admin_wp_id'] ?? 0);

        // --- 白名单校验 ---
        $fail = function ($msg) use (&$error) { $error = $msg; return false; };

        $ok = true;
        if ($dbName === '' || $dbUser === '') {
            $ok = $fail('请填写数据库信息');
        } elseif (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $dbName)) {
            $ok = $fail('数据库名只能包含字母、数字和下划线（1-64 位）');
        } elseif (!preg_match('/^[a-zA-Z0-9_.\-:]{1,255}$/', $dbHost)) {
            $ok = $fail('数据库主机格式不合法');
        } elseif (!preg_match('/^[a-zA-Z0-9_.\-@]{1,64}$/', $dbUser)) {
            $ok = $fail('数据库用户名含有非法字符');
        } elseif ($dbPrefix !== '' && !preg_match('/^[a-zA-Z0-9_]{1,20}$/', $dbPrefix)) {
            $ok = $fail('表前缀只能包含字母、数字和下划线（1-20 位）');
        } elseif (!isSafeUrl($siteUrl)) {
            $ok = $fail('网站地址必须是合法的 http(s) 地址');
        } elseif (!isSafeUrl($wpSiteUrl)) {
            $ok = $fail('WordPress 站点地址必须是合法的 http(s) 地址');
        } elseif (!preg_match('/^[A-Za-z0-9_\-]{1,100}$/', $wpClientId)) {
            $ok = $fail('Client ID 只能包含字母、数字、下划线和短横线');
        } elseif (!preg_match('/^[A-Za-z0-9_\-]{1,200}$/', $wpClientSecret)) {
            $ok = $fail('Client Secret 含有非法字符');
        } elseif ($adminWpId <= 0) {
            $ok = $fail('请填写管理员的 WordPress 用户 ID（正整数）');
        }

        if ($ok) {
            try {
                $dsn = 'mysql:host=' . $dbHost . ';charset=utf8mb4';
                $pdo = new PDO($dsn, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);

                // 标识符已通过白名单校验，可安全用于 DDL
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `$dbName`");

                // --- 生成配置文件：var_export 杜绝引号逃逸 ---
                $config = "<?php\n"
                    . "/**\n"
                    . " * 卡密提取系统配置文件\n"
                    . " * 由安装向导自动生成，请勿手工修改引号内容\n"
                    . " */\n\n"
                    . "// 数据库配置\n"
                    . "define('DB_HOST', "   . var_export($dbHost, true) . ");\n"
                    . "define('DB_NAME', "   . var_export($dbName, true) . ");\n"
                    . "define('DB_USER', "   . var_export($dbUser, true) . ");\n"
                    . "define('DB_PASS', "   . var_export($dbPass, true) . ");\n"
                    . "define('DB_PREFIX', " . var_export($dbPrefix, true) . ");\n\n"
                    . "// 网站配置\n"
                    . "define('SITE_URL', "  . var_export($siteUrl, true) . ");\n"
                    . "define('SITE_NAME', " . var_export($siteName, true) . ");\n"
                    . "define('APP_VERSION', '1.0.6');\n\n"
                    . "// WordPress OAuth 配置\n"
                    . "define('WP_SITE_URL', "      . var_export($wpSiteUrl, true) . ");\n"
                    . "define('WP_CLIENT_ID', "     . var_export($wpClientId, true) . ");\n"
                    . "define('WP_CLIENT_SECRET', " . var_export($wpClientSecret, true) . ");\n\n"
                    . "// 安全配置\n"
                    . "// 设为 true 可全局关闭浏览器检测（仅服务器侧可控，不接受 URL 参数）\n"
                    . "define('DISABLE_BROWSER_CHECK', false);\n\n"
                    . "// 默认设置\n"
                    . "define('DEFAULT_DAILY_LIMIT', 3);\n"
                    . "define('DEFAULT_CARDS_PER_DRAW', 1);\n\n"
                    . "// 时区\n"
                    . "date_default_timezone_set('Asia/Shanghai');\n";

                if (@file_put_contents($configFile, $config) === false) {
                    throw new RuntimeException('无法写入 config.php，请检查目录权限');
                }
                @chmod($configFile, 0600);

                // 生成保护配置文件的 .htaccess（Apache；nginx 请参照 README 加 location 规则）
                $htaccessFile = $rootDir . '/.htaccess';
                if (!file_exists($htaccessFile)) {
                    @file_put_contents($htaccessFile, implode("\n", [
                        '# 禁止通过 HTTP 访问配置文件',
                        '<FilesMatch "^(config|config\.example)\.php$">',
                        '    Require all denied',
                        '</FilesMatch>',
                        '',
                        '# 关闭目录列表',
                        'Options -Indexes',
                        '',
                    ]));
                }

                // 加载配置并建表
                require_once $configFile;
                require_once $rootDir . '/includes/Database.php';

                Database::createTables();

                // 创建管理员账号
                $db = Database::getInstance();
                $db->insert('users', [
                    'wp_user_id' => $adminWpId,
                    'username'   => 'admin',
                    'role'       => 'admin',
                ]);

                // 自锁
                @file_put_contents($lockFile, date('Y-m-d H:i:s'));

                $success       = '安装成功！';
                $justInstalled = true;
                $step          = 3;

            } catch (PDOException $ex) {
                // 不回显 PDO 原始信息（含主机/库名/用户名）
                error_log('Install DB error: ' . $ex->getMessage());
                $error = '数据库连接失败，请检查主机、库名、用户名与密码。';
            } catch (Throwable $ex) {
                error_log('Install error: ' . $ex->getMessage());
                $error = '安装失败：' . e($ex->getMessage());
            }
        }
    }
}

/**
 * 仅允许 http/https 的合法 URL
 */
function isSafeUrl($url) {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $scheme = parse_url($url, PHP_URL_SCHEME);
    return in_array($scheme, ['http', 'https'], true);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>安装 - 卡密提取系统</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .install-container { max-width: 600px; margin: 40px auto; }
        .step-indicator { display: flex; justify-content: center; margin-bottom: 30px; }
        .step { width: 40px; height: 40px; border-radius: 50%; background: #ddd; display: flex; align-items: center; justify-content: center; margin: 0 10px; font-weight: bold; }
        .step.active { background: var(--primary); color: white; }
        .step.done { background: #5cb85c; color: white; }
    </style>
</head>
<body>
    <div class="container install-container">
        <div style="text-align: center; margin-bottom: 30px;">
            <h1 style="color: var(--primary);">🎀 卡密提取系统</h1>
            <p style="color: #888;">系统安装向导</p>
        </div>

        <div class="step-indicator">
            <div class="step <?php echo $step >= 1 ? ($step > 1 ? 'done' : 'active') : ''; ?>">1</div>
            <div class="step <?php echo $step >= 2 ? ($step > 2 ? 'done' : 'active') : ''; ?>">2</div>
            <div class="step <?php echo $step >= 3 ? 'active' : ''; ?>">3</div>
        </div>

        <?php if ($alreadyInstalled && !$justInstalled): ?>
        <div class="card">
            <div class="card-body" style="text-align: center;">
                <div style="font-size: 3rem; margin-bottom: 15px;">🔒</div>
                <h3>系统已安装</h3>
                <p style="color: #888; margin: 15px 0;">安装程序已锁定。如需重新安装，请先删除 <code>config.php</code> 与 <code>install/.locked</code>。</p>
                <a href="../index.php" class="btn btn-primary">进入首页</a>
                <a href="../admin/" class="btn btn-secondary">进入后台</a>
            </div>
        </div>

        <?php elseif ($step == 1): ?>
        <div class="card">
            <div class="card-header">
                <h2>步骤 1: 环境检测</h2>
            </div>
            <div class="card-body">
                <table style="width: 100%;">
                    <tr>
                        <td>PHP版本 (≥7.4)</td>
                        <td style="text-align: right;">
                            <?php if (version_compare(PHP_VERSION, '7.4.0', '>=')): ?>
                            <span style="color: #5cb85c;">✓ <?php echo e(PHP_VERSION); ?></span>
                            <?php else: ?>
                            <span style="color: #dc3545;">✗ <?php echo e(PHP_VERSION); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>PDO MySQL 扩展</td>
                        <td style="text-align: right;">
                            <?php if (extension_loaded('pdo_mysql')): ?>
                            <span style="color: #5cb85c;">✓ 已安装</span>
                            <?php else: ?>
                            <span style="color: #dc3545;">✗ 未安装</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>cURL扩展</td>
                        <td style="text-align: right;">
                            <?php if (extension_loaded('curl')): ?>
                            <span style="color: #5cb85c;">✓ 已安装</span>
                            <?php else: ?>
                            <span style="color: #dc3545;">✗ 未安装</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>目录可写</td>
                        <td style="text-align: right;">
                            <?php if (is_writable($rootDir)): ?>
                            <span style="color: #5cb85c;">✓ 可写</span>
                            <?php else: ?>
                            <span style="color: #dc3545;">✗ 不可写</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <div style="text-align: center; margin-top: 20px;">
                    <a href="?step=2" class="btn btn-primary btn-lg">下一步</a>
                </div>
            </div>
        </div>

        <?php elseif ($step == 2): ?>
        <div class="card">
            <div class="card-header">
                <h2>步骤 2: 配置信息</h2>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                <div class="alert alert-error"><?php echo e($error); ?></div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['install_csrf']); ?>">

                    <h4 style="margin-bottom: 15px; color: var(--primary);">📦 数据库配置</h4>

                    <div class="form-group">
                        <label>数据库主机</label>
                        <input type="text" name="db_host" class="form-control" value="<?php echo e($_POST['db_host'] ?? 'localhost'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>数据库名（仅字母/数字/下划线）</label>
                        <input type="text" name="db_name" class="form-control" value="<?php echo e($_POST['db_name'] ?? 'card_system'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>数据库用户名</label>
                        <input type="text" name="db_user" class="form-control" value="<?php echo e($_POST['db_user'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>数据库密码</label>
                        <input type="password" name="db_pass" class="form-control">
                    </div>

                    <div class="form-group">
                        <label>表前缀</label>
                        <input type="text" name="db_prefix" class="form-control" value="<?php echo e($_POST['db_prefix'] ?? 'cs_'); ?>">
                    </div>

                    <hr style="margin: 25px 0;">
                    <h4 style="margin-bottom: 15px; color: var(--primary);">🌐 网站配置</h4>

                    <div class="form-group">
                        <label>网站地址（不要以/结尾）</label>
                        <input type="url" name="site_url" class="form-control" placeholder="https://card.example.com" value="<?php echo e($_POST['site_url'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>网站名称</label>
                        <input type="text" name="site_name" class="form-control" value="<?php echo e($_POST['site_name'] ?? '卡密提取中心'); ?>" required>
                    </div>

                    <hr style="margin: 25px 0;">
                    <h4 style="margin-bottom: 15px; color: var(--primary);">🔗 WordPress OAuth 配置</h4>
                    <p style="color: #888; font-size: 0.9rem; margin-bottom: 15px;">
                        需要在WordPress站点安装 OAuth Server 插件并创建应用
                    </p>

                    <div class="form-group">
                        <label>WordPress站点地址（不要以/结尾）</label>
                        <input type="url" name="wp_site_url" class="form-control" placeholder="https://your-wordpress-site.com" value="<?php echo e($_POST['wp_site_url'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Client ID</label>
                        <input type="text" name="wp_client_id" class="form-control" value="<?php echo e($_POST['wp_client_id'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Client Secret</label>
                        <input type="text" name="wp_client_secret" class="form-control" value="<?php echo e($_POST['wp_client_secret'] ?? ''); ?>" required>
                    </div>

                    <hr style="margin: 25px 0;">
                    <h4 style="margin-bottom: 15px; color: var(--primary);">👤 管理员设置</h4>

                    <div class="form-group">
                        <label>管理员的WordPress用户ID</label>
                        <input type="number" name="admin_wp_id" class="form-control" placeholder="1" min="1" value="<?php echo e($_POST['admin_wp_id'] ?? '1'); ?>" required>
                        <small style="color: #888;">这个ID对应WordPress中的用户，该用户将成为卡密系统的管理员</small>
                    </div>

                    <div style="text-align: center; margin-top: 25px;">
                        <a href="?step=1" class="btn btn-secondary">上一步</a>
                        <button type="submit" class="btn btn-primary btn-lg">开始安装</button>
                    </div>
                </form>
            </div>
        </div>

        <?php elseif ($step == 3): ?>
        <div class="card">
            <div class="card-body" style="text-align: center;">
                <div style="font-size: 4rem; margin-bottom: 20px;">🎉</div>
                <h2 style="color: #5cb85c;">安装成功！</h2>
                <p style="color: #888; margin: 20px 0;">
                    卡密提取系统已成功安装，请使用WordPress账号登录后访问后台。
                </p>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 10px; margin: 20px 0;">
                    <p><strong>⚠️ 安全提示</strong></p>
                    <p style="font-size: 0.9rem; color: #666;">
                        安装程序已自动锁定（<code>install/.locked</code>）。<br>
                        仍建议直接删除 <code>install</code> 目录。<br>
                        若使用 nginx，请手动添加规则禁止访问 <code>config.php</code>（见 README）。
                    </p>
                </div>
                <a href="../index.php" class="btn btn-primary btn-lg">进入首页</a>
                <a href="../admin/" class="btn btn-secondary btn-lg">进入后台</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
