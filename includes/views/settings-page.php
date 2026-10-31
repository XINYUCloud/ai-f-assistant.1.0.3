<?php
// 变量都是从 ACA_Settings::render_settings_page() 传进来的：$settings, $has_key, $masked
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$feature_labels = array(
	'polish'     => __( '智能润色', 'ai-content-assistant' ),
	'expand'     => __( '扩写内容', 'ai-content-assistant' ),
	'shrink'     => __( '精简/缩写', 'ai-content-assistant' ),
	'continue'   => __( 'AI 续写', 'ai-content-assistant' ),
	'summary'    => __( '生成摘要', 'ai-content-assistant' ),
	'seo_title'  => __( 'SEO 标题建议', 'ai-content-assistant' ),
	'translate'  => __( '多语言翻译', 'ai-content-assistant' ),
	'bullet'     => __( '要点提炼', 'ai-content-assistant' ),
);
?>
<div class="wrap aca-settings-wrap">
	<h1><?php esc_html_e( 'AI 文章润色助手 - 设置', 'ai-content-assistant' ); ?></h1>

	<?php if ( isset( $_GET['aca_updated'] ) ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( '设置已保存。', 'ai-content-assistant' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( isset( $_GET['aca_log_cleared'] ) ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( '使用记录已清空。', 'ai-content-assistant' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( isset( $_GET['prompt_saved'] ) ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( '模板已保存。', 'ai-content-assistant' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( isset( $_GET['prompt_deleted'] ) ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( '模板已删除。', 'ai-content-assistant' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( isset( $_GET['prompt_error'] ) ) : ?>
		<div class="notice notice-error is-dismissible">
			<p><?php esc_html_e( '模板名称或内容不能为空。', 'ai-content-assistant' ); ?></p>
		</div>
	<?php endif; ?>

	<h2 class="nav-tab-wrapper">
		<a href="#aca-tab-provider" class="nav-tab nav-tab-active" data-tab="provider"><?php esc_html_e( 'AI 服务商', 'ai-content-assistant' ); ?></a>
		<a href="#aca-tab-features" class="nav-tab" data-tab="features"><?php esc_html_e( '功能开关', 'ai-content-assistant' ); ?></a>
		<a href="#aca-tab-advanced" class="nav-tab" data-tab="advanced"><?php esc_html_e( '高级选项', 'ai-content-assistant' ); ?></a>
		<a href="#aca-tab-prompts" class="nav-tab" data-tab="prompts"><?php esc_html_e( 'Prompt 模板', 'ai-content-assistant' ); ?></a>
		<a href="#aca-tab-logs" class="nav-tab" data-tab="logs"><?php esc_html_e( '使用记录', 'ai-content-assistant' ); ?></a>
	</h2>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="aca-settings-form">
		<input type="hidden" name="action" value="aca_save_settings" />
		<?php wp_nonce_field( 'aca_save_settings_action', 'aca_nonce' ); ?>

		<div class="aca-tab-panel" id="aca-tab-provider">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="api_base_url"><?php esc_html_e( 'API 接口地址', 'ai-content-assistant' ); ?></label></th>
					<td>
						<input type="url" id="api_base_url" name="api_base_url" class="regular-text"
							placeholder="https://api.openai.com/v1"
							value="<?php echo esc_attr( $settings['api_base_url'] ); ?>" />
						<p class="description">
							<?php esc_html_e( '请填写兼容 OpenAI 格式的接口 Base URL（不含 /chat/completions），例如自建中转、第三方代理或官方地址。', 'ai-content-assistant' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="api_key"><?php esc_html_e( 'API 密钥', 'ai-content-assistant' ); ?></label></th>
					<td>
						<input type="password" id="api_key" name="api_key" class="regular-text" autocomplete="off"
							placeholder="<?php echo $has_key ? esc_attr( $masked ) : 'sk-xxxxxxxxxxxxxxxx'; ?>" value="" />
						<?php if ( $has_key ) : ?>
							<label style="margin-left:10px;">
								<input type="checkbox" name="clear_api_key" value="1" /> <?php esc_html_e( '清除已保存的密钥', 'ai-content-assistant' ); ?>
							</label>
						<?php endif; ?>
						<p class="description">
							<?php esc_html_e( '密钥会通过 AES-256 加密后存储于数据库，不会以明文形式保存。留空则保留原有密钥不变。', 'ai-content-assistant' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="model"><?php esc_html_e( '模型名称', 'ai-content-assistant' ); ?></label></th>
					<td>
						<input type="text" id="model" name="model" class="regular-text"
							value="<?php echo esc_attr( $settings['model'] ); ?>" placeholder="gpt-3.5-turbo" />
						<p class="description"><?php esc_html_e( '填写服务商支持的模型标识，例如 gpt-4o-mini、deepseek-chat 等。', 'ai-content-assistant' ); ?></p>
						<div class="aca-model-presets">
							<button type="button" class="button button-small aca-model-preset" data-model="gpt-4o-mini">GPT-4o mini</button>
							<button type="button" class="button button-small aca-model-preset" data-model="deepseek-chat">DeepSeek-V3</button>
							<button type="button" class="button button-small aca-model-preset" data-model="moonshot-v1-8k">Moonshot 8k</button>
							<button type="button" class="button button-small aca-model-preset" data-model="qwen-plus">通义千问 Plus</button>
						</div>
						<p class="description"><?php esc_html_e( '点击上面按钮可快速填入常用模型标识（需你的服务商支持对应模型）。', 'ai-content-assistant' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"></th>
					<td>
						<button type="button" class="button" id="aca-test-connection"><?php esc_html_e( '测试连接', 'ai-content-assistant' ); ?></button>
						<p class="description"><?php esc_html_e( '会用上面表单里当前填的地址/密钥/模型直接测试，不需要先保存。', 'ai-content-assistant' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="aca-tab-panel" id="aca-tab-features" style="display:none;">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( '启用功能', 'ai-content-assistant' ); ?></th>
					<td>
						<?php foreach ( $feature_labels as $key => $label ) : ?>
							<label style="display:block;margin-bottom:6px;">
								<input type="checkbox" name="enabled_features[]" value="<?php echo esc_attr( $key ); ?>"
									<?php checked( in_array( $key, (array) $settings['enabled_features'], true ) ); ?> />
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
			</table>
		</div>

		<div class="aca-tab-panel" id="aca-tab-advanced" style="display:none;">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="temperature"><?php esc_html_e( 'Temperature', 'ai-content-assistant' ); ?></label></th>
					<td>
						<input type="number" id="temperature" name="temperature" step="0.1" min="0" max="2"
							value="<?php echo esc_attr( $settings['temperature'] ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( '值越高输出越有创意，越低越保守稳定。建议 0.3 - 0.9。', 'ai-content-assistant' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="max_tokens"><?php esc_html_e( '最大 Token 数', 'ai-content-assistant' ); ?></label></th>
					<td><input type="number" id="max_tokens" name="max_tokens" min="1" max="32000"
						value="<?php echo esc_attr( $settings['max_tokens'] ); ?>" class="small-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="timeout"><?php esc_html_e( '请求超时（秒）', 'ai-content-assistant' ); ?></label></th>
					<td><input type="number" id="timeout" name="timeout" min="5" max="300"
						value="<?php echo esc_attr( $settings['timeout'] ); ?>" class="small-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="daily_quota"><?php esc_html_e( '每用户每日调用上限', 'ai-content-assistant' ); ?></label></th>
					<td>
						<input type="number" id="daily_quota" name="daily_quota" min="0"
							value="<?php echo esc_attr( $settings['daily_quota'] ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( '0 表示不限制。用于防止 API 额度被过度消耗。', 'ai-content-assistant' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="log_retention_days"><?php esc_html_e( '日志保留天数', 'ai-content-assistant' ); ?></label></th>
					<td>
						<input type="number" id="log_retention_days" name="log_retention_days" min="0" max="365"
							value="<?php echo esc_attr( $settings['log_retention_days'] ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( '超过该天数的使用记录将在每日计划任务中自动清理，0 表示永久保留。', 'ai-content-assistant' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '卸载插件时', 'ai-content-assistant' ); ?></th>
					<td>
						<label>
							<input type="hidden" name="purge_on_uninstall" value="0" />
							<input type="checkbox" name="purge_on_uninstall" value="1"
								<?php checked( ! empty( $settings['purge_on_uninstall'] ) ); ?> />
							<?php esc_html_e( '彻底删除插件时，同时清除全部设置、Prompt 模板与使用记录', 'ai-content-assistant' ); ?>
						</label>
						<p class="description"><?php esc_html_e( '默认不勾选：即使删除插件，你的 API 配置和历史记录也会保留，方便重新安装后继续使用。', 'ai-content-assistant' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<?php submit_button( __( '保存设置', 'ai-content-assistant' ) ); ?>
	</form>

	<div class="aca-tab-panel" id="aca-tab-prompts" style="display:none;">
		<?php ACA_Prompts::instance()->render_admin_ui(); ?>
	</div>

	<div class="aca-tab-panel" id="aca-tab-logs" style="display:none;">
		<?php ACA_Logger::instance()->render_admin_ui(); ?>
	</div>

	<div id="aca-test-modal-overlay" class="aca-modal-overlay" style="display:none;">
		<div class="aca-modal-box">
			<h3 id="aca-test-modal-title"></h3>
			<p id="aca-test-modal-message"></p>
			<p class="aca-modal-actions">
				<button type="button" class="button button-primary" id="aca-test-modal-close"><?php esc_html_e( '知道了', 'ai-content-assistant' ); ?></button>
			</p>
		</div>
	</div>
</div>
