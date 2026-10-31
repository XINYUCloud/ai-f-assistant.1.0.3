<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// wp aca test-connection / wp aca clear-logs，服务器上排查问题比登后台方便
class ACA_CLI {

	public static function register() {
		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::add_command( 'aca', __CLASS__ );
		}
	}

	/**
	 * 测试与 AI 服务商的连接
	 *
	 * ## EXAMPLES
	 *
	 *     wp aca test-connection
	 */
	public function test_connection( $args, $assoc_args ) {
		$result = ACA_AI_Client::instance()->test_connection();

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'AI 回复: ' . $result );
	}

	/**
	 * 清理过期使用日志
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : 清理多少天之前的日志，默认使用设置中的保留天数
	 *
	 * ## EXAMPLES
	 *
	 *     wp aca clear-logs --days=30
	 */
	public function clear_logs( $args, $assoc_args ) {
		global $wpdb;
		$table = ACA_Logger::table_name();
		$days  = isset( $assoc_args['days'] ) ? (int) $assoc_args['days'] : (int) ACA_Settings::get_settings()['log_retention_days'];

		if ( $days <= 0 ) {
			WP_CLI::warning( '未指定有效天数，跳过清理。' );
			return;
		}

		$deleted = $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", $days )
		);

		WP_CLI::success( sprintf( '已清理 %d 条超过 %d 天的日志。', (int) $deleted, $days ) );
	}
}
