# AI 文章润色助手

轻量级 WordPress AI 写作插件。在文章编辑页直接调用你配置的 AI 服务商，完成润色、扩写、缩写、续写、摘要、SEO 标题、翻译和要点提炼。

兼容任意遵循 OpenAI `/chat/completions` 格式的接口，包括 OpenAI 官方 API、Azure OpenAI 中转、DeepSeek、Moonshot（Kimi）、通义千问兼容模式以及自建网关。

- 插件版本：`1.0.3`
- 兼容 WordPress：`5.8+`
- 兼容 PHP：`7.4+`
- 许可证：GPL-2.0-or-later

## 功能特性

- **8 种写作功能**：智能润色、扩写内容、精简/缩写、AI 续写、生成摘要、SEO 标题建议、多语言翻译、要点提炼，可在设置页自由启停。
- **双编辑器支持**：同时适配 Gutenberg 区块编辑器和经典编辑器（TinyMCE）。
- **兼容任意 OpenAI 格式服务商**：只需填写 Base URL、API Key 和模型名称。
- **API 密钥加密存储**：密钥使用 AES-256 加密后写入数据库，不以明文保存。
- **自定义 Prompt 模板**：把常用的写作指令固化成模板，在编辑页"更多功能"中直接调用，支持 `{TITLE}` 占位符。
- **配额与日志**：可设置每用户每日调用上限，记录用户、文章、功能与 Token 消耗，支持筛选、分页和导出 CSV。
- **一键测试连接**：保存前即可用当前表单值测试服务商是否可用。
- **WP-CLI 支持**：命令行测试连接、清理历史日志。
- **可控卸载**：可自行选择卸载插件时是否清除设置、模板和使用记录。

## 安装

### 方式一：后台上传（推荐）

1. 从 [Releases](https://github.com/XINYUCloud/ai-f-assistant.1.0.3/releases) 下载 `ai-f-assistant-1.0.3.zip`。
2. 进入 WordPress 后台 **插件 → 安装插件 → 上传插件**，选择该 zip 并安装。
3. 启用插件。
4. 进入 **设置 → AI 润色助手**，填写 AI 服务商配置。

### 方式二：源码部署

将本仓库源码放入 `/wp-content/plugins/ai-f-assistant/` 目录，然后在后台启用插件。

## 配置

进入 WordPress 后台 **设置 → AI 润色助手**，在"AI 服务商"页签填写：

| 配置项 | 说明 | 示例 |
| --- | --- | --- |
| API 接口地址 | 兼容 OpenAI 格式的 Base URL，不包含 `/chat/completions` | `https://api.openai.com/v1` |
| API 密钥 | 服务商提供的 Key，加密后存储；留空保存则保留原密钥 | `sk-...` |
| 模型名称 | 服务商支持的模型标识 | `gpt-4o-mini`、`deepseek-chat` |

填写后点击 **测试连接** 验证配置。测试会直接使用表单中的当前值，不需要先保存。

其他页签：

- **功能开关**：启用或禁用编辑页中的功能按钮。
- **高级选项**：Temperature、最大 Token 数、请求超时、每用户每日调用上限、日志保留天数、卸载时是否清除数据。
- **Prompt 模板**：新增、查看和删除自定义写作指令。
- **使用记录**：按功能/状态筛选调用日志，支持导出 CSV 和清空记录。

## 使用

打开任意文章编辑页：

- **Gutenberg**：编辑器右上角工具栏会显示 AI 助手面板。
- **经典编辑器**：AI 助手面板显示在编辑区侧边栏。

选中文本后选择功能，结果会展示在面板中。正文类结果默认追加到正文末尾，不会覆盖原文；SEO 标题结果可直接填入文章标题。

## WP-CLI

```bash
# 测试 AI 服务商连接
wp aca test-connection

# 清理超过 30 天的使用日志
wp aca clear-logs --days=30
```

## 目录结构

```text
.
├── ai-f-assistant.php          # 插件入口与元信息
├── uninstall.php               # 卸载逻辑
├── readme.txt                  # WordPress 插件目录格式说明
├── includes/
│   ├── class-aca-ai-client.php # Chat Completions 调用与错误处理
│   ├── class-aca-ajax.php      # 编辑器 AJAX 接口
│   ├── class-aca-cli.php       # WP-CLI 命令
│   ├── class-aca-crypto.php    # API 密钥加解密
│   ├── class-aca-editor.php    # 编辑器集成
│   ├── class-aca-logger.php    # 使用日志与统计
│   ├── class-aca-prompts.php   # 内置与自定义 Prompt
│   ├── class-aca-settings.php  # 设置页与保存逻辑
│   └── views/settings-page.php # 设置页视图
├── assets/
│   ├── css/                    # 后台与编辑器样式
│   └── js/                     # 区块/经典编辑器与管理页脚本
└── LICENSE                     # GPL-2.0
```

## 开发与打包

要求本地具备 PHP 7.4+ 与 `zip` 命令。

```bash
# PHP 语法检查
for f in ai-f-assistant.php uninstall.php includes/*.php includes/views/*.php; do php -l "$f"; done

# 构建可安装的插件包
mkdir -p /tmp/ai-f-assistant
cp -r ai-f-assistant.php uninstall.php readme.txt includes assets LICENSE /tmp/ai-f-assistant/
cd /tmp && zip -r ai-f-assistant-1.0.3.zip ai-f-assistant
```

## 常见问题

**支持哪些 AI 服务商？**

任何暴露标准 `/v1/chat/completions` 接口、遵循 OpenAI 请求/响应格式的服务商都可以使用，包括 OpenAI、DeepSeek、Moonshot、通义千问兼容模式以及各类中转网关。

**API 密钥安全吗？**

密钥使用 AES-256-CBC 加密，密钥派生自站点 `wp-config.php` 中的安全 salt。仅在发起请求时于服务器内存中临时解密，不会向前端展示明文。

**测试连接提示接口没有返回合法 JSON？**

通常是 Base URL 填写错误（例如漏掉 `/v1`，或直接填了网站首页），请确认地址后重试。需确保服务器可以访问外部 API。

**如何限制普通作者的调用次数？**

在"高级选项"中设置"每用户每日调用上限"，`0` 表示不限制。

## 更新日志

完整版本记录见 [readme.txt](readme.txt)。当前 `1.0.3` 主要更新包括：

- 新增"要点提炼"功能
- Prompt 模板支持 `{TITLE}` 占位符
- 结果面板展示 Token 消耗
- 常用模型快捷填充
- 使用记录筛选、分页与 CSV 导出
- 修复经典编辑器面板中的存储型 XSS
- 修复 Gutenberg 应用正文时覆盖原文的问题

## 许可证

本项目基于 [GNU General Public License v2.0](LICENSE) 许可，附加条款为 GPL-2.0-or-later。

## 链接

- 插件主页：<https://www.fohok.xin/product/ai-f-assistant>
- 问题反馈：<https://github.com/XINYUCloud/ai-f-assistant.1.0.3/issues>
