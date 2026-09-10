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

// Optional per-site overrides that have to exist before WordPress is found.
// PEP_WP_LOAD_PATH is the only one that genuinely belongs here: it is what
// locates wp-config.php, so it cannot be set from inside wp-config.php.
// Everything else should go in wp-config.php. Not tracked in git.
if ( file_exists( __DIR__ . '/pep-local-config.php' ) ) {
	require_once __DIR__ . '/pep-local-config.php';
}

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
	if ( ! defined( 'PEP_RPC_REQUEST' ) || ! PEP_RPC_REQUEST ) {
		return $plugins;
	}

	if ( ! pep_should_suppress_plugins() ) {
		return $plugins;
	}

	return array();
}

/**
 * Whether other plugins should be suppressed for this RPC request.
 *
 * Checked here rather than before the filter is registered. This runs from
 * wp-settings.php, by which point wp-config.php has been parsed and the
 * options API is up; the registration site below runs before wp-load.php,
 * where neither is true yet.
 *
 * Precedence: the wp-config.php constant wins when defined, otherwise the
 * "Suppress other plugins" setting on the PEP admin screen, otherwise on.
 *
 * @return bool
 */
function pep_should_suppress_plugins() {
	if ( defined( 'PEP_DISABLE_OTHER_PLUGINS' ) ) {
		return (bool) PEP_DISABLE_OTHER_PLUGINS;
	}

	// Safe to read an option at this point: wp-settings.php has already set
	// up $wpdb and the options API, since it is reading the active plugin
	// list through the very same API to get here.
	if ( function_exists( 'get_option' ) ) {
		return (bool) get_option( 'pep_disable_other_plugins', 1 );
	}

	return true;
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

	// plugin.php has to be in scope before wp-settings.php reads the active
	// plugin list, so pull it in from the located root rather than via a
	// CWD-relative path.
	require_once dirname( $pep_wp_load ) . '/wp-includes/plugin.php';

	// Always registered. Whether it actually suppresses anything is decided
	// inside the callback, so PEP_DISABLE_OTHER_PLUGINS can be set from
	// wp-config.php.
	add_filter( 'option_active_plugins', 'pep_disable_other_plugins', 1 );
	add_filter( 'site_option_active_sitewide_plugins', 'pep_disable_other_plugins', 1 );

	require_once $pep_wp_load;

	remove_filter( 'option_active_plugins', 'pep_disable_other_plugins', 1 );
	remove_filter( 'site_option_active_sitewide_plugins', 'pep_disable_other_plugins', 1 );
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
