<?php
/**
 * Simple OAuth Server - 卸载清理
 *
 * 删除插件创建的 oauth_clients / oauth_tokens 两张表，并清理计划任务。
 * 注意：表中残留的 access_token 等同长期有效的登录凭证，卸载时必须清除。
 */

// 必须由 WordPress 卸载流程触发
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 多站点：遍历所有站点执行清理
if ( is_multisite() ) {
	$blog_ids = get_sites(
		array(
			'fields'                 => 'ids',
			'number'                 => 0,
			'update_site_meta_cache' => false,
		)
	);

	foreach ( $blog_ids as $blog_id ) {
		switch_to_blog( $blog_id );
		simple_oauth_server_drop_tables();
		restore_current_blog();
	}
} else {
	simple_oauth_server_drop_tables();
}

// 清理计划任务
wp_clear_scheduled_hook( 'simple_oauth_server_cleanup' );

/**
 * 删除当前站点的插件数据表
 */
function simple_oauth_server_drop_tables() {
	global $wpdb;

	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}oauth_clients" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}oauth_tokens" );

	delete_option( 'simple_oauth_server_db_version' );
}
