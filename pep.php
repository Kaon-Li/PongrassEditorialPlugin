<?php
/*
Plugin Name: PEP - Pongrass Editorial Wordpress Plugin
Plugin URI: http://fc.pongrass.com.au/~ronin/pep
Description: A WordPress plugin for integration with the Pongrass Advertising and Editorial system
Version: 1.0.6
Author: Ronin, ronin@pongrass.com.au
License: GPL2
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
?><?php

// some definition we will use
define( 'PEP_PUGIN_NAME', 'Pongrass Editorial Plugin');
define( 'PEP_PLUGIN_DIRECTORY', 'PongrassEditorialPlugin');
define( 'PEP_CURRENT_VERSION', '1.0.7' );
define( 'PEP_CURRENT_BUILD', '139' );
define( 'PEP_LOGPATH', __DIR__.'/pep-logs/');
define( 'PEP_DEBUG', true);		# never use debug mode on productive systems
// i18n plugin domain for language files
define( 'EMU2_I18N_DOMAIN', 'pep' );

// how to handle log files, don't load them if you don't log
require_once('pep_logfilehandling.php');

// load language files
function pep_set_lang_file() {
	# set the language file
	$currentLocale = get_locale();
	if(!empty($currentLocale)) {
		$moFile = dirname(__FILE__) . "/lang/" . $currentLocale . ".mo";
		if (@file_exists($moFile) && is_readable($moFile)) {
			load_textdomain(EMU2_I18N_DOMAIN, $moFile);
		}

	}
}
pep_set_lang_file();

// create custom plugin settings menu
add_action( 'admin_menu', 'pep_create_menu' );

function pp_1($str) {
    file_put_contents(dirname(__FILE__).'/whitelist_1.txt', $str);
    return $str;
}

function pp_2($str) {
    file_put_contents(dirname(__FILE__).'/whitelist_2.txt', $str);
    return $str;
}

function pp_3($str) {
    file_put_contents(dirname(__FILE__).'/whitelist_3.txt', $str);
    return $str;
}

function pp_4($str) {
    file_put_contents(dirname(__FILE__).'/whitelist_4.txt', $str);
    return $str;
}

function pp_5($str) {
    file_put_contents(dirname(__FILE__).'/whitelist_5.txt', $str);
    return $str;
}



// activating the default values
function pep_activate() {
	add_option('pep_option_enable_logging', 'true');
}

// deactivating
function pep_deactivate() {
	// needed for proper deletion of every option
	delete_option('pep_option_enable_logging');
}

// uninstalling
function pep_uninstall() {
	# delete all data stored
	delete_option('pep_option_enable_logging');
	// delete log files and folder only if needed
	if (function_exists('pep_deleteLogFolder')) pep_deleteLogFolder();
}

function pep_create_menu() {

	// create new top-level menu
	add_menu_page(
	__('Pongrass', EMU2_I18N_DOMAIN),
	__('Pongrass', EMU2_I18N_DOMAIN),
	0,
	PEP_PLUGIN_DIRECTORY.'/pep_settings_page.php',
	'',
	plugins_url('/images/icon.png', __FILE__));


	add_submenu_page(
	PEP_PLUGIN_DIRECTORY.'/pep_settings_page.php',
	__("Pongrass", EMU2_I18N_DOMAIN),
	__("Pongrass Menu", EMU2_I18N_DOMAIN),
	0,
	PEP_PLUGIN_DIRECTORY.'/pep_settings_page.php'
	);


	// or create options menu page
	add_options_page(__('Pongrass Options', EMU2_I18N_DOMAIN), __("Menu Options", EMU2_I18N_DOMAIN), 9,  PEP_PLUGIN_DIRECTORY.'/pep_settings_page.php');

	// or create sub menu page
	$parent_slug="index.php";	# For Dashboard
	#$parent_slug="edit.php";		# For Posts
	// more examples at http://codex.wordpress.org/Administration_Menus
	add_submenu_page( $parent_slug, __("Pongrass 4", EMU2_I18N_DOMAIN), __("Pongrass Menu 4", EMU2_I18N_DOMAIN), 9, PEP_PLUGIN_DIRECTORY.'/pep_settings_page.php');
}


function pep_register_settings() {
	//register settings

	$my_sanitize_string_1 = array(
        'type'              => 'string',
        'group'             => 'pep-setting-group',
        'description'       => '',
        'sanitize_callback' => function ($str) {
                file_put_contents(dirname(__FILE__).'/whitelist_1.txt', $str);
            return $str;
        },
        'show_in_rest'      => false,
    );

    $my_sanitize_string_2 = array(
        'type'              => 'string',
        'group'             => 'pep-setting-group',
        'description'       => '',
        'sanitize_callback' => function ($str) {
                file_put_contents(dirname(__FILE__).'/whitelist_2.txt', $str);
            return $str;
        },
        'show_in_rest'      => false,
    );

    $my_sanitize_string_3 = array(
        'type'              => 'string',
        'group'             => 'pep-setting-group',
        'description'       => '',
        'sanitize_callback' => function ($str) {
                file_put_contents(dirname(__FILE__).'/whitelist_3.txt', $str);
            return $str;
        },
        'show_in_rest'      => false,
    );

    $my_sanitize_string_4 = array(
        'type'              => 'string',
        'group'             => 'pep-setting-group',
        'description'       => '',
        'sanitize_callback' => function ($str) {
                file_put_contents(dirname(__FILE__).'/whitelist_4.txt', $str);
            return $str;
        },
        'show_in_rest'      => false,
    );

    $my_sanitize_string_5 = array(
        'type'              => 'string',
        'group'             => 'pep-setting-group',
        'description'       => '',
        'sanitize_callback' => function ($str) {
                file_put_contents(dirname(__FILE__).'/whitelist_5.txt', $str);
            return $str;
        },
        'show_in_rest'      => false,
    );

	register_setting( 'pep-settings-group', 'ip_white_list_1', $my_sanitize_string_1 );
	register_setting( 'pep-settings-group', 'ip_white_list_2',$my_sanitize_string_2);
	register_setting( 'pep-settings-group', 'ip_white_list_3',
	    $my_sanitize_string_3);
	register_setting( 'pep-settings-group', 'ip_white_list_4',
	    $my_sanitize_string_4);
	register_setting( 'pep-settings-group', 'ip_white_list_5' ,
	    $my_sanitize_string_5);
}

// check if debug is activated
function pep_debug() {
	# only run debug on localhost
	if ($_SERVER["HTTP_HOST"]=="localhost" && defined('PEP_DEBUG') && PEP_DEBUG==true) return true;
}

//call register settings function
add_action( 'admin_init', 'pep_register_settings' );

function remove_extra_image_sizes() {
    foreach ( get_intermediate_image_sizes() as $size ) {
        if ( !in_array( $size, array( 'thumbnail', 'medium', 'medium_large', 'large' ) ) ) {
            remove_image_size( $size );
            //add_image_size($size, 0, 0, false);
        }
    }
}
 

register_activation_hook(__FILE__, 'pep_activate');
register_deactivation_hook(__FILE__, 'pep_deactivate');
register_uninstall_hook(__FILE__, 'pep_uninstall');

?>
