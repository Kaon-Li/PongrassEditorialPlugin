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
	$starting_points = array( __DIR__ );

	// When the plugin directory is a symlink or a junction, __DIR__ resolves
	// to the link target, which is outside the WordPress tree, and walking
	// up from it never reaches wp-load.php. The path the web server actually
	// dispatched keeps the in-tree view, so try that too.
	if ( ! empty( $_SERVER['SCRIPT_FILENAME'] ) ) {
		$script_dir = dirname( (string) $_SERVER['SCRIPT_FILENAME'] );

		if ( is_dir( $script_dir ) ) {
			$starting_points[] = $script_dir;
		}
	}

	if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) && is_dir( (string) $_SERVER['DOCUMENT_ROOT'] ) ) {
		$starting_points[] = (string) $_SERVER['DOCUMENT_ROOT'];
	}

	foreach ( array_unique( $starting_points ) as $dir ) {
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
	}

	return false;
}

/**
 * Which plugins to unload for the duration of an RPC request.
 *
 * Returns one of:
 *   'all'      - unload every other plugin. Fastest, but also unloads any
 *                security plugin, so nothing inspects RPC traffic.
 *   'none'     - load the full plugin stack, same as a normal page load.
 *   'selected' - unload only the plugins listed in pep_suppressed_plugins.
 *
 * Resolved here rather than before the filter is registered. This runs from
 * wp-settings.php, by which point wp-config.php has been parsed and the
 * options API is up; the registration site below runs before wp-load.php,
 * where neither is true yet.
 *
 * Precedence: the wp-config.php constant wins when defined, then the admin
 * setting, then the pre-2.7.2 boolean option, then 'all'.
 *
 * @return string
 */
function pep_suppression_mode() {
	if ( defined( 'PEP_DISABLE_OTHER_PLUGINS' ) ) {
		return PEP_DISABLE_OTHER_PLUGINS ? 'all' : 'none';
	}

	// Safe to read an option at this point: wp-settings.php has already set
	// up $wpdb and the options API, since it is reading the active plugin
	// list through the very same API to get here.
	if ( ! function_exists( 'get_option' ) ) {
		return 'all';
	}

	$mode = get_option( 'pep_suppression_mode', '' );

	if ( in_array( $mode, array( 'all', 'none', 'selected' ), true ) ) {
		return $mode;
	}

	// Carried over from the 2.7.1 boolean, for installs that set it before
	// the per-plugin list existed.
	$legacy = get_option( 'pep_disable_other_plugins', null );

	if ( null !== $legacy ) {
		return empty( $legacy ) ? 'none' : 'all';
	}

	return 'all';
}

/**
 * The plugin files to unload when the mode is 'selected'.
 *
 * @return string[] e.g. array( 'akismet/akismet.php' )
 */
function pep_suppressed_plugins() {
	if ( ! function_exists( 'get_option' ) ) {
		return array();
	}

	$selected = get_option( 'pep_suppressed_plugins', array() );

	return is_array( $selected ) ? array_values( array_filter( array_map( 'strval', $selected ) ) ) : array();
}

/**
 * Whether the plugin list should be altered at all for this request.
 *
 * @return bool
 */
function pep_is_rpc_request() {
	// Keyed off a constant set by the endpoint rather than sniffing
	// PHP_SELF, which is derived from the request and can be manipulated.
	return defined( 'PEP_RPC_REQUEST' ) && PEP_RPC_REQUEST;
}

/**
 * Filter for option_active_plugins: a list of plugin files.
 *
 * @param mixed $plugins Active plugin list.
 * @return mixed
 */
function pep_filter_active_plugins( $plugins ) {
	if ( ! pep_is_rpc_request() || ! is_array( $plugins ) ) {
		return $plugins;
	}

	$mode = pep_suppression_mode();

	if ( 'none' === $mode ) {
		return $plugins;
	}

	if ( 'all' === $mode ) {
		return array();
	}

	$suppress = pep_suppressed_plugins();

	if ( empty( $suppress ) ) {
		return $plugins;
	}

	// Reindexed, because wp_get_active_and_valid_plugins() expects a list.
	return array_values( array_diff( $plugins, $suppress ) );
}

/**
 * Filter for site_option_active_sitewide_plugins on multisite.
 *
 * That option is keyed by plugin file with an activation timestamp as the
 * value, so entries are removed by key rather than by value.
 *
 * @param mixed $plugins Network-active plugin map.
 * @return mixed
 */
function pep_filter_sitewide_plugins( $plugins ) {
	if ( ! pep_is_rpc_request() || ! is_array( $plugins ) ) {
		return $plugins;
	}

	$mode = pep_suppression_mode();

	if ( 'none' === $mode ) {
		return $plugins;
	}

	if ( 'all' === $mode ) {
		return array();
	}

	$suppress = pep_suppressed_plugins();

	if ( empty( $suppress ) ) {
		return $plugins;
	}

	return array_diff_key( $plugins, array_flip( $suppress ) );
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

	// Always registered. What they actually do is decided inside the
	// callbacks, so the mode can come from wp-config.php or from an option.
	add_filter( 'option_active_plugins', 'pep_filter_active_plugins', 1 );
	add_filter( 'site_option_active_sitewide_plugins', 'pep_filter_sitewide_plugins', 1 );

	require_once $pep_wp_load;

	remove_filter( 'option_active_plugins', 'pep_filter_active_plugins', 1 );
	remove_filter( 'site_option_active_sitewide_plugins', 'pep_filter_sitewide_plugins', 1 );
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
