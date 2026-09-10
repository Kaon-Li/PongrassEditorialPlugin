<?php
/*
 *
 *  Copyright 2010 Juergen Schulze, 1manfactory.com

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'PEP_LOGPATH' ) ) {
	define( 'PEP_LOGPATH', __DIR__ . '/pep-logs/' );
}

// This file is also loaded by pep_json.php with the rest of the plugin stack
// suppressed, so pep.php may not have run.
if ( ! defined( 'EMU2_I18N_DOMAIN' ) ) {
	define( 'EMU2_I18N_DOMAIN', 'pep' );
}

// Roll the log over rather than letting it grow without limit.
if ( ! defined( 'PEP_LOG_MAX_BYTES' ) ) {
	define( 'PEP_LOG_MAX_BYTES', 8 * 1024 * 1024 );
}

add_action( 'admin_notices', 'pep_check_create_log_folder' );

/**
 * The current month's log file.
 *
 * @return string Absolute path.
 */
function pep_log_file() {
	return trailingslashit( PEP_LOGPATH ) . 'ops-' . gmdate( 'Y-m' ) . '.log';
}

/**
 * Write the files that stop the log directory being served over HTTP.
 *
 * index.html only defeats directory listing; the .log files themselves are
 * still fetchable by name without a deny rule.
 *
 * @return void
 */
function pep_protect_log_folder() {
	$dir = trailingslashit( PEP_LOGPATH );

	$guards = array(
		'index.html' => '',
		'index.php'  => "<?php // Silence is golden.\n",
		'.htaccess'  => "# Added by the Pongrass Editorial Plugin.\n"
			. "# Log files can contain post content and user data.\n"
			. "<IfModule mod_authz_core.c>\n"
			. "\tRequire all denied\n"
			. "</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n"
			. "\tOrder allow,deny\n"
			. "\tDeny from all\n"
			. "</IfModule>\n",
	);

	foreach ( $guards as $name => $contents ) {
		$path = $dir . $name;
		if ( ! file_exists( $path ) ) {
			@file_put_contents( $path, $contents );
		}
	}
}

/**
 * Create the log folder if it is missing.
 *
 * @return bool True when the folder exists and is writable.
 */
function pep_createLogFolder() {
	if ( ! is_dir( PEP_LOGPATH ) ) {
		// 0755, not 0777: a world-writable directory inside the web root
		// lets any other process on the host drop files into it.
		if ( ! wp_mkdir_p( PEP_LOGPATH ) ) {
			update_option( 'pep_on_off', 0 );
			return false;
		}
	}

	if ( ! is_writable( PEP_LOGPATH ) ) {
		if ( ! @chmod( PEP_LOGPATH, 0755 ) ) {
			update_option( 'pep_on_off', 0 );
			return false;
		}
	}

	pep_protect_log_folder();

	return true;
}

/**
 * Delete the log folder and everything in it.
 *
 * @return void
 */
function pep_deleteLogFolder() {
	if ( is_dir( PEP_LOGPATH ) ) {
		pep_deltree( PEP_LOGPATH );
	}
}

/**
 * Create the log folder, reporting success or failure in the admin panel.
 *
 * @return void
 */
function pep_check_create_log_folder() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! pep_check_folder_error() ) {
		return;
	}

	if ( ! pep_createLogFolder() ) {
		printf(
			'<div id="message" class="error"><p>%s %s %s</p></div>',
			esc_html__( 'Pongrass Editorial Plugin (PEP) Error: Cannot write to log folder', EMU2_I18N_DOMAIN ),
			esc_html( PEP_LOGPATH ),
			esc_html__( 'Write access required.', EMU2_I18N_DOMAIN )
		);
	} else {
		printf(
			'<div id="message" class="updated"><p>%s %s</p></div>',
			esc_html__( 'Pongrass Editorial Plugin (PEP): Log folder created:', EMU2_I18N_DOMAIN ),
			esc_html( PEP_LOGPATH )
		);
	}
}

/**
 * Whether the log folder is currently unusable.
 *
 * @return bool
 */
function pep_check_folder_error() {
	if ( is_writable( PEP_LOGPATH ) ) {
		return false;
	}

	return ! @chmod( PEP_LOGPATH, 0755 );
}

/**
 * Recursively delete a folder.
 *
 * @param string $f Folder path.
 * @return bool
 */
function pep_deltree( $f ) {
	if ( is_dir( $f ) ) {
		$entries = glob( trailingslashit( $f ) . '*' );

		// glob() returns false on error; foreach over false is a TypeError.
		if ( is_array( $entries ) ) {
			foreach ( $entries as $sf ) {
				if ( is_dir( $sf ) && ! is_link( $sf ) ) {
					// Was a call to the non-existent ept_deltree(), which is a
					// fatal Error on PHP 8 that @ does not suppress.
					pep_deltree( $sf );
				} else {
					@unlink( $sf );
				}
			}
		}
	}

	if ( is_dir( $f ) ) {
		@rmdir( $f );
	}

	return true;
}

/**
 * Rotate the log once it passes PEP_LOG_MAX_BYTES.
 *
 * @param string $logFile Absolute path to the active log.
 * @return void
 */
function pep_rotate_log( $logFile ) {
	if ( ! file_exists( $logFile ) ) {
		return;
	}

	$size = @filesize( $logFile );
	if ( false === $size || $size < PEP_LOG_MAX_BYTES ) {
		return;
	}

	$rotated = $logFile . '.1';
	if ( file_exists( $rotated ) ) {
		@unlink( $rotated );
	}

	@rename( $logFile, $rotated );
}

/**
 * Write a message to the log file.
 *
 * @param string     $string       Message.
 * @param string|int $functionname Calling context.
 * @param string|int $linenumber   Line reference.
 * @return void
 */
function pep_writelog( $string = '', $functionname = '', $linenumber = '' ) {
	$string = (string) $string;

	if ( '' === $string ) {
		return;
	}

	if ( ! is_dir( PEP_LOGPATH ) && ! pep_createLogFolder() ) {
		return;
	}

	$logFile = pep_log_file();
	pep_rotate_log( $logFile );

	$timeStamp = gmdate( 'd/M/Y:H:i:s O' );

	// utf8_encode() was removed here: it assumed the input was Latin-1 and
	// mangled the UTF-8 that WordPress actually hands us. It is also
	// deprecated as of PHP 8.2 and scheduled for removal.
	$logline = sprintf(
		"[%s] %s %s %s\r\n",
		$timeStamp,
		html_entity_decode( $string, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
		(string) $functionname,
		(string) $linenumber
	);

	// file_put_contents with LOCK_EX avoids interleaved writes from
	// concurrent requests, and avoids fwrite() on a failed fopen() handle,
	// which is a TypeError on PHP 8.
	@file_put_contents( $logFile, $logline, FILE_APPEND | LOCK_EX );
}

/**
 * Write a message that may contain post content, user emails or credentials.
 *
 * The log directory sits inside the web root and the log can be read back
 * through the RPC endpoint, so payload logging is opt-in.
 *
 * @param string     $string       Message.
 * @param string|int $functionname Calling context.
 * @param string|int $linenumber   Line reference.
 * @return void
 */
function pep_writelog_sensitive( $string = '', $functionname = '', $linenumber = '' ) {
	if ( ! defined( 'PEP_LOG_PAYLOADS' ) || ! PEP_LOG_PAYLOADS ) {
		return;
	}

	pep_writelog( $string, $functionname, $linenumber );
}

/**
 * The contents of the current log file.
 *
 * @return string
 */
function pep_getcurrentlog() {
	$logFile = pep_log_file();

	if ( file_exists( $logFile ) ) {
		$contents = @file_get_contents( $logFile );
		return ( false === $contents ) ? '' : $contents;
	}

	return '';
}

/**
 * Delete the current log file.
 *
 * @return void
 */
function pep_clearcurrentlog() {
	$logFile = pep_log_file();

	if ( file_exists( $logFile ) ) {
		@unlink( $logFile );
	}
}
