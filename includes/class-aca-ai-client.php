<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 和AI服务商的 /chat/completions 打交道的地方。
 *
 * 踩过的坑：
 * - 有些中转站地址填错了会返回一个HTML错误页而不是 JSON，之前直接当空结果处理，
 *   用户根本不知道问题出在哪，所以这里单独判断一下。
 * - curl层面的连不上（DNS/超时/证书）和接口层面返回的业务错误要分开提示，
 *   不然用户会把网络问题当成"密钥填错了"到处试。
 */
class ACA_AI_Client {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * @param array $messages  [ ['role'=>..,'content'=>..], ... ]
	 * @param array $overrides 可覆盖 model / temperature / max_tokens / base_url / api_key
	 * @return array|WP_Error  成功返回 ['content'=>string, 'usage'=>array, 'cost'=float]
	 */
	public function chat( array $messages, array $overrides = array() ) {
		$settings = ACA_Settings::get_settings();

		$base_url = ! empty( $overrides['base_url'] ) ? $overrides['base_url'] : $settings['api_base_url'];
		$base_url = untrailingslashit( trim( $base_url ) );

		$api_key = ! empty( $overrides['api_key'] ) ? $overrides['api_key'] : ACA_Settings::get_decrypted_api_key();

		if ( empty( $base_url ) ) {
			return new WP_Error( 'aca_no_base_url', __( '还没填 API 接口地址，去设置页填一下。', 'ai-content-assistant' ) );
		}
		if ( empty( $api_key ) ) {
			return new WP_Error( 'aca_no_api_key', __( 'API 密钥为空或解密失败，回设置页重新填一次密钥。', 'ai-content-assistant' ) );
		}

		$endpoint = $base_url . '/chat/completions';

		$payload = array(
			'model'       => ! empty( $overrides['model'] ) ? $overrides['model'] : $settings['model'],
			'messages'    => $messages,
			'temperature' => isset( $overrides['temperature'] ) ? (float) $overrides['temperature'] : (float) $settings['temperature'],
			'max_tokens'  => isset( $overrides['max_tokens'] ) ? (int) $overrides['max_tokens'] : (int) $settings['max_tokens'],
			'stream'      => false,
		);

		$response = wp_remote_post( $endpoint, array(
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => (int) $settings['timeout'],
		) );

		// 第一层：网络层面就没打通（DNS解析失败/连接超时/证书问题等）
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'aca_network_error',
				sprintf(
					/* translators: %s: 底层错误信息 */
					__( '连不上这个接口地址：%s（先检查地址填得对不对，服务器能不能出网，防火墙有没有拦）', 'ai-content-assistant' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );

		// 第二层：网络通了，但对面根本没返回 JSON —— 常见于地址填错（比如漏了 /v1，
		// 或者填成了网站首页），对方服务器回了个 HTML 404/500 页面。
		$decoded = json_decode( $raw_body, true );
		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			$snippet = mb_substr( wp_strip_all_tags( $raw_body ), 0, 120 );
			return new WP_Error(
				'aca_invalid_json',
				sprintf(
					/* translators: 1: HTTP状态码 2: 返回内容片段 */
					__( '接口没有返回合法的 JSON（HTTP %1$d），大概率是地址填错了。返回内容开头：%2$s', 'ai-content-assistant' ),
					$status_code,
					$snippet ? $snippet . '…' : __( '（空）', 'ai-content-assistant' )
				)
			);
		}

		// 第三层：HTTP 状态码非 2xx，通常是密钥错误 / 余额不足 / 模型名不对
		if ( $status_code < 200 || $status_code >= 300 ) {
			$err_msg = '';
			if ( is_array( $decoded ) ) {
				if ( isset( $decoded['error']['message'] ) ) {
					$err_msg = $decoded['error']['message'];
				} elseif ( isset( $decoded['message'] ) ) {
					$err_msg = $decoded['message'];
				}
			}
			if ( ! $err_msg ) {
				$err_msg = mb_substr( $raw_body, 0, 200 );
			}

			return new WP_Error(
				'aca_api_error',
				sprintf(
					/* translators: 1: HTTP状态码 2: 错误信息 */
					__( '服务商返回了错误（HTTP %1$d）：%2$s', 'ai-content-assistant' ),
					$status_code,
					$err_msg
				)
			);
		}

		// 第四层：状态码是 200，结构却对不上——有的服务商 choices 是空数组，
		// 有的干脆没有 message.content，都得兜住
		$content = null;
		if ( isset( $decoded['choices'][0]['message']['content'] ) ) {
			$content = $decoded['choices'][0]['message']['content'];
		} elseif ( isset( $decoded['choices'][0]['text'] ) ) {
			// 少数老式补全接口（非 chat 格式）会把文本放在这里
			$content = $decoded['choices'][0]['text'];
		}

		if ( null === $content || '' === trim( (string) $content ) ) {
			$hint = empty( $decoded['choices'] )
				? __( '返回结果里没有 choices 字段，可能是模型名称填错了，或者这个服务商不是标准 chat completions 格式。', 'ai-content-assistant' )
				: __( '返回内容是空的，可能是内容被服务商的审核机制拦截了，换个说法再试试。', 'ai-content-assistant' );

			return new WP_Error( 'aca_empty_response', $hint );
		}

		return array(
			'content' => trim( $content ),
			'usage'   => isset( $decoded['usage'] ) && is_array( $decoded['usage'] ) ? $decoded['usage'] : array(),
		);
	}

	/**
	 * 设置页"测试连接"按钮背后调的方法
	 *
	 * @param array $overrides 可选，测试还没保存的表单值：base_url / api_key / model
	 * @return string|WP_Error
	 */
	public function test_connection( array $overrides = array() ) {
		$result = $this->chat(
			array( array( 'role' => 'user', 'content' => '请只回复两个字：正常' ) ),
			array_merge( $overrides, array( 'max_tokens' => 30 ) )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['content'] ) ) {
			// 理论上 chat() 已经拦掉这种情况了，这里再兜一层保险
			return new WP_Error( 'aca_empty_response', __( '接口连上了，但没有返回任何文字内容，换个模型名称试试。', 'ai-content-assistant' ) );
		}

		return $result['content'];
	}
}
