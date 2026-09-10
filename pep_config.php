<?php
/**
 * PEP - WordPress bootstrap for the standalone RPC endpoint.
 *
 * pep_json.php is hit directly by the Pongrass clients rather than going
 * through index.php, so it has to load WordPress itself.
 *
 * Two things changed here:
 *
 *  - The WordPress root is now discovered by walking up from this file
 *    instead of being hard-coded to specific customer paths. Those paths
 *    were wrong on every other install and disclosed server layout.
 *
 *  - wp-load.php is loaded instead of wp-blog-header.php. wp-blog-header
 *    also runs the main query and the template loader, which renders the
 *    active theme and emits HTML ahead of the JSON response.
 *
 * A site with an unusual layout can short-circuit discovery by defining
 * PEP_WP_LOAD_PATH in wp-config.php.
 *
 * @package PEP
 */

/**
 * Locate wp-load.php.
 *
 * @return string|false Absolute path, or false when it cannot be found.
 */
function pep_locate_wp_load() {
	if ( defined( 'PEP_WP_LOAD_PATH' ) && file_exists( PEP_WP_LOAD_PATH ) ) {
		return PEP_WP_LOAD_PATH;
	}

	// Normal layout: wp-content/plugins/PongrassEditorialPlugin/ is three
	// levels below the WordPress root.
	$dir = __DIR__;

	for ( $depth = 0; $depth < 8; $depth++ ) {
		$candidate = $dir . '/wp-load.php';

		if ( file_exists( $candidate ) ) {
			return $candidate;
		}

		$parent = dirname( $dir );
		if ( $parent === $dir ) {
			break;
		}

		$dir = $parent;
	}

	return false;
}

/**
 * Suppress every other plugin for the duration of an RPC request.
 *
 * The endpoint only needs core, and loading the full plugin stack on every
 * article push is slow. Note that this also disables any security plugin,
 * so it can be turned off by defining PEP_DISABLE_OTHER_PLUGINS as false.
 *
 * @param mixed $plugins Active plugin list.
 * @return mixed
 */
function pep_disable_other_plugins( $plugins ) {
	// Keyed off a constant set by the endpoint rather than sniffing
	// PHP_SELF, which is derived from the request and can be manipulated.
	if ( defined( 'PEP_RPC_REQUEST' ) && PEP_RPC_REQUEST ) {
		return array();
	}

	return $plugins;
}

if ( ! defined( 'ABSPATH' ) ) {
	$pep_wp_load = pep_locate_wp_load();

	if ( false === $pep_wp_load ) {
		http_response_code( 500 );
		header( 'Content-Type: application/json' );
		echo pep_json_encode_fallback(
			array(
				'id'     => 0,
				'result' => null,
				'error'  => 'WordPress could not be loaded',
			)
		);
		exit;
	}

	$pep_suppress_plugins = ! defined( 'PEP_DISABLE_OTHER_PLUGINS' ) || PEP_DISABLE_OTHER_PLUGINS;

	if ( $pep_suppress_plugins ) {
		// plugin.php has to be in scope before wp-settings.php reads the
		// active plugin list, so pull it in from the located root rather
		// than via a CWD-relative path.
		require_once dirname( $pep_wp_load ) . '/wp-includes/plugin.php';

		add_filter( 'option_active_plugins', 'pep_disable_other_plugins', 1 );
		add_filter( 'site_option_active_sitewide_plugins', 'pep_disable_other_plugins', 1 );
	}

	require_once $pep_wp_load;

	if ( $pep_suppress_plugins ) {
		remove_filter( 'option_active_plugins', 'pep_disable_other_plugins', 1 );
		remove_filter( 'site_option_active_sitewide_plugins', 'pep_disable_other_plugins', 1 );
	}
}

/**
 * json_encode wrapper for the window before WordPress is available.
 *
 * @param mixed $data Value to encode.
 * @return string
 */
function pep_json_encode_fallback( $data ) {
	$encoded = json_encode( $data );

	return ( false === $encoded ) ? '{"error":"encoding failed"}' : $encoded;
}
