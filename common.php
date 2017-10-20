<?php
// common functions for Standard and Cloud plugins

// TODO: make sure to update timestamp field in table for image record
// TODO: port scan optimizations from core, and do the batch insert record stuff for more resiliant processing

function ewwwio_memory( $function ) {
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_debug' ) ) {
		global $ewww_memory;
//		$ewww_memory .= $function . ': ' . memory_get_usage(true) . "\n";
	}
}

if ( ! function_exists( 'boolval' ) ) {
	function boolval( $value ) {
		return (bool) $value;
	}
}

/**
 * Find out if set_time_limit() is allowed
 */
function ewww_image_optimizer_stl_check() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( ewww_image_optimizer_safemode_check() ) {
		return false;
	}
	if ( defined( 'EWWW_IMAGE_OPTIMIZER_DISABLE_STL' ) && EWWW_IMAGE_OPTIMIZER_DISABLE_STL ) {
		ewwwio_debug_message( 'stl disabled by user' );
		return false;
	}
	if ( function_exists( 'wp_is_ini_value_changeable' ) && ! wp_is_ini_value_changeable( 'max_execution_time' ) ) {
		ewwwio_debug_message( 'max_execution_time not configurable' );
		return false;
	}
	return ewww_image_optimizer_function_exists( 'set_time_limit' );
}

/**
 * Checks if a function is disabled or does not exist.
 *
 * @param string $function The name of a function to test.
 * @return bool True if the function is available, False if not.
 */
function ewww_image_optimizer_function_exists( $function ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( function_exists( 'ini_get' ) ) {
		$disabled = @ini_get( 'disable_functions' );
		ewwwio_debug_message( "disable_functions: $disabled" );
	}
	if ( extension_loaded( 'suhosin' ) && function_exists( 'ini_get' ) ) {
		$suhosin_disabled = @ini_get( 'suhosin.executor.func.blacklist' );
		ewwwio_debug_message( "suhosin_blacklist: $suhosin_disabled" );
		if ( ! empty( $suhosin_disabled ) ) {
			$suhosin_disabled = explode( ',', $suhosin_disabled );
			$suhosin_disabled = array_map( 'trim', $suhosin_disabled );
			$suhosin_disabled = array_map( 'strtolower', $suhosin_disabled );
			if ( function_exists( $function ) && ! in_array( $function, $suhosin_disabled ) ) {
				return true;
			}
			return false;
		}
	}
	return function_exists( $function );
}

// used to output debug messages to a logfile in the plugin folder in cases where output to the screen is a bad idea
function ewww_image_optimizer_debug_log() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	global $ewww_debug;
	if ( ! empty( $ewww_debug ) && ewww_image_optimizer_get_option( 'ewww_image_optimizer_debug' ) ) {
		$timestamp = date( 'y-m-d h:i:s.u' ) . "\n";
		if ( ! file_exists( EWWWIO_PATH . 'debug.log' ) ) {
			touch( EWWWIO_PATH . 'debug.log' );
		}
		$ewww_debug_log = str_replace( '<br>', "\n", $ewww_debug );
		file_put_contents( EWWWIO_PATH . 'debug.log', $timestamp . $ewww_debug_log, FILE_APPEND );
	}
	$ewww_debug = '';
	ewwwio_memory( __FUNCTION__ );
}

// check filesize, and prevent errors by ensuring file exists, and that the cache has been cleared
function ewww_image_optimizer_filesize( $file ) {
	if ( is_file( $file ) ) {
		// flush the cache for filesize
		clearstatcache();
		// find out the size of the new PNG file
		return filesize( $file );
	} else {
		return 0;
	}
}

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

function ewww_image_optimizer_cloud_key_sanitize( $key ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	$key = trim( $key );
	ewwwio_debug_message( print_r( $_REQUEST, true ) );
	if ( ewww_image_optimizer_cloud_verify( false, $key ) ) {
		ewwwio_debug_message( 'sanitize (verification) successful' );
		ewwwio_memory( __FUNCTION__ );
		ewww_image_optimizer_debug_log();
		return $key;
	} else {
		ewwwio_debug_message( 'sanitize (verification) failed' );
		ewwwio_memory( __FUNCTION__ );
		ewww_image_optimizer_debug_log();
		return '';
	}
}

// adds our version to the useragent for http requests
function ewww_image_optimizer_cloud_useragent() {
	return 'EWWWIO PHP/' . EWWW_IMAGE_OPTIMIZER_VERSION;
}

// submits the api key for verification
function ewww_image_optimizer_cloud_verify( $cache = true, $api_key = '' ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( empty( $api_key ) ) {
		$api_key = ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' );
	} elseif ( empty( $api_key ) && ! empty( $_POST['ewww_image_optimizer_cloud_key'] ) ) {
		$api_key = $_POST['ewww_image_optimizer_cloud_key'];
	}
	if ( empty( $api_key ) ) {
		ewwwio_debug_message( 'no api key' );
		if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_level' ) > 10 ) {
			update_option( 'ewww_image_optimizer_jpg_level', 10 );
		}
		if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_level' ) > 10 && ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_level' ) != 40 ) {
			update_option( 'ewww_image_optimizer_png_level', 10 );
		}
		if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_pdf_level' ) > 0 ) {
			update_option( 'ewww_image_optimizer_pdf_level', 0 );
		}
		return false;
	}
	$ewww_cloud_status = get_transient( 'ewww_image_optimizer_cloud_status' );
	if ( false && $cache && preg_match( '/great/', $ewww_cloud_status ) ) {
		ewwwio_debug_message( 'using cached verification' );
		return $ewww_cloud_status;
	}
		$result = ewww_image_optimizer_cloud_post_key( 'optimize.exactlywww.com', 'https', $api_key );
		if ( empty( $result->success ) ) {
			$result->throw_for_status( false );
			ewwwio_debug_message( "verification failed" );
		} elseif ( ! empty( $result->body ) && preg_match( '/(great|exceeded)/', $result->body ) ) {
			$verified = $result->body;
			ewwwio_debug_message( "verification success" );
		} else {
			ewwwio_debug_message( "verification failed" );
			ewwwio_debug_message( print_r( $result, true ) );
		}
	if ( empty( $verified ) ) {
		ewwwio_memory( __FUNCTION__ );
		return FALSE;
	} else {
		set_transient( 'ewww_image_optimizer_cloud_status', $verified, 3600 );
		if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_level' ) < 20 && ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_level' ) < 20 && ewww_image_optimizer_get_option( 'ewww_image_optimizer_gif_level' ) < 20 && ewww_image_optimizer_get_option( 'ewww_image_optimizer_pdf_level' ) == 0 ) {
			ewww_image_optimizer_cloud_enable();
		}
		ewwwio_debug_message( "verification body contents: " . $result->body );
		ewwwio_memory( __FUNCTION__ );
		return $verified;
	}
}

function ewww_image_optimizer_cloud_post_key( $ip, $transport, $key ) {
	$useragent = ewww_image_optimizer_cloud_useragent();
	$result = Requests::post( "$transport://$ip/verify/", array(), array( 'api_key' => $key ), array( 'timeout' => 5, 'useragent' => $useragent ) );
	return $result;
}

// checks the provided api key for quota information
function ewww_image_optimizer_cloud_quota() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	$api_key = ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' );
	$url = "https://optimize.exactlywww.com/quota/";
	$useragent = ewww_image_optimizer_cloud_useragent();
	$result = Requests::post( $url, array(), array( 'api_key' => $api_key ), array( 'timeout' => 5, 'useragent' => $useragent ) );
	/*$result = wp_remote_post( $url, array(
		'timeout' => 5,
		'sslverify' => false,
		'body' => array( 'api_key' => $api_key )
	) );*/
	if ( ! $result->success ) {
		$result->throw_for_status( false );
		ewwwio_debug_message( "quota request failed: $error_message" );
		ewwwio_memory( __FUNCTION__ );
		return '';
	} elseif ( ! empty( $result->body ) ) {
		ewwwio_debug_message( "quota data retrieved: " . $result->body );
		$quota = explode( ' ', $result->body );
		ewwwio_memory( __FUNCTION__ );
		if ( $quota[0] == 0 && $quota[1] > 0 ) {
			return esc_html( sprintf( _n( 'optimized %1$d images, usage will reset in %2$d day.', 'optimized %1$d images, usage will reset in %2$d days.', $quota[2], EWWW_IMAGE_OPTIMIZER_DOMAIN ), $quota[1], $quota[2] ) );
		} elseif ( $quota[0] == 0 && $quota[1] < 0 ) {
			return esc_html( sprintf( _n( '%1$d image credit remaining.', '%1$d image credits remaining.', abs( $quota[1] ), EWWW_IMAGE_OPTIMIZER_DOMAIN ), abs( $quota[1] ) ) );
		} elseif ( $quota[0] > 0 && $quota[1] < 0 ) {
			$real_quota = $quota[0] - $quota[1];
			return esc_html( sprintf( _n( '%1$d image credit remaining.', '%1$d image credits remaining.', $real_quota, EWWW_IMAGE_OPTIMIZER_DOMAIN ), $real_quota ) );
		} else {
			return esc_html( sprintf( _n( 'used %1$d of %2$d, usage will reset in %3$d day.', 'used %1$d of %2$d, usage will reset in %3$d days.', $quota[2], EWWW_IMAGE_OPTIMIZER_DOMAIN ), $quota[1], $quota[0], $quota[2] ) );
		}
	}
}

/* submits an image to the cloud optimizer and saves the optimized image to disk
 *
 * Returns an array of the $file, $results, $converted to tell us if an image changes formats, and the $original file if it did.
 *
 * @param   string $file		Full absolute path to the image file
 * @param   string $type		mimetype of $file
 * @param   boolean $convert		true says we want to attempt conversion of $file
 * @param   string $newfile		filename of new converted image
 * @param   string $newtype		mimetype of $newfile
 * @param   boolean $fullsize		is this the full-size original?
 * @param   array $jpg_params		r, g, b values and jpg quality setting for conversion
 * @returns array
*/
function ewww_image_optimizer_cloud_optimizer( $file, $type, $convert = false, $newfile = null, $newtype = null, $fullsize = false, $jpg_params = array( 'r' => '255', 'g' => '255', 'b' => '255', 'quality' => null ) ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
//	$ewww_cloud_ip = get_transient( 'ewww_image_optimizer_cloud_ip' );
//	$ewww_cloud_transport = get_transient( 'ewww_image_optimizer_cloud_transport' );
	$ewww_status = get_transient( 'ewww_image_optimizer_cloud_status' );
	$started = microtime( true );
	if ( preg_match( '/exceeded/', $ewww_status ) ) {
		if ( ! ewww_image_optimizer_cloud_verify() ) {
			return array( $file, false, 'key verification failed', 0 );
/*		} else {
			$ewww_cloud_ip = get_transient( 'ewww_image_optimizer_cloud_ip' );
			$ewww_cloud_transport = get_transient( 'ewww_image_optimizer_cloud_transport' );*/
		}
	}
	EWWWIO_CLI::line( ewww_image_optimizer_cloud_quota() );
	// calculate how much time has elapsed since we started
	$elapsed = microtime( true ) - $started;
	// output how much time has elapsed since we started
	ewwwio_debug_message( sprintf( 'Cloud verify took %.3f seconds', $elapsed ) );
	$ewww_status = get_transient( 'ewww_image_optimizer_cloud_status' );
	if ( ! empty ( $ewww_status ) && preg_match( '/exceeded/', $ewww_status ) ) {
		ewwwio_debug_message( 'license exceeded, image not processed' );
		return array($file, false, 'exceeded', 0);
	}
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_metadata_skip_full' ) && $fullsize ) {
		$metadata = 1;
	} elseif ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_remove_meta' ) ){
        	// don't copy metadata
                $metadata = 0;
        } else {
                // copy all the metadata
                $metadata = 1;
        }
	if ( empty( $convert ) ) {
		$convert = 0;
	} else {
		$convert = 1;
	}
	$lossy_fast = 0;
	if ( ewww_image_optimizer_get_option('ewww_image_optimizer_lossy_skip_full') && $fullsize ) {
		$lossy = 0;
	} elseif ( $type == 'image/png' && ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_level' ) >= 40 ) {
		$lossy = 1;
		if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_level' ) == 40 ) {
			$lossy_fast = 1;
		}
	} elseif ( $type == 'image/jpeg' && ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_level' ) >= 30 ) {
		$lossy = 1;
		if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_level' ) == 30 ) {
			$lossy_fast = 1;
		}
	} elseif ( $type == 'application/pdf' && ewww_image_optimizer_get_option( 'ewww_image_optimizer_pdf_level' ) == 20 ) {
		$lossy = 1;
	} else {
		$lossy = 0;
	}
	if ( $newtype == 'image/webp' ) {
		$webp = 1;
	} else {
		$webp = 0;
	}
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_level' ) == 30 ) {
		$png_compress = 1;
	} else {
		$png_compress = 0;
	}
	ewwwio_debug_message( "file: $file " );
	ewwwio_debug_message( "type: $type" );
	ewwwio_debug_message( "convert: $convert" );
	ewwwio_debug_message( "newfile: $newfile" );
	ewwwio_debug_message( "newtype: $newtype" );
	ewwwio_debug_message( "webp: $webp" );
	ewwwio_debug_message( "jpg_params: " . print_r($jpg_params, true) );
	$api_key = ewww_image_optimizer_get_option('ewww_image_optimizer_cloud_key');
	$url = "https://optimize.exactlywww.com/";
	$boundary = generate_password( 24 );

	$useragent = ewww_image_optimizer_cloud_useragent();
	$headers = array(
        	'content-type' => 'multipart/form-data; boundary=' . $boundary,
//		'timeout' => 90,
//		'httpversion' => '1.0',
//		'blocking' => true
		);
	$post_fields = array(
		'filename' => $file,
		'convert' => $convert,
		'metadata' => $metadata,
		'api_key' => $api_key,
		'red' => $jpg_params['r'],
		'green' => $jpg_params['g'],
		'blue' => $jpg_params['b'],
		'quality' => $jpg_params['quality'],
		'compress' => $png_compress,
		'lossy' => $lossy,
		'lossy_fast' => $lossy_fast,
		'webp' => $webp,
	);

	$payload = '';
	foreach ($post_fields as $name => $value) {
        	$payload .= '--' . $boundary;
	        $payload .= "\r\n";
	        $payload .= 'Content-Disposition: form-data; name="' . $name .'"' . "\r\n\r\n";
	        $payload .= $value;
	        $payload .= "\r\n";
	}

	$payload .= '--' . $boundary;
	$payload .= "\r\n";
	$payload .= 'Content-Disposition: form-data; name="file"; filename="' . basename($file) . '"' . "\r\n";
	$payload .= 'Content-Type: ' . $type . "\r\n";
	$payload .= "\r\n";
	$payload .= file_get_contents($file);
	$payload .= "\r\n";
	$payload .= '--' . $boundary;
	$payload .= 'Content-Disposition: form-data; name="submitHandler"' . "\r\n";
	$payload .= "\r\n";
	$payload .= "Upload\r\n";
	$payload .= '--' . $boundary . '--';

	// retrieve the time when the optimizer starts
//	$started = microtime(true);
	$response = Requests::post(
		$url,
		$headers,
		$payload,
		array(
			'timeout' => 90,
			'useragent' => $useragent,
		)
	);
/*	$response = wp_remote_post( $url, array(
		'timeout' => 90,
		'headers' => $headers,
		'sslverify' => false,
		'body' => $payload,
		) );*/
//	$elapsed = microtime(true) - $started;
//	$ewww_debug .= "processing image via cloud took $elapsed seconds<br>";
	if ( ! $response->success ) {
		$response->throw_for_status( false );
		ewwwio_debug_message( "optimize failed, see exception" );
		return array( $file, false, 'cloud optimize failed', 0 );
	} else {
		$tempfile = $file . ".tmp";
		file_put_contents( $tempfile, $response->body );
		$orig_size = filesize( $file );
		$newsize = $orig_size;
		$converted = false;
		$msg = '';
		if ( preg_match( '/exceeded/', $response->body ) ) {
			ewwwio_debug_message( 'License Exceeded' );
			set_transient( 'ewww_image_optimizer_cloud_status', 'exceeded', 3600 );
			$msg = 'exceeded';
			unlink( $tempfile );
		} elseif ( ewww_image_optimizer_mimetype( $tempfile, 'i' ) == $type ) {
			$newsize = filesize( $tempfile );
			ewwwio_debug_message( "cloud results: $newsize (new) vs. $orig_size (original)" );
			rename( $tempfile, $file );
		} elseif ( ewww_image_optimizer_mimetype( $tempfile, 'i' ) == 'image/webp' ) {
			$newsize = filesize( $tempfile );
			ewwwio_debug_message( "cloud results: $newsize (new) vs. $orig_size (original)" );
			rename( $tempfile, $newfile );
		} elseif ( ewww_image_optimizer_mimetype( $tempfile, 'i' ) == $newtype ) {
			$converted = true;
			$newsize = filesize( $tempfile );
			ewwwio_debug_message( "cloud results: $newsize (new) vs. $orig_size (original)" );
			rename( $tempfile, $newfile );
			$file = $newfile;
		} else {
			unlink( $tempfile );
		}
		ewwwio_memory( __FUNCTION__ );
		return array( $file, $converted, $msg, $newsize );
	}
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

// takes a human-readable size, and generates an approximate byte-size
function ewww_image_optimizer_size_unformat( $formatted ) {
	$size_parts = explode( '&nbsp;', $formatted );
	switch ( $size_parts[1] ) {
		case 'B':
			return intval( $size_parts[0] );
		case 'kB':
			return intval( $size_parts[0] * 1024 );
		case 'MB':
			return intval( $size_parts[0] * 1048576 );
		case 'GB':
			return intval( $size_parts[0] * 1073741824 );
		case 'TB':
			return intval( $size_parts[0] * 1099511627776 );
		default:
			return 0;
	}
}

// test mimetype based on file extension instead of file contents
// only use for places where speed outweighs accuracy
function ewww_image_optimizer_quick_mimetype( $path ) {
	$pathextension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	switch ( $pathextension ) {
		case 'jpg':
		case 'jpeg':
		case 'jpe':
			return 'image/jpeg';
		case 'png':
			return 'image/png';
		case 'gif':
			return 'image/gif';
		case 'pdf':
			return 'application/pdf';
		default:
			return false;
	}
}

// make sure an array/object can be parsed by a foreach()
function ewww_image_optimizer_iterable( $var ) {
	return ! empty( $var ) && ( is_array( $var ) || is_object( $var ) );
}

function ewwwio_debug_message( $message ) {
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_debug' ) ) {
		global $ewww_debug;
		$ewww_debug .= "$message<br>";
		echo $message . "\n";
	}
}

/**
 * Adds version information to the in-memory debug log.
 *
 * @global string $ewww_debug The in-memory debug log.
 * @global int $wp_version
 */
function ewwwio_debug_version_info() {
	global $ewww_debug;
	if ( ! extension_loaded( 'suhosin' ) && function_exists( 'get_current_user' ) ) {
		$ewww_debug .= get_current_user() . '<br>';
	}

	$ewww_debug .= 'EWWW IO version: ' . EWWW_IMAGE_OPTIMIZER_VERSION . '<br>';

	if ( defined( 'PHP_VERSION_ID' ) ) {
		$ewww_debug .= 'PHP version: ' . PHP_VERSION_ID . '<br>';
	}
}


function ewwwio_memory_output() {
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_debug' ) ) {
		global $ewww_memory;
		$timestamp = date('y-m-d h:i:s.u') . "  ";
		if (!file_exists(EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'memory.log'))
			touch(EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'memory.log');
		file_put_contents(EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'memory.log', $timestamp . $ewww_memory, FILE_APPEND);
	}
}

// EWWW replacements

function trailingslashit( $string ) {
	return untrailingslashit( $string ) . '/';
}

function untrailingslashit( $string ) {
	return rtrim( $string, '/\\' );
}

function size_format( $bytes, $decimals = 0 ) {
    $quant = array(
        'TB' => 1024 * 1024 * 1024 * 1024,
        'GB' => 1024 * 1024 * 1024,
        'MB' => 1024 * 1024,
        'KB' => 1024,
        'B'  => 1,
    );

    if ( 0 === $bytes ) {
        return number_format_i18n( 0, $decimals ) . ' B';
    }

    foreach ( $quant as $unit => $mag ) {
        if ( doubleval( $bytes ) >= $mag ) {
            return number_format_i18n( $bytes / $mag, $decimals ) . ' ' . $unit;
        }
    }

    return false;
}

function number_format_i18n( $number, $decimals = 0 ) {
    $formatted = number_format( $number, absint( $decimals ) );
    return $formatted;
}

function absint( $maybeint ) {
	return abs( intval( $maybeint ) );
}

// Basically just used to generate random strings.
function generate_password( $length = 12 ) {
	$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	$password = '';
	for ( $i = 0; $i < $length; $i++ ) {
		$password .= substr( $chars, rand( 0, strlen( $chars ) - 1 ), 1 );
	}
	return $password;
}
?>
