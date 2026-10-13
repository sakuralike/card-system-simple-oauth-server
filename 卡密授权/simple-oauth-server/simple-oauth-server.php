<?php
/**
 * Plugin Name: Simple OAuth Server
 * Description: 为WordPress添加OAuth 2.0服务端功能，允许第三方网站使用WordPress账号登录
 * Version: 1.1.0
 * Author: Card System
 * License: GPLv2 or later
 *
 * 安全加固（v1.1.0）：
 *  - S3  redirect_uri 必须与客户端注册值精确比对，不匹配即拒绝（RFC 6749 §10.6）
 *  - M6  激活钩子移出构造函数，改为文件作用域静态方法
 *  - M7  增加命名空间与常量式重复加载保护（避免同名类致命错误）
 *  - M8  支持 WordPress 安装在子目录的场景
 *  - M9  移除 Token 端点的 Access-Control-Allow-Origin: *
 *  - M10 client_secret 改用 hash_equals 常量时间比较
 *  - M15 新增 uninstall.php 与过期令牌清理 cron
 *  - L12 管理页显式校验 current_user_can('manage_options')
 */

namespace SimpleOAuthServer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 重复加载保护。
// 注意：不能用 class_exists( Plugin::class, false ) —— PHP 的 early binding 会在编译阶段
// 就把本文件的顶层类写进类表，导致该判断恒为 true，从而跳过全部引导代码。
if ( defined( 'SIMPLE_OAUTH_SERVER_LOADED' ) ) {
	return;
}
define( 'SIMPLE_OAUTH_SERVER_LOADED', true );

final class Plugin {

	const VERSION         = '1.1.0';
	const CLEANUP_HOOK    = 'simple_oauth_server_cleanup';
	const CODE_TTL        = 600;   // 授权码有效期（秒）
	const TOKEN_TTL       = 3600;  // 访问令牌有效期（秒）

	/** @var Plugin|null */
	private static $instance = null;

	/** @var string */
	private $table_name;

	/** @var string */
	private $tokens_table;

	/**
	 * 单例入口
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		global $wpdb;

		$this->table_name   = $wpdb->prefix . 'oauth_clients';
		$this->tokens_table = $wpdb->prefix . 'oauth_tokens';

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		// 使用 init 钩子处理 OAuth 请求（更早执行）
		add_action( 'init', array( $this, 'handle_oauth_request' ), 1 );
		// 过期数据清理
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'cleanup_expired' ) );
	}

	/**
	 * 激活插件（静态方法，供文件作用域的 register_activation_hook 调用）
	 */
	public static function activate() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$clients_table   = $wpdb->prefix . 'oauth_clients';
		$tokens_table    = $wpdb->prefix . 'oauth_tokens';

		// OAuth客户端表
		$sql1 = "CREATE TABLE IF NOT EXISTS {$clients_table} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id VARCHAR(100) NOT NULL,
            client_secret VARCHAR(100) NOT NULL,
            name VARCHAR(200) NOT NULL,
            redirect_uri TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY client_id (client_id)
        ) $charset_collate;";

		// OAuth令牌表
		$sql2 = "CREATE TABLE IF NOT EXISTS {$tokens_table} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id VARCHAR(100) NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            access_token VARCHAR(200) NOT NULL,
            refresh_token VARCHAR(200),
            code VARCHAR(200),
            code_redirect_uri TEXT,
            code_expires DATETIME,
            token_expires DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY client_id (client_id),
            KEY user_id (user_id),
            KEY access_token (access_token),
            KEY code (code)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql1 );
		dbDelta( $sql2 );

		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CLEANUP_HOOK );
		}
	}

	/**
	 * 停用插件：清理计划任务（数据保留，卸载时才删除）
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
	}

	/**
	 * 清理过期的授权码与访问令牌
	 */
	public static function cleanup_expired() {
		global $wpdb;

		$tokens_table = $wpdb->prefix . 'oauth_tokens';

		$wpdb->query(
			"DELETE FROM {$tokens_table}
             WHERE (code_expires IS NOT NULL AND code_expires < UTC_TIMESTAMP())
                OR (token_expires IS NOT NULL AND token_expires < UTC_TIMESTAMP())"
		);
	}

	/**
	 * 处理OAuth请求 - 通过URL参数方式
	 */
	public function handle_oauth_request() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		// 解析路径
		$path = (string) parse_url( $request_uri, PHP_URL_PATH );
		$path = trim( $path, '/' );

		// M8：剥离 WordPress 所在子目录前缀（如装在 /blog/ 时也能正确匹配）
		$home_path = trim( (string) parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home_path && 0 === strpos( $path, $home_path ) ) {
			$path = trim( substr( $path, strlen( $home_path ) ), '/' );
		}

		// 匹配 oauth/authorize, oauth/token, oauth/me
		if ( preg_match( '#^oauth/(authorize|token|me)/?$#', $path, $matches ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			$action = $matches[1];

			switch ( $action ) {
				case 'authorize':
					$this->handle_authorize();
					break;
				case 'token':
					$this->handle_token();
					break;
				case 'me':
					$this->handle_me();
					break;
			}
			exit;
		}
	}

	/**
	 * 处理授权请求
	 */
	private function handle_authorize() {
		foreach ( array( 'client_id', 'redirect_uri', 'state', 'response_type' ) as $parameter ) {
			if ( isset( $_GET[ $parameter ] ) && ! is_string( $_GET[ $parameter ] ) ) {
				wp_die( '无效的授权参数', '授权失败', array( 'response' => 400 ) );
			}
		}
		$client_id     = sanitize_text_field( wp_unslash( $_GET['client_id'] ?? '' ) );
		$redirect_uri  = esc_url_raw( wp_unslash( $_GET['redirect_uri'] ?? '' ) );
		$state         = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
		$response_type = sanitize_text_field( wp_unslash( $_GET['response_type'] ?? 'code' ) );

		// 验证客户端
		$client = $this->get_client( $client_id );
		if ( ! $client ) {
			wp_die( '无效的客户端ID', '授权失败', array( 'response' => 400 ) );
		}

		// ★ S3：redirect_uri 必须与注册值精确匹配，否则拒绝。
		// 这是防止授权码被投递到攻击者站点的唯一屏障（RFC 6749 §10.6）。
		if ( '' !== $redirect_uri && ! $this->is_allowed_redirect( $client, $redirect_uri ) ) {
			wp_die( '回调地址与注册的客户端不匹配', '授权失败', array( 'response' => 400 ) );
		}

		if ( 'code' !== $response_type ) {
			wp_die( '不支持的 response_type', '授权失败', array( 'response' => 400 ) );
		}

		// 检查用户是否登录
		if ( ! is_user_logged_in() ) {
			// 保存当前URL并跳转到登录页
			$current_url = add_query_arg( $_GET, home_url( '/oauth/authorize' ) );
			wp_redirect( wp_login_url( $current_url ) );
			exit;
		}

		// 处理授权确认
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( isset( $_POST['authorize'] ) && wp_verify_nonce( $nonce, 'oauth_authorize' ) ) {
			$user = wp_get_current_user();

			// 生成授权码
			$code = wp_generate_password( 40, false );
			$target = ( '' !== $redirect_uri ) ? $redirect_uri : $this->primary_redirect( $client );
			if ( 1 !== $this->save_auth_code( $client_id, $user->ID, $code, $target ) ) {
				wp_die( '暂时无法生成授权码，请重试', '授权失败', array( 'response' => 500 ) );
			}

			// ★ 重定向回客户端：始终使用注册时登记的回调地址
			$redirect = add_query_arg(
				array(
					'code'  => $code,
					'state' => $state,
				),
				$target
			);

			wp_redirect( $redirect );
			exit;
		}

		// 显示授权页面
		$user = wp_get_current_user();
		$this->render_authorize_page( $client, $user );
	}

	/**
	 * 渲染授权确认页
	 */
	private function render_authorize_page( $client, $user ) {
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<meta name="robots" content="noindex, nofollow">
			<title>授权登录 - <?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
			<style>
				* { margin: 0; padding: 0; box-sizing: border-box; }
				body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f0f1; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
				.auth-box { background: white; border-radius: 10px; box-shadow: 0 2px 20px rgba(0,0,0,0.1); padding: 40px; max-width: 400px; width: 90%; text-align: center; }
				.site-logo { font-size: 2rem; margin-bottom: 20px; }
				h1 { font-size: 1.3rem; margin-bottom: 10px; }
				.app-name { color: #0073aa; font-weight: 600; }
				.user-info { background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 20px 0; }
				.user-avatar { width: 50px; height: 50px; border-radius: 50%; margin-bottom: 10px; }
				.permissions { text-align: left; margin: 20px 0; padding: 15px; background: #fff8e5; border-radius: 8px; }
				.permissions h4 { margin-bottom: 10px; color: #856404; }
				.permissions li { margin: 5px 0; color: #666; }
				.buttons { display: flex; gap: 10px; margin-top: 20px; }
				.btn { flex: 1; padding: 12px; border: none; border-radius: 6px; font-size: 1rem; cursor: pointer; text-decoration: none; display: inline-block; }
				.btn-primary { background: #0073aa; color: white; }
				.btn-secondary { background: #ddd; color: #333; }
				.redirect-uri { font-size: 12px; color: #888; word-break: break-all; margin-top: 10px; }
			</style>
		</head>
		<body>
			<div class="auth-box">
				<div class="site-logo">🔐</div>
				<h1>授权登录</h1>
				<p><span class="app-name"><?php echo esc_html( $client['name'] ); ?></span> 请求访问您的账号</p>

				<div class="user-info">
					<?php echo get_avatar( $user->ID, 50, '', '', array( 'class' => 'user-avatar' ) ); ?>
					<div><?php echo esc_html( $user->display_name ); ?></div>
					<small style="color: #888;"><?php echo esc_html( $user->user_email ); ?></small>
				</div>

				<div class="permissions">
					<h4>将获取以下权限：</h4>
					<ul>
						<li>读取您的用户名和邮箱</li>
						<li>读取您的头像</li>
					</ul>
				</div>

				<form method="post">
					<?php wp_nonce_field( 'oauth_authorize' ); ?>
					<div class="buttons">
						<a href="<?php echo esc_url( home_url() ); ?>" class="btn btn-secondary">取消</a>
						<button type="submit" name="authorize" value="1" class="btn btn-primary">授权</button>
					</div>
				</form>

				<div class="redirect-uri">
					授权后跳转至：<?php echo esc_html( $this->primary_redirect( $client ) ); ?>
				</div>
			</div>
		</body>
		</html>
		<?php
	}

	/**
	 * 处理令牌请求
	 */
	private function handle_token() {
		header( 'Content-Type: application/json' );
		// M9：移除 Access-Control-Allow-Origin: *，敏感端点不开放跨源读取

		foreach ( array( 'grant_type', 'client_id', 'client_secret', 'code', 'redirect_uri' ) as $parameter ) {
			if ( isset( $_POST[ $parameter ] ) && ! is_string( $_POST[ $parameter ] ) ) {
				$this->json_error( 'invalid_request', 'OAuth parameters must be strings', 400 );
			}
		}
		$grant_type    = sanitize_text_field( wp_unslash( $_POST['grant_type'] ?? '' ) );
		$client_id     = sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) );
		$client_secret = sanitize_text_field( wp_unslash( $_POST['client_secret'] ?? '' ) );
		$code          = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );
		$redirect_uri  = esc_url_raw( wp_unslash( $_POST['redirect_uri'] ?? '' ) );

		// 验证客户端（M10：hash_equals 常量时间比较）
		$client = $this->get_client( $client_id );
		if ( ! $client || ! hash_equals( (string) $client['client_secret'], (string) $client_secret ) ) {
			$this->json_error( 'invalid_client', 'Client credentials are invalid', 401 );
		}

		if ( 'authorization_code' !== $grant_type ) {
			$this->json_error( 'unsupported_grant_type', 'Only authorization_code is supported', 400 );
		}

		if ( '' === $redirect_uri || ! $this->is_allowed_redirect( $client, $redirect_uri ) ) {
			$this->json_error( 'invalid_grant', 'redirect_uri does not match the registered value', 400 );
		}

		// 验证授权码
		$auth = $this->verify_auth_code( $client_id, $code );
		if ( ! $auth || empty( $auth['code_redirect_uri'] ) || ! hash_equals( (string) $auth['code_redirect_uri'], $redirect_uri ) || 1 !== $this->delete_auth_code( $auth ) ) {
			$this->json_error( 'invalid_grant', 'Authorization code is invalid, expired, or already used', 400 );
		}

		// 生成访问令牌
		$access_token  = wp_generate_password( 40, false );
		$refresh_token = wp_generate_password( 40, false );

		if ( 1 !== $this->save_access_token( $client_id, $auth['user_id'], $access_token, $refresh_token ) ) {
			$this->json_error( 'server_error', 'Failed to save access token', 500 );
		}

		echo wp_json_encode(
			array(
				'access_token'  => $access_token,
				'token_type'    => 'Bearer',
				'expires_in'    => self::TOKEN_TTL,
				'refresh_token' => $refresh_token,
			)
		);
		exit;
	}

	/**
	 * 获取用户信息
	 */
	private function handle_me() {
		header( 'Content-Type: application/json' );

		$auth_header = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) ) : '';
		if ( preg_match( '/Bearer\s+(.+)/i', $auth_header, $matches ) ) {
			$access_token = $matches[1];
		} else {
			$this->json_error( 'invalid_token', 'Missing bearer token', 401 );
		}

		// 验证令牌
		$token = $this->verify_access_token( $access_token );
		if ( ! $token ) {
			$this->json_error( 'invalid_token', 'Token is invalid or expired', 401 );
		}

		// 获取用户信息
		$user = get_user_by( 'ID', $token['user_id'] );
		if ( ! $user ) {
			$this->json_error( 'user_not_found', 'User not found', 404 );
		}

		echo wp_json_encode(
			array(
				'ID'           => $user->ID,
				'user_login'   => $user->user_login,
				'user_email'   => $user->user_email,
				'display_name' => $user->display_name,
				'avatar'       => get_avatar_url( $user->ID, array( 'size' => 96 ) ),
			)
		);
		exit;
	}

	/**
	 * 输出 JSON 错误并终止
	 */
	private function json_error( $error, $description = '', $status = 400 ) {
		status_header( $status );
		echo wp_json_encode(
			array(
				'error'             => $error,
				'error_description' => $description,
			)
		);
		exit;
	}

	/**
	 * 获取客户端
	 */
	private function get_client( $client_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE client_id = %s", $client_id ),
			ARRAY_A
		);
	}

	/**
	 * 客户端注册的主回调地址（注册值可能含多行，取第一行为准）
	 */
	private function primary_redirect( $client ) {
		$list = preg_split( '/[\r\n]+/', trim( (string) $client['redirect_uri'] ) );
		return trim( $list[0] ?? '' );
	}

	/**
	 * 校验 redirect_uri 是否在客户端注册列表中（精确匹配）
	 */
	private function is_allowed_redirect( $client, $uri ) {
		$registered = preg_split( '/[\r\n]+/', trim( (string) $client['redirect_uri'] ) );
		foreach ( $registered as $allowed ) {
			$allowed = trim( $allowed );
			if ( '' !== $allowed && hash_equals( $allowed, (string) $uri ) ) {
				return true;
			}
		}
		return false;
	}

	private function ensure_code_redirect_column() {
		global $wpdb;

		$check = "SHOW COLUMNS FROM {$this->tokens_table} LIKE 'code_redirect_uri'";
		if ( 'code_redirect_uri' === $wpdb->get_var( $check ) ) {
			return true;
		}
		$lock = 'oauth_redirect_' . substr( hash( 'sha256', $wpdb->dbname . ':' . $this->tokens_table ), 0, 40 );
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 15)', $lock ) ) ) {
			return false;
		}
		try {
			if ( 'code_redirect_uri' === $wpdb->get_var( $check ) ) {
				return true;
			}
			return false !== $wpdb->query( "ALTER TABLE {$this->tokens_table} ADD COLUMN code_redirect_uri TEXT NULL" );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * 保存授权码
	 */
	private function save_auth_code( $client_id, $user_id, $code, $redirect_uri ) {
		global $wpdb;

		if ( ! $this->ensure_code_redirect_column() ) {
			return false;
		}
		return $wpdb->insert(
			$this->tokens_table,
			array(
				'client_id'    => $client_id,
				'user_id'      => $user_id,
				'access_token' => '',
				'code'         => $code,
				'code_redirect_uri' => $redirect_uri,
				'code_expires' => gmdate( 'Y-m-d H:i:s', time() + self::CODE_TTL ),
			)
		);
	}

	/**
	 * 验证授权码
	 */
	private function verify_auth_code( $client_id, $code ) {
		global $wpdb;

		$auth = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->tokens_table} WHERE client_id = %s AND code = %s",
				$client_id,
				$code
			),
			ARRAY_A
		);

		if ( ! $auth ) {
			return null;
		}

		if ( empty( $auth['code_expires'] ) || strtotime( $auth['code_expires'] . ' UTC' ) <= time() ) {
			return null; // 已过期
		}

		return $auth;
	}

	/**
	 * 删除授权码
	 */
	private function delete_auth_code( $auth ) {
		global $wpdb;
		return $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->tokens_table} WHERE id = %d AND client_id = %s AND code = %s AND BINARY code_redirect_uri = BINARY %s AND code_expires > %s",
				$auth['id'],
				$auth['client_id'],
				$auth['code'],
				$auth['code_redirect_uri'],
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}

	/**
	 * 保存访问令牌
	 */
	private function save_access_token( $client_id, $user_id, $access_token, $refresh_token ) {
		global $wpdb;

		return $wpdb->insert(
			$this->tokens_table,
			array(
				'client_id'     => $client_id,
				'user_id'       => $user_id,
				'access_token'  => $access_token,
				'refresh_token' => $refresh_token,
				'token_expires' => gmdate( 'Y-m-d H:i:s', time() + self::TOKEN_TTL ),
			)
		);
	}

	/**
	 * 验证访问令牌
	 */
	private function verify_access_token( $access_token ) {
		global $wpdb;

		$token = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->tokens_table} WHERE access_token = %s", $access_token ),
			ARRAY_A
		);

		if ( ! $token || ! $this->get_client( $token['client_id'] ) ) {
			return null;
		}

		if ( empty( $token['token_expires'] ) || strtotime( $token['token_expires'] . ' UTC' ) <= time() ) {
			return null;
		}

		return $token;
	}

	/**
	 * 添加管理菜单
	 */
	public function add_admin_menu() {
		add_options_page(
			'OAuth 应用管理',
			'OAuth 应用',
			'manage_options',
			'oauth-apps',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * 渲染管理页面
	 */
	public function render_admin_page() {
		global $wpdb;

		// L12：显式能力校验，不依赖 add_options_page 的拦截
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '无权限访问该页面', '无权限', array( 'response' => 403 ) );
		}

		// 处理表单
		$action = isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';

		if ( 'add' === $action ) {
			$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'oauth_add_app' ) ) {
				echo '<div class="notice notice-error"><p>安全校验失败，请刷新页面后重试。</p></div>';
			} else {
				$redirect_uri = esc_url_raw( wp_unslash( $_POST['redirect_uri'] ?? '' ) );
				$name         = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );

				if ( '' === $redirect_uri || '' === $name ) {
					echo '<div class="notice notice-error"><p>请填写应用名称与合法的回调地址。</p></div>';
				} else {
					$wpdb->insert(
						$this->table_name,
						array(
							'client_id'     => wp_generate_password( 20, false ),
							'client_secret' => wp_generate_password( 40, false ),
							'name'          => $name,
							'redirect_uri'  => $redirect_uri,
						)
					);
					echo '<div class="notice notice-success"><p>应用创建成功！</p></div>';
				}
			}
		}

		if ( 'delete' === $action ) {
			$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'oauth_delete_app' ) ) {
				echo '<div class="notice notice-error"><p>安全校验失败，请刷新页面后重试。</p></div>';
			} else {
				$id = absint( $_POST['id'] ?? 0 );
				$client = $wpdb->get_row(
					$wpdb->prepare( "SELECT client_id FROM {$this->table_name} WHERE id = %d", $id ),
					ARRAY_A
				);
				if ( $client && 1 === $wpdb->delete( $this->table_name, array( 'id' => $id ) ) ) {
					$wpdb->delete( $this->tokens_table, array( 'client_id' => $client['client_id'] ) );
					echo '<div class="notice notice-success"><p>应用已删除</p></div>';
				} else {
					echo '<div class="notice notice-error"><p>应用删除失败，请刷新页面后重试。</p></div>';
				}
			}
		}

		$apps = $wpdb->get_results( "SELECT * FROM {$this->table_name} ORDER BY id DESC", ARRAY_A );
		?>
		<div class="wrap">
			<h1>OAuth 应用管理</h1>

			<h2>添加新应用</h2>
			<form method="post" style="max-width: 500px; background: #fff; padding: 20px; border: 1px solid #ccd0d4;">
				<?php wp_nonce_field( 'oauth_add_app' ); ?>
				<input type="hidden" name="action" value="add">

				<table class="form-table">
					<tr>
						<th>应用名称</th>
						<td><input type="text" name="name" class="regular-text" required></td>
					</tr>
					<tr>
						<th>回调地址</th>
						<td>
							<input type="url" name="redirect_uri" class="regular-text" placeholder="https://card.example.com/callback.php" required>
							<p class="description">
								用户授权后将重定向到此地址。<strong>必须与客户端实际使用的 redirect_uri 完全一致</strong>，
								否则授权请求会被拒绝（防止授权码劫持）。多个地址请用换行分隔。
							</p>
						</td>
					</tr>
				</table>

				<p><button type="submit" class="button button-primary">创建应用</button></p>
			</form>

			<h2 style="margin-top: 30px;">已创建的应用</h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>名称</th>
						<th>Client ID</th>
						<th>Client Secret</th>
						<th>回调地址</th>
						<th>操作</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $apps ) ) : ?>
					<tr><td colspan="5">暂无应用</td></tr>
					<?php else : ?>
					<?php foreach ( $apps as $app ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $app['name'] ); ?></strong></td>
						<td><code><?php echo esc_html( $app['client_id'] ); ?></code></td>
						<td><code><?php echo esc_html( $app['client_secret'] ); ?></code></td>
						<td><?php echo esc_html( $app['redirect_uri'] ); ?></td>
						<td>
							<form method="post" style="display: inline;">
								<?php wp_nonce_field( 'oauth_delete_app' ); ?>
								<input type="hidden" name="action" value="delete">
								<input type="hidden" name="id" value="<?php echo esc_attr( $app['id'] ); ?>">
								<button type="submit" class="button" onclick="return confirm('确定删除？')">删除</button>
							</form>
						</td>
					</tr>
					<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<div style="margin-top: 30px; padding: 20px; background: #fff; border: 1px solid #ccd0d4;">
				<h3>OAuth 端点地址</h3>
				<table class="form-table">
					<tr>
						<th>授权地址</th>
						<td><code><?php echo esc_html( home_url( '/oauth/authorize' ) ); ?></code></td>
					</tr>
					<tr>
						<th>令牌地址</th>
						<td><code><?php echo esc_html( home_url( '/oauth/token' ) ); ?></code></td>
					</tr>
					<tr>
						<th>用户信息</th>
						<td><code><?php echo esc_html( home_url( '/oauth/me' ) ); ?></code></td>
					</tr>
				</table>
			</div>
		</div>
		<?php
	}
}

// ---------------------------------------------------------------------------
// 引导（M6：激活/停用钩子放在文件作用域，不再写在构造函数里）
// ---------------------------------------------------------------------------
register_activation_hook( __FILE__, array( __NAMESPACE__ . '\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( __NAMESPACE__ . '\Plugin', 'deactivate' ) );

Plugin::instance();
