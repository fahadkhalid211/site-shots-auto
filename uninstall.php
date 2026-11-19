<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
wp_clear_scheduled_hook( 'ssa_scheduled' );
wp_clear_scheduled_hook( 'ssa_cron_step' );
delete_option( 'ssa_settings' );
delete_option( 'ssa_queue' );
delete_option( 'ssa_index' );
$u   = wp_upload_dir();
$dir = trailingslashit( $u['basedir'] ) . 'site-shots/';
if ( is_dir( $dir ) ) {
	foreach ( (array) glob( $dir . '*' ) as $f ) {
		if ( is_file( $f ) ) {
			@unlink( $f );
		}
	}
	@rmdir( $dir );
}
