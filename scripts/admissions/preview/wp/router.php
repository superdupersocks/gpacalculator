<?php
// php -S router for the preview WordPress: real files are served as they are, everything else goes to WordPress.
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( '/' !== $path && is_file( $_SERVER['DOCUMENT_ROOT'] . $path ) && ! preg_match( '/\.php$/', $path ) ) {
	return false;
}
if ( preg_match( '#^/wp-admin/admin-ajax\.php$#', $path ) ) {
	require $_SERVER['DOCUMENT_ROOT'] . '/wp-admin/admin-ajax.php';
	return true;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
