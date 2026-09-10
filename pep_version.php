<?php
/**
 * PEP - version constants.
 *
 * This is the single place to edit when cutting a release. Both the plugin
 * (pep.php) and the standalone RPC endpoint (pep_json.php) read from here,
 * so pep_get_version() reports the right number without anyone remembering
 * to update a literal in a second file.
 *
 * The one duplicate that cannot be removed is the "Version:" line in the
 * pep.php plugin header. WordPress parses that as a static comment before
 * any PHP runs, so it has to be a literal. pep_check_version_sync() raises
 * an admin notice if it drifts from PEP_CURRENT_VERSION.
 *
 * @package PEP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'PEP_CURRENT_VERSION' ) ) {
	define( 'PEP_CURRENT_VERSION', '2.7.1' );
}

if ( ! defined( 'PEP_CURRENT_BUILD' ) ) {
	define( 'PEP_CURRENT_BUILD', '150' );
}

if ( ! defined( 'PEP_VERSION_DATE' ) ) {
	define( 'PEP_VERSION_DATE', '2026-09-10' );
}
