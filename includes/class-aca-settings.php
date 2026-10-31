<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 设置页那点事：服务商配置、功能开关、Prompt 模板入口都从这里挂进去
class ACA_Settings {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_aca_save_settings', array( $this, 'handle_save' ) );
		add_action( 'admin_post_aca_test_connection', array( $this, 'handle_test_connection' ) );
	}

	public static function get_defaults() {
		return array(
			'api_base_url'       => '',
			'api_key_encrypted'  => '',
			'model'              => 'gpt-3.5-turbo',
			'temperature'        => 0.7,
			'max_tokens'         => 2000,
			'timeout'            => 60,
			'enabled_features'   => array( 'polish', 'expand', 'shrink', 'continue', 'summary', 'seo_title', 'translate', 'bullet' ),
			'daily_quota'        => 0, // 0 = 不限制
			'log_retention_days' => 30,
			'purge_on_uninstall' => false,
		);
	}

	public static function get_settings() {
		$saved = get_option( ACA_OPTION_KEY, array() );
		return wp_parse_args( $saved, self::get_defaults() );
	}

	// 只在真正要发请求的时候才解密，别的地方不要碰这个方法
	public static function get_decrypted_api_key() {
		$settings = self::get_settings();
		if ( empty( $settings['api_key_encrypted'] ) ) {
			return '';
		}
		$decrypted = ACA_Crypto::decrypt( $settings['api_key_encrypted'] );
		return is_string( $decrypted ) ? $decrypted : '';
	}

	public function register_menu() {
		add_options_page(
			__( 'AI 文章润色助手设置', 'ai-content-assistant' ),
			__( 'AI 润色助手', 'ai-content-assistant' ),
			'manage_options',
			'aca-settings',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting( 'aca_settings_group', ACA_OPTION_KEY );
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足', 'ai-content-assistant' ) );
		}
		check_admin_referer( 'aca_save_settings_action', 'aca_nonce' );

		$existing = self::get_settings();

		$base_url = isset( $_POST['api_base_url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['api_base_url'] ) ) ) : '';
		$model    = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : 'gpt-3.5-turbo';
		$temp     = isset( $_POST['temperature'] ) ? (float) $_POST['temperature'] : 0.7;
		$maxtok   = isset( $_POST['max_tokens'] ) ? absint( $_POST['max_tokens'] ) : 2000;
		$timeout  = isset( $_POST['timeout'] ) ? absint( $_POST['timeout'] ) : 60;
		$quota    = isset( $_POST['daily_quota'] ) ? absint( $_POST['daily_quota'] ) : 0;
		$retention = isset( $_POST['log_retention_days'] ) ? absint( $_POST['log_retention_days'] ) : 30;
		$purge_on_uninstall = isset( $_POST['purge_on_uninstall'] ) && '1' === $_POST['purge_on_uninstall'];

		$features_all = array( 'polish', 'expand', 'shrink', 'continue', 'summary', 'seo_title', 'translate', 'bullet' );
		$features     = array();
		if ( isset( $_POST['enabled_features'] ) && is_array( $_POST['enabled_features'] ) ) {
			foreach ( wp_unslash( $_POST['enabled_features'] ) as $f ) {
				$f = sanitize_key( $f );
				if ( in_array( $f, $features_all, true ) ) {
					$features[] = $f;
				}
			}
		}

		$new_settings = array(
			'api_base_url'       => $base_url,
			'model'              => $model,
			'temperature'        => max( 0, min( 2, $temp ) ),
			'max_tokens'         => max( 1, min( 32000, $maxtok ) ),
			'timeout'            => max( 5, min( 300, $timeout ) ),
			'enabled_features'   => $features,
			'daily_quota'        => $quota,
			'log_retention_days' => max( 0, min( 365, $retention ) ),
			'purge_on_uninstall' => $purge_on_uninstall,
			'api_key_encrypted'  => $existing['api_key_encrypted'],
		);

		// 仅当用户填写了新密钥时才更新加密值；留空则保留原有密钥（避免每次保存都要求重新输入）
		if ( isset( $_POST['api_key'] ) ) {
			$raw_key = trim( wp_unslash( $_POST['api_key'] ) );
			if ( '' !== $raw_key && '********' !== $raw_key ) {
				$encrypted = ACA_Crypto::encrypt( $raw_key );
				if ( false !== $encrypted ) {
					$new_settings['api_key_encrypted'] = $encrypted;
				}
			}
		}

		// 支持一键清除密钥
		if ( isset( $_POST['clear_api_key'] ) && '1' === $_POST['clear_api_key'] ) {
			$new_settings['api_key_encrypted'] = '';
		}

		update_option( ACA_OPTION_KEY, $new_settings );

		wp_safe_redirect( add_query_arg( array( 'page' => 'aca-settings', 'aca_updated' => '1' ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	// 走 admin-post 而不是纯 ajax，图个能直接跳转回设置页带 nonce 的方便；
	// 前端用 $.post 调这个 URL，返回还是标准 JSON。
	// 这里刻意不去读数据库里已保存的设置——用户填完表单还没点保存就点"测试连接"
	// 是很常见的操作，直接用表单里当前的值测，别逼着人先保存一次。
	public function handle_test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足', 'ai-content-assistant' ) ), 403 );
		}
		check_admin_referer( 'aca_test_connection_action', 'aca_nonce' );

		$overrides = array();

		if ( isset( $_POST['api_base_url'] ) && '' !== trim( wp_unslash( $_POST['api_base_url'] ) ) ) {
			$overrides['base_url'] = esc_url_raw( trim( wp_unslash( $_POST['api_base_url'] ) ) );
		}

		if ( isset( $_POST['model'] ) && '' !== trim( wp_unslash( $_POST['model'] ) ) ) {
			$overrides['model'] = sanitize_text_field( wp_unslash( $_POST['model'] ) );
		}

		// 密钥框里显示的是掩码占位符，真填了新密钥才覆盖，否则用库里已保存的（如果有的话）
		if ( isset( $_POST['api_key'] ) ) {
			$raw_key = trim( wp_unslash( $_POST['api_key'] ) );
			if ( '' !== $raw_key ) {
				$overrides['api_key'] = $raw_key;
			}
		}

		$result = ACA_AI_Client::instance()->test_connection( $overrides );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array(
				'message' => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			) );
		}

		wp_send_json_success( array( 'message' => __( '连接成功！', 'ai-content-assistant' ), 'reply' => $result ) );
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings     = self::get_settings();
		$has_key      = ! empty( $settings['api_key_encrypted'] );
		$masked       = '';
		if ( $has_key ) {
			$decrypted = self::get_decrypted_api_key();
			$masked    = $decrypted ? ACA_Crypto::mask( $decrypted ) : __( '（无法解密，请重新填写）', 'ai-content-assistant' );
		}
		include ACA_PLUGIN_DIR . 'includes/views/settings-page.php';
	}
}
