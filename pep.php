<?php
/*
Plugin Name: PEP - Pongrass Editorial Wordpress Plugin
Plugin URI: http://fc.pongrass.com.au/~ronin/pep
Description: A WordPress plugin for integration with the Pongrass Advertising and Editorial system
Version: 2.7.1
Author: Ronin, ronin@pongrass.com.au
License: GPL2
Requires PHP: 8.0
*/

/* adopted from the plugin template written by Juergen Schulze */

/*  Copyright 2011  Juergen Schulze  (email : 1manfactory@gmail.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PEP_PUGIN_NAME', 'Pongrass Editorial Plugin' );
define( 'PEP_PLUGIN_DIRECTORY', 'PongrassEditorialPlugin' );
define( 'PEP_LOGPATH', __DIR__ . '/pep-logs/' );

// PEP_CURRENT_VERSION, PEP_CURRENT_BUILD and PEP_VERSION_DATE live in one
// file so the endpoint and the admin screen cannot report different numbers.
require_once __DIR__ . '/pep_version.php';

// Follows WP_DEBUG rather than being pinned on. The old value shipped as
// true, and pep_debug() was the only thing keeping it from taking effect.
if ( ! defined( 'PEP_DEBUG' ) ) {
	define( 'PEP_DEBUG', defined( 'WP_DEBUG' ) && WP_DEBUG );
}

// i18n plugin domain for language files.
define( 'EMU2_I18N_DOMAIN', 'pep' );

// The capability required to see and change PEP settings.
define( 'PEP_ADMIN_CAPABILITY', 'manage_options' );

require_once __DIR__ . '/pep_logfilehandling.php';
require_once __DIR__ . '/pep_security.php';

/**
 * Load the language file for the current locale.
 *
 * @return void
 */
function pep_set_lang_file() {
	$currentLocale = get_locale();

	if ( ! empty( $currentLocale ) ) {
		$moFile = __DIR__ . '/lang/' . $currentLocale . '.mo';

		if ( file_exists( $moFile ) && is_readable( $moFile ) ) {
			load_textdomain( EMU2_I18N_DOMAIN, $moFile );
		}
	}
}
add_action( 'init', 'pep_set_lang_file' );

add_action( 'admin_menu', 'pep_create_menu' );

/**
 * Default values applied on activation.
 *
 * Existing installs are switched into legacy mode so their Pongrass clients
 * keep working while an API key is rolled out. A brand new install starts
 * with legacy mode off, so it is never open by default.
 *
 * @return void
 */
function pep_activate() {
	add_option( 'pep_option_enable_logging', 'true' );

	$is_upgrade = (bool) get_option( 'ip_white_list_1', '' )
		|| (bool) get_option( 'ip_white_list_2', '' )
		|| (bool) get_option( 'ip_white_list_3', '' );

	add_option( PEP_OPT_LEGACY_MODE, $is_upgrade ? 1 : 0 );

	pep_createLogFolder();
}

/**
 * Deactivation cleanup.
 *
 * @return void
 */
function pep_deactivate() {
	delete_option( 'pep_option_enable_logging' );
}

/**
 * Remove everything the plugin stored.
 *
 * @return void
 */
function pep_uninstall() {
	delete_option( 'pep_option_enable_logging' );
	delete_option( PEP_OPT_API_KEY_HASH );
	delete_option( PEP_OPT_LEGACY_MODE );
	delete_option( 'pep_disable_other_plugins' );
	delete_option( 'pep_on_off' );

	for ( $i = 1; $i <= PEP_WHITELIST_SLOTS; $i++ ) {
		delete_option( 'ip_white_list_' . $i );
	}

	if ( function_exists( 'pep_deleteLogFolder' ) ) {
		pep_deleteLogFolder();
	}
}

/**
 * Register the admin menu.
 *
 * The old code registered the same page four times, twice at capability 0
 * and twice at capability 9. Capability 0 maps to the subscriber user level,
 * so any logged-in user could open the settings screen. It is now a single
 * entry behind manage_options.
 *
 * @return void
 */
function pep_create_menu() {
	add_menu_page(
		__( 'Pongrass', EMU2_I18N_DOMAIN ),
		__( 'Pongrass', EMU2_I18N_DOMAIN ),
		PEP_ADMIN_CAPABILITY,
		'pep-settings',
		'pep_render_settings_page',
		plugins_url( '/images/icon.png', __FILE__ )
	);

	add_options_page(
		__( 'Pongrass Options', EMU2_I18N_DOMAIN ),
		__( 'Pongrass', EMU2_I18N_DOMAIN ),
		PEP_ADMIN_CAPABILITY,
		'pep-settings',
		'pep_render_settings_page'
	);
}

/**
 * Render the settings screen.
 *
 * @return void
 */
function pep_render_settings_page() {
	if ( ! current_user_can( PEP_ADMIN_CAPABILITY ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', EMU2_I18N_DOMAIN ) );
	}

	require __DIR__ . '/pep_settings_page.php';
}

/**
 * Validate one whitelist entry.
 *
 * Accepts a bare address, a CIDR range, or a trailing-wildcard pattern, and
 * discards anything else. The old callback wrote the raw value straight to a
 * .txt file inside the plugin directory, where it was readable over HTTP.
 *
 * @param mixed $value Submitted value.
 * @return string
 */
function pep_sanitize_whitelist_entry( $value ) {
	$value = trim( sanitize_text_field( (string) $value ) );

	if ( '' === $value ) {
		return '';
	}

	if ( filter_var( $value, FILTER_VALIDATE_IP ) ) {
		return $value;
	}

	// CIDR range.
	if ( false !== strpos( $value, '/' ) ) {
		list( $subnet, $bits ) = array_pad( explode( '/', $value, 2 ), 2, '' );

		if ( filter_var( $subnet, FILTER_VALIDATE_IP ) && is_numeric( $bits ) ) {
			$max  = ( false !== strpos( $subnet, ':' ) ) ? 128 : 32;
			$bits = (int) $bits;

			if ( $bits >= 0 && $bits <= $max ) {
				return $subnet . '/' . $bits;
			}
		}

		add_settings_error( 'pep-settings-group', 'pep_bad_cidr', sprintf(
			/* translators: %s: the rejected value */
			__( '"%s" is not a valid CIDR range and was discarded.', EMU2_I18N_DOMAIN ),
			$value
		) );

		return '';
	}

	// Trailing-wildcard pattern, e.g. 203.0.113.*
	if ( 1 === preg_match( '/^[0-9a-fA-F:.]+\*$/', $value ) ) {
		return $value;
	}

	add_settings_error( 'pep-settings-group', 'pep_bad_ip', sprintf(
		/* translators: %s: the rejected value */
		__( '"%s" is not a valid IP address, range or pattern and was discarded.', EMU2_I18N_DOMAIN ),
		$value
	) );

	return '';
}

/**
 * Register settings.
 *
 * @return void
 */
function pep_register_settings() {
	for ( $i = 1; $i <= PEP_WHITELIST_SLOTS; $i++ ) {
		register_setting(
			'pep-settings-group',
			'ip_white_list_' . $i,
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'pep_sanitize_whitelist_entry',
				'show_in_rest'      => false,
			)
		);
	}

	register_setting(
		'pep-settings-group',
		PEP_OPT_LEGACY_MODE,
		array(
			'type'              => 'boolean',
			'default'           => 0,
			'sanitize_callback' => static function ( $value ) {
				return empty( $value ) ? 0 : 1;
			},
			'show_in_rest'      => false,
		)
	);

	// Read by pep_should_suppress_plugins() in pep_config.php. Kept as a
	// setting so a site without wp-config.php access can still turn plugin
	// suppression off. Option name is a literal in both places because
	// pep_config.php runs before this file is loaded.
	register_setting(
		'pep-settings-group',
		'pep_disable_other_plugins',
		array(
			'type'              => 'boolean',
			'default'           => 1,
			'sanitize_callback' => static function ( $value ) {
				return empty( $value ) ? 0 : 1;
			},
			'show_in_rest'      => false,
		)
	);
}
add_action( 'admin_init', 'pep_register_settings' );

/**
 * Handle the "generate a new API key" button.
 *
 * @return void
 */
function pep_handle_regenerate_key() {
	if ( ! current_user_can( PEP_ADMIN_CAPABILITY ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', EMU2_I18N_DOMAIN ) );
	}

	check_admin_referer( 'pep_regenerate_key' );

	$key = pep_generate_api_key();

	// The plaintext is never stored, so hold it briefly for one render.
	set_transient( 'pep_new_api_key_' . get_current_user_id(), $key, 5 * MINUTE_IN_SECONDS );

	pep_writelog( 'API key regenerated by user ' . get_current_user_id() );

	wp_safe_redirect( add_query_arg( 'pep_key_generated', '1', admin_url( 'admin.php?page=pep-settings' ) ) );
	exit;
}
add_action( 'admin_post_pep_regenerate_key', 'pep_handle_regenerate_key' );

/**
 * Warn an administrator when the endpoint is misconfigured.
 *
 * @return void
 */
function pep_access_admin_notice() {
	if ( ! current_user_can( PEP_ADMIN_CAPABILITY ) ) {
		return;
	}

	if ( defined( 'PEP_LEGACY_ALLOW_ANY_IP' ) && PEP_LEGACY_ALLOW_ANY_IP ) {
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Pongrass Editorial Plugin:', EMU2_I18N_DOMAIN ),
			esc_html__( 'PEP_LEGACY_ALLOW_ANY_IP is enabled. The RPC endpoint accepts unauthenticated requests from any address. Remove this from wp-config.php as soon as your clients send an API key.', EMU2_I18N_DOMAIN )
		);

		return;
	}

	if ( pep_access_is_unconfigured() ) {
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Pongrass Editorial Plugin:', EMU2_I18N_DOMAIN ),
			esc_html__( 'The RPC endpoint is rejecting every request because no API key has been generated and no IP whitelist is configured.', EMU2_I18N_DOMAIN ),
			esc_url( admin_url( 'admin.php?page=pep-settings' ) ),
			esc_html__( 'Open PEP settings', EMU2_I18N_DOMAIN )
		);

		return;
	}

	if ( pep_legacy_mode_enabled() && ! pep_has_api_key() ) {
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Pongrass Editorial Plugin:', EMU2_I18N_DOMAIN ),
			esc_html__( 'Legacy keyless access is enabled. Requests are allowed on IP address alone, which is spoofable. Generate an API key, update your Pongrass clients, then turn legacy mode off.', EMU2_I18N_DOMAIN ),
			esc_url( admin_url( 'admin.php?page=pep-settings' ) ),
			esc_html__( 'Open PEP settings', EMU2_I18N_DOMAIN )
		);
	}
}
add_action( 'admin_notices', 'pep_access_admin_notice' );

/**
 * Warn when the plugin header version has drifted from PEP_CURRENT_VERSION.
 *
 * WordPress parses the "Version:" header as a static comment, so it has to
 * be a literal and cannot read the constant. This is the safety net for the
 * one place the version still has to be typed twice.
 *
 * @return void
 */
function pep_check_version_sync() {
	if ( ! current_user_can( PEP_ADMIN_CAPABILITY ) ) {
		return;
	}

	$header = get_file_data( __FILE__, array( 'Version' => 'Version' ) );

	if ( empty( $header['Version'] ) || $header['Version'] === PEP_CURRENT_VERSION ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
		esc_html__( 'Pongrass Editorial Plugin:', EMU2_I18N_DOMAIN ),
		esc_html(
			sprintf(
				/* translators: 1: version from the plugin header, 2: version constant */
				__( 'The plugin header says version %1$s but PEP_CURRENT_VERSION is %2$s. Update the Version line in pep.php to match pep_version.php.', EMU2_I18N_DOMAIN ),
				$header['Version'],
				PEP_CURRENT_VERSION
			)
		)
	);
}
add_action( 'admin_notices', 'pep_check_version_sync' );

/**
 * Whether debug output is allowed.
 *
 * @return bool
 */
function pep_debug() {
	$host = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '';

	return 'localhost' === $host && defined( 'PEP_DEBUG' ) && PEP_DEBUG;
}

/**
 * Drop every intermediate image size except the core ones.
 *
 * Not hooked by default; renamed from the unprefixed remove_extra_image_sizes()
 * so it cannot collide with a theme or another plugin.
 *
 * @return void
 */
function pep_remove_extra_image_sizes() {
	foreach ( get_intermediate_image_sizes() as $size ) {
		if ( ! in_array( $size, array( 'thumbnail', 'medium', 'medium_large', 'large' ), true ) ) {
			remove_image_size( $size );
		}
	}
}

register_activation_hook( __FILE__, 'pep_activate' );
register_deactivation_hook( __FILE__, 'pep_deactivate' );
register_uninstall_hook( __FILE__, 'pep_uninstall' );
