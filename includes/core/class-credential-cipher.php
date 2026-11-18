<?php
/**
 * Szyfrowanie poświadczeń w spoczynku. Skopiowane z IoT Data Widget
 * (includes/class-credential-cipher.php, commit b10c54c — patrz SHARED.md,
 * oznaczone SYNC-REQUIRED), bez zmian poza namespace/nazwą klasy i
 * kontekstem HMAC (osobny od IoT, żeby kompromitacja jednego nie dawała
 * wprost klucza do drugiego).
 *
 * @package Veloxa\Solar
 */

namespace Veloxa\Solar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Credential_Cipher
 */
class Credential_Cipher {

	/**
	 * Metoda szyfrowania OpenSSL — CBC z osobnym, losowym IV per wartość.
	 */
	const CIPHER_METHOD = 'aes-256-cbc';

	/**
	 * Prefiks oznaczający wartość już zaszyfrowaną tym mechanizmem.
	 */
	const PREFIX = 'enc:v1:';

	/**
	 * Szyfruje wartość. Pusty string zostaje pusty.
	 *
	 * @param string $plaintext Wartość jawna.
	 * @return string Zaszyfrowana wartość z prefiksem, albo pusty string.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		$iv        = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::CIPHER_METHOD ) );
		$encrypted = openssl_encrypt( $plaintext, self::CIPHER_METHOD, self::derive_key(), OPENSSL_RAW_DATA, $iv );

		if ( false === $encrypted ) {
			return $plaintext;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- kodowanie binarnego szyfrogramu do zapisu w opcji, nie zaciemnianie kodu.
		return self::PREFIX . base64_encode( $iv ) . ':' . base64_encode( $encrypted );
	}

	/**
	 * Deszyfruje wartość. Wartość BEZ prefiksu wraca bez zmian (przezroczysta
	 * migracja ze starego jawnego tekstu).
	 *
	 * @param string $value Wartość z bazy (zaszyfrowana albo jawna).
	 * @return string Wartość jawna.
	 */
	public static function decrypt( string $value ): string {
		if ( ! self::is_encrypted( $value ) ) {
			return $value;
		}

		$parts = explode( ':', substr( $value, strlen( self::PREFIX ) ), 2 );

		if ( 2 !== count( $parts ) ) {
			return '';
		}

		list( $iv_b64, $ciphertext_b64 ) = $parts;
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- dekodowanie szyfrogramu zapisanego przez encrypt(), nie zaciemnianie kodu.
		$iv         = base64_decode( $iv_b64, true );
		$ciphertext = base64_decode( $ciphertext_b64, true );
		// phpcs:enable

		if ( false === $iv || false === $ciphertext ) {
			return '';
		}

		$decrypted = openssl_decrypt( $ciphertext, self::CIPHER_METHOD, self::derive_key(), OPENSSL_RAW_DATA, $iv );

		return false !== $decrypted ? $decrypted : '';
	}

	/**
	 * Czy wartość jest zaszyfrowana (ma prefiks PREFIX).
	 *
	 * @param string $value Wartość z bazy.
	 * @return bool
	 */
	public static function is_encrypted( string $value ): bool {
		return 0 === strpos( $value, self::PREFIX );
	}

	/**
	 * Klucz AES-256 (32 surowych bajtów) pochodny od soli WP.
	 *
	 * @return string 32 surowe bajty.
	 */
	private static function derive_key(): string {
		return hash_hmac( 'sha256', 'veloxa_solar_credential_cipher', wp_salt( 'auth' ), true );
	}
}
