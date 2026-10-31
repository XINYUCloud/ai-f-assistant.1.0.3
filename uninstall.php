<?php
/**
 * 卸载脚本
 *
 * 默认情况下，仅在用户于设置中明确勾选"卸载时删除全部数据"时才清空数据；
 * 否则保留设置和日志，避免用户误删插件导致数据丢失。
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'aca_settings', array() );

$should_purge = ! empty( $settings['purge_on_uninstall'] );

if ( $should_purge ) {
	delete_option( 'aca_settings' );
	delete_option( 'aca_custom_prompts' );
	delete_option( 'aca_db_version' );

	global $wpdb;
	$table = $wpdb->prefix . 'aca_usage_log';
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL

	wp_clear_scheduled_hook( 'aca_daily_cleanup' );
}
