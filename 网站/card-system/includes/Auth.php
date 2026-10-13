<?php
/**
 * 用户认证类 - WordPress OAuth
 *
 * 安全加固（v1.0.6）：
 *  - S4  恢复 OAuth state 校验。原代码生成了 state 却在回调第一行 unset 掉并注释"不再验证"，
 *        导致登录劫持（攻击者把自己的 code 塞给受害者，受害者即登录成攻击者账号）。
 *        session 跨域丢失的正确解法是用 SameSite=Lax 的 cookie 承载 state —— Lax 恰好允许
 *        OAuth 回跳这类顶层 GET 导航携带 cookie，不需要取消校验。
 *  - M1  移除 CURLOPT_SSL_VERIFYPEER => false，避免 client_secret / access_token 被中间人截获。
 *  - M14 头像 URL 校验协议，仅接受 http/https。
 *  - 附加：登录成功后 session_regenerate_id()，防会话固定。
 */

class Auth {

    /** state cookie 名称 */
    const STATE_COOKIE = 'oauth_state';

    /**
     * 获取WordPress OAuth登录URL
     */
    public static function getLoginUrl($redirect = '') {
        if (empty($redirect)) {
            $redirect = SITE_URL . '/callback.php';
        }

        $state = $_SESSION['oauth_state'] ?? null;
        $expires = $_SESSION['oauth_state_expires'] ?? 0;
        if (!is_string($state) || $state === '' || !is_int($expires) || $expires <= time()) {
            $state = self::generateState();
            $expires = time() + 600;
            $_SESSION['oauth_state'] = $state;
            $_SESSION['oauth_state_expires'] = $expires;
        }
        if (($_COOKIE[self::STATE_COOKIE] ?? null) !== $state) {
            self::setStateCookie($state, $expires);
        }
        $_SESSION['oauth_redirect'] = $redirect;

        $params = [
            'response_type' => 'code',
            'client_id' => WP_CLIENT_ID,
            'redirect_uri' => SITE_URL . '/callback.php',
            'state' => $state,
        ];

        return WP_SITE_URL . '/oauth/authorize?' . http_build_query($params);
    }

    /**
     * 处理OAuth回调
     */
    public static function handleCallback($code, $state) {
        $expectedSession = $_SESSION['oauth_state'] ?? null;
        $expires = $_SESSION['oauth_state_expires'] ?? 0;
        $matched = is_string($code) && $code !== ''
            && is_string($state) && is_string($expectedSession) && $expectedSession !== ''
            && is_int($expires) && $expires > time()
            && hash_equals($expectedSession, $state);

        // 无论成功与否都清除，保证 state 一次性
        self::clearStateCookie();
        unset($_SESSION['oauth_state'], $_SESSION['oauth_state_expires']);

        if (!$matched) {
            throw new Exception('安全校验失败（state 不匹配），请重新登录');
        }

        // 获取access_token
        $tokenData = self::getAccessToken($code);

        if (!$tokenData || empty($tokenData['access_token'])) {
            $error = $tokenData['error'] ?? '未知错误';
            // 如果是 invalid_grant，说明授权码已使用或过期
            if ($error === 'invalid_grant') {
                throw new Exception('授权码已过期或已使用，请重新登录');
            }
            throw new Exception('获取访问令牌失败: ' . $error);
        }

        // 获取用户信息
        $userInfo = self::getUserInfo($tokenData['access_token']);

        if (!$userInfo || empty($userInfo['ID'])) {
            $error = $userInfo['error'] ?? '未知错误';
            throw new Exception('获取用户信息失败: ' . $error);
        }

        // 创建或更新本地用户
        return self::createOrUpdateUser($userInfo);
    }

    /**
     * 获取access_token
     */
    private static function getAccessToken($code) {
        $url = WP_SITE_URL . '/oauth/token';

        $data = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => WP_CLIENT_ID,
            'client_secret' => WP_CLIENT_SECRET,
            'redirect_uri' => SITE_URL . '/callback.php',
        ];

        $response = self::httpPost($url, $data);

        return json_decode($response, true);
    }

    /**
     * 获取WordPress用户信息
     */
    private static function getUserInfo($accessToken) {
        $url = WP_SITE_URL . '/oauth/me';

        $response = self::httpGet($url, [
            'Authorization: Bearer ' . $accessToken,
        ]);

        return json_decode($response, true);
    }

    /**
     * 创建或更新本地用户
     */
    private static function createOrUpdateUser($wpUser) {
        $db = Database::getInstance();

        $existingUser = $db->fetch(
            "SELECT * FROM " . DB_PREFIX . "users WHERE wp_user_id = ?",
            [$wpUser['ID']]
        );

        $userData = [
            'wp_user_id' => $wpUser['ID'],
            'username' => $wpUser['user_login'] ?? $wpUser['display_name'] ?? 'user_' . $wpUser['ID'],
            'email' => $wpUser['user_email'] ?? '',
            // M14：头像必须是 http/https 的合法 URL
            'avatar' => self::safeAvatarUrl($wpUser['avatar'] ?? ''),
            'last_login' => date('Y-m-d H:i:s'),
        ];

        if ($existingUser) {
            // 更新用户：不覆盖管理员手工设置的 role / 限额 / 封禁状态
            $updateData = [
                'wp_user_id' => $userData['wp_user_id'],
                'username' => $userData['username'],
                'email' => $userData['email'],
                'avatar' => $userData['avatar'],
                'last_login' => $userData['last_login'],
            ];
            $db->update('users', $updateData, 'id = ?', [$existingUser['id']]);
            $userId = $existingUser['id'];
        } else {
            // 创建新用户
            $userData['daily_limit'] = max(0, (int) DEFAULT_DAILY_LIMIT);
            $userData['cards_per_draw'] = max(1, (int) DEFAULT_CARDS_PER_DRAW);
            $userId = $db->insert('users', $userData);
        }

        return $db->fetch("SELECT * FROM " . DB_PREFIX . "users WHERE id = ?", [$userId]);
    }

    /**
     * 登录用户
     */
    public static function login($user) {
        // 防会话固定：登录成功后轮换 session id
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user'] = $user;
    }

    /**
     * 登出
     */
    public static function logout() {
        self::clearStateCookie();
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        // 清除会话 cookie
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
    }

    /**
     * 获取当前登录用户
     */
    public static function getUser() {
        if (empty($_SESSION['user_id'])) {
            return null;
        }

        // 重新从数据库获取最新用户信息
        $db = Database::getInstance();
        $user = $db->fetch(
            "SELECT * FROM " . DB_PREFIX . "users WHERE id = ?",
            [$_SESSION['user_id']]
        );

        if ($user) {
            $_SESSION['user'] = $user;
        } else {
            unset($_SESSION['user_id'], $_SESSION['user']);
        }

        return $user;
    }

    /**
     * 检查是否登录
     */
    public static function isLoggedIn() {
        return (bool) self::getUser();
    }

    /**
     * 检查是否管理员
     */
    public static function isAdmin() {
        $user = self::getUser();
        return $user && $user['role'] === 'admin';
    }

    /**
     * 生成随机state
     */
    private static function generateState() {
        return bin2hex(random_bytes(32));
    }

    /**
     * 写入 state cookie（一次性，10 分钟有效）
     */
    private static function setStateCookie($state, $expires) {
        setcookie(self::STATE_COOKIE, $state, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',   // Lax 允许 OAuth 回跳（顶层 GET 导航）携带
        ]);
        $_COOKIE[self::STATE_COOKIE] = $state;
    }

    /**
     * 作废 state cookie
     */
    private static function clearStateCookie() {
        setcookie(self::STATE_COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::STATE_COOKIE]);
    }

    /**
     * 是否 HTTPS
     */
    private static function isHttps() {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        return false;
    }

    /**
     * 头像 URL 安全校验（M14）
     */
    private static function safeAvatarUrl($url) {
        $url = (string) $url;
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }

    /**
     * HTTP GET请求
     */
    private static function httpGet($url, $headers = []) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            // M1：不再禁用证书校验
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }

    /**
     * HTTP POST请求
     */
    private static function httpPost($url, $data, $headers = []) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers),
            // M1：不再禁用证书校验
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }
}
