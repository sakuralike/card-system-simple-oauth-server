# 🎀 卡密提取系统 - 宝塔面板搭建教程

一个粉色系的卡密提取网站，支持WordPress账号登录，功能完善的后台管理系统。

---

## 📦 功能特点

### 前端功能
- 🎨 粉色系可爱界面
- 🔐 WordPress账号快捷登录
- 🎁 分类卡密提取
- 🔍 已提取卡密查询
- 📜 提取历史记录
- 📱 响应式设计，支持手机

### 后台功能
- 📊 数据统计仪表盘
- 🎫 卡密增删改查
- 📥 批量导入卡密
- 📝 单独/批量设置兑换内容
- 📁 分类管理
- 👥 用户管理（设置每日提取次数和数量）
- 📜 提取日志查看
- ⚙️ 系统设置

---

## 🛠️ 环境要求

- PHP 7.4+（推荐 PHP 8.0+）
- MySQL 5.7+
- 已安装宝塔面板
- 已有WordPress网站

---

## 📝 搭建步骤

### 第一步：在WordPress安装OAuth插件

1. **上传插件**
   - 将 `wordpress-plugin/simple-oauth-server.php` 上传到WordPress的 `/wp-content/plugins/` 目录

2. **启用插件**
   - 登录WordPress后台
   - 进入「插件」→「已安装的插件」
   - 找到「Simple OAuth Server」，点击「启用」

3. **创建OAuth应用**
   - 进入「设置」→「OAuth 应用」
   - 点击「创建应用」
   - 填写信息：
     - **应用名称**: 卡密提取系统
     - **回调地址**: `https://你的卡密系统域名/callback.php`
   - 点击「创建应用」
   - **⚠️ 重要：记录下显示的 Client ID 和 Client Secret**

4. **刷新固定链接**
   - 进入「设置」→「固定链接」
   - 不用修改任何内容，直接点击「保存更改」
   - 这一步很重要，确保OAuth路由生效

---

### 第二步：创建卡密系统站点

1. **登录宝塔面板**

2. **创建网站**
   - 点击「网站」→「添加站点」
   - 填写域名：如 `card.yourdomain.com`
   - 选择PHP版本：7.4 或 8.0
   - 选择MySQL数据库
   - 点击「提交」

3. **申请SSL证书（推荐）**
   - 点击刚创建的网站
   - 选择「SSL」→「Let's Encrypt」
   - 点击「申请」
   - 开启「强制HTTPS」

---

### 第三步：上传卡密系统文件

1. **上传文件**
   - 进入「文件」
   - 找到网站目录：`/www/wwwroot/card.yourdomain.com`
   - 删除目录中的默认文件
   - 上传卡密系统压缩包并解压
   - 确保文件结构如下：
   ```
   /www/wwwroot/card.yourdomain.com/
   ├── index.php
   ├── callback.php
   ├── history.php
   ├── query.php
   ├── logout.php
   ├── init.php
   ├── config.example.php
   ├── api/
   │   ├── draw.php
   │   └── query.php
   ├── admin/
   │   ├── index.php
   │   ├── cards.php
   │   ├── categories.php
   │   ├── users.php
   │   ├── logs.php
   │   ├── settings.php
   │   ├── common.php
   │   └── admin.css
   ├── assets/
   │   ├── css/style.css
   │   └── js/app.js
   ├── includes/
   │   ├── Database.php
   │   ├── Auth.php
   │   └── CardManager.php
   └── install/
       └── index.php
   ```

2. **设置目录权限**
   - 选中网站根目录
   - 右键「权限」
   - 设置为 755
   - 勾选「应用到子目录」

---

### 第四步：配置卡密系统

#### 方法一：使用安装向导（推荐）

1. 访问 `https://card.yourdomain.com/install/`
2. 按照向导填写配置信息：
   - **数据库信息**：使用宝塔创建站点时生成的数据库信息
   - **网站地址**：`https://card.yourdomain.com`
   - **WordPress OAuth配置**：填写第一步记录的 Client ID 和 Client Secret
   - **管理员WordPress用户ID**：在WordPress后台「用户」列表中可以看到

#### 方法二：手动配置

1. **复制配置文件**
   ```bash
   cp config.example.php config.php
   ```

2. **编辑 config.php**
   ```php
   <?php
   // 数据库配置（使用宝塔创建的数据库信息）
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'card_xxxxx');        // 你的数据库名
   define('DB_USER', 'card_xxxxx');        // 你的数据库用户名
   define('DB_PASS', 'xxxxxxxxxx');        // 你的数据库密码
   define('DB_PREFIX', 'cs_');
   
   // 网站配置
   define('SITE_URL', 'https://card.yourdomain.com');
   define('SITE_NAME', '卡密提取中心');
   
   // WordPress OAuth 配置
   define('WP_SITE_URL', 'https://your-wordpress-site.com');
   define('WP_CLIENT_ID', 'xxxxxxxxxxxxxxxx');      // 第一步获取的Client ID
   define('WP_CLIENT_SECRET', 'xxxxxxxxxxxxxxxx');  // 第一步获取的Client Secret
   
   define('APP_VERSION', '1.0.6');

   // 安全配置
   // 设为 true 可全局关闭浏览器检测（仅服务器侧可控，不接受 URL 参数）
   define('DISABLE_BROWSER_CHECK', false);

   // 默认设置
   define('DEFAULT_DAILY_LIMIT', 3);      // 默认每日提取次数
   define('DEFAULT_CARDS_PER_DRAW', 1);   // 默认每次提取数量
   
   date_default_timezone_set('Asia/Shanghai');
   ```

3. **初始化数据库**
   - 访问 `https://card.yourdomain.com/install/` 完成数据库初始化
   - 安装完成后程序会**自动锁定**（生成 `install/.locked`），再次访问会被拒绝

---

### 第五步：设置管理员

1. **登录卡密系统**
   - 访问 `https://card.yourdomain.com`
   - 点击「登录」使用WordPress账号授权

2. **进入数据库设置管理员**
   - 在宝塔面板打开「数据库」→「phpMyAdmin」
   - 选择卡密系统数据库
   - 执行SQL（将 `你的用户ID` 替换为实际ID）：
   ```sql
   UPDATE cs_users SET role = 'admin' WHERE id = 你的用户ID;
   ```
   - 或者在安装时已指定管理员ID，无需此步骤

3. **访问后台**
   - 访问 `https://card.yourdomain.com/admin/`
   - 即可进入管理后台

---

### 第六步：安全设置

1. **删除安装目录**
   ```bash
   rm -rf /www/wwwroot/card.yourdomain.com/install/
   ```
   > 安装程序已自动锁定（`install/.locked`），但直接删除目录是最彻底的做法。

2. **设置防跨站攻击**
   - 在宝塔中打开网站设置
   - 点击「防跨站攻击」开关

3. **禁止通过 HTTP 访问 config.php（重要）**

   config.php 含数据库密码与 OAuth Client Secret。Apache 用户可直接使用程序自带的
   `.htaccess`；**nginx 用户必须手动添加规则**，否则配置有误时源码会被当作静态文件下载：

   ```nginx
   location ~* /(config|config\.example)\.php$ {
       deny all;
   }
   ```

4. **修改后台路径（可选）**
   - 直接将 `admin` 目录重命名为自定义名称（如 `myadmin123`）即可
   - 注意：旧版 config.php 中的 `ADMIN_PATH` 常量**从未被代码使用**，已移除；
     改目录名本身就是生效的做法，不需要配任何常量

5. **OAuth 回调地址必须完全一致**
   - WordPress 后台「OAuth 应用」里登记的回调地址，必须与卡密系统的
     `{SITE_URL}/callback.php` **逐字符一致**
   - 1.1.0 起插件会强制精确比对，不一致的授权请求会被直接拒绝（防授权码劫持）

---

## 📖 使用说明

### 后台管理

#### 卡密管理
- **添加卡密**：手动添加单个卡密
- **批量导入**：每行一个卡密，可同时设置兑换内容
- **批量操作**：
  - 勾选多个卡密
  - 可批量设置分类
  - 可批量设置兑换内容
  - 可批量删除

#### 用户管理
- **每日提取次数**：设置用户每天可以提取的次数
- **每次提取数量**：设置每次提取获得几个卡密
- **禁止提取**：可以禁止某用户提取
- **批量设置**：选中多个用户统一设置限制

#### 分类管理
- 创建不同分类的卡密
- 设置分类图标（支持Emoji）
- 可以禁用/启用分类
- 设置排序顺序

### 前端使用

1. 用户访问首页
2. 点击登录，跳转到WordPress授权
3. 授权后返回卡密系统
4. 选择分类，点击提取
5. 获得卡密，可复制保存
6. 可在「提取记录」查看历史
7. 可在「查询卡密」查询已提取的卡密详情

---

## ❓ 常见问题

### Q: 登录后跳转失败？
A: 检查以下几点：
1. WordPress的OAuth插件是否正确安装并启用
2. 是否刷新了WordPress的固定链接
3. config.php 中的 WP_SITE_URL 是否正确
4. OAuth应用的回调地址是否正确

### Q: 提示"无效的客户端ID"？
A: Client ID 配置错误，检查 config.php 中的 WP_CLIENT_ID

### Q: 提取时提示"暂无可用卡密"？
A: 当前分类没有可用卡密，去后台添加

### Q: 如何修改默认提取次数？
A: 修改 config.php 中的 DEFAULT_DAILY_LIMIT 和 DEFAULT_CARDS_PER_DRAW

### Q: 忘记后台地址？
A: 默认是 `你的域名/admin/`。后台目录名即访问路径，直接看服务器上的目录名即可。

---

## 📂 文件结构说明

```
card-system/
├── index.php              # 前端首页
├── callback.php           # OAuth回调处理
├── history.php            # 提取历史页面
├── query.php              # 卡密查询页面
├── logout.php             # 登出处理
├── init.php               # 初始化文件
├── config.php             # 配置文件（需创建）
├── config.example.php     # 配置文件模板
│
├── api/                   # API接口
│   ├── draw.php          # 提取接口
│   └── query.php         # 查询接口
│
├── admin/                 # 后台管理
│   ├── index.php         # 仪表盘
│   ├── cards.php         # 卡密管理
│   ├── categories.php    # 分类管理
│   ├── users.php         # 用户管理
│   ├── logs.php          # 提取日志
│   ├── settings.php      # 系统设置
│   ├── common.php        # 公共文件
│   └── admin.css         # 后台样式
│
├── assets/                # 静态资源
│   ├── css/style.css     # 前端样式
│   └── js/app.js         # 前端脚本
│
├── includes/              # 核心类
│   ├── Database.php      # 数据库类
│   ├── Auth.php          # 认证类
│   └── CardManager.php   # 卡密管理类
│
├── install/               # 安装向导
│   └── index.php
│
└── wordpress-plugin/      # WordPress插件
    └── simple-oauth-server.php
```

---

## 🔄 版本历史

### v1.0.0
- 初始版本发布
- 完整的卡密管理功能
- WordPress OAuth登录集成
- 粉色系前端界面

---

## 📞 技术支持

如有问题，请检查：
1. PHP版本是否满足要求
2. 数据库配置是否正确
3. OAuth配置是否正确
4. 文件权限是否正确

---

**祝使用愉快！** 🎀
