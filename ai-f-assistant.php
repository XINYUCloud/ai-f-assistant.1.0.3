<?php
/**
 * Plugin Name:       AI 文章润色助手 (AI Content Assistant)
 * Plugin URI:        https://www.fohok.xin/product/ai-f-assistant
 * Description:       文章编辑页里塞一个AI助手，兼容OpenAI格式的/chat/completions接口（官方/中转/自建都行）。润色、扩写、缩写、续写、摘要、SEO标题、翻译、要点提炼。
 * Version:           1.0.3
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            fohok(open-fohok)
 * Text Domain:       ai-content-assistant
 * Domain Path:       /languages
 * License:           GPL v2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ACA_VERSION', '1.0.3' );
define( 'ACA_PLUGIN_FILE', __FILE__ );
define( 'ACA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ACA_OPTION_KEY', 'aca_settings' );
define( 'ACA_LOG_TABLE', 'aca_usage_log' );

require_once ACA_PLUGIN_DIR . 'includes/class-aca-crypto.php';
require_once ACA_PLUGIN_DIR . 'includes/class-aca-settings.php';
require_once ACA_PLUGIN_DIR . 'includes/class-aca-ai-client.php';
require_once ACA_PLUGIN_DIR . 'includes/class-aca-prompts.php';
require_once ACA_PLUGIN_DIR . 'includes/class-aca-logger.php';
require_once ACA_PLUGIN_DIR . 'includes/class-aca-editor.php';
require_once ACA_PLUGIN_DIR . 'includes/class-aca-ajax.php';
require_once ACA_PLUGIN_DIR . 'includes/class-aca-cli.php';

// 单例，图省事，插件不复杂没必要上依赖注入那一套
final class AI_Content_Assistant {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->init_hooks();
	}

	private function init_hooks() {
		register_activation_hook( ACA_PLUGIN_FILE, array( $this, 'on_activate' ) );
		register_deactivation_hook( ACA_PLUGIN_FILE, array( $this, 'on_deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( $this, 'init_modules' ) );

		add_action( 'admin_notices', array( $this, 'maybe_show_setup_notice' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'ai-content-assistant', false, dirname( plugin_basename( ACA_PLUGIN_FILE ) ) . '/languages' );
	}

	public function init_modules() {
		ACA_Settings::instance();
		ACA_Prompts::instance();
		ACA_Logger::instance();
		ACA_Editor::instance();
		ACA_Ajax::instance();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			ACA_CLI::register();
		}
	}

	public function on_activate() {
		ACA_Logger::create_table();

		$defaults = ACA_Settings::get_defaults();
		if ( false === get_option( ACA_OPTION_KEY ) ) {
			add_option( ACA_OPTION_KEY, $defaults );
		}

		if ( false === get_option( 'aca_db_version' ) ) {
			add_option( 'aca_db_version', ACA_VERSION );
		}

		flush_rewrite_rules();
	}

	public function on_deactivate() {
		flush_rewrite_rules();
	}

	// 没配 API 之前，在后台顶部提醒一下，免得用户装完插件找不到怎么用
	public function maybe_show_setup_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = ACA_Settings::get_settings();
		if ( ! empty( $settings['api_base_url'] ) && ! empty( $settings['api_key_encrypted'] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'AI 文章润色助手：还没配置 AI 服务商接口，功能暂时用不了。', 'ai-content-assistant' ),
			esc_url( admin_url( 'options-general.php?page=aca-settings' ) ),
			esc_html__( '去设置一下', 'ai-content-assistant' )
		);
	}
}

AI_Content_Assistant::instance();

