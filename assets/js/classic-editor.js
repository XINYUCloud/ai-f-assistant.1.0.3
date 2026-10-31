/**
 * 经典编辑器（TinyMCE）那边的面板，塞进 meta box 里。
 *
 * 主要改动（相对 1.0.2）：
 * - 修复 XSS：自定义 Prompt 名称、后端错误信息改用 text 写入，不再直接拼 HTML
 * - 修正正文类功能的"应用"行为：
 *     · 有选中文本 → 结果替换选中部分，不碰其余内容
 *     · 无选中 → 续写/要点等"增量类"功能把结果追加到正文末尾；
 *       润色/扩写/缩写等"整篇处理"功能才替换全文（用户选了"处理全文"时）
 * - 展示每次调用的 token 消耗
 */
( function ( $ ) {
	'use strict';

	$( function () {
		if ( typeof ACA_DATA === 'undefined' ) {
			return;
		}

		var $root = $( '#aca-classic-panel-root' );
		if ( ! $root.length ) {
			return;
		}

		if ( ! ACA_DATA.configured ) {
			var $warn = $( '<div class="notice notice-warning inline"><p></p></div>' );
			$warn.find( 'p' ).text( ACA_DATA.i18n.notConfigured );
			$root.append( $warn );
			return;
		}

		var allFeatures = ( ACA_DATA.features || [] ).concat( ACA_DATA.customPrompts || [] );

		var frag = document.createDocumentFragment();
		var panel = document.createElement( 'div' );
		panel.className = 'aca-classic-panel';

		panel.innerHTML =
			'<p><select id="aca-feature-select" class="widefat"></select></p>' +
			'<p id="aca-lang-wrap" style="display:none;">' +
				'<label>' + ACA_DATA.i18n.translateTo + '</label>' +
				'<input type="text" id="aca-lang-input" class="widefat" value="English" />' +
			'</p>' +
			'<p class="aca-scope-row">' +
				'<label><input type="radio" name="aca_scope" value="selection" checked /> <span data-key="runOnSelection"></span></label><br/>' +
				'<label><input type="radio" name="aca_scope" value="full" /> <span data-key="runOnFull"></span></label>' +
			'</p>' +
			'<p><button type="button" class="button button-primary widefat" id="aca-run-btn"></button></p>' +
			'<div id="aca-status-area"></div>' +
			'<div id="aca-result-area" style="display:none;">' +
				'<div id="aca-summary-hint" style="display:none;" class="notice notice-info inline"><p></p></div>' +
				'<div id="aca-seo-hint" style="display:none;" class="notice notice-info inline"><p></p></div>' +
				'<div id="aca-body-hint" style="display:none;" class="notice notice-info inline"><p></p></div>' +
				'<textarea id="aca-result-textarea" class="widefat" rows="10"></textarea>' +
				'<p class="aca-result-meta-wrap"><span id="aca-result-meta" style="display:none;"></span></p>' +
				'<p>' +
					'<button type="button" class="button button-primary" id="aca-apply-btn"></button> ' +
					'<button type="button" class="button" id="aca-regenerate-btn"></button> ' +
					'<button type="button" class="button" id="aca-copy-btn"></button> ' +
					'<button type="button" class="button-link" id="aca-discard-btn"></button>' +
				'</p>' +
			'</div>';

		// 用 text 填充用户可控文案，杜绝把自定义 Prompt 名/后端信息拼进 HTML 的 XSS 隐患
		var $panel = $( panel );
		$root.append( panel );

		var $featureSelect = $panel.find( '#aca-feature-select' );
		$panel.find( '[data-key=runOnSelection]' ).text( ACA_DATA.i18n.runOnSelection );
		$panel.find( '[data-key=runOnFull]' ).text( ACA_DATA.i18n.runOnFull );
		$panel.find( '#aca-run-btn' ).text( ACA_DATA.i18n.panelTitle );
		$panel.find( '#aca-summary-hint p' ).text( ACA_DATA.i18n.summaryHint );
		$panel.find( '#aca-seo-hint p' ).text( ACA_DATA.i18n.seoTitleHint );
		$panel.find( '#aca-body-hint p' ).text( ACA_DATA.i18n.bodyApplyNotice );

		// 下拉选项用 DOM 方式构造并 text() 赋值，避免名称里带 HTML 时被解析执行
		$featureSelect.append( $( '<option value=""></option>' ).text( ACA_DATA.i18n.selectFeature ) );
		allFeatures.forEach( function ( f ) {
			var $opt = $( '<option></option>' ).attr( 'value', f.key );
			if ( f.needsLang ) {
				$opt.attr( 'data-needslang', '1' );
			}
			$opt.text( f.label );
			$featureSelect.append( $opt );
		} );

		var ERROR_HINTS = {
			aca_network_error: '接口地址是不是填错了，或者服务器出不了网。',
			aca_invalid_json: '对方没返回 JSON，接口地址大概率不对。',
			aca_api_error: '密钥、余额或者模型名称，大概率是这三样出问题。',
			aca_empty_response: '内容可能被拦了，换个说法再试一次。'
		};

		var lastRequest = null;

		$featureSelect.on( 'change', function () {
			var $sel = $( this ).find( ':selected' );
			var needsLang = '1' === String( $sel.data( 'needslang' ) );
			$panel.find( '#aca-lang-wrap' ).toggle( needsLang );
		} );

		function currentFeature() {
			return $featureSelect.val();
		}

		function getSelectedOrFull() {
			if ( window.tinymce && tinymce.get( 'content' ) && ! tinymce.get( 'content' ).isHidden() ) {
				var editor = tinymce.get( 'content' );
				var selected = editor.selection.getContent( { format: 'text' } );
				if ( selected && selected.trim() ) {
					return { text: selected.trim(), hasSelection: true };
				}
				return { text: editor.getContent( { format: 'text' } ), hasSelection: false, editor: editor };
			}
			var $textarea = $( '#content' );
			if ( ! $textarea.length ) {
				return { text: '' };
			}
			var start = $textarea[0].selectionStart;
			var end = $textarea[0].selectionEnd;
			var textareaMode = start !== end;
			var out = {
				text: textareaMode ? $textarea.val().substring( start, end ).trim() : $textarea.val(),
				hasSelection: textareaMode
			};
			if ( textareaMode ) {
				out.start = start;
				out.end = end;
			}
			return out;
		}

		function setStatus( message, type ) {
			var $area = $panel.find( '#aca-status-area' );
			$area.empty();
			if ( message ) {
				var cls = 'error' === type ? 'notice-error' : 'notice-info';
				var $n = $( '<div class="notice ' + cls + ' inline"><p></p></div>' );
				$n.find( 'p' ).text( message );
				$area.append( $n );
			}
		}

		function toggleFeatureHints( feature ) {
			$panel.find( '#aca-summary-hint' ).toggle( 'summary' === feature );
			$panel.find( '#aca-seo-hint' ).toggle( 'seo_title' === feature );
			$panel.find( '#aca-body-hint' ).toggle( 'summary' !== feature && 'seo_title' !== feature );

			var applyLabel = ACA_DATA.i18n.applyResult;
			if ( 'summary' === feature ) {
				applyLabel = ACA_DATA.i18n.applyToExcerpt;
			} else if ( 'seo_title' === feature ) {
				applyLabel = ACA_DATA.i18n.applyToTitle;
			}
			$panel.find( '#aca-apply-btn' ).text( applyLabel );
		}

		function dispatchRequest( params ) {
			var feature = params ? params.feature : currentFeature();
			if ( ! feature ) {
				setStatus( ACA_DATA.i18n.selectFeature, 'error' );
				return;
			}

			var content = params ? params.content : null;
			var lang = params ? params.lang : ( $panel.find( '#aca-lang-input' ).val() || 'English' );

			if ( null === content ) {
				var scope = $( 'input[name="aca_scope"]:checked' ).val();
				if ( 'selection' === scope ) {
					content = getSelectedOrFull().text;
				} else if ( window.tinymce && tinymce.get( 'content' ) && ! tinymce.get( 'content' ).isHidden() ) {
					content = tinymce.get( 'content' ).getContent( { format: 'text' } );
				} else {
					content = $( '#content' ).val();
				}
			}

			if ( ! content || ! content.trim() ) {
				setStatus( ACA_DATA.i18n.noSelection, 'error' );
				return;
			}

			lastRequest = { feature: feature, content: content, lang: lang };

			var $runBtn = $panel.find( '#aca-run-btn' ).prop( 'disabled', true );
			var $regenBtn = $panel.find( '#aca-regenerate-btn' ).prop( 'disabled', true );
			$panel.find( '#aca-result-meta' ).hide();
			setStatus( ACA_DATA.i18n.processing, 'info' );

			$.post( ACA_DATA.ajaxUrl, {
				action: 'aca_process_text',
				nonce: ACA_DATA.nonce,
				feature: feature,
				content: content,
				post_id: $( '#post_ID' ).val() || 0,
				lang: lang
			} ).done( function ( resp ) {
				$runBtn.prop( 'disabled', false );
				$regenBtn.prop( 'disabled', false );

				if ( resp && resp.success ) {
					setStatus( '', '' );
					$panel.find( '#aca-result-textarea' ).val( resp.data.result );
					var meta = resp.data.meta;
					var $meta = $panel.find( '#aca-result-meta' );
					if ( meta && meta.total_tokens ) {
						$meta.text( ACA_DATA.i18n.usedTokens.replace( '{tokens}', String( meta.total_tokens ) ) ).show();
					} else {
						$meta.hide();
					}
					$panel.find( '#aca-result-area' ).show();
					toggleFeatureHints( feature );
				} else {
					var msg = ( resp && resp.data && resp.data.message ) || ACA_DATA.i18n.error;
					var code = resp && resp.data && resp.data.code;
					var hint = code && ERROR_HINTS[ code ] ? '（' + ERROR_HINTS[ code ] + '）' : '';
					setStatus( msg + hint, 'error' );
				}
			} ).fail( function ( xhr ) {
				$runBtn.prop( 'disabled', false );
				$regenBtn.prop( 'disabled', false );
				setStatus( ACA_DATA.i18n.error + ' HTTP ' + xhr.status, 'error' );
			} );
		}

		// 经典编辑器正文类功能的应用：
		// - 有选中文本 → 结果替换选中部分
		// - 无选中 → 续写/要点等"增量类"功能把结果追加到正文末尾（避免覆盖丢失原文），
		//   其余整篇处理类功能才用结果替换全文
		function applyBodyResult( result, feature ) {
			var isIncremental = ( 'continue' === feature || 'bullet' === feature );
			if ( window.tinymce && tinymce.get( 'content' ) && ! tinymce.get( 'content' ).isHidden() ) {
				var editor = tinymce.get( 'content' );
				var html = result.replace( /\n/g, '<br>' );
				if ( editor.selection.getContent() ) {
					editor.selection.setContent( html );
				} else if ( isIncremental ) {
					editor.setContent( editor.getContent() + html );
				} else {
					editor.setContent( html );
				}
				return;
			}
			var $textarea = $( '#content' );
			if ( ! $textarea.length ) {
				return;
			}
			var val = $textarea.val();
			var start = $textarea[0].selectionStart;
			var end = $textarea[0].selectionEnd;
			if ( start !== end ) {
				var before = val.substring( 0, start );
				var after = val.substring( end );
				$textarea.val( before + result + after );
			} else if ( isIncremental ) {
				$textarea.val( val + ( val && ! /[\s]$/.test( val ) ? '\n\n' : '' ) + result );
			} else {
				$textarea.val( result );
			}
		}

		$panel.find( '#aca-run-btn' ).on( 'click', function () {
			dispatchRequest( null );
		} );

		$panel.find( '#aca-regenerate-btn' ).on( 'click', function () {
			if ( ! lastRequest ) {
				return;
			}
			dispatchRequest( lastRequest );
		} );

		$panel.find( '#aca-apply-btn' ).on( 'click', function () {
			var result = $panel.find( '#aca-result-textarea' ).val();
			if ( ! result ) {
				return;
			}

			var feature = lastRequest ? lastRequest.feature : currentFeature();

			if ( 'summary' === feature ) {
				var $excerpt = $( '#excerpt' );
				if ( $excerpt.length ) {
					$excerpt.val( result );
				} else {
					window.alert( ACA_DATA.i18n.noExcerptBox );
					return;
				}
			} else if ( 'seo_title' === feature ) {
				$( '#title' ).val( result ).trigger( 'input' ).trigger( 'change' );
			} else {
				applyBodyResult( result, feature );
			}

			$panel.find( '#aca-result-area' ).hide();
			$panel.find( '#aca-result-textarea' ).val( '' );
			$panel.find( '#aca-result-meta' ).hide();
		} );

		$panel.find( '#aca-copy-btn' ).on( 'click', function () {
			var result = $panel.find( '#aca-result-textarea' ).val();
			if ( result && navigator.clipboard ) {
				navigator.clipboard.writeText( result );
			}
		} );

		$panel.find( '#aca-discard-btn' ).on( 'click', function () {
			$panel.find( '#aca-result-area' ).hide();
			$panel.find( '#aca-result-textarea' ).val( '' );
			$panel.find( '#aca-result-meta' ).hide();
		} );
	} );
} )( jQuery );