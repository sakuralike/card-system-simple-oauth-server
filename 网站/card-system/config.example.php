<?php
/**
 * 卡密提取系统配置文件
 * 请复制此文件为 config.php 并修改配置
 *
 * 变更说明（v1.0.6）：
 *  - 移除从未被使用的 SECRET_KEY / ADMIN_PATH 常量（原为死配置，README 却教人靠它"提高安全性"）。
 *  - 新增 APP_VERSION 与 DISABLE_BROWSER_CHECK（服务器侧开关，不接受 URL 参数）。
 */

// 数据库配置
define('DB_HOST', 'localhost');
define('DB_NAME', 'card_system');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_PREFIX', 'cs_');

// 网站配置
define('SITE_URL', 'https://card.example.com');  // 卡密系统网址（不要以/结尾）
define('SITE_NAME', '卡密提取中心');
define('APP_VERSION', '1.0.6');

// WordPress OAuth 配置
define('WP_SITE_URL', 'https://your-wordpress-site.com');  // WordPress站点地址（不要以/结尾）
define('WP_CLIENT_ID', 'your_client_id');      // OAuth Client ID
define('WP_CLIENT_SECRET', 'your_client_secret');  // OAuth Client Secret

// 安全配置
// 设为 true 可全局关闭浏览器检测（仅服务器侧可控；旧版 ?skip_browser_check 后门已移除）
define('DISABLE_BROWSER_CHECK', false);

// 默认设置
define('DEFAULT_DAILY_LIMIT', 3);      // 默认每日提取次数
define('DEFAULT_CARDS_PER_DRAW', 1);   // 默认每次提取数量

// 时区
date_default_timezone_set('Asia/Shanghai');
