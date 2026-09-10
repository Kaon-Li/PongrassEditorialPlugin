<?php
/**
 * PEP - authentication and access control for the JSON-RPC endpoint.
 *
 * The endpoint in pep_json.php bootstraps WordPress and then performs
 * privileged operations (creating and deleting posts, uploading media,
 * writing meta). Historically the only gate was an IP whitelist that was
 * skipped entirely when unconfigured, which left the endpoint open.
 *
 * Access is now granted by, in order of preference:
 *   1. A shared-secret API key, sent as the X-PEP-Key header or as a "key"
 *      member of the JSON-RPC envelope.
 *   2. Legacy mode: no key, but the caller's IP is on a non-empty whitelist.
 *      This exists so existing Pongrass clients keep working while they are
 *      updated to send a key, and is meant to be switched off afterwards.
 *
 * @package PEP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PEP_OPT_API_KEY_HASH', 'pep_api_key_hash' );
define( 'PEP_OPT_LEGACY_MODE', 'pep_legacy_ip_only' );
define( 'PEP_WHITELIST_SLOTS', 5 );

/**
 * The configured IP whitelist entries, empty ones removed.
 *
 * Accepts a plain address, a CIDR range, or a trailing-wildcard pattern.
 *
 * @return string[]
 */
function pep_get_whitelist() {
	$entries = array();

	for ( $i = 1; $i <= PEP_WHITELIST_SLOTS; $i++ ) {
		$value = trim( (string) get_option( 'ip_white_list_' . $i, '' ) );
		if ( '' !== $value ) {
			$entries[] = $value;
		}
	}

	return $entries;
}

/**
 * The IP address the current request came from.
 *
 * Only REMOTE_ADDR is trusted by default. X-Forwarded-For is attacker
 * controlled unless a reverse proxy is known to overwrite it, so honouring it
 * has to be opted into from wp-config.php with PEP_TRUST_PROXY_HEADER.
 *
 * @return string
 */
function pep_client_ip() {
	if ( defined( 'PEP_TRUST_PROXY_HEADER' ) && PEP_TRUST_PROXY_HEADER && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		// The left-most entry is the originating client; the rest are proxies.
		$forwarded = explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] );
		$candidate = trim( $forwarded[0] );
		if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
			return $candidate;
		}
	}

	return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
}

/**
 * Whether an address falls inside a CIDR range. Handles IPv4 and IPv6.
 *
 * @param string $ip   Address to test.
 * @param string $cidr Range in address/prefix form.
 * @return bool
 */
function pep_ip_in_cidr( $ip, $cidr ) {
	list( $subnet, $bits ) = array_pad( explode( '/', $cidr, 2 ), 2, null );

	$packed_ip     = @inet_pton( $ip );
	$packed_subnet = @inet_pton( trim( (string) $subnet ) );

	if ( false === $packed_ip || false === $packed_subnet ) {
		return false;
	}

	// Mixing an IPv4 rule with an IPv6 address (or vice versa) never matches.
	if ( strlen( $packed_ip ) !== strlen( $packed_subnet ) ) {
		return false;
	}

	$max_bits = strlen( $packed_ip ) * 8;
	$bits     = ( null === $bits || '' === $bits ) ? $max_bits : (int) $bits;

	if ( $bits < 0 || $bits > $max_bits ) {
		return false;
	}

	$whole_bytes    = intdiv( $bits, 8 );
	$remaining_bits = $bits % 8;

	if ( $whole_bytes > 0
		&& ! hash_equals( substr( $packed_subnet, 0, $whole_bytes ), substr( $packed_ip, 0, $whole_bytes ) ) ) {
		return false;
	}

	if ( 0 === $remaining_bits ) {
		return true;
	}

	$mask        = ~( ( 1 << ( 8 - $remaining_bits ) ) - 1 ) & 0xFF;
	$ip_byte     = ord( $packed_ip[ $whole_bytes ] ) & $mask;
	$subnet_byte = ord( $packed_subnet[ $whole_bytes ] ) & $mask;

	return $ip_byte === $subnet_byte;
}

/**
 * Whether an address matches a single whitelist entry.
 *
 * @param string $ip   Address to test.
 * @param string $rule Exact address, CIDR range, or trailing-wildcard pattern.
 * @return bool
 */
function pep_ip_matches( $ip, $rule ) {
	$ip   = trim( (string) $ip );
	$rule = trim( (string) $rule );

	if ( '' === $ip || '' === $rule ) {
		return false;
	}

	if ( false !== strpos( $rule, '/' ) ) {
		return pep_ip_in_cidr( $ip, $rule );
	}

	if ( false !== strpos( $rule, '*' ) ) {
		$pattern = '/^' . str_replace( '\*', '.*', preg_quote( $rule, '/' ) ) . '$/';
		return 1 === preg_match( $pattern, $ip );
	}

	// Compare packed forms so that padded variants do not slip through.
	$packed_ip   = @inet_pton( $ip );
	$packed_rule = @inet_pton( $rule );

	if ( false === $packed_ip || false === $packed_rule ) {
		return false;
	}

	if ( strlen( $packed_ip ) !== strlen( $packed_rule ) ) {
		return false;
	}

	return hash_equals( $packed_rule, $packed_ip );
}

/**
 * Whether the calling address is on the whitelist.
 *
 * An empty whitelist matches nothing. This is deliberate: the previous
 * behaviour of treating "unconfigured" as "allow everyone" is what left the
 * endpoint open to the internet.
 *
 * @return bool
 */
function pep_ip_is_whitelisted() {
	$ip = pep_client_ip();

	foreach ( pep_get_whitelist() as $rule ) {
		if ( pep_ip_matches( $ip, $rule ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether an API key has been configured.
 *
 * @return bool
 */
function pep_has_api_key() {
	return '' !== (string) get_option( PEP_OPT_API_KEY_HASH, '' );
}

/**
 * Generate, store and return a new API key.
 *
 * Only the hash is persisted, so the plaintext is returned to the caller once
 * and cannot be recovered from the database afterwards.
 *
 * @return string The new plaintext key.
 */
function pep_generate_api_key() {
	$key = 'pep_' . bin2hex( random_bytes( 24 ) );
	update_option( PEP_OPT_API_KEY_HASH, hash( 'sha256', $key ), false );

	return $key;
}

/**
 * The API key supplied with the current request, if any.
 *
 * @param array|null $request Decoded JSON-RPC envelope, when already parsed.
 * @return string Empty string when no key was supplied.
 */
function pep_api_key_from_request( $request = null ) {
	if ( ! empty( $_SERVER['HTTP_X_PEP_KEY'] ) ) {
		return trim( (string) $_SERVER['HTTP_X_PEP_KEY'] );
	}

	if ( is_array( $request ) && ! empty( $request['key'] ) && is_string( $request['key'] ) ) {
		return trim( $request['key'] );
	}

	// Multipart uploads cannot always set headers, so allow a form field too.
	if ( ! empty( $_POST['pep_key'] ) && is_string( $_POST['pep_key'] ) ) {
		return trim( wp_unslash( $_POST['pep_key'] ) );
	}

	return '';
}

/**
 * Whether a supplied key matches the stored hash.
 *
 * @param string $key Plaintext key from the request.
 * @return bool
 */
function pep_verify_api_key( $key ) {
	$stored = (string) get_option( PEP_OPT_API_KEY_HASH, '' );

	if ( '' === $stored || '' === (string) $key ) {
		return false;
	}

	return hash_equals( $stored, hash( 'sha256', (string) $key ) );
}

/**
 * Whether legacy keyless access is still permitted.
 *
 * @return bool
 */
function pep_legacy_mode_enabled() {
	return (bool) get_option( PEP_OPT_LEGACY_MODE, false );
}

/**
 * Decide whether the current request may use the RPC endpoint.
 *
 * @param array|null $request Decoded JSON-RPC envelope, when already parsed.
 * @return array{allowed:bool,mode:string,reason:string}
 */
function pep_authorize_request( $request = null ) {
	$ip  = pep_client_ip();
	$key = pep_api_key_from_request( $request );

	if ( '' !== $key ) {
		if ( pep_verify_api_key( $key ) ) {
			return array(
				'allowed' => true,
				'mode'    => 'api_key',
				'reason'  => '',
			);
		}

		// A wrong key is a hard failure, even from a whitelisted address.
		return array(
			'allowed' => false,
			'mode'    => 'api_key',
			'reason'  => 'Invalid API key',
		);
	}

	if ( ! pep_legacy_mode_enabled() ) {
		return array(
			'allowed' => false,
			'mode'    => 'none',
			'reason'  => pep_has_api_key()
				? 'No API key supplied'
				: 'No API key supplied and no API key configured on this site',
		);
	}

	if ( pep_ip_is_whitelisted() ) {
		return array(
			'allowed' => true,
			'mode'    => 'legacy_ip',
			'reason'  => '',
		);
	}

	// Escape hatch for an operator who needs the integration running while
	// keys are rolled out. Deliberately requires editing wp-config.php.
	if ( defined( 'PEP_LEGACY_ALLOW_ANY_IP' ) && PEP_LEGACY_ALLOW_ANY_IP ) {
		return array(
			'allowed' => true,
			'mode'    => 'legacy_open',
			'reason'  => '',
		);
	}

	$whitelist = pep_get_whitelist();

	return array(
		'allowed' => false,
		'mode'    => 'legacy_ip',
		'reason'  => empty( $whitelist )
			? 'Legacy mode is on but no IP whitelist is configured'
			: sprintf( 'Address %s is not whitelisted', $ip ),
	);
}

/**
 * Whether the plugin is in a state where it will reject every RPC request.
 *
 * Used to warn an administrator before their integration goes quiet.
 *
 * @return bool
 */
function pep_access_is_unconfigured() {
	if ( pep_has_api_key() ) {
		return false;
	}

	if ( defined( 'PEP_LEGACY_ALLOW_ANY_IP' ) && PEP_LEGACY_ALLOW_ANY_IP ) {
		return false;
	}

	$whitelist = pep_get_whitelist();

	return ! ( pep_legacy_mode_enabled() && ! empty( $whitelist ) );
}
