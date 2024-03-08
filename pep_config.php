<?php

// need to include the blog header and media php


if(file_exists(stream_resolve_include_path('/www/neoskosmos_125/public/web/wp/wp-blog-header.php'))){
   require_once('/www/neoskosmos_125/public/web/wp/wp-blog-header.php');
}
if(file_exists(stream_resolve_include_path('/www/neoskosmos_125/public/web/wp/wp-includes/media.php'))){
	require_once('/www/neoskosmos_125/public/web/wp/wp-includes/media.php');
}

if(file_exists(stream_resolve_include_path('../../../wp-blog-header.php'))){
	require_once('../../../wp-blog-header.php');
}

if(file_exists(stream_resolve_include_path('../../../wp-includes/media.php'))){
	require_once('../../../wp-includes/media.php');
}

if(file_exists(stream_resolve_include_path(ABSPATH.'wp-blog-header.php'))){
	require_once(ABSPATH.'wp-blog-header.php');
}

if(file_exists(stream_resolve_include_path(ABSPATH.'wp-includes/media.php'))){
	require_once(ABSPATH.'wp-includes/media.php');
}




// the ftp root directory where all files are uploaded to
define( 'FTP_ROOT', $_SERVER['DOCUMENT_ROOT'].'/cms/ads');

?>