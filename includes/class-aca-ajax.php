<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 编辑器面板发过来的请求都走这一个入口
class ACA_Ajax {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_aca_process_text', array( $this, 'handle_process_text' ) );
	}

	public function handle_process_text() {
		check_ajax_referer( 'aca_editor_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'ai-content-assistant' ) ), 403 );
		}

		$feature = isset( $_POST['feature'] ) ? sanitize_text_field( wp_unslash( $_POST['feature'] ) ) : '';
		$content = isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '';
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$lang    = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : '';

		if ( empty( $feature ) || '' === trim( $content ) ) {
			wp_send_json_error( array( 'message' => __( '缺少必要参数：功能类型或文本内容为空。', 'ai-content-assistant' ) ) );
		}

		// 内容长度保护（防止超大文章一次性提交导致超时/超额）
		$content = wp_strip_all_tags( $content );
		if ( mb_strlen( $content ) > 12000 ) {
			$content = mb_substr( $content, 0, 12000 );
		}

		// 每日额度检查
		$settings = ACA_Settings::get_settings();
		$user_id  = get_current_user_id();

		if ( ! empty( $settings['daily_quota'] ) ) {
			$used = ACA_Logger::get_today_count_for_user( $user_id );
			if ( $used >= (int) $settings['daily_quota'] ) {
				wp_send_json_error( array( 'message' => __( '今日调用次数已达上限，请明天再试。', 'ai-content-assistant' ), 'code' => 'quota_exceeded' ) );
			}
		}

		$extra = array();
		if ( 'translate' === $feature && $lang ) {
			$extra['lang'] = $lang;
		}

		// 支持在 Prompt 模板里用 {TITLE} 占位符引用当前文章标题，让指令更贴合上下文
		$messages = ACA_Prompts::build_messages( $feature, $content, $extra );

		if ( is_wp_error( $messages ) ) {
			ACA_Logger::log( $user_id, $post_id, $feature, array(), 'failed', $messages->get_error_message() );
			wp_send_json_error( array( 'message' => $messages->get_error_message() ) );
		}

		// 全文处理时把 {TITLE} 替换成文章当前标题；没有标题就用"（无标题）"兜底
		if ( strpos( wp_json_encode( $messages ), '{TITLE}' ) !== false ) {
			$title = get_the_title( $post_id );
			if ( '' === trim( (string) $title ) ) {
				$title = __( '（无标题）', 'ai-content-assistant' );
			}
			foreach ( $messages as &$m ) {
				if ( is_array( $m ) && isset( $m['content'] ) ) {
					$m['content'] = str_replace( '{TITLE}', $title, (string) $m['content'] );
				}
			}
			unset( $m );
		}

		$result = ACA_AI_Client::instance()->chat( $messages );

		if ( is_wp_error( $result ) ) {
			ACA_Logger::log( $user_id, $post_id, $feature, array(), 'failed', $result->get_error_message() );
			wp_send_json_error( array(
				'message' => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			) );
		}

		ACA_Logger::log( $user_id, $post_id, $feature, $result['usage'], 'success' );

		// 返回结果的同时带上用量与结果统计，前端可展示 token 消耗 / 字数
		wp_send_json_success( array(
			'result'     => $result['content'],
			'usage'      => $result['usage'],
			'char_count' => mb_strlen( $result['content'] ),
			'meta'       => array(
				'total_tokens' => isset( $result['usage']['total_tokens'] ) ? (int) $result['usage']['total_tokens'] : 0,
			),
		) );
	}
}
