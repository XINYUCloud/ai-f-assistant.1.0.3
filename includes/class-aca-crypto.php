<?php
/**
 * 密钥这块用 AES-256-CBC，密钥从 wp-config.php 的 AUTH_KEY / AUTH_SALT 派生。
 *
 * 顺带记一句：一开始想用 SHA256，后来发现 SHA256 是单向哈希，加密完就没法解出来了，
 * 而调用 AI 接口时又必须把明文密钥传回去，所以只能用对称加密。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACA_Crypto {

	const CIPHER = 'aes-256-cbc';

	private static function get_key() {
		$secret = '';

		if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
			$secret .= AUTH_KEY;
		}
		if ( defined( 'AUTH_SALT' ) && AUTH_SALT ) {
			$secret .= AUTH_SALT;
		}
		if ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY ) {
			$secret .= SECURE_AUTH_KEY;
		}

		if ( '' === $secret ) {
			// 正常生产环境不会走到这里，wp-config.php 里应该都配好了 salts
			$secret = 'aca-fallback-' . site_url() . '-' . DB_NAME;
		}

		return hash( 'sha256', $secret, true );
	}

	public static function encrypt( $plaintext ) {
		if ( '' === $plaintext || null === $plaintext ) {
			return '';
		}

		$key    = self::get_key();
		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		$iv     = openssl_random_pseudo_bytes( $iv_len );

		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $ciphertext ) {
			return false;
		}

		return base64_encode( $iv . $ciphertext );
	}

	public static function decrypt( $encoded ) {
		if ( '' === $encoded || null === $encoded ) {
			return '';
		}

		$raw = base64_decode( $encoded, true );
		if ( false === $raw ) {
			return false;
		}

		$key    = self::get_key();
		$iv_len = openssl_cipher_iv_length( self::CIPHER );

		if ( strlen( $raw ) <= $iv_len ) {
			return false;
		}

		$iv         = substr( $raw, 0, $iv_len );
		$ciphertext = substr( $raw, $iv_len );

		return openssl_decrypt( $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
	}

	// 设置页展示用，别把整个密钥糊用户脸上
	public static function mask( $key ) {
		$len = strlen( $key );
		if ( $len <= 8 ) {
			return str_repeat( '*', $len );
		}
		return substr( $key, 0, 4 ) . str_repeat( '*', max( 4, $len - 8 ) ) . substr( $key, -4 );
	}
}
