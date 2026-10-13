# 卡密提取网站 + WordPress 授权登录

这是一个由两个独立部分组成的卡密领取系统：

- **卡密网站**：负责分类、库存、领取规则、领取记录和后台管理。
- **WordPress OAuth 插件**：负责使用 WordPress 账号登录卡密网站。

项目不包含支付、订单、分销或会员资格细分。核心目标是：**一个用户一次领取固定数量的卡密，并且在并发、网络重试和页面刷新后仍然只发放一次。**

## 当前状态

| 项目 | 当前值 |
| --- | --- |
| 卡密网站代码 | `网站/card-system1.0.5/`（代码内应用版本为 `1.0.6`） |
| WordPress 插件 | `simple-oauth-server` `1.1.0` |
| 推荐 PHP | 8.3+ |
| 数据库 | MySQL 8.0+ 或兼容的 MariaDB |
| WordPress | 现有 WordPress 站点即可；本地副本验证版本为 7.1.2 |
| 最新网站压缩包 | `网站/卡密提取网站1.0.6.zip` |
| 最新插件压缩包 | `卡密授权/simple-oauth-server1.1.0.zip` |

`网站/卡密提取网站1.0.5.zip` 和 `卡密授权/simple-oauth-server1.0.3.zip` 是旧包，包含修复前的实现，不应再用于部署。

## 功能概览

### 用户端

- 使用 WordPress 账号授权登录，不在卡密网站保存 WordPress 密码。
- 查看启用的卡密分类和可领取库存。
- 按分类领取卡密。
- 查看已领取卡密、兑换内容和领取时间。
- 通过卡密查询页面找回自己已领取的卡密。
- 支持桌面端和移动端浏览器。

### 管理端

- 查看总库存、可领取库存、用户数和领取批次。
- 添加、批量导入、删除和批量整理卡密。
- 管理分类、图标、描述、排序和启用状态。
- 为用户设置每天领取次数、每次领取张数和禁止领取状态。
- 查看领取记录、用户和 IP 信息。
- 修改首页公告和领取规则。

## 领取规则

系统将“领取次数”和“卡密张数”分开处理：

- `daily_limit`：每天允许成功领取的**批次数**。
- `cards_per_draw`：每个批次发放的卡密张数。
- 一次成功领取 N 张，只消耗 1 次。
- 可用库存少于本次要求的张数时，整次失败，不发卡、不扣次数。
- 分类被禁用后，不能通过接口绕过分类页面继续领取。
- 用户限额为 `0` 时，表示暂时不允许领取。
- 同一浏览器请求重试时，使用相同 `request_id` 返回原批次，不会再次发卡。
- 同一分类中完全相同的卡号按二进制精确匹配去重；大小写不同的卡号视为不同卡号，不跨分类去重。

历史 `draw_logs` 仍按卡密保留明细；系统另外按 `request_id` 统计领取批次。旧安装升级时会回填卡号、内容和分类快照，避免后台删除库存后历史记录丢失。

## 目录结构

```text
卡密网站/
├── README.md
├── 网站/
│   ├── card-system1.0.5/                 # 卡密网站源码
│   │   ├── index.php                     # 首页和领取入口
│   │   ├── callback.php                  # OAuth 回调
│   │   ├── history.php                   # 领取历史
│   │   ├── query.php                     # 卡密查询页
│   │   ├── api/
│   │   │   ├── draw.php                  # 领取接口
│   │   │   └── query.php                 # 查询接口
│   │   ├── admin/                        # 后台页面
│   │   ├── includes/
│   │   │   ├── Database.php              # PDO 和升级数据结构
│   │   │   ├── Auth.php                  # WordPress OAuth 客户端
│   │   │   ├── CardManager.php           # 库存、配额、幂等领取
│   │   │   └── BrowserCheck.php           # 浏览器兼容性提示
│   │   ├── install/index.php             # 安装向导
│   │   ├── wordpress-plugin/             # 与网站同步的插件副本
│   │   ├── tests/claims.php              # MySQL 领取回归测试
│   │   ├── config.example.php            # 配置模板
│   │   └── .htaccess                     # Apache 访问保护
│   ├── 卡密提取网站1.0.6.zip
│   └── 卡密提取网站1.0.5.zip             # 旧包，不要部署
├── 卡密授权/
│   ├── simple-oauth-server/              # WordPress 插件源码
│   │   ├── simple-oauth-server.php
│   │   └── uninstall.php
│   ├── simple-oauth-server1.1.0.zip
│   └── simple-oauth-server1.0.3.zip      # 旧包，不要部署
├── 审查报告-卡密系统.md
└── 修复记录-卡密系统-1.0.6.md
```

## OAuth 登录流程

1. 卡密网站生成一次性 `state`，写入服务端 session 和 `SameSite=Lax` cookie。
2. 浏览器跳转到 WordPress `/oauth/authorize`。
3. WordPress 用户确认授权后，插件把授权码回调到卡密网站。
4. 卡密网站使用 `client_secret` 调用 `/oauth/token` 换取 token。
5. 卡密网站调用 `/oauth/me` 获取 WordPress 用户资料。
6. 本地按 `wp_user_id` 创建或更新用户，然后建立卡密网站 session。

插件提供以下端点：

```text
GET/POST /oauth/authorize
POST     /oauth/token
GET      /oauth/me
```

授权码只能消费一次；删除 WordPress OAuth 应用时会同时撤销该应用已签发的 token。授权地址、token 地址和用户信息地址都应使用 HTTPS。

## 部署步骤

### 1. 准备 WordPress

1. 将以下两个文件放入独立插件目录，例如 `wp-content/plugins/simple-oauth-server/`：
   - `卡密授权/simple-oauth-server/simple-oauth-server.php`
   - `卡密授权/simple-oauth-server/uninstall.php`
2. 在 WordPress 后台启用 **Simple OAuth Server**。
3. 打开「设置 → OAuth 应用」，创建一个应用。
4. 回调地址必须逐字符填写为：

   ```text
   https://卡密网站域名/callback.php
   ```

5. 记录 Client ID 和 Client Secret。Client Secret 只写入服务器配置，不要提交 Git、写入前端或发送到聊天记录。
6. 保存一次 WordPress 固定链接，确保 `/oauth/authorize`、`/oauth/token` 和 `/oauth/me` 路由生效。

### 2. 上传卡密网站

解压 `网站/卡密提取网站1.0.6.zip` 到独立站点根目录。生产环境推荐：

- PHP 8.3+。
- PDO MySQL 和 cURL 扩展。
- MySQL 8.0+ 或兼容 MariaDB。
- 全站 HTTPS。
- Web 根目录不能直接暴露 `config.php`、`install/`、`includes/`、`tests/` 和插件源码目录。

### 3. 初始化配置

复制配置模板：

```bash
cp config.example.php config.php
```

填写以下值：

```php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'card_system');
define('DB_USER', 'card_app');
define('DB_PASS', '数据库密码');
define('DB_PREFIX', 'cs_');

define('SITE_URL', 'https://card.example.com');
define('SITE_NAME', '卡密提取中心');

define('WP_SITE_URL', 'https://www.example.com');
define('WP_CLIENT_ID', 'WordPress OAuth Client ID');
define('WP_CLIENT_SECRET', 'WordPress OAuth Client Secret');

define('DISABLE_BROWSER_CHECK', false);
define('DEFAULT_DAILY_LIMIT', 3);
define('DEFAULT_CARDS_PER_DRAW', 1);

date_default_timezone_set('Asia/Shanghai');
```

也可以访问 `/install/` 使用安装向导。安装向导会创建表、创建初始管理员、生成 `.locked`，并使用安全方式写入配置。初始化失败后不要继续使用残留配置文件；先确认数据库状态，再按现场备份流程处理。

### 4. Web 服务器保护

Apache 可使用项目内 `.htaccess`。Nginx 至少添加：

```nginx
location ~* ^/(?:config(?:\.example)?\.php|install(?:/|$)|includes(?:/|$)|tests(?:/|$)|wordpress-plugin(?:/|$)) {
    deny all;
}

location ~* ^/wp-content/debug\.log {
    deny all;
}
```

安装完成后仍建议删除 `install/`。如果站点在反向代理后面，必须正确传递 HTTPS 协议头，否则 session/state cookie 可能出现安全属性或回跳问题。

## 数据表

默认前缀为 `cs_`，实际表名取决于 `DB_PREFIX`：

| 表 | 用途 |
| --- | --- |
| `users` | WordPress 用户映射、角色、每日限额和封禁状态 |
| `categories` | 卡密分类、图标、描述、启用状态 |
| `cards` | 卡号、兑换内容、库存状态和归属用户 |
| `draw_logs` | 每张卡的历史明细，包含快照字段 |
| `draw_requests` | 每次领取请求的幂等响应和批次标识 |
| `settings` | 公告、领取规则和升级标记 |

网站首次加载核心领取功能时，会在事务和 MySQL advisory lock 保护下补齐领取相关结构。升级会保留旧表和旧记录，不会清空库存或重置用户。

## 管理操作建议

- 导入卡密前先按分类整理，系统会跳过同分类完全相同的卡号。
- 禁用分类会阻止新领取，但不会删除该分类已有卡密或历史记录。
- 删除卡密前确认它是否已经发放；已发放卡密的历史快照仍应保留。
- 将用户每日限额设为 `0` 表示暂时禁止领取，不需要额外封禁账号。
- 更改 OAuth 回调地址后，要同步修改 WordPress OAuth 应用登记值和 `config.php`。
- 生产数据库、OAuth secret 和 WordPress 账号密码不应写入仓库或测试输出。

## 接口说明

### 领取

```http
POST /api/draw.php
Content-Type: application/json

{
  "category_id": 1,
  "request_id": "32位小写十六进制随机值",
  "csrf_token": "当前页面生成的 CSRF token"
}
```

成功响应包含 `cards`、`remaining`、`request_id`；同一用户再次提交相同 `request_id` 时返回原响应并标记 `replayed`。网络错误、5xx 或不完整响应时，前端会保留原请求，不生成新的领取请求。

### 查询

```http
POST /api/query.php
Content-Type: application/json

{
  "card_code": "已领取卡号",
  "csrf_token": "当前页面生成的 CSRF token"
}
```

查询只允许卡密所属用户查看，不能用其他用户的账号查询。

## 本地完整副本测试环境

如果已创建本机隔离环境，配置目录为：

```text
D:\kfhj\23-Card-Claim-Test
```

访问地址：

- WordPress 副本：[http://localhost:18810/](http://localhost:18810/)
- 卡密网站：[http://localhost:18811/](http://localhost:18811/)

该环境使用独立 Docker 卷和数据库，不连接原站数据库；原完整副本仍为 [http://localhost:18797/](http://localhost:18797/)。测试环境阻断外部 HTTP、邮件和自动 cron，仅用于本机验证。启动或停止测试环境：

```powershell
cd D:\kfhj\23-Card-Claim-Test
docker compose up -d
docker compose ps
docker compose down
```

不要把 `secrets/`、测试账号密码或测试数据库导出提交到项目仓库。

## 测试与检查

PHP 语法检查：

```powershell
$php = 'D:\kfhj\10-PHP\8.3.35\php.exe'
Get-ChildItem 网站\card-system1.0.5,卡密授权\simple-oauth-server -Recurse -Filter *.php |
  ForEach-Object { & $php -l $_.FullName }
```

JavaScript 语法检查：

```powershell
node --check 网站\card-system1.0.5\assets\js\app.js
```

MySQL 领取回归测试必须使用专用测试库，并且脚本只接受数据库名 `card_claim_test`：

```powershell
$env:CARD_TEST_DSN = 'mysql:host=127.0.0.1;port=3306;dbname=card_claim_test;charset=utf8mb4'
$env:CARD_TEST_USER = 'card_app'
$env:CARD_TEST_PASSWORD = '只用于测试的密码'
php 网站\card-system1.0.5\tests\claims.php
```

测试会覆盖：

- 每日批次数与每批张数。
- 禁用分类和 `0` 限额。
- 同请求重放和响应丢失后的恢复。
- 多 session 并发领取。
- 库存不足的整批回滚。
- 旧日志快照和删除卡密后的历史查询。
- 同分类重复卡号和旧重复库存。

不要对生产数据库运行该测试。

## 故障排查

### OAuth 回跳失败

按以下顺序检查：

1. WordPress 插件是否启用。
2. WordPress OAuth 应用的回调地址是否与 `{SITE_URL}/callback.php` 完全一致。
3. `WP_SITE_URL`、Client ID 和 Client Secret 是否对应同一个 OAuth 应用。
4. WordPress 固定链接是否已保存刷新。
5. 卡密站和 WordPress 是否都使用 HTTPS，反向代理是否传递 `X-Forwarded-Proto`。

### 页面显示有库存但不能领取

后台库存数字现在按“唯一、未发放、所在分类已启用”的可领取口径统计。旧数据中的重复卡号、已经在历史记录出现的卡号和停用分类不会计入可领取数量。

### 页面提示“结果尚未确认”

这是幂等保护，不要点击生成新请求。保持同一浏览器和同一账号，刷新页面后点击“重试本次提取”；系统会先查询原 `request_id`，优先恢复原批次。

## 安全边界

- 生产环境必须使用 HTTPS。
- `config.php` 包含数据库密码和 OAuth Client Secret，必须限制文件访问权限。
- OAuth Client Secret 只保存在服务端。
- 后台写操作和 API 请求均需要 CSRF 保护。
- 外部测试副本和生产副本必须使用不同数据库、卷和凭据。
- 旧 ZIP 包不能用于部署。

## 许可证说明

WordPress 插件文件头标注为 **GPLv2 or later**。卡密网站部分当前没有单独的根目录 `LICENSE` 文件；如果要对外发布、二次分发或合并第三方代码，请先明确网站部分的许可证和第三方资源授权。
