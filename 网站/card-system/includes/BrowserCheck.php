<?php
/**
 * 浏览器检测类
 * 禁止不安全/有问题的浏览器访问
 */

class BrowserCheck {
    
    /**
     * 被禁止的浏览器列表
     * 键是UA关键词，值是浏览器名称
     */
    private static $blockedBrowsers = [
        'QQBrowser' => 'QQ浏览器',
        'MQQBrowser' => 'QQ浏览器',
        'QQ/' => 'QQ内置浏览器',
        'MicroMessenger' => '微信内置浏览器',
        '360SE' => '360安全浏览器',
        '360EE' => '360极速浏览器',
        'QIHU 360' => '360浏览器',
        'Maxthon' => '遨游浏览器',
        'LBBROWSER' => '猎豹浏览器',
        'BIDUBrowser' => '百度浏览器',
        'baiduboxapp' => '百度APP',
        'MetaSr' => '搜狗浏览器',
        'SE 2.X' => '搜狗浏览器',
        '2345Explorer' => '2345浏览器',
        '2345chrome' => '2345浏览器',
        'UCBrowser' => 'UC浏览器',
        'UBrowser' => 'UC浏览器',
        'Quark' => '夸克浏览器',
        'HeyTapBrowser' => 'OPPO浏览器',
        'MiuiBrowser' => '小米浏览器',
        'VivoBrowser' => 'vivo浏览器',
        'HuaweiBrowser' => '华为浏览器',
    ];
    
    /**
     * 检测是否是被禁止的浏览器
     * @return array|false 如果被禁止返回浏览器信息，否则返回false
     */
    public static function isBlocked() {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        if (empty($ua)) {
            return false;
        }
        
        foreach (self::$blockedBrowsers as $keyword => $name) {
            if (stripos($ua, $keyword) !== false) {
                return [
                    'keyword' => $keyword,
                    'name' => $name,
                    'ua' => $ua
                ];
            }
        }
        
        return false;
    }
    
    /**
     * 执行检测并显示提示页面
     */
    public static function check() {
        // S6：旧版这里判断 isset($_GET['skip_browser_check'])，任何人加 ?skip_browser_check=1
        // 即可让整套浏览器拦截失效（调试后门）。现改为仅服务器侧常量可控。
        if (defined('DISABLE_BROWSER_CHECK') && DISABLE_BROWSER_CHECK) {
            return;
        }

        $blocked = self::isBlocked();
        
        if ($blocked) {
            self::showBlockPage($blocked['name']);
        }
    }
    
    /**
     * 显示禁止访问页面
     */
    private static function showBlockPage($browserName) {
        // 阻止缓存
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>请更换浏览器</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, 'PingFang SC', 'Microsoft YaHei', sans-serif;
            background: linear-gradient(135deg, #fff0f3 0%, #ffe4ec 50%, #ffd6e0 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(255, 107, 139, 0.2);
            padding: 40px 30px;
            max-width: 420px;
            width: 100%;
            text-align: center;
            animation: slideUp 0.6s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .icon {
            font-size: 80px;
            margin-bottom: 20px;
            animation: bounce 2s ease-in-out infinite;
        }
        
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        .title {
            font-size: 24px;
            font-weight: 600;
            color: #e91e63;
            margin-bottom: 12px;
        }
        
        .subtitle {
            font-size: 15px;
            color: #888;
            margin-bottom: 8px;
        }
        
        .browser-name {
            display: inline-block;
            background: linear-gradient(135deg, #ff6b8a, #ff8fab);
            color: white;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
            margin: 15px 0;
        }
        
        .reason {
            background: #fff8fa;
            border: 1px solid #ffe0e8;
            border-radius: 12px;
            padding: 15px;
            margin: 20px 0;
            text-align: left;
        }
        
        .reason-title {
            font-size: 14px;
            font-weight: 600;
            color: #e91e63;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .reason-list {
            list-style: none;
            font-size: 13px;
            color: #666;
            line-height: 1.8;
        }
        
        .reason-list li::before {
            content: "•";
            color: #ff6b8a;
            margin-right: 8px;
        }
        
        .recommend {
            margin: 25px 0 20px;
        }
        
        .recommend-title {
            font-size: 14px;
            color: #666;
            margin-bottom: 15px;
        }
        
        .browser-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }
        
        .browser-item {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 15px 10px;
            transition: all 0.3s ease;
        }
        
        .browser-item:hover {
            background: #fff0f3;
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(255, 107, 139, 0.15);
        }
        
        .browser-icon {
            font-size: 32px;
            margin-bottom: 8px;
        }
        
        .browser-label {
            font-size: 12px;
            color: #666;
            font-weight: 500;
        }
        
        .tips {
            background: linear-gradient(135deg, #fff0f3, #ffe8ed);
            border-radius: 12px;
            padding: 15px;
            margin-top: 20px;
        }
        
        .tips-title {
            font-size: 13px;
            font-weight: 600;
            color: #e91e63;
            margin-bottom: 8px;
        }
        
        .tips-content {
            font-size: 12px;
            color: #888;
            line-height: 1.6;
        }
        
        .copy-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: linear-gradient(135deg, #ff6b8a, #ff8fab);
            color: white;
            padding: 12px 24px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            margin-top: 20px;
            transition: all 0.3s ease;
        }
        
        .copy-link:hover {
            transform: scale(1.05);
            box-shadow: 0 8px 25px rgba(255, 107, 139, 0.35);
        }
        
        .copy-link:active {
            transform: scale(0.98);
        }
        
        .toast {
            position: fixed;
            bottom: 100px;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: rgba(0, 0, 0, 0.75);
            color: white;
            padding: 12px 24px;
            border-radius: 25px;
            font-size: 14px;
            opacity: 0;
            transition: all 0.3s ease;
            z-index: 1000;
        }
        
        .toast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }
        
        /* 手机端适配 */
        @media (max-width: 480px) {
            body {
                padding: 15px;
            }
            
            .container {
                padding: 30px 20px;
                border-radius: 20px;
            }
            
            .icon {
                font-size: 60px;
            }
            
            .title {
                font-size: 20px;
            }
            
            .subtitle {
                font-size: 14px;
            }
            
            .browser-grid {
                gap: 10px;
            }
            
            .browser-item {
                padding: 12px 8px;
            }
            
            .browser-icon {
                font-size: 28px;
            }
            
            .browser-label {
                font-size: 11px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🚫</div>
        <h1 class="title">浏览器不兼容</h1>
        <p class="subtitle">检测到您正在使用</p>
        <div class="browser-name"><?php echo htmlspecialchars($browserName); ?></div>
        
        <div class="reason">
            <div class="reason-title">
                <span>⚠️</span>
                <span>为什么不支持？</span>
            </div>
            <ul class="reason-list">
                <li>该浏览器可能存在兼容性问题</li>
                <li>可能影响登录授权的正常进行</li>
                <li>可能导致页面显示异常</li>
            </ul>
        </div>
        
        <div class="recommend">
            <div class="recommend-title">💡 推荐使用以下浏览器访问</div>
            <div class="browser-grid">
                <div class="browser-item">
                    <div class="browser-icon">🌐</div>
                    <div class="browser-label">Chrome</div>
                </div>
                <div class="browser-item">
                    <div class="browser-icon">🔷</div>
                    <div class="browser-label">Edge</div>
                </div>
                <div class="browser-item">
                    <div class="browser-icon">🦊</div>
                    <div class="browser-label">Firefox</div>
                </div>
            </div>
        </div>
        
        <div class="tips">
            <div class="tips-title">📱 手机用户提示</div>
            <div class="tips-content">
                请复制下方链接，粘贴到手机自带浏览器或 Chrome 浏览器中打开
            </div>
        </div>
        
        <button class="copy-link" onclick="copyLink()">
            <span>📋</span>
            <span>复制网址</span>
        </button>
    </div>
    
    <div class="toast" id="toast">链接已复制到剪贴板</div>
    
    <script>
        function copyLink() {
            const url = window.location.href.split('?')[0];
            
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(() => {
                    showToast();
                }).catch(() => {
                    fallbackCopy(url);
                });
            } else {
                fallbackCopy(url);
            }
        }
        
        function fallbackCopy(text) {
            const input = document.createElement('input');
            input.value = text;
            input.style.position = 'fixed';
            input.style.opacity = '0';
            document.body.appendChild(input);
            input.select();
            input.setSelectionRange(0, 99999);
            
            try {
                document.execCommand('copy');
                showToast();
            } catch (e) {
                showToast('复制失败，请手动复制');
            }
            
            document.body.removeChild(input);
        }
        
        function showToast(msg) {
            const toast = document.getElementById('toast');
            if (msg) toast.textContent = msg;
            toast.classList.add('show');
            setTimeout(() => {
                toast.classList.remove('show');
            }, 2000);
        }
    </script>
</body>
</html>
        <?php
        exit;
    }
}
