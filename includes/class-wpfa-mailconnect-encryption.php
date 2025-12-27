<?php

/**
 * Encryption utility for sensitive data protection.
 *
 * Provides encryption and decryption methods for sensitive configuration data
 * using WordPress salts and OpenSSL for secure credential storage. The encryption
 * uses AES-256-GCM for authenticated encryption (confidentiality and integrity).
 *
 * @link       https://fossasia.org
 * @since      1.2.0
 * @package    Wpfa_Mailconnect
 * @subpackage Wpfa_Mailconnect/includes
 */

/**
 * Encryption utility class definition.
 *
 * This class uses AES-256-GCM with a non-standard component ordering:
 * 
 * Encrypted Format: wpfa_enc_[base64( IV | Tag | Ciphertext )]
 * 
 * IMPORTANT: Standard GCM implementations use IV | Ciphertext | Tag ordering.
 * Our custom ordering places the fixed-length authentication tag before the
 * variable-length ciphertext for easier parsing. This makes the implementation
 * incompatible with standard GCM tools and libraries.
 * 
 * Do NOT change the component ordering without implementing a migration path,
 * as it will break decryption of all existing encrypted passwords.
 *
 * @since      1.2.0
 * @package    Wpfa_Mailconnect
 * @subpackage Wpfa_Mailconnect/includes
 * @author     FOSSASIA <info@fossasia.org>
 */
class Wpfa_Mailconnect_Encryption {

	/**
	 * Encryption method to use (AES-256-GCM for authenticated encryption).
	 *
	 * Note: Must be lowercase for OpenSSL functions.
	 *
	 * @since 1.2.0 (Changed from AES-256-CBC)
	 * @since 1.2.0 (Changed to lowercase)
	 */
	const CIPHER_METHOD = 'aes-256-gcm';

	/**
	 * Length of the authentication tag in bytes (128 bits) for AES-GCM.
	 *
	 * @since 1.2.0
	 */
	const TAG_LENGTH = 16;

	/**
	 * Prefix to identify encrypted values.
	 *
	 * @since 1.2.0
	 */
	const ENCRYPTED_PREFIX = 'wpfa_enc_';

	/**
	 * Encrypts a string value using AES-256-GCM.
	 *
	 * Uses OpenSSL with AES-256-GCM authenticated encryption and WordPress salts for the key.
	 * The output is IV + Authentication Tag + Ciphertext, Base64 encoded.
	 *
	 * @since  1.2.0
	 * @param  string $value The plain text value to encrypt.
	 * @return string        The encrypted value with prefix, or original value if encryption fails.
	 */
	public static function encrypt( $value ) {
		// Return empty if value is empty
		if ( empty( $value ) ) {
			return $value;
		}

		// Don't re-encrypt already encrypted values
		if ( self::is_encrypted( $value ) ) {
			return $value;
		}

		// Check if OpenSSL is available
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			error_log( 'WPFA MailConnect: OpenSSL not available, storing value without encryption.' );
			return $value;
		}

		try {
			$key = self::get_encryption_key();
			
			// IV length for AES-256-GCM is typically 12 bytes (96 bits), but the actual length is determined by OpenSSL
			$iv_length = openssl_cipher_iv_length( self::CIPHER_METHOD );

			// Check for unsupported cipher or invalid length
			if ( ! is_int( $iv_length ) || $iv_length <= 0 ) {
				error_log( 'WPFA MailConnect: Cipher method "' . self::CIPHER_METHOD . '" is unsupported or IV length is invalid (' . (int) $iv_length . '). Storing value without encryption.' );
				return $value;
			}

			// Generate cryptographically secure IV
			$iv	 = self::get_secure_random_bytes( $iv_length );

			// Check if IV generation failed
			if ( false === $iv ) {
				error_log( 'WPFA MailConnect: Failed to generate cryptographically secure IV. Storing value without encryption.' );
				return $value;
			}

			$tag = ''; // Required variable for the GCM authentication tag

			$encrypted = openssl_encrypt(
				$value,
				self::CIPHER_METHOD,
				$key,
				OPENSSL_RAW_DATA, // Use raw data mode for GCM
				$iv,
				$tag, // Output parameter for the authentication tag
				'', // Additional authenticated data (none needed here)
				self::TAG_LENGTH // Length of the authentication tag (16 bytes)
			);

			if ( false === $encrypted ) {
				error_log( 'WPFA MailConnect: Encryption failed.' );
				return $value;
			}

			// IMPORTANT: Non-standard component ordering for easier parsing.
			// 
			// Standard GCM format:     IV | Ciphertext | Tag
			// This implementation uses: IV | Tag | Ciphertext
			//
			// Rationale: Fixed-length components (IV, Tag) come first, making
			// extraction simpler since tag length is constant (16 bytes). This
			// ordering is functionally equivalent but NOT compatible with standard
			// GCM tools. Do not change this ordering without a migration path, as
			// it will break decryption of all existing encrypted data.
			//
			// Structure:
			//   - IV:         First N bytes (typically 12 for AES-256-GCM)
			//   - Tag:        Next 16 bytes (authentication tag)
			//   - Ciphertext: Remaining bytes (variable length)
			$result = base64_encode( $iv . $tag . $encrypted );

			// Add prefix to identify encrypted values
			return self::ENCRYPTED_PREFIX . $result;

		} catch ( Exception $e ) {
			error_log( 'WPFA MailConnect Encryption Error: ' . $e->getMessage() );
			return $value;
		}
	}

	/**
	 * Decrypts an authenticated encrypted string value using AES-256-GCM.
	 *
	 * @since  1.2.0
	 * @param  string $value The encrypted value (with prefix).
	 * @return string        The decrypted plain text value, or original if not encrypted/decryption fails.
	 */
	public static function decrypt( $value ) {
		// Return empty if value is empty
		if ( empty( $value ) ) {
			return $value;
		}

		// If not encrypted, return as-is (backwards compatibility)
		if ( ! self::is_encrypted( $value ) ) {
			return $value;
		}

		// Store the original encrypted value to return on failure, preventing silent data loss.
		$original_value = $value;

		// Check if OpenSSL is available
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			error_log( 'WPFA MailConnect: OpenSSL not available for decryption. Returning original value.' );
			return $original_value;
		}

		try {
			// Remove prefix
			$payload = substr( $value, strlen( self::ENCRYPTED_PREFIX ) );

			// Decode from base64
			$decoded = base64_decode( $payload, true );

			if ( false === $decoded ) {
				error_log( 'WPFA MailConnect: Base64 decode failed. Returning original value.' );
				return $original_value;
			}

			$key       = self::get_encryption_key();
			$iv_length = openssl_cipher_iv_length( self::CIPHER_METHOD );
			$tag_length = self::TAG_LENGTH;

			// Check for unsupported cipher or invalid length
			if ( ! is_int( $iv_length ) || $iv_length <= 0 ) {
				error_log( 'WPFA MailConnect: Cipher method "' . self::CIPHER_METHOD . '" is unsupported or IV length is invalid during decryption. Returning original value.' );
				return $original_value;
			}

			// Check if the decoded payload is long enough (IV + Tag + at least one block of data)
			if ( strlen( $decoded ) < $iv_length + $tag_length ) {
				error_log( 'WPFA MailConnect: Decoded payload is too short. Returning original value.' );
				return $original_value;
			}

			// Extract components using our non-standard ordering (see encrypt() for details).
			// Order: IV | Tag | Ciphertext
			$iv			 = substr( $decoded, 0, $iv_length );
			$tag		 = substr( $decoded, $iv_length, $tag_length );
			$encrypted = substr( $decoded, $iv_length + $tag_length );

			$decrypted = openssl_decrypt(
				$encrypted,
				self::CIPHER_METHOD,
				$key,
				OPENSSL_RAW_DATA, // Use raw data mode for GCM
				$iv,
				$tag // Input parameter for the authentication tag
			);

			if ( false === $decrypted ) {
				// Decryption fails if the tag does not match the ciphertext (tampering detected)
				error_log( 'WPFA MailConnect: Decryption or authentication failed (possible tampering). Returning original value.' );
				return $original_value;
			}

			return $decrypted;

		} catch ( Exception $e ) {
			error_log( 'WPFA MailConnect Decryption Error: ' . $e->getMessage() . '. Returning original value.' );
			return $original_value;
		}
	}

	/**
	 * Checks if a value appears to be legacy CBC-encrypted.
	 *
	 * Legacy format: base64_iv:base64_ciphertext
	 *
	 * @since  				1.2.0
	 * @param  string $data The value to check.
	 * @return bool         True if appears to be legacy format.
	 */
	public static function is_legacy_encrypted( $data ) {
		if ( empty( $data ) || ! is_string( $data ) ) {
			return false;
		}
		
		// Must NOT be new format
		if ( self::is_encrypted( $data ) ) {
			return false;
		}
		
		// Must contain exactly one colon separator
		if ( substr_count( $data, ':' ) !== 1 ) {
			return false;
		}
		
		// Both parts must look like base64
		$parts = explode( ':', $data, 2 );
		if ( count( $parts ) !== 2 ) {
			return false;
		}
		
		// Validate base64 format: alphanumeric + / + = (padding)
		$base64_pattern = '/^[A-Za-z0-9+\/]*=*$/';
		$is_iv_base64        = (bool) preg_match( $base64_pattern, $parts[0] );
		$is_ciphertext_base64 = (bool) preg_match( $base64_pattern, $parts[1] );

		if ( ! $is_iv_base64 || ! $is_ciphertext_base64 ) {
			return false;
		}

		// Additionally ensure the parts are valid base64 by decoding strictly
		$iv_decoded        = base64_decode( $parts[0], true );
		$ciphertext_decoded = base64_decode( $parts[1], true );

		if ( false === $iv_decoded || false === $ciphertext_decoded ) {
			return false;
		}

		return true;
	}

	/**
	 * Decrypts data using the legacy AES-256-CBC method.
	 *
	 * Used as a fallback migration path for credentials encrypted 
	 * with versions prior to 1.2.0.
	 *
	 * @since  1.2.0
	 * @param  string $data The encrypted string.
	 * @return string The decrypted string, or the original string on failure.
	 */
	public static function decrypt_legacy( $data ) {
		// Use the new validation method
		if ( ! self::is_legacy_encrypted( $data ) ) {
			return $data;
		}
		
		$parts = explode( ':', $data, 2 );
		// explode() guaranteed to return 2 elements due to is_legacy_encrypted() check
		
		$iv            = base64_decode( $parts[0], true ); // strict mode
		$encrypted_raw = base64_decode( $parts[1], true );
		
		// Validate base64 decoding succeeded
		if ( false === $iv || false === $encrypted_raw ) {
			error_log( 'WPFA MailConnect: Legacy format base64 decode failed.' );
			return $data;
		}
		
		$key = self::get_encryption_key();

		$decrypted = openssl_decrypt( $encrypted_raw, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $decrypted ) {
			error_log( 'WPFA MailConnect: Legacy decryption failed.' );
			return $data;
		}
		
		return $decrypted;
	}

	/**
	 * Checks if a value is encrypted.
	 *
	 * @since  1.2.0
	 * @param  string $value The value to check.
	 * @return bool          True if encrypted, false otherwise.
	 */
	public static function is_encrypted( $value ) {
		return is_string( $value ) && strpos( $value, self::ENCRYPTED_PREFIX ) === 0;
	}

	/**
	 * Generates an encryption key based on a dedicated key and WordPress salts.
	 *
	 * Prefer a dedicated encryption key (WPFA_MAILCONNECT_ENC_KEY) if defined,
	 * and combine it with available WordPress salts/keys to create a unique,
	 * site-specific encryption key.
	 *
	 * @since  1.2.0
	 * @return string The encryption key.
	 */
	private static function get_encryption_key() {
		$entropy_parts = array();

		// Prefer a dedicated encryption key if available (e.g. defined in wp-config.php).
		if ( defined( 'WPFA_MAILCONNECT_ENC_KEY' ) && WPFA_MAILCONNECT_ENC_KEY ) {
			$entropy_parts[] = WPFA_MAILCONNECT_ENC_KEY;
		}

		// Add all available WordPress salts/keys as additional entropy.
		$wp_salts = array(
			'AUTH_KEY',
			'SECURE_AUTH_KEY',
			'LOGGED_IN_KEY',
			'NONCE_KEY',
			'AUTH_SALT',
			'SECURE_AUTH_SALT',
			'LOGGED_IN_SALT',
			'NONCE_SALT',
		);

		foreach ( $wp_salts as $salt_const ) {
			if ( defined( $salt_const ) ) {
				$entropy_parts[] = constant( $salt_const );
			}
		}

		// As a final fallback, ensure we never return an empty key material.
		if ( empty( $entropy_parts ) ) {
			$entropy_parts[] = 'wpfa_mailconnect_fallback_' . __FILE__;
		}

		$key_material = implode( '|', $entropy_parts );

		// Hash to ensure consistent key length for AES-256 (32 bytes).
		return hash( 'sha256', $key_material, true );
	}

	/**
	 * Generates cryptographically secure random bytes for IV/salt.
	 *
	 * Uses random_bytes() (PHP 7+) if available, falling back to openssl_random_pseudo_bytes().
	 *
	 * @since 			1.2.0
	 * @param int 		$length The number of random bytes to generate.
	 * @return string|false The random bytes, or false on failure.
	 */
	private static function get_secure_random_bytes( $length ) {
		if ( function_exists( 'random_bytes' ) ) {
			try {
				return random_bytes( $length );
			} catch ( Exception $e ) {
				error_log( 'WPFA MailConnect: random_bytes failed with exception: ' . $e->getMessage() );
				// Fall through to openssl if it fails
			}
		}

		if ( function_exists( 'openssl_random_pseudo_bytes' ) ) {
			$bytes = openssl_random_pseudo_bytes( $length );
			// Check if openssl failed or returned too few bytes
			if ( false !== $bytes && strlen( $bytes ) === $length ) {
				return $bytes;
			}
			return false;
		}

		return false;
	}
}