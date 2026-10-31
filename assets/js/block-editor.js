/**
 * AI 助手侧边栏，塞进 Gutenberg 里。
 * 用的都是老三样：wp-element / wp-components / wp-data，没上构建工具，图个直接改直接生效。
 *
 * 主要改动（相对 1.0.2）：
 * - 应用正文类结果时不再整篇覆盖 content，改为把结果作为新段落块追加到文末，杜绝误删原文
 * - 展示每次调用的 token 消耗与结果字数
 * - 处理中禁用"换一个"，给出明确加载提示
 * - 加入 {TITLE} 占位符说明
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || typeof ACA_DATA === 'undefined' ) {
		return;
	}

	var registerPlugin = wp.plugins.registerPlugin;
	var PluginSidebar = wp.editPost.PluginSidebar;
	var PluginSidebarMoreMenuItem = wp.editPost.PluginSidebarMoreMenuItem;
	var h = wp.element.createElement;
	var useState = wp.element.useState;
	var Fragment = wp.element.Fragment;

	var PanelBody = wp.components.PanelBody;
	var Button = wp.components.Button;
	var SelectControl = wp.components.SelectControl;
	var TextareaControl = wp.components.TextareaControl;
	var TextControl = wp.components.TextControl;
	var Notice = wp.components.Notice;
	var Spinner = wp.components.Spinner;
	var ButtonGroup = wp.components.ButtonGroup;

	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var useRef = wp.element.useRef;
	var createBlock = wp.blocks.createBlock;

	// 每个功能各有各的排查提示，塞进错误信息里，别让用户干瞪眼
	var ERROR_HINTS = {
		aca_network_error: '接口地址是不是填错了，或者服务器出不了网。',
		aca_invalid_json: '对方没返回 JSON，接口地址大概率不对（漏了 /v1 之类的）。',
		aca_api_error: '密钥、余额或者模型名称，大概率是这三样出问题。',
		aca_empty_response: '内容可能被拦了，换个说法再试一次。',
		quota_exceeded: ''
	};

	function grabSelectedText() {
		var sel = window.getSelection ? window.getSelection().toString() : '';
		return sel ? sel.trim() : '';
	}

	function stripHtml( html ) {
		var tmp = document.createElement( 'div' );
		tmp.innerHTML = html;
		return tmp.textContent || tmp.innerText || '';
	}

	// "正文类"功能（会改动正文）；摘要/标题走各自专门的入口，不碰正文
	function isBodyResult( feature ) {
		return feature && 'summary' !== feature && 'seo_title' !== feature;
	}

	function AcaPanel() {
		var featureState = useState( '' );
		var feature = featureState[0], setFeature = featureState[1];

		var langState = useState( 'English' );
		var lang = langState[0], setLang = langState[1];

		var loadingState = useState( false );
		var loading = loadingState[0], setLoading = loadingState[1];

		var errorState = useState( '' );
		var error = errorState[0], setError = errorState[1];

		var resultState = useState( '' );
		var resultText = resultState[0], setResultText = resultState[1];

		var metaState = useState( null );
		var meta = metaState[0], setMeta = metaState[1];

		var scopeState = useState( 'selection' );
		var scope = scopeState[0], setScope = scopeState[1];

		// "换一个"要用跟上一次一模一样的输入内容重新生成，不能等点了按钮再现抓选区
		var lastRequestRef = useRef( null );

		var editorDispatch = useDispatch( 'core/editor' );
		var editPost = editorDispatch.editPost;
		var blockEditorDispatch = useDispatch( 'core/block-editor' );
		var insertBlocks = blockEditorDispatch.insertBlocks;

		var postContent = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostContent();
		}, [] );

		var postId = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostId();
		}, [] );

		var allFeatures = ACA_DATA.features.concat( ACA_DATA.customPrompts );
		var featureOptions = [ { label: ACA_DATA.i18n.selectFeature, value: '' } ];
		allFeatures.forEach( function ( f ) {
			featureOptions.push( { label: f.label, value: f.key } );
		} );

		function runIt( reuse ) {
			setError( '' );
			setMeta( null );

			var runFeature = feature;
			var runContent;
			var runLang = lang;

			if ( reuse && lastRequestRef.current ) {
				runFeature = lastRequestRef.current.feature;
				runContent = lastRequestRef.current.content;
				runLang = lastRequestRef.current.lang;
			} else {
				if ( ! runFeature ) {
					setError( ACA_DATA.i18n.selectFeature );
					return;
				}
				runContent = 'selection' === scope ? grabSelectedText() : '';
				if ( ! runContent ) {
					runContent = stripHtml( postContent );
				}
				if ( ! runContent || ! runContent.trim() ) {
					setError( ACA_DATA.i18n.noSelection );
					return;
				}
			}

			lastRequestRef.current = { feature: runFeature, content: runContent, lang: runLang };
			setLoading( true );

			var form = new FormData();
			form.append( 'action', 'aca_process_text' );
			form.append( 'nonce', ACA_DATA.nonce );
			form.append( 'feature', runFeature );
			form.append( 'content', runContent );
			form.append( 'post_id', postId );
			if ( 'translate' === runFeature ) {
				form.append( 'lang', runLang );
			}

			fetch( ACA_DATA.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: form } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( json ) {
					setLoading( false );

					if ( json && json.success ) {
						setResultText( json.data.result );
						if ( json.data.meta ) {
							setMeta( json.data.meta );
						}
						return;
					}

					var msg = ( json && json.data && json.data.message ) || ACA_DATA.i18n.error;
					var code = json && json.data && json.data.code;
					var hint = code && ERROR_HINTS[ code ] ? '（' + ERROR_HINTS[ code ] + '）' : '';
					setError( msg + hint );
				} )
				.catch( function ( err ) {
					setLoading( false );
					setError( ACA_DATA.i18n.error + ' ' + err.message );
				} );
		}

		function applyIt() {
			if ( ! resultText ) {
				return;
			}

			var appliedFeature = lastRequestRef.current ? lastRequestRef.current.feature : feature;

			if ( 'summary' === appliedFeature ) {
				editPost( { excerpt: resultText } );
			} else if ( 'seo_title' === appliedFeature ) {
				editPost( { title: resultText } );
			} else {
				// 关键修复：不整体覆盖 content，而是把结果作为新段落块追加到文末，
				// 即使中途出状况也不会弄丢用户原有的正文。
				var block = createBlock( 'core/paragraph', { content: resultText } );
				insertBlocks( [ block ] );
			}
			setResultText( '' );
			setMeta( null );
		}

		function copyIt() {
			if ( resultText && navigator.clipboard ) {
				navigator.clipboard.writeText( resultText );
			}
		}

		if ( ! ACA_DATA.configured ) {
			return h( PanelBody, { title: ACA_DATA.i18n.panelTitle },
				h( Notice, { status: 'warning', isDismissible: false }, ACA_DATA.i18n.notConfigured ),
				h( 'p', { style: { marginTop: '8px' } },
					h( 'a', { href: ACA_DATA.settingsUrl, target: '_blank', rel: 'noopener noreferrer' }, ACA_DATA.i18n.goSettings )
				)
			);
		}

		var displayFeature = ( resultText && lastRequestRef.current ) ? lastRequestRef.current.feature : feature;

		var applyLabel = ACA_DATA.i18n.applyResult;
		if ( 'summary' === displayFeature ) {
			applyLabel = ACA_DATA.i18n.applyToExcerpt;
		} else if ( 'seo_title' === displayFeature ) {
			applyLabel = ACA_DATA.i18n.applyToTitle;
		}

		return h( PanelBody, { title: ACA_DATA.i18n.panelTitle, initialOpen: true },
			h( SelectControl, {
				label: ACA_DATA.i18n.selectFeature,
				value: feature,
				options: featureOptions,
				onChange: setFeature
			} ),

			'translate' === feature ? h( TextControl, {
				label: ACA_DATA.i18n.translateTo,
				value: lang,
				onChange: setLang,
				placeholder: 'English / 日本語 / Français ...'
			} ) : null,

			h( ButtonGroup, { className: 'aca-scope-group' },
				h( Button, {
					isPrimary: 'selection' === scope,
					isSecondary: 'selection' !== scope,
					onClick: function () { setScope( 'selection' ); }
				}, ACA_DATA.i18n.runOnSelection ),
				h( Button, {
					isPrimary: 'full' === scope,
					isSecondary: 'full' !== scope,
					onClick: function () { setScope( 'full' ); }
				}, ACA_DATA.i18n.runOnFull )
			),

			'summary' === feature || 'seo_title' === feature ? h( 'p', { className: 'aca-scope-hint' },
				ACA_DATA.i18n.scopeBodyNotice
			) : null,

			h( Button, {
				isPrimary: true,
				onClick: function () { runIt( false ); },
				disabled: loading,
				style: { marginTop: '10px', width: '100%', justifyContent: 'center' }
			}, loading ? h( Spinner, null ) : ACA_DATA.i18n.panelTitle ),

			loading ? h( 'p', { className: 'aca-processing-hint' }, ACA_DATA.i18n.processing ) : null,

			error ? h( Notice, {
				status: 'error',
				isDismissible: true,
				onRemove: function () { setError( '' ); }
			}, error ) : null,

			resultText ? h( 'div', { className: 'aca-result-box' },
				'summary' === displayFeature ? h( Notice, { status: 'info', isDismissible: false }, ACA_DATA.i18n.summaryHint ) : null,
				'seo_title' === displayFeature ? h( Notice, { status: 'info', isDismissible: false }, ACA_DATA.i18n.seoTitleHint ) : null,
				'summary' !== displayFeature && 'seo_title' !== displayFeature ? h( Notice, { status: 'info', isDismissible: false }, ACA_DATA.i18n.bodyApplyNotice ) : null,
				h( TextareaControl, { value: resultText, onChange: setResultText, rows: 'seo_title' === displayFeature ? 2 : 8 } ),
				meta && meta.total_tokens ? h( 'p', { className: 'aca-result-meta' },
					ACA_DATA.i18n.usedTokens.replace( '{tokens}', String( meta.total_tokens ) )
				) : null,
				h( 'div', { className: 'aca-result-actions' },
					h( Button, { isPrimary: true, onClick: applyIt }, applyLabel ),
					h( Button, { isSecondary: true, onClick: function () { runIt( true ); }, disabled: loading }, ACA_DATA.i18n.regenerate ),
					h( Button, { isSecondary: true, onClick: copyIt }, ACA_DATA.i18n.copyResult ),
					h( Button, { isTertiary: true, onClick: function () { setResultText( '' ); setMeta( null ); } }, ACA_DATA.i18n.discard )
				)
			) : null
		);
	}

	registerPlugin( 'aca-assistant', {
		icon: 'edit',
		render: function () {
			return h( Fragment, null,
				h( PluginSidebarMoreMenuItem, { target: 'aca-assistant-sidebar', icon: 'edit' }, ACA_DATA.i18n.panelTitle ),
				h( PluginSidebar, { name: 'aca-assistant-sidebar', title: ACA_DATA.i18n.panelTitle, icon: 'edit' },
					h( AcaPanel, null )
				)
			);
		}
	} );

} )( window.wp );