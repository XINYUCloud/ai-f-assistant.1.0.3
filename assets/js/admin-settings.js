/**
 * 设置页那点交互：tab 切换、测试连接。
 * 不搞打包工具了，原生 jQuery 够用。
 */
( function ( $ ) {
	'use strict';

	// 只有这三个 tab 里的内容才是"设置表单"的一部分，Prompt模板/使用记录
	// 是各自独立的表单/展示，跟主表单的"保存设置"按钮没关系，切过去就得把按钮藏起来，
	// 不然点了没反应
	var SETTINGS_TABS = [ 'provider', 'features', 'advanced' ];
	var ACA_TAB_KEY = 'aca_settings_tab';

	function activateTab( tab, remember ) {
		var $nav = $( '.nav-tab-wrapper .nav-tab[data-tab="' + tab + '"]' );
		if ( ! $nav.length ) {
			return;
		}
		$nav.trigger( 'click' );
		if ( remember ) {
			try {
				window.localStorage.setItem( ACA_TAB_KEY, tab );
			} catch ( e ) { /* ignore */ }
		}
	}

	$( function () {

		$( '.nav-tab-wrapper .nav-tab' ).on( 'click', function ( e ) {
			e.preventDefault();
			var tab = $( this ).data( 'tab' );

			$( '.nav-tab-wrapper .nav-tab' ).removeClass( 'nav-tab-active' );
			$( this ).addClass( 'nav-tab-active' );

			$( '.aca-tab-panel' ).hide();
			$( '#aca-tab-' + tab ).show();

			var $submitRow = $( '#aca-settings-form .submit' );
			if ( SETTINGS_TABS.indexOf( tab ) !== -1 ) {
				$submitRow.show();
			} else {
				$submitRow.hide();
			}

			try {
				window.localStorage.setItem( ACA_TAB_KEY, tab );
			} catch ( err ) { /* ignore */ }
		} );

		// 让"Prompt 模板 / 使用记录"tab 里的哈希链接也能触发 tab 切换
		// （它们用的是 #aca-tab-xxx 形式的 href）
		function syncHashToTab() {
			var hash = window.location.hash;
			var tab = hash.replace( '#aca-tab-', '' );
			if ( tab && SETTINGS_TABS.indexOf( tab ) !== -1 ) {
				activateTab( tab, false );
			}
		}
		$( window ).on( 'hashchange', syncHashToTab );

		// 恢复上次离开时的 tab（兼容从设置页子页面跳转回来的场景）
		try {
			var lastTab = window.localStorage.getItem( ACA_TAB_KEY );
			if ( lastTab ) {
				activateTab( lastTab, false );
			}
		} catch ( err ) { /* ignore */ }

		// 模型快捷填充
		$( '.aca-model-preset' ).on( 'click', function () {
			$( '#model' ).val( $( this ).data( 'model' ) ).trigger( 'change' );
		} );

		// 不同错误码给点不一样的排查提示，省得每次都要来问客服
		var hintsByCode = {
			aca_network_error: '通常是地址填错或者服务器出网受限，BaoTa 面板可以看下出站规则。',
			aca_invalid_json: '接口地址大概率漏了 /v1 或者填成了首页地址，对照服务商文档核对一下。',
			aca_api_error: '一般是密钥错误、余额不足，或者模型名称对方不支持。',
			aca_empty_response: '内容可能被服务商的审核策略拦了，换个说法或换个模型试试。'
		};

		function showModal( title, message, isError ) {
			var $overlay = $( '#aca-test-modal-overlay' );
			$( '#aca-test-modal-title' ).text( title ).toggleClass( 'aca-modal-title-error', !! isError );
			$( '#aca-test-modal-message' ).text( message );
			$overlay.show();
		}

		$( '#aca-test-modal-close' ).on( 'click', function () {
			$( '#aca-test-modal-overlay' ).hide();
		} );
		// 点遮罩本身（不是点里面的框）也能关
		$( '#aca-test-modal-overlay' ).on( 'click', function ( e ) {
			if ( e.target === this ) {
				$( this ).hide();
			}
		} );

		$( '#aca-test-connection' ).on( 'click', function () {
			if ( typeof ACA_ADMIN === 'undefined' ) {
				return;
			}
			var $btn = $( this ).prop( 'disabled', true ).text( ACA_ADMIN.i18n.testing );

			// 直接拿表单里当前填的值去测，不用先点"保存设置"——
			// 这三个字段就在同一个 tab 里，读 DOM 比较省事
			$.post( ACA_ADMIN.ajaxUrl, {
				action: 'aca_test_connection',
				aca_nonce: ACA_ADMIN.testNonce,
				api_base_url: $( '#api_base_url' ).val(),
				api_key: $( '#api_key' ).val(),
				model: $( '#model' ).val()
			} ).done( function ( resp ) {
				$btn.prop( 'disabled', false ).text( ACA_ADMIN.i18n.buttonLabel );

				if ( resp && resp.success ) {
					showModal( ACA_ADMIN.i18n.successTitle, resp.data.reply, false );
					return;
				}

				var msg = ( resp && resp.data && resp.data.message ) || '未知错误';
				var code = resp && resp.data && resp.data.code;
				var hint = code && hintsByCode[ code ] ? '\n\n' + hintsByCode[ code ] : '';

				showModal( ACA_ADMIN.i18n.failTitle, msg + hint, true );
			} ).fail( function ( xhr ) {
				$btn.prop( 'disabled', false ).text( ACA_ADMIN.i18n.buttonLabel );
				showModal( ACA_ADMIN.i18n.failTitle, 'HTTP ' + xhr.status + '，WordPress 层面请求就没成功，检查下 admin-ajax 是否被 CDN/WAF 拦截。', true );
			} );
		} );

	} );
} )( jQuery );
