<?php
// functions for WP plugins that haven't been needed or used in the PHP library yet (left-overs).

// Scan a folder for images and return them as an array.
function ewww_image_optimizer_image_scan( $dir ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	$images = array();
	if ( ! is_dir( $dir ) ) {
		return $images;
	}
	ewwwio_debug_message( "scanning folder for images: $dir" );
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ), RecursiveIteratorIterator::CHILD_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD );
	$start = microtime( true );
	$file_counter = 0;
	if ( ewww_image_optimizer_stl_check() ) {
		set_time_limit( 0 );
	}
	foreach ( $iterator as $file ) {
		$file_counter++;
		if ( $file->isFile() ) {
			$path = $file->getPathname();
			if ( preg_match( '/(\/|\\\\)\./', $path ) ) {
				continue;
			}
			if ( ! ewww_image_optimizer_quick_mimetype( $path ) ) {
				continue;
			}
			ewwwio_debug_message( "queued $path" );
			$images[] = $path;
		}
		ewww_image_optimizer_debug_log();
	}
	$end = microtime( true ) - $start;
        ewwwio_debug_message( "query time for $file_counter files (seconds): $end" );
	ewwwio_memory( __FUNCTION__ );
	return $images;
}

// called to process each image in the loop for images outside of media library
function ewww_image_optimizer_aux_images_loop( $attachment = null, $auto = false ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	global $ewww_defer;
	$ewww_defer = false;
	$output = array();
	// verify that an authorized user has started the optimizer
	$permissions = apply_filters( 'ewww_image_optimizer_bulk_permissions', '' );
	if ( ! $auto && ( empty( $_REQUEST['ewww_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['ewww_wpnonce'], 'ewww-image-optimizer-bulk' ) || ! current_user_can( $permissions ) ) ) {
		$output['error'] = esc_html__( 'Access token has expired, please reload the page.', EWWW_IMAGE_OPTIMIZER_DOMAIN );
		echo json_encode( $output );
		die();
	}
	session_write_close();
	if ( ! empty( $_REQUEST['ewww_wpnonce'] ) ) {
		// find out if our nonce is on it's last leg/tick
		$tick = wp_verify_nonce( $_REQUEST['ewww_wpnonce'], 'ewww-image-optimizer-bulk' );
		if ( $tick === 2 ) {
			ewwwio_debug_message( 'nonce on its last leg' );
			$output['new_nonce'] = wp_create_nonce( 'ewww-image-optimizer-bulk' );
		} else {
			ewwwio_debug_message( 'nonce still alive and kicking' );
			$output['new_nonce'] = '';
		}
	}
	// retrieve the time when the optimizer starts
	$started = microtime( true );
	// get the 'aux attachments' with a list of attachments remaining
	$attachments = get_option( 'ewww_image_optimizer_aux_attachments' );
	if ( empty( $attachment ) ) {
		$attachment = array_shift( $attachments );
	}
	// do the optimization for the current image
	$results = ewww_image_optimizer( $attachment );
	//global $ewww_exceed;
	$ewww_status = get_transient( 'ewww_image_optimizer_cloud_status' );
	if ( ! empty ( $ewww_status ) && preg_match( '/exceeded/', $ewww_status ) ) {
		if ( ! $auto ) {
			$output['error'] = esc_html__( 'License Exceeded', EWWW_IMAGE_OPTIMIZER_DOMAIN );
			echo json_encode( $output );
		}
		die();
	}
	// store the updated list of attachment IDs back in the 'bulk_attachments' option
	update_option( 'ewww_image_optimizer_aux_attachments', $attachments, false );
	if ( ! $auto ) {
		// output the path
		$output['results'] = sprintf( "<p>" . esc_html__( 'Optimized', EWWW_IMAGE_OPTIMIZER_DOMAIN ) . " <strong>%s</strong><br>", esc_html( $attachment ) );
		// tell the user what the results were for the original image
		$output['results'] .= sprintf( "%s<br>", $results[1] );
		// calculate how much time has elapsed since we started
		$elapsed = microtime( true ) - $started;
		// output how much time has elapsed since we started
		$output['results'] .= sprintf( esc_html__( 'Elapsed: %.3f seconds', EWWW_IMAGE_OPTIMIZER_DOMAIN ) . "</p>", $elapsed );
		if ( get_site_option( 'ewww_image_optimizer_debug' ) ) {
			global $ewww_debug;
			$output['results'] .= '<div style="background-color:#ffff99;">' . $ewww_debug . '</div>';
		}
		if ( ! empty( $attachments ) ) {
			$next_file = array_shift( $attachments );
			$loading_image = plugins_url( '/images/wpspin.gif', __FILE__ );
			$output['next_file'] = "<p>" . esc_html__( 'Optimizing', EWWW_IMAGE_OPTIMIZER_DOMAIN ) . " <b>$next_file</b>&nbsp;<img src='$loading_image' alt='loading'/></p>";
		}
		echo json_encode( $output );
		ewwwio_memory( __FUNCTION__ );
		die();
	}
	ewwwio_memory( __FUNCTION__ );
}

function ewwwio_memory_output() {
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_debug' ) ) {
		global $ewww_memory;
		$timestamp = date( 'y-m-d h:i:s.u' ) . '  ';
		if ( ! file_exists( EWWWIO_PATH . 'memory.log' ) ) {
			touch( EWWWIO_PATH . 'memory.log' );
		}
		file_put_contents( EWWWIO_PATH . 'memory.log', $timestamp . $ewww_memory, FILE_APPEND );
	}
}
?>
