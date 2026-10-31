<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 往文章编辑页塞面板，Gutenberg 和经典编辑器（TinyMCE）两条腿走路
class ACA_Editor {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_classic_editor_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_settings_assets' ) );
		add_action( 'add_meta_boxes', array( $this, 'maybe_register_classic_metabox' ) );
	}

	private function is_configured() {
		$settings = ACA_Settings::get_settings();
		return ! empty( $settings['api_base_url'] ) && ! empty( $settings['api_key_encrypted'] );
	}

	private function get_shared_localize_data() {
		$settings = ACA_Settings::get_settings();
		$builtin  = ACA_Prompts::get_builtin_prompts();
		$customs  = ACA_Prompts::get_custom_prompts();

		$features = array();
		foreach ( $builtin as $key => $item ) {
			if ( in_array( $key, (array) $settings['enabled_features'], true ) ) {
				$features[] = array(
					'key'   => $key,
					'label' => $item['label'],
					'needsLang' => ( 'translate' === $key ),
				);
			}
		}

		$custom_list = array();
		foreach ( $customs as $id => $item ) {
			$custom_list[] = array(
				'key'   => 'custom_' . $id,
				'label' => $item['name'],
				'needsLang' => false,
			);
		}

		return array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'settingsUrl'  => admin_url( 'options-general.php?page=aca-settings' ),
			'nonce'        => wp_create_nonce( 'aca_editor_nonce' ),
			'configured'   => $this->is_configured(),
			'features'     => $features,
			'customPrompts'=> $custom_list,
			'i18n'         => array(
				'panelTitle'      => __( 'AI 文章润色助手', 'ai-content-assistant' ),
				'goSettings'      => __( '前往设置页配置', 'ai-content-assistant' ),
				'selectFeature'   => __( '请选择要执行的操作', 'ai-content-assistant' ),
				'noSelection'     => __( '未选中文本，将全文发送给 AI。', 'ai-content-assistant' ),
				'processing'      => __( 'AI 正在处理，请稍候…', 'ai-content-assistant' ),
				'applyResult'     => __( '应用结果', 'ai-content-assistant' ),
				'applyToExcerpt'  => __( '填入摘要框', 'ai-content-assistant' ),
				'applyToTitle'    => __( '填入标题', 'ai-content-assistant' ),
				'regenerate'      => __( '换一个', 'ai-content-assistant' ),
				'summaryHint'     => __( '生成的是摘要，点击下方按钮会填入"摘要"输入框，而不是替换正文。', 'ai-content-assistant' ),
				'seoTitleHint'    => __( '生成的是候选标题，点击下方按钮会填入文章标题，不满意可以点"换一个"重新生成。', 'ai-content-assistant' ),
				'bodyApplyNotice' => __( '正文类结果会以新段落追加到正文末尾，不会覆盖你已有的内容，可放心应用。', 'ai-content-assistant' ),
				'scopeBodyNotice' => __( '摘要和标题建议会根据全文来生成，不受上面"处理选中/全文"范围影响。', 'ai-content-assistant' ),
				'noExcerptBox'    => __( '当前文章类型没有启用摘要输入框，先在"屏幕选项"里勾选"摘要"，或者手动复制下方内容。', 'ai-content-assistant' ),
				'copyResult'      => __( '复制', 'ai-content-assistant' ),
				'discard'         => __( '丢弃', 'ai-content-assistant' ),
				'error'           => __( '出错了：', 'ai-content-assistant' ),
				'notConfigured'   => __( '插件尚未配置 AI 服务商，请管理员前往设置页配置。', 'ai-content-assistant' ),
				'moreFeatures'    => __( '更多功能', 'ai-content-assistant' ),
				'translateTo'     => __( '翻译为', 'ai-content-assistant' ),
				'quotaExceeded'   => __( '今日调用次数已达上限，请明天再试。', 'ai-content-assistant' ),
				'runOnSelection'  => __( '处理选中', 'ai-content-assistant' ),
				'runOnFull'       => __( '处理全文', 'ai-content-assistant' ),
				'usedTokens'      => __( '本次消耗 {tokens} tokens', 'ai-content-assistant' ),
			),
		);
	}

	// Gutenberg 那边要挂的脚本样式
	public function enqueue_block_editor_assets() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, $this->get_supported_post_types(), true ) ) {
			return;
		}

		wp_enqueue_script(
			'aca-block-editor',
			ACA_PLUGIN_URL . 'assets/js/block-editor.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n', 'wp-block-editor' ),
			ACA_VERSION,
			true
		);

		wp_enqueue_style( 'aca-editor-style', ACA_PLUGIN_URL . 'assets/css/editor.css', array(), ACA_VERSION );

		wp_localize_script( 'aca-block-editor', 'ACA_DATA', $this->get_shared_localize_data() );
	}

	// 经典编辑器（TinyMCE）那边要挂的脚本样式
	public function enqueue_classic_editor_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, $this->get_supported_post_types(), true ) ) {
			return;
		}

		// 若当前使用 Gutenberg，则不重复加载经典编辑器资源
		if ( function_exists( 'use_block_editor_for_post' ) && get_post() && use_block_editor_for_post( get_post() ) ) {
			return;
		}

		wp_enqueue_script(
			'aca-classic-editor',
			ACA_PLUGIN_URL . 'assets/js/classic-editor.js',
			array( 'jquery' ),
			ACA_VERSION,
			true
		);
		wp_enqueue_style( 'aca-editor-style', ACA_PLUGIN_URL . 'assets/css/editor.css', array(), ACA_VERSION );

		wp_localize_script( 'aca-classic-editor', 'ACA_DATA', $this->get_shared_localize_data() );
	}

	// 设置页自己的样式脚本（tab切换、测试连接按钮）
	public function enqueue_settings_assets( $hook ) {
		if ( 'settings_page_aca-settings' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'aca-admin-style', ACA_PLUGIN_URL . 'assets/css/admin.css', array(), ACA_VERSION );
		wp_enqueue_script( 'aca-admin-script', ACA_PLUGIN_URL . 'assets/js/admin-settings.js', array( 'jquery' ), ACA_VERSION, true );

		wp_localize_script( 'aca-admin-script', 'ACA_ADMIN', array(
			'ajaxUrl'    => admin_url( 'admin-post.php' ),
			'testNonce'  => wp_create_nonce( 'aca_test_connection_action' ),
			'i18n'       => array(
				'testing'      => __( '正在测试…', 'ai-content-assistant' ),
				'buttonLabel'  => __( '测试连接', 'ai-content-assistant' ),
				'successTitle' => __( '连接成功', 'ai-content-assistant' ),
				'failTitle'    => __( '连接失败', 'ai-content-assistant' ),
			),
		) );
	}

	// 经典编辑器没有原生侧边栏机制，用 metabox 当容器，脚本负责把面板渲染进去
	public function maybe_register_classic_metabox() {
		foreach ( $this->get_supported_post_types() as $post_type ) {
			add_meta_box(
				'aca_assistant_box',
				__( 'AI 文章润色助手', 'ai-content-assistant' ),
				array( $this, 'render_classic_metabox' ),
				$post_type,
				'side',
				'high'
			);
		}
	}

	public function render_classic_metabox() {
		echo '<div id="aca-classic-panel-root"></div>';
	}

	private function get_supported_post_types() {
		// 想让插件支持自定义文章类型的话，挂这个 filter 就行
		return apply_filters( 'aca_supported_post_types', array( 'post', 'page' ) );
	}
}
