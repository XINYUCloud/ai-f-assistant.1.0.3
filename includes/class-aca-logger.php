<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 使用记录：谁在哪篇文章用了什么功能、花了多少 token，都记这张表里
// TODO: 后面数据量大了这张表可能得加个按月分表，先不折腾
class ACA_Logger {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'aca_daily_cleanup', array( $this, 'cleanup_old_logs' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
		add_action( 'admin_post_aca_clear_logs', array( $this, 'handle_clear_logs' ) );
		add_action( 'admin_post_aca_export_logs', array( $this, 'handle_export_logs' ) );

		if ( ! wp_next_scheduled( 'aca_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'aca_daily_cleanup' );
		}
	}

	// 仪表盘首页加一张概览卡片，一眼看到今天用了多少次、配没配好
	public function register_dashboard_widget() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'aca_dashboard_widget',
			__( 'AI 文章润色助手', 'ai-content-assistant' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	public function render_dashboard_widget() {
		global $wpdb;
		$table = self::table_name();

		$settings    = ACA_Settings::get_settings();
		$configured  = ! empty( $settings['api_base_url'] ) && ! empty( $settings['api_key_encrypted'] );

		$today_start  = current_time( 'Y-m-d' ) . ' 00:00:00';
		$today_calls  = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'success' AND created_at >= %s", $today_start )
		);
		$total_calls  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'success'" );
		$total_tokens = (int) $wpdb->get_var( "SELECT SUM(total_tokens) FROM {$table} WHERE status = 'success'" );

		$recent_failed = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'failed' AND created_at >= %s", $today_start )
		);

		// 近7天每日成功调用数，画一个简单的 mini 柱状图
		$trend      = array();
		$last7_days = array();
		for ( $i = 6; $i >= 0; $i-- ) {
			$day = wp_date( 'Y-m-d', strtotime( '-' . $i . ' days', current_time( 'timestamp' ) ) );
			$last7_days[ $day ] = 0;
		}
		$week_start = wp_date( 'Y-m-d', strtotime( '-6 days', current_time( 'timestamp' ) ) );
		$trend_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS d, COUNT(*) AS c FROM {$table} WHERE status = 'success' AND created_at >= %s GROUP BY DATE(created_at)",
				$week_start . ' 00:00:00'
			),
			ARRAY_A
		);
		if ( is_array( $trend_rows ) ) {
			foreach ( $trend_rows as $r ) {
				if ( isset( $last7_days[ $r['d'] ] ) ) {
					$last7_days[ $r['d'] ] = (int) $r['c'];
				}
			}
		}
		$max_trend = max( 1, max( array_values( $last7_days ) ) );
		?>
		<div class="aca-dashboard-widget">
			<?php if ( ! $configured ) : ?>
				<p>
					<span class="dashicons dashicons-warning" style="color:#dba617;"></span>
					<?php esc_html_e( '还没配置 AI 服务商，功能暂时用不了。', 'ai-content-assistant' ); ?>
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=aca-settings' ) ); ?>"><?php esc_html_e( '去配置', 'ai-content-assistant' ); ?></a>
				</p>
			<?php else : ?>
				<ul class="aca-dashboard-stats">
					<li>
						<strong><?php echo esc_html( number_format_i18n( $today_calls ) ); ?></strong>
						<?php esc_html_e( '今日调用次数', 'ai-content-assistant' ); ?>
					</li>
					<li>
						<strong><?php echo esc_html( number_format_i18n( $total_calls ) ); ?></strong>
						<?php esc_html_e( '累计调用次数', 'ai-content-assistant' ); ?>
					</li>
					<li>
						<strong><?php echo esc_html( number_format_i18n( $total_tokens ) ); ?></strong>
						<?php esc_html_e( '累计消耗 tokens', 'ai-content-assistant' ); ?>
					</li>
					<?php if ( $recent_failed > 0 ) : ?>
						<li class="aca-dashboard-warn">
							<strong><?php echo esc_html( number_format_i18n( $recent_failed ) ); ?></strong>
							<?php esc_html_e( '今日失败次数，点进设置页看看具体报错', 'ai-content-assistant' ); ?>
						</li>
					<?php endif; ?>
				</ul>
				<div class="aca-dashboard-trend">
					<p class="aca-dashboard-trend-title"><?php esc_html_e( '近 7 天调用趋势', 'ai-content-assistant' ); ?></p>
					<div class="aca-trend-bars">
						<?php
						$i = 0;
						foreach ( $last7_days as $day => $count ) :
							$h = max( 6, (int) round( $count / $max_trend * 100 ) );
							?>
							<div class="aca-trend-item" title="<?php echo esc_attr( wp_date( 'n/j', strtotime( $day ) ) . '：' . $count . ' 次' ); ?>">
								<div class="aca-trend-bar" style="height:<?php echo esc_attr( $h ); ?>px;"></div>
								<span class="aca-trend-day"><?php echo esc_html( wp_date( 'j', strtotime( $day ) ) ); ?></span>
							</div>
							<?php
							$i++;
						endforeach;
						?>
					</div>
				</div>
				<p class="aca-dashboard-links">
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=aca-settings' ) ); ?>"><?php esc_html_e( '打开设置 / 使用记录', 'ai-content-assistant' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
		<style>
			.aca-dashboard-stats { margin: 0; }
			.aca-dashboard-stats li { padding: 4px 0; border-bottom: 1px solid #eee; }
			.aca-dashboard-stats li:last-child { border-bottom: none; }
			.aca-dashboard-stats li strong { display: inline-block; min-width: 3em; }
			.aca-dashboard-warn { color: #b32d2e; }
			.aca-dashboard-links { margin-bottom: 0; }
			.aca-dashboard-trend { margin-top: 10px; }
			.aca-dashboard-trend-title { margin: 0 0 4px; font-size: 12px; color: #646970; }
			.aca-trend-bars { display: flex; align-items: flex-end; gap: 8px; height: 90px; }
			.aca-trend-item { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; gap: 2px; height: 100%; }
			.aca-trend-bar { width: 70%; min-height: 3px; background: #2271b1; border-radius: 2px 2px 0 0; }
			.aca-trend-day { font-size: 11px; color: #646970; }
		</style>
		<?php
	}

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . ACA_LOG_TABLE;
	}

	// 建库表，激活钩子里调
	public static function create_table() {
		global $wpdb;
		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			feature VARCHAR(50) NOT NULL DEFAULT '',
			prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0,
			completion_tokens INT UNSIGNED NOT NULL DEFAULT 0,
			total_tokens INT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'success',
			error_message TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY post_id (post_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	public static function log( $user_id, $post_id, $feature, $usage = array(), $status = 'success', $error_message = '' ) {
		global $wpdb;

		$wpdb->insert(
			self::table_name(),
			array(
				'user_id'            => (int) $user_id,
				'post_id'            => (int) $post_id,
				'feature'            => sanitize_key( $feature ),
				'prompt_tokens'      => isset( $usage['prompt_tokens'] ) ? (int) $usage['prompt_tokens'] : 0,
				'completion_tokens'  => isset( $usage['completion_tokens'] ) ? (int) $usage['completion_tokens'] : 0,
				'total_tokens'       => isset( $usage['total_tokens'] ) ? (int) $usage['total_tokens'] : 0,
				'status'             => sanitize_key( $status ),
				'error_message'      => $error_message ? sanitize_textarea_field( $error_message ) : null,
				'created_at'         => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	// 每日额度限制要用到，算今天成功调用了几次
	public static function get_today_count_for_user( $user_id ) {
		global $wpdb;
		$table = self::table_name();

		$today_start = current_time( 'Y-m-d' ) . ' 00:00:00';

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status = 'success' AND created_at >= %s",
				$user_id,
				$today_start
			)
		);

		return (int) $count;
	}

	public function cleanup_old_logs() {
		$settings = ACA_Settings::get_settings();
		$days     = (int) $settings['log_retention_days'];

		if ( $days <= 0 ) {
			return; // 0 表示永久保留
		}

		global $wpdb;
		$table = self::table_name();

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days
			)
		);
	}

	public function render_admin_ui() {
		global $wpdb;
		$table = self::table_name();

		$total_calls  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'success'" );
		$total_tokens = (int) $wpdb->get_var( "SELECT SUM(total_tokens) FROM {$table} WHERE status = 'success'" );

		$feature_labels    = wp_list_pluck( ACA_Prompts::get_builtin_prompts(), 'label' );
		$feature_labels['bullet'] = $feature_labels['bullet'] ?? __( '要点提炼', 'ai-content-assistant' );

		// 筛选与分页
		$filter_feature = isset( $_GET['aca_feature'] ) ? sanitize_key( wp_unslash( $_GET['aca_feature'] ) ) : '';
		$filter_status  = isset( $_GET['aca_status'] ) ? sanitize_key( wp_unslash( $_GET['aca_status'] ) ) : '';
		$paged          = isset( $_GET['aca_paged'] ) ? max( 1, absint( $_GET['aca_paged'] ) ) : 1;
		$per_page       = 20;

		$where  = array( '1=1' );
		$params = array();
		if ( '' !== $filter_feature ) {
			$where[]  = 'feature = %s';
			$params[] = $filter_feature;
		}
		if ( '' !== $filter_status && in_array( $filter_status, array( 'success', 'failed' ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $filter_status;
		}
		$where_sql = implode( ' AND ', $where );

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) );
		$pages = (int) ceil( $total / $per_page );
		$paged = min( $paged, max( 1, $pages ) );
		$off   = ( $paged - 1 ) * $per_page;

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, $off ) ) ),
			ARRAY_A
		);

		// 构造保留筛选条件的翻页链接
		$base_url = add_query_arg( array( 'page' => 'aca-settings' ), admin_url( 'options-general.php' ) );
		if ( '' !== $filter_feature ) {
			$base_url = add_query_arg( array( 'aca_feature' => $filter_feature ), $base_url );
		}
		if ( '' !== $filter_status ) {
			$base_url = add_query_arg( array( 'aca_status' => $filter_status ), $base_url );
		}

		$clear_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=aca_clear_logs&for=filtered' . ( $filter_feature ? '&aca_feature=' . $filter_feature : '' ) ),
			'aca_clear_logs_action',
			'aca_nonce'
		);
		$clear_all_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=aca_clear_logs&for=all' ),
			'aca_clear_logs_action',
			'aca_nonce'
		);
		$export_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=aca_export_logs' ),
			'aca_export_logs_action',
			'aca_nonce'
		);
		?>
		<h3><?php esc_html_e( '使用概览', 'ai-content-assistant' ); ?></h3>
		<p>
			<?php
			printf(
				/* translators: 1: 调用次数 2: token 总数 */
				esc_html__( '累计成功调用 %1$s 次，消耗约 %2$s tokens。', 'ai-content-assistant' ),
				'<strong>' . number_format_i18n( $total_calls ) . '</strong>',
				'<strong>' . number_format_i18n( $total_tokens ) . '</strong>'
			);
			?>
		</p>

		<h3><?php esc_html_e( '使用记录', 'ai-content-assistant' ); ?></h3>

		<form method="get" class="aca-log-filter">
			<input type="hidden" name="page" value="aca-settings" />
			<select name="aca_feature">
				<option value=""><?php esc_html_e( '全部功能', 'ai-content-assistant' ); ?></option>
				<?php foreach ( $feature_labels as $fk => $fl ) : ?>
					<option value="<?php echo esc_attr( $fk ); ?>" <?php selected( $filter_feature, $fk ); ?>><?php echo esc_html( $fl ); ?></option>
				<?php endforeach; ?>
				<?php foreach ( ACA_Prompts::get_custom_prompts() as $cid => $item ) : ?>
					<option value="<?php echo esc_attr( 'custom_' . $cid ); ?>" <?php selected( $filter_feature, 'custom_' . $cid ); ?>><?php echo esc_html( $item['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="aca_status">
				<option value=""><?php esc_html_e( '全部状态', 'ai-content-assistant' ); ?></option>
				<option value="success" <?php selected( $filter_status, 'success' ); ?>><?php esc_html_e( '成功', 'ai-content-assistant' ); ?></option>
				<option value="failed" <?php selected( $filter_status, 'failed' ); ?>><?php esc_html_e( '失败', 'ai-content-assistant' ); ?></option>
			</select>
			<button type="submit" class="button"><?php esc_html_e( '筛选', 'ai-content-assistant' ); ?></button>
			<a class="button" href="<?php echo esc_url( admin_url( 'options-general.php?page=aca-settings' ) ); ?>"><?php esc_html_e( '重置', 'ai-content-assistant' ); ?></a>
			<a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( '导出 CSV（当前筛选）', 'ai-content-assistant' ); ?></a>
			<a class="button aca-log-clear" href="<?php echo esc_url( $total > 0 ? $clear_all_url : '#' ); ?>"
				onclick="return confirm('<?php echo esc_js( __( '确定要清空全部使用记录吗？此操作不可撤销。', 'ai-content-assistant' ) ); ?>');"
				<?php echo $total > 0 ? '' : 'disabled'; ?>><?php esc_html_e( '清空全部记录', 'ai-content-assistant' ); ?></a>
		</form>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( '时间', 'ai-content-assistant' ); ?></th>
					<th><?php esc_html_e( '用户', 'ai-content-assistant' ); ?></th>
					<th><?php esc_html_e( '文章', 'ai-content-assistant' ); ?></th>
					<th><?php esc_html_e( '功能', 'ai-content-assistant' ); ?></th>
					<th><?php esc_html_e( 'Tokens', 'ai-content-assistant' ); ?></th>
					<th><?php esc_html_e( '状态', 'ai-content-assistant' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( '暂无记录', 'ai-content-assistant' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) :
						$user      = get_userdata( $row['user_id'] );
						$post_link = $row['post_id'] ? get_edit_post_link( $row['post_id'] ) : '';
						$fk        = $row['feature'];
						$feature   = isset( $feature_labels[ $fk ] ) ? $feature_labels[ $fk ] : $fk;
						if ( 0 === strpos( $fk, 'custom_' ) ) {
							$customs = ACA_Prompts::get_custom_prompts();
							$cid     = substr( $fk, 7 );
							if ( isset( $customs[ $cid ] ) ) {
								$feature = $customs[ $cid ]['name'];
							}
						}
						?>
						<tr>
							<td><?php echo esc_html( $row['created_at'] ); ?></td>
							<td><?php echo esc_html( $user ? $user->display_name : '#' . $row['user_id'] ); ?></td>
							<td>
								<?php if ( $post_link ) : ?>
									<a href="<?php echo esc_url( $post_link ); ?>"><?php echo esc_html( get_the_title( $row['post_id'] ) ); ?></a>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $feature ); ?></td>
							<td><?php echo esc_html( $row['total_tokens'] ); ?></td>
							<td>
								<?php if ( 'success' === $row['status'] ) : ?>
									<span style="color:#46b450;">✔ <?php esc_html_e( '成功', 'ai-content-assistant' ); ?></span>
								<?php else : ?>
									<span style="color:#dc3232;" title="<?php echo esc_attr( $row['error_message'] ); ?>">✘ <?php esc_html_e( '失败', 'ai-content-assistant' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ) : ?>
			<div class="tablenav"><div class="tablenav-pages">
				<span class="displaying-num"><?php printf( esc_html__( '共 %s 条', 'ai-content-assistant' ), number_format_i18n( $total ) ); ?></span>
				<?php
				for ( $i = 1; $i <= $pages; $i++ ) :
					$purl = add_query_arg( array( 'aca_paged' => $i ), $base_url );
					if ( $i === $paged ) :
						?>
						<span class="button current"><?php echo esc_html( $i ); ?></span>
					<?php else : ?>
						<a class="button" href="<?php echo esc_url( $purl ); ?>"><?php echo esc_html( $i ); ?></a>
					<?php endif; ?>
				<?php endfor; ?>
			</div></div>
		<?php endif; ?>
		<?php
	}

	// 手动清空日志（支持只清空当前筛选条件匹配到的，或全部清空）
	public function handle_clear_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足', 'ai-content-assistant' ) );
		}
		check_admin_referer( 'aca_clear_logs_action', 'aca_nonce' );

		global $wpdb;
		$table = self::table_name();

		$for_filters = isset( $_GET['for'] ) && 'all' !== $_GET['for'];

		if ( $for_filters ) {
			$where  = array( '1=1' );
			$params = array();
			if ( isset( $_GET['aca_feature'] ) && '' !== $_GET['aca_feature'] ) {
				$where[]  = 'feature = %s';
				$params[] = sanitize_key( wp_unslash( $_GET['aca_feature'] ) );
			}
			if ( isset( $_GET['aca_status'] ) && '' !== $_GET['aca_status'] ) {
				$where[]  = 'status = %s';
				$params[] = sanitize_key( wp_unslash( $_GET['aca_status'] ) );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE " . implode( ' AND ', $where ), $params ) );
		} else {
			$wpdb->query( "DELETE FROM {$table}" );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'aca-settings', 'aca_log_cleared' => '1' ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	// 导出当前筛选条件下的日志为 CSV（BOM 前缀保证 Excel 正确识别中文）
	public function handle_export_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足', 'ai-content-assistant' ) );
		}
		check_admin_referer( 'aca_export_logs_action', 'aca_nonce' );

		global $wpdb;
		$table = self::table_name();

		$where  = array( '1=1' );
		$params = array();
		if ( isset( $_GET['aca_feature'] ) && '' !== $_GET['aca_feature'] ) {
			$where[]  = 'feature = %s';
			$params[] = sanitize_key( wp_unslash( $_GET['aca_feature'] ) );
		}
		if ( isset( $_GET['aca_status'] ) && '' !== $_GET['aca_status'] ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( wp_unslash( $_GET['aca_status'] ) );
		}
		$where_sql = implode( ' AND ', $where );

		$rows = $wpdb->get_results(
			$params ? $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC", $params ) : "SELECT * FROM {$table} ORDER BY id DESC",
			ARRAY_A
		);

		$feature_labels = wp_list_pluck( ACA_Prompts::get_builtin_prompts(), 'label' );
		$feature_labels['bullet'] = $feature_labels['bullet'] ?? __( '要点提炼', 'ai-content-assistant' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=aca-logs-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM
		fputcsv( $out, array(
			__( '时间', 'ai-content-assistant' ),
			__( '用户 ID', 'ai-content-assistant' ),
			__( '用户名', 'ai-content-assistant' ),
			__( '文章 ID', 'ai-content-assistant' ),
			__( '文章标题', 'ai-content-assistant' ),
			__( '功能', 'ai-content-assistant' ),
			__( '输入 Tokens', 'ai-content-assistant' ),
			__( '输出 Tokens', 'ai-content-assistant' ),
			__( '总 Tokens', 'ai-content-assistant' ),
			__( '状态', 'ai-content-assistant' ),
			__( '错误信息', 'ai-content-assistant' ),
		) );

		foreach ( $rows as $row ) {
			$user  = get_userdata( $row['user_id'] );
			$fk    = $row['feature'];
			$feature = isset( $feature_labels[ $fk ] ) ? $feature_labels[ $fk ] : $fk;
			if ( 0 === strpos( $fk, 'custom_' ) ) {
				$customs = ACA_Prompts::get_custom_prompts();
				$cid     = substr( $fk, 7 );
				if ( isset( $customs[ $cid ] ) ) {
					$feature = $customs[ $cid ]['name'];
				}
			}
			fputcsv( $out, array(
				$row['created_at'],
				(int) $row['user_id'],
				$user ? $user->user_login : '',
				(int) $row['post_id'],
				$row['post_id'] ? get_the_title( $row['post_id'] ) : '',
				$feature,
				(int) $row['prompt_tokens'],
				(int) $row['completion_tokens'],
				(int) $row['total_tokens'],
				'success' === $row['status'] ? __( '成功', 'ai-content-assistant' ) : __( '失败', 'ai-content-assistant' ),
				$row['error_message'],
			) );
		}

		fclose( $out );
		exit;
	}
}
