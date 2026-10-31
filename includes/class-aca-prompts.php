<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prompt 模板：内置的 7 个功能 + 用户自己加的自定义模板
class ACA_Prompts {

	private static $instance = null;
	const OPTION_KEY = 'aca_custom_prompts';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_aca_save_prompt', array( $this, 'handle_save_prompt' ) );
		add_action( 'admin_post_aca_delete_prompt', array( $this, 'handle_delete_prompt' ) );
	}

	// 内置 7 个功能对应的 system prompt，{LANG} 是翻译功能用的占位符
	public static function get_builtin_prompts() {
		return array(
			'polish' => array(
				'label'  => __( '智能润色', 'ai-content-assistant' ),
				'system' => __( '你是一位专业的中文编辑，擅长在保留原意的前提下润色文章，使其表达更流畅、逻辑更清晰、用词更准确。不要改变原文的核心观点和结构，只输出润色后的正文，不要添加任何解释或前后缀。', 'ai-content-assistant' ),
			),
			'expand' => array(
				'label'  => __( '扩写内容', 'ai-content-assistant' ),
				'system' => __( '你是一位内容创作助手，请在保持原文风格和主旨的基础上，对给定内容进行合理扩写，补充细节、例子或论证，使内容更充实。只输出扩写后的正文，不要添加解释。', 'ai-content-assistant' ),
			),
			'shrink' => array(
				'label'  => __( '精简/缩写', 'ai-content-assistant' ),
				'system' => __( '你是一位内容编辑助手，请在保留核心信息的前提下，将给定内容精简、去除冗余表达，使其更加简洁凝练。只输出精简后的正文，不要添加解释。', 'ai-content-assistant' ),
			),
			'continue' => array(
				'label'  => __( 'AI 续写', 'ai-content-assistant' ),
				'system' => __( '你是一位内容创作助手，请紧接给定内容的结尾，延续原文的风格、语气与逻辑进行续写。只输出续写的新内容，不要重复原文，不要添加解释。', 'ai-content-assistant' ),
			),
			'summary' => array(
				'label'  => __( '生成摘要', 'ai-content-assistant' ),
				'system' => __( '你是一位专业编辑，请为给定内容生成一段简洁准确的摘要，控制在150字以内，适合作为文章摘要或社交媒体分享描述使用。只输出摘要正文。', 'ai-content-assistant' ),
			),
			'seo_title' => array(
				'label'  => __( 'SEO 标题建议', 'ai-content-assistant' ),
				'system' => __( '你是一位SEO专家，请根据给定内容只给出1个最吸引点击、最符合搜索引擎优化原则的标题。要求：只输出这一个标题本身，不要编号、不要引号、不要多余说明，不要输出多个备选。', 'ai-content-assistant' ),
			),
			'translate' => array(
				'label'  => __( '多语言翻译', 'ai-content-assistant' ),
				'system' => __( '你是一位专业翻译，请将给定内容准确、自然地翻译成{LANG}，保留原文的段落结构和格式，只输出翻译结果。', 'ai-content-assistant' ),
			),
			'bullet' => array(
				'label'  => __( '要点提炼', 'ai-content-assistant' ),
				'system' => __( '你是一位内容提炼助手，请从给定内容中提取出最核心的要点，用简洁的要点列表形式输出。每条要点独立成行，以"- "开头，直接输出要点列表，不要添加任何解释或前后缀。', 'ai-content-assistant' ),
			),
		);
	}

	public static function get_custom_prompts() {
		return get_option( self::OPTION_KEY, array() );
	}

	// 组装最终发给 AI 的 messages 数组，custom_ 前缀代表用户自己加的模板
	public static function build_messages( $feature_key, $content, $extra = array() ) {
		$system = '';

		if ( 0 === strpos( $feature_key, 'custom_' ) ) {
			$id      = substr( $feature_key, 7 );
			$customs = self::get_custom_prompts();
			if ( ! isset( $customs[ $id ] ) ) {
				return new WP_Error( 'aca_prompt_not_found', __( '自定义模板不存在。', 'ai-content-assistant' ) );
			}
			$system = $customs[ $id ]['prompt'];
		} else {
			$builtin = self::get_builtin_prompts();
			if ( ! isset( $builtin[ $feature_key ] ) ) {
				return new WP_Error( 'aca_prompt_not_found', __( '功能不存在。', 'ai-content-assistant' ) );
			}
			$system = $builtin[ $feature_key ]['system'];
		}

		if ( isset( $extra['lang'] ) ) {
			$system = str_replace( '{LANG}', $extra['lang'], $system );
		}

		return array(
			array( 'role' => 'system', 'content' => $system ),
			array( 'role' => 'user', 'content' => $content ),
		);
	}

	public function handle_save_prompt() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( '权限不足', 'ai-content-assistant' ) );
		}
		check_admin_referer( 'aca_save_prompt_action', 'aca_nonce' );

		$name   = isset( $_POST['prompt_name'] ) ? sanitize_text_field( wp_unslash( $_POST['prompt_name'] ) ) : '';
		$prompt = isset( $_POST['prompt_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt_text'] ) ) : '';
		$id     = isset( $_POST['prompt_id'] ) ? sanitize_key( $_POST['prompt_id'] ) : '';

		if ( empty( $name ) || empty( $prompt ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'aca-settings', 'prompt_error' => '1' ), admin_url( 'options-general.php' ) ) );
			exit;
		}

		$customs = self::get_custom_prompts();

		if ( empty( $id ) ) {
			$id = 'p' . substr( md5( $name . microtime() ), 0, 10 );
		}

		$customs[ $id ] = array(
			'name'   => $name,
			'prompt' => $prompt,
		);

		update_option( self::OPTION_KEY, $customs );

		wp_safe_redirect( add_query_arg( array( 'page' => 'aca-settings', 'prompt_saved' => '1' ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	public function handle_delete_prompt() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( '权限不足', 'ai-content-assistant' ) );
		}
		check_admin_referer( 'aca_delete_prompt_action', 'aca_nonce' );

		$id      = isset( $_GET['id'] ) ? sanitize_key( $_GET['id'] ) : '';
		$customs = self::get_custom_prompts();

		if ( $id && isset( $customs[ $id ] ) ) {
			unset( $customs[ $id ] );
			update_option( self::OPTION_KEY, $customs );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'aca-settings', 'prompt_deleted' => '1' ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	// 设置页里"Prompt 模板"这个 tab 的内容
	public function render_admin_ui() {
		$customs = self::get_custom_prompts();
		?>
		<h3><?php esc_html_e( '新增自定义 Prompt 模板', 'ai-content-assistant' ); ?></h3>
		<p class="description"><?php esc_html_e( '自定义模板会出现在文章编辑页 AI 助手面板的"更多功能"下拉中，方便你固定常用的写作指令（如"转换为小红书文案风格"）。', 'ai-content-assistant' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="aca_save_prompt" />
			<?php wp_nonce_field( 'aca_save_prompt_action', 'aca_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="prompt_name"><?php esc_html_e( '模板名称', 'ai-content-assistant' ); ?></label></th>
					<td><input type="text" name="prompt_name" id="prompt_name" class="regular-text" placeholder="<?php esc_attr_e( '例如：转换为小红书风格', 'ai-content-assistant' ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="prompt_text"><?php esc_html_e( 'System Prompt 内容', 'ai-content-assistant' ); ?></label></th>
					<td><textarea name="prompt_text" id="prompt_text" rows="4" class="large-text" placeholder="<?php esc_attr_e( '描述你希望 AI 如何处理选中的文本…', 'ai-content-assistant' ); ?>"></textarea></td>
				</tr>
			</table>
			<?php submit_button( __( '保存模板', 'ai-content-assistant' ) ); ?>
		</form>

		<h3><?php esc_html_e( '已保存的模板', 'ai-content-assistant' ); ?></h3>
		<?php if ( empty( $customs ) ) : ?>
			<p><?php esc_html_e( '暂无自定义模板。', 'ai-content-assistant' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( '名称', 'ai-content-assistant' ); ?></th>
						<th><?php esc_html_e( 'Prompt 内容', 'ai-content-assistant' ); ?></th>
						<th><?php esc_html_e( '操作', 'ai-content-assistant' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $customs as $id => $item ) : ?>
						<tr>
							<td><?php echo esc_html( $item['name'] ); ?></td>
							<td><?php echo esc_html( wp_trim_words( $item['prompt'], 20 ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aca_delete_prompt&id=' . $id ), 'aca_delete_prompt_action', 'aca_nonce' ) ); ?>"
									onclick="return confirm('<?php echo esc_js( __( '确定要删除该模板吗？', 'ai-content-assistant' ) ); ?>');"
									class="button button-small"><?php esc_html_e( '删除', 'ai-content-assistant' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}
}
