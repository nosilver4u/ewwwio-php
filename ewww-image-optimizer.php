<?php

if ( ! defined( 'EWWWIO_PATH' ) ) {
// this is the full system path to the plugin folder
define( 'EWWWIO_PATH', dirname( __file__ ) . '/' );
}
if ( file_exists( EWWWIO_PATH . 'config.php' ) ) {
	include_once( EWWWIO_PATH . 'config.php' );
}

require( ABSPATH . 'classes/Requests/library/Requests.php' );
Requests::register_autoloader();

require( EWWWIO_PATH . 'common.php' );
if ( defined( 'DB_NAME' ) && DB_NAME ) {
	require( EWWWIO_PATH . 'mysql-db.php' );
} else {
	require( EWWWIO_PATH . 'sqlite3-db.php' );
}
//require( ABSPATH . 'iocli.php' );
//require( ABSPATH . 'silo.php' );

// check to see if the cloud constant is defined (which would mean we've already run init) and then set it properly if not
function ewww_image_optimizer_cloud_init() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( ! defined( 'EWWW_IMAGE_OPTIMIZER_CLOUD' ) ) {
		define( 'EWWW_IMAGE_OPTIMIZER_CLOUD', TRUE );
	}
	ewwwio_memory( __FUNCTION__ );
}

// set some default option values
function ewww_image_optimizer_set_defaults() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	add_option( 'ewww_image_optimizer_remove_meta', true );
	add_option( 'ewww_image_optimizer_jpg_level', '30' );
	add_option( 'ewww_image_optimizer_png_level', '20' );
	add_option( 'ewww_image_optimizer_gif_level', '10' );
	add_option( 'ewww_image_optimizer_pdf_level', '10' );
}

// check the mimetype of the given file ($path) with various methods
// valid values for $type are 'b' for binary or 'i' for image
function ewww_image_optimizer_mimetype( $path, $case ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	ewwwio_debug_message( "testing mimetype: $path" );
	$type = false;
	if ( 'i' === $case && preg_match( '/^RIFF.+WEBPVP8/', file_get_contents( $path, null, null, 0, 16 ) ) ) {
		return 'image/webp';
	}
	if ( $case === 'i' && strpos( $path, 's3' ) === 0 ) {
		$pathextension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		switch ( $pathextension ) {
			case 'jpg':
			case 'jpeg':
			case 'jpe':
				ewwwio_debug_message( 's3 type: image/jpeg' );
				return 'image/jpeg';
			case 'png':
				ewwwio_debug_message( 's3 type: image/png' );
				return 'image/png';
			case 'gif':
				ewwwio_debug_message( 's3 type: image/gif' );
				return 'image/gif';
			case 'pdf':
				ewwwio_debug_message( 's3 type: application/pdf' );
				return 'application/pdf';
		}
	}
	if ( function_exists( 'finfo_file' ) && defined( 'FILEINFO_MIME' ) ) {
		// create a finfo resource
		$finfo = finfo_open( FILEINFO_MIME );
		// retrieve the mimetype
		$type = explode( ';', finfo_file( $finfo, $path ) );
		$type = $type[0];
		finfo_close( $finfo );
		ewwwio_debug_message( "finfo_file: $type" );
	}
	// see if we can use the getimagesize function
	if ( empty( $type ) && function_exists( 'getimagesize' ) && $case === 'i' ) {
		// run getimagesize on the file
		$type = getimagesize($path);
		// make sure we have results
		if(false !== $type){
			// store the mime-type
			$type = $type['mime'];
		}
		ewwwio_debug_message( "getimagesize: $type" );
	}
	// see if we can use mime_content_type
	if ( empty( $type ) && function_exists( 'mime_content_type' ) ) {
		// retrieve and store the mime-type
		$type = mime_content_type( $path );
		ewwwio_debug_message( "mime_content_type: $type" );
	}
	// if nothing else has worked, try the 'file' command
	if ( ( empty( $type ) || $type != 'application/x-executable' ) && $case === 'b' ) {
		// find the 'file' command
		if ( $file = ewww_image_optimizer_find_nix_binary( 'file', 'f' ) ) {
			// run 'file' on the file in question
			exec( "$file $path", $filetype );
			ewwwio_debug_message( "file command: {$filetype[0]}" );
			// if we've found a proper binary
			if ( ( strpos( $filetype[0], 'ELF' ) && strpos( $filetype[0], 'executable' ) ) || strpos( $filetype[0], 'Mach-O universal binary' ) ) {
				$type = 'application/x-executable';
			}
		}
	}
	// if we are dealing with a binary, and found an executable
	if ( $case === 'b' && preg_match( '/executable|octet-stream/', $type ) ) {
		ewwwio_memory( __FUNCTION__ );
		return $type;
	// otherwise, if we are dealing with an image
	} elseif ( $case == 'i' ) {
		ewwwio_memory( __FUNCTION__ );
		return $type;
	// if all else fails, bail
	} else {
		ewwwio_debug_message( 'no mime functions or not a binary' );
		ewwwio_memory( __FUNCTION__ );
		return false;
	}
}

// escape any spaces in the filename, not sure any more than that is necessary for unixy systems
function ewww_image_optimizer_escapeshellcmd( $path ) {
	return ( preg_replace( '/ /', '\ ', $path ) );
}

// test the given path ($path) to see if it returns a valid version string
// returns: version string if found, FALSE if not
function ewww_image_optimizer_tool_found( $path, $tool ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	ewwwio_debug_message( "testing case: $tool at $path" );
	// '*b' cases are 'blind' testing in case we can't get at the version string, but the binaries are actually working, we run a test compression, and compare the results with what they should be
	switch( $tool ) {
		case 'j': // jpegtran
			exec( $path . ' -v ' . EWWW_IMAGE_OPTIMIZER_IMAGES_PATH . 'sample.jpg 2>&1', $jpegtran_version );
			if ( ! empty( $jpegtran_version ) ) ewwwio_debug_message( "$path: {$jpegtran_version[0]}" );
			foreach ( $jpegtran_version as $jout ) {
				if ( preg_match( '/Independent JPEG Group/', $jout ) ) {
					ewwwio_debug_message( 'optimizer found' );
					return $jout;
				}
			}
			break;
		case 'jb':
			$upload_dir = wp_upload_dir();
			$testjpg = trailingslashit( $upload_dir['basedir'] ) . 'testopti.jpg';
			exec( $path . ' -copy none -optimize -outfile ' . ewww_image_optimizer_escapeshellarg( $testjpg ) . ' ' . ewww_image_optimizer_escapeshellarg( EWWW_IMAGE_OPTIMIZER_IMAGES_PATH . 'testorig.jpg' ) );
			$testjpgsize = ewww_image_optimizer_filesize( $testjpg );
			ewwwio_debug_message( "blind testing jpegtran, is $testjpgsize smaller than 5700?" );
			if ( $testjpgsize ) {
				unlink( $testjpg );
			}
			if ( 0 < $testjpgsize && $testjpgsize < 5700 ) {
				ewwwio_debug_message( 'optimizer found' );
				return esc_html__( 'unknown', EWWW_IMAGE_OPTIMIZER_DOMAIN ) ;
			}
			break;
		case 'o': // optipng
			exec( $path . ' -v 2>&1', $optipng_version );
			if ( ! empty( $optipng_version ) ) ewwwio_debug_message( "$path: {$optipng_version[0]}" );
			if ( ! empty( $optipng_version ) && strpos( $optipng_version[0], 'OptiPNG' ) === 0 ) {
				ewwwio_debug_message( 'optimizer found' );
				return $optipng_version[0];
			}
			break;
		case 'ob':
			$upload_dir = wp_upload_dir();
			$testpng = trailingslashit( $upload_dir['basedir'] ) . 'testopti.png';
			exec( $path . ' -out ' . ewww_image_optimizer_escapeshellarg( $testpng ) . ' -o1 -quiet -strip all ' . ewww_image_optimizer_escapeshellarg( EWWW_IMAGE_OPTIMIZER_IMAGES_PATH . 'testorig.png' ) );
			$testpngsize = ewww_image_optimizer_filesize( $testpng );
			ewwwio_debug_message( "blind testing optipng, is $testpngsize smaller than 110?" );
			if ( $testpngsize ) {
				unlink( $testpng );
			}
			if ( 0 < $testpngsize && $testpngsize < 110 ) {
				ewwwio_debug_message( 'optimizer found' );
				return esc_html__( 'unknown', EWWW_IMAGE_OPTIMIZER_DOMAIN ) ;
			}
			break;
		case 'g': // gifsicle
			exec( $path . ' --version 2>&1', $gifsicle_version );
			if ( ! empty( $gifsicle_version ) ) ewwwio_debug_message( "$path: {$gifsicle_version[0]}" );
			if ( ! empty( $gifsicle_version ) && strpos( $gifsicle_version[0], 'LCDF Gifsicle' ) === 0 ) {
				ewwwio_debug_message( 'optimizer found' );
				return $gifsicle_version[0];
			}
			break;
		case 'gb':
			$upload_dir = wp_upload_dir();
			$testgif = trailingslashit( $upload_dir['basedir'] ) . 'testopti.gif';
			exec( $path . ' -O3 -o ' . ewww_image_optimizer_escapeshellarg( $testgif ) . ' ' . ewww_image_optimizer_escapeshellarg( EWWW_IMAGE_OPTIMIZER_IMAGES_PATH . 'testorig.gif' ) );
			$testgifsize = ewww_image_optimizer_filesize( $testgif );
			ewwwio_debug_message( "blind testing gifsicle, is $testgifsize smaller than 12000?" );
			if ( $testgifsize ) {
				unlink( $testgif );
			}
			if ( 0 < $testgifsize && $testgifsize < 12000 ) {
				ewwwio_debug_message( 'optimizer found' );
				return esc_html__( 'unknown', EWWW_IMAGE_OPTIMIZER_DOMAIN ) ;
			}
			break;
		case 'p': // pngout
			exec( "$path 2>&1", $pngout_version );
			if ( ! empty( $pngout_version ) ) ewwwio_debug_message( "$path: {$pngout_version[0]}" );
			if ( ! empty( $pngout_version ) && strpos( $pngout_version[0], 'PNGOUT' ) === 0 ) {
				ewwwio_debug_message( 'optimizer found' );
				return $pngout_version[0];
			}
			break;
		case 'pb':
			$upload_dir = wp_upload_dir();
			$testpng = trailingslashit( $upload_dir['basedir'] ) . 'testopti.png';
			exec( $path . ' -s3 -q ' . ewww_image_optimizer_escapeshellarg( EWWW_IMAGE_OPTIMIZER_IMAGES_PATH . 'testorig.png' ) . ' ' . ewww_image_optimizer_escapeshellarg( $testpng ) );
			$testpngsize = ewww_image_optimizer_filesize( $testpng );
			ewwwio_debug_message( "blind testing pngout, is $testpngsize smaller than 110?" );
			if ( $testpngsize ) {
				unlink( $testpng );
			}
			if ( 0 < $testpngsize && $testpngsize < 110 ) {
				ewwwio_debug_message( 'optimizer found' );
				return esc_html__( 'unknown', EWWW_IMAGE_OPTIMIZER_DOMAIN ) ;
			}
			break;
		case 'q': // pngquant
			exec( $path . ' -V 2>&1', $pngquant_version );
			if ( ! empty( $pngquant_version ) ) ewwwio_debug_message( "$path: {$pngquant_version[0]}" );
			if ( ! empty( $pngquant_version ) && substr( $pngquant_version[0], 0, 3 ) >= 2.0 ) {
				ewwwio_debug_message( 'optimizer found' );
				return $pngquant_version[0];
			}
			break;
		case 'qb':
			$upload_dir = wp_upload_dir();
			$testpng = trailingslashit( $upload_dir['basedir'] ) . 'testopti.png';
			exec( $path . ' -o ' . ewww_image_optimizer_escapeshellarg( $testpng ) . ' ' . ewww_image_optimizer_escapeshellarg( EWWW_IMAGE_OPTIMIZER_IMAGES_PATH . 'testorig.png' ) );
			$testpngsize = ewww_image_optimizer_filesize( $testpng );
			ewwwio_debug_message( "blind testing pngquant, is $testpngsize smaller than 114?" );
			if ( $testpngsize ) {
				unlink( $testpng );
			}
			if ( 0 < $testpngsize && $testpngsize < 114 ) {
				ewwwio_debug_message( 'optimizer found' );
				return esc_html__( 'unknown', EWWW_IMAGE_OPTIMIZER_DOMAIN ) ;
			}
			break;
		case 'i': // ImageMagick
			exec("$path -version 2>&1", $convert_version);
			if (!empty($convert_version)) ewwwio_debug_message( "$path: {$convert_version[0]}" );
			if (!empty($convert_version) && strpos($convert_version[0], 'ImageMagick')) {
				ewwwio_debug_message( 'imagemagick found' );
				return $convert_version[0];
			}
			break;
		case 'f': // file
			exec("$path -v 2>&1", $file_version);
			if (!empty($file_version[1])) ewwwio_debug_message( "$path: {$file_version[1]}" );
			if (!empty($file_version[1]) && preg_match('/magic/', $file_version[1])) {
				ewwwio_debug_message( 'file binary found' );
				return $file_version[0];
			} elseif (!empty($file_version[1]) && preg_match('/usage: file/', $file_version[1])) {
				ewwwio_debug_message( 'file binary found' );
				return $file_version[0];
			}
			break;
		case 'n': // nice
			exec("$path 2>&1", $nice_output);
			if ( is_array( $nice_output ) && isset( $nice_output ) ) ewwwio_debug_message( "$path: {$nice_output[0]}" );
			if ( is_array( $nice_output ) && isset( $nice_output ) && preg_match( '/usage/', $nice_output[0] ) ) {
				ewwwio_debug_message( 'nice found' );
				return TRUE;
			} elseif ( is_array( $nice_output ) && isset( $nice_output ) && preg_match( '/^\d+$/', $nice_output[0] ) ) {
				ewwwio_debug_message( 'nice found' );
				return TRUE;
			}
			break;
		case 't': // tar
			exec("$path --version 2>&1", $tar_version);
			if (!empty($tar_version[0])) ewwwio_debug_message( "$path: {$tar_version[0]}" );
			if (!empty($tar_version[0]) && preg_match('/bsdtar/', $tar_version[0])) {
				ewwwio_debug_message( 'tar found' );
				return $tar_version[0];
			} elseif (!empty($tar_version[0]) && preg_match('/GNU tar/i', $tar_version[0])) {
				ewwwio_debug_message( 'tar found' );
				return $tar_version[0];
			}
			break;
		case 'w': //cwebp
			exec( "$path -version 2>&1", $webp_version );
			if ( ! empty( $webp_version ) ) ewwwio_debug_message( "$path: {$webp_version[0]}" );
			if ( ! empty( $webp_version ) && preg_match( '/0.\d.\d/', $webp_version[0] ) ) {
				ewwwio_debug_message( 'optimizer found' );
				return $webp_version[0];
			}
			break;
		case 'wb':
			$upload_dir = wp_upload_dir();
			$testpng = trailingslashit( $upload_dir['basedir'] ) . 'testopti.png';
			exec( $path . ' -lossless -quiet ' . ewww_image_optimizer_escapeshellarg( EWWW_IMAGE_OPTIMIZER_IMAGES_PATH . 'testorig.png' ) . ' -o ' . ewww_image_optimizer_escapeshellarg( $testpng ) );
			$testpngsize = ewww_image_optimizer_filesize( $testpng );
			ewwwio_debug_message( "blind testing cwebp, is $testpngsize smaller than 114?" );
			if ( $testpngsize ) {
				unlink( $testpng );
			}
			if ( 0 < $testpngsize && $testpngsize < 114 ) {
				ewwwio_debug_message( 'optimizer found' );
				return esc_html__( 'unknown', EWWW_IMAGE_OPTIMIZER_DOMAIN ) ;
			}
			break;
	}
	ewwwio_debug_message( 'tool not found' );
	ewwwio_memory( __FUNCTION__ );
	return FALSE;
}

// searches for the given $binary on a Windows system and passes along the $switch
function ewww_image_optimizer_find_win_binary( $binary, $switch ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( empty( $binary ) || empty( $switch ) ) {
		return '';
	}
	$use_system = ewww_image_optimizer_get_option( 'ewww_image_optimizer_skip_bundle' );
	if ( file_exists( EWWW_IMAGE_OPTIMIZER_TOOL_PATH . $binary . '.exe' ) && ! $use_system ) {
		$binary_path = EWWW_IMAGE_OPTIMIZER_TOOL_PATH . $binary . '.exe';
		ewwwio_debug_message( "found $binary_path, testing..." );
		if ( ewww_image_optimizer_md5check( $binary_path ) && ewww_image_optimizer_tool_found( '"' . $binary_path . '"', $switch ) ) {
			return '"' . $binary_path . '"';
		}
	}
	if ( file_exists( EWWW_IMAGE_OPTIMIZER_TOOL_PATH . $binary . '-custom.exe' ) && ! $use_system ) {
		$binary_path = EWWW_IMAGE_OPTIMIZER_TOOL_PATH . $binary . '-custom.exe';
		ewwwio_debug_message( "found $binary_path, testing..." );
		if ( ewww_image_optimizer_tool_found( '"' . $binary_path . '"', $switch ) ) {
			return '"' . $binary_path . '"';
		}
	}
	if ( file_exists( EWWW_IMAGE_OPTIMIZER_TOOL_PATH . $binary . '-alt.exe' ) && ! $use_system ) {
		$binary_path = EWWW_IMAGE_OPTIMIZER_TOOL_PATH . $binary . '-alt.exe';
		ewwwio_debug_message( "found $binary_path, testing..." );
		if ( ewww_image_optimizer_tool_found( '"' . $binary_path . '"', $switch ) ) {
			return '"' . $binary_path . '"';
		}
	}
	// if we still haven't found a usable binary, try a system-installed version
	if ( ewww_image_optimizer_tool_found( $binary . '.exe', $switch ) ) {
		return $binary . '.exe';
	} else {
		return '';
	}
}

// searches for the given $binary on a *nix system and passes along the $switch
function ewww_image_optimizer_find_nix_binary( $binary, $switch ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( empty( $binary ) || empty( $switch ) ) {
		return '';
	}
	$use_system = ewww_image_optimizer_get_option( 'ewww_image_optimizer_skip_bundle' );
	// first check for the binary in the ewww tool folder
	if ( file_exists( EWWW_IMAGE_OPTIMIZER_TOOL_PATH . $binary ) && ! $use_system ) {
		$binary_path = EWWW_IMAGE_OPTIMIZER_TOOL_PATH . $binary;
		ewwwio_debug_message( "found $binary_path, testing..." );
		if ( ewww_image_optimizer_md5check( $binary_path ) && ewww_image_optimizer_mimetype( $binary_path, 'b') ) {
			$binary_path = ewww_image_optimizer_escapeshellcmd( $binary_path );
			if ( ewww_image_optimizer_tool_found( $binary_path, $switch ) ) {
				return $binary_path;
			}
		}
	}
	// if the standard binary didn't work, see if the user custom compiled one and check that
	if ( file_exists( EWWW_IMAGE_OPTIMIZER_TOOL_PATH . $binary . '-custom') && ! $use_system ) {
		$binary_path = EWWW_IMAGE_OPTIMIZER_TOOL_PATH . $binary . '-custom';
		ewwwio_debug_message( "found $binary_path, testing..." );
		if ( filesize( $binary_path ) > 15000 && ewww_image_optimizer_mimetype( $binary_path, 'b') ) {
			$binary_path = ewww_image_optimizer_escapeshellcmd( $binary_path );
			if ( ewww_image_optimizer_tool_found( $binary_path, $switch ) ) {
				return $binary_path;
			}
		}
	}
	// see if the alternative binary works
	if ( file_exists( EWWW_IMAGE_OPTIMIZER_TOOL_PATH . $binary . '-alt' ) && ! $use_system ) {
		$binary_path = EWWW_IMAGE_OPTIMIZER_TOOL_PATH . $binary . '-alt';
		ewwwio_debug_message( "found $binary_path, testing..." );
		if ( filesize( $binary_path) > 15000 && ewww_image_optimizer_mimetype( $binary_path, 'b' ) ) {
			$binary_path = ewww_image_optimizer_escapeshellcmd( $binary_path );
			if (ewww_image_optimizer_tool_found( $binary_path, $switch ) ) {
				return $binary_path;
			}
		}
	}
	// if we still haven't found a usable binary, try a system-installed version
	if ( ewww_image_optimizer_tool_found( $binary, $switch ) ) {
		return $binary;
	} elseif ( ewww_image_optimizer_tool_found( '/usr/bin/' . $binary, $switch ) ) {
		return '/usr/bin/' . $binary;
	} elseif ( ewww_image_optimizer_tool_found( '/usr/local/bin/' . $binary, $switch ) ) {
		return '/usr/local/bin/' . $binary;
	} elseif ( ewww_image_optimizer_tool_found( '/usr/gnu/bin/' . $binary, $switch ) ) {
		return '/usr/gnu/bin/' . $binary;
	} elseif ( ewww_image_optimizer_tool_found( '/usr/syno/bin/' . $binary, $switch ) ) { // for synology diskstation OS
		return '/usr/syno/bin/' . $binary;
	} else {
		return '';
	}
}

/**
 * Process an image.
 *
 * Returns an array of the $file, $results, $converted to tell us if an image changes formats, and the $original file if it did.
 *
 * @param   string $file		Full absolute path to the image file
 * @param   int $gallery_type		1=wordpress, 2=nextgen, 3=flagallery, 4=aux_images, 5=image editor, 6=imagestore
 * @param   boolean $converted		tells us if this is a resize and the full image was converted to a new format
 * @param   boolean $new		tells the optimizer that this is a new image, so it should attempt conversion regardless of previous results
 * @param   boolean $fullsize		tells the optimizer this is a full size image
 * @returns array
 */
function ewww_image_optimizer( $file, $gallery_type = 4, $converted = false, $new = false, $fullsize = false ) {
	global $ewwwio_cli;
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	// if the plugin gets here without initializing, we need to run through some things first
	if ( ! defined( 'EWWW_IMAGE_OPTIMIZER_CLOUD' ) ) {
		ewww_image_optimizer_cloud_init();
	}
	session_write_close();
	$bypass_optimization = apply_filters( 'ewww_image_optimizer_bypass', false, $file );
	if ( true === $bypass_optimization ) {
		// tell the user optimization was skipped
		ewwwio_debug_message( "optimization bypassed: $file" );
		return array( false, __( "Optimization skipped", EWWW_IMAGE_OPTIMIZER_DOMAIN ), $converted, $file );
	}
	// initialize the original filename
	$original = $file;
	$result = '';
	// check that the file exists
	if ( FALSE === file_exists( $file ) ) {
		// tell the user we couldn't find the file
		$msg = sprintf( __( 'Could not find %s', EWWW_IMAGE_OPTIMIZER_DOMAIN ), $file );
		ewwwio_debug_message( "file doesn't appear to exist: $file" );
		// send back the above message
		return array( false, $msg, $converted, $original );
	}
	// check that the file is writable
	if ( FALSE === is_writable( $file ) ) {
		// tell the user we can't write to the file
		$msg = sprintf( __( '%s is not writable', EWWW_IMAGE_OPTIMIZER_DOMAIN ), $file );
		ewwwio_debug_message( "couldn't write to the file $file" );
		// send back the above message
		return array( false, $msg, $converted, $original );
	}
	if ( function_exists( 'fileperms' ) )
		$file_perms = substr( sprintf( '%o', fileperms( $file ) ), -4 );
	$file_owner = 'unknown';
	$file_group = 'unknown';
	if (function_exists('posix_getpwuid')) {
		$file_owner = posix_getpwuid(fileowner($file));
		$file_owner = $file_owner['name'];
	}
	if (function_exists('posix_getgrgid')) {
		$file_group = posix_getgrgid(filegroup($file));
		$file_group = $file_group['name'];
	}
	ewwwio_debug_message( "permissions: $file_perms, owner: $file_owner, group: $file_group" );
	$type = ewww_image_optimizer_mimetype( $file, 'i' );
	if ( ! $type ) {
		ewwwio_debug_message( 'could not find any functions for mimetype detection' );
		//otherwise we store an error message since we couldn't get the mime-type
		return array( false, __( 'Unknown file type', EWWW_IMAGE_OPTIMIZER_DOMAIN ), $converted, $original );
	}
	if ( strpos( $type, 'image' ) === FALSE && strpos( $type, 'pdf' ) === FALSE ) {
		ewwwio_debug_message( "unsupported mimetype: $type" );
		return array( false, __( 'Unsupported file type', EWWW_IMAGE_OPTIMIZER_DOMAIN ) . ": $type", $converted, $original );
	}
	if ( ! EWWW_IMAGE_OPTIMIZER_CLOUD ) {
		if ( ! defined( 'EWWW_IMAGE_OPTIMIZER_NOEXEC' ) ) {
			// Check if exec is disabled
			if( ewww_image_optimizer_exec_check() ) {
				define( 'EWWW_IMAGE_OPTIMIZER_NOEXEC', true );
				ewwwio_debug_message( 'exec seems to be disabled' );
				ewww_image_optimizer_disable_tools();
				// otherwise, query the php settings for safe mode
			} elseif ( ewww_image_optimizer_safemode_check() ) {
				define( 'EWWW_IMAGE_OPTIMIZER_NOEXEC', true );
				ewwwio_debug_message( 'safe mode appears to be enabled' );
				ewww_image_optimizer_disable_tools();
			} else {
				define( 'EWWW_IMAGE_OPTIMIZER_NOEXEC', false );
			}
		}
		if ( EWWW_IMAGE_OPTIMIZER_NOEXEC ) {
			$nice = '';
		} else {
			// check to see if 'nice' exists
			$nice = ewww_image_optimizer_find_nix_binary( 'nice', 'n' );
		}
	}
	$skip = ewww_image_optimizer_skip_tools();
	// if the user has disabled the utility checks
	if ( EWWW_IMAGE_OPTIMIZER_CLOUD ) {
		$skip['jpegtran'] = true;
		$skip['optipng'] = true;
		$skip['gifsicle'] = true;
		$skip['pngout'] = true;
		$skip['pngquant'] = true;
		$skip['webp'] = true;
	}
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_metadata_skip_full' ) && $fullsize ) {
		$keep_metadata = true;
	} else {
		$keep_metadata = false;
	}
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_lossy_skip_full' ) && $fullsize ) {
		$skip_lossy = true;
	} else {
		$skip_lossy = false;
	}
	if ( ini_get( 'max_execution_time' ) < 90 && ewww_image_optimizer_stl_check() ) {
		set_time_limit( 0 );
	}
	// if the full-size image was converted
	if ( $converted ) {
		ewwwio_debug_message( 'full-size image was converted, need to rebuild filename for meta' );
		$filenum = $converted;
		// grab the file extension
		preg_match('/\.\w+$/', $file, $fileext);
		// strip the file extension
		$filename = str_replace($fileext[0], '', $file);
		// grab the dimensions
		preg_match('/-\d+x\d+(-\d+)*$/', $filename, $fileresize);
		// strip the dimensions
		$filename = str_replace($fileresize[0], '', $filename);
		// reconstruct the filename with the same increment (stored in $converted) as the full version
		$refile = $filename . '-' . $filenum . $fileresize[0] . $fileext[0];
		// rename the file
		rename($file, $refile);
		ewwwio_debug_message( "moved $file to $refile" );
		// and set $file to the new filename
		$file = $refile;
		$original = $file;
	}
	// get the original image size
	$orig_size = filesize( $file );
	ewwwio_debug_message( "original filesize: $orig_size" );
	if ( $orig_size < ewww_image_optimizer_get_option( 'ewww_image_optimizer_skip_size' ) ) {
		// tell the user optimization was skipped
		ewwwio_debug_message( "optimization bypassed due to filesize: $file" );
		return array( false, __( "Optimization skipped", EWWW_IMAGE_OPTIMIZER_DOMAIN ), $converted, $file );
	}
	if ( $type == 'image/png' && ewww_image_optimizer_get_option( 'ewww_image_optimizer_skip_png_size' ) && $orig_size > ewww_image_optimizer_get_option( 'ewww_image_optimizer_skip_png_size' ) ) {
		// tell the user optimization was skipped
		ewwwio_debug_message( "optimization bypassed due to filesize: $file" );
		return array( false, __( "Optimization skipped", EWWW_IMAGE_OPTIMIZER_DOMAIN ), $converted, $file );
	}
	// initialize $new_size with the original size, HOW ABOUT A ZERO...
	//$new_size = $orig_size;
	$new_size = 0;
	// set the optimization process to OFF
	$optimize = false;
	// toggle the convert process to ON
	$convert = true;
	// allow other plugins to mangle the image however they like prior to optimization
	do_action( 'ewww_image_optimizer_pre_optimization', $file, $type );
	// run the appropriate optimization/conversion for the mime-type
	switch ( $type ) {
		case 'image/jpeg':
			$png_size = 0;
			// if jpg2png conversion is enabled, and this image is in the wordpress media library
			if ( ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_to_png' ) && $gallery_type == 1 ) || ! empty( $_GET['ewww_convert'] ) ) {
				// generate the filename for a PNG
				// if this is a resize version
				if ( $converted ) {
					// just change the file extension
					$pngfile = preg_replace( '/\.\w+$/', '.png', $file );
				// if this is a full size image
				} else {
					// get a unique filename for the png image
					list( $pngfile, $filenum ) = ewww_image_optimizer_unique_filename( $file, '.png' );
				}
			} else {
				// otherwise, set it to OFF
				$convert = false;
				$pngfile = '';
			}
			// check for previous optimization, so long as the force flag is on and this isn't a new image that needs converting
			if ( empty( $_REQUEST['ewww_force'] ) && empty( $ewwwio_cli->force ) && ! ( $new && $convert ) ) {
				if ( $results_msg = ewww_image_optimizer_check_table( $file, $orig_size ) ) {
					return array( $file, $results_msg, $converted, $original );
				}
			}
			if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_level' ) > 10 ) {
				list( $file, $converted, $result, $new_size ) = ewww_image_optimizer_cloud_optimizer( $file, $type, $convert, $pngfile, 'image/png', $skip_lossy );
				if ( $converted ) {
					if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_delete_originals' ) == TRUE ) {
						// delete the original JPG
						unlink( $original );
					}
					$converted = $filenum;
					ewww_image_optimizer_webp_create( $file, $new_size, 'image/png', null, $orig_size != $new_size );
				} else {
					ewww_image_optimizer_webp_create( $file, $new_size, $type, null, $orig_size != $new_size );
				}
				break;
			}
			if ( $convert ) {
				$tools = ewww_image_optimizer_path_check(
					! $skip['jpegtran'],
					! $skip['optipng'],
					false,
					! $skip['pngout'],
					! $skip['pngquant'],
					! $skip['webp']
				);
			} else {
				$tools = ewww_image_optimizer_path_check(
					! $skip['jpegtran'],
					false,
					false,
					false,
					false,
					! $skip['webp']
				);
			}
			// if jpegtran optimization is disabled
			if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_level' ) == 0 ) {
				// store an appropriate message in $result
				$result = __( 'JPG optimization is disabled', EWWW_IMAGE_OPTIMIZER_DOMAIN );
			// otherwise, if we aren't skipping the utility verification and jpegtran doesn't exist
			} elseif ( ! $skip['jpegtran'] && ! $tools['JPEGTRAN'] ) {
				// store an appropriate message in $result
				$result = sprintf( __( '%s is missing', EWWW_IMAGE_OPTIMIZER_DOMAIN ), '<em>jpegtran</em>' );
			// otherwise, things should be good, so...
			} else {
				// set the optimization process to ON
				$optimize = true;
			}
			// if optimization is turned ON
			if ( $optimize ) {
				ewwwio_debug_message( 'attempting to optimize JPG...' );
				// generate temporary file-name:
				$progfile = $file . ".prog"; // progressive jpeg
				// check to see if we are supposed to strip metadata (badly named)
				if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_remove_meta' ) && ! $keep_metadata ) {
					// don't copy metadata
					$copy_opt = 'none';
				} else {
					// copy all the metadata
					$copy_opt = 'all';
				}
				// run jpegtran - progressive
				exec( "$nice " . $tools['JPEGTRAN'] . " -copy $copy_opt -optimize -progressive -outfile " . ewww_image_optimizer_escapeshellarg( $progfile ) . " " . ewww_image_optimizer_escapeshellarg( $file ) );
				// check the filesize of the progressive JPG
				$new_size = ewww_image_optimizer_filesize( $progfile );
				ewwwio_debug_message( "optimized JPG size: $new_size" );
				// if the best-optimized is smaller than the original JPG, and we didn't create an empty JPG
				if ( $orig_size > $new_size && $new_size != 0 && ewww_image_optimizer_mimetype($progfile, 'i') == $type ) {
					// replace the original with the optimized file
					rename($progfile, $file);
					// store the results of the optimization
					$result = "$orig_size vs. $new_size";
				// if the optimization didn't produce a smaller JPG
				} else {
					if ( is_file( $progfile ) ) {
						// delete the optimized file
						unlink($progfile);
					}
					// store the results
					$result = 'unchanged';
					$new_size = $orig_size;
				}
			// if conversion and optimization are both turned OFF, finish the JPG processing
			} elseif ( ! $convert ) {
				ewww_image_optimizer_webp_create( $file, $orig_size, $type, $tools['WEBP'] );
				break;
			}
			// if the conversion process is turned ON, or if this is a resize and the full-size was converted
			if ( $convert ) {
				ewwwio_debug_message( "attempting to convert JPG to PNG: $pngfile" );
				if ( empty( $new_size ) ) {
					$new_size = $orig_size;
				}
				// convert the JPG to PNG
				if ( ewww_image_optimizer_gmagick_support() ) {
					try {
						$gmagick = new Gmagick( $file );
						$gmagick->stripimage();
						$gmagick->setimageformat( 'PNG' );
						$gmagick->writeimage( $pngfile );
					} catch ( Exception $gmagick_error ) {
						ewwwio_debug_message( $gmagick_error->getMessage() );
					}
					$png_size = ewww_image_optimizer_filesize( $pngfile );
				}
				if ( ! $png_size && ewww_image_optimizer_imagick_support() ) {
					try {
						$imagick = new Imagick( $file );
						$imagick->stripImage();
						$imagick->setImageFormat( 'PNG' );
						$imagick->writeImage( $pngfile );
					} catch ( Exception $imagick_error ) {
						ewwwio_debug_message( $imagick_error->getMessage() );
					}
					$png_size = ewww_image_optimizer_filesize( $pngfile );
				}
				if ( ! $png_size ) {
					$convert_path = '';
					// retrieve version info for ImageMagick
					if ( PHP_OS != 'WINNT' ) {
						$convert_path = ewww_image_optimizer_find_nix_binary( 'convert', 'i' );
					} elseif ( PHP_OS == 'WINNT' ) {
						$convert_path = ewww_image_optimizer_find_win_binary( 'convert', 'i' );
					}
					if ( ! empty( $convert_path ) ) {
						ewwwio_debug_message( 'converting with ImageMagick' );
						exec( $convert_path . " " . ewww_image_optimizer_escapeshellarg( $file ) . " -strip " . ewww_image_optimizer_escapeshellarg( $pngfile ) );
						$png_size = ewww_image_optimizer_filesize( $pngfile );
					}
				}
				if ( ! $png_size && ewww_image_optimizer_gd_support() ) {
					ewwwio_debug_message( 'converting with GD' );
					imagepng( imagecreatefromjpeg( $file ), $pngfile );
					$png_size = ewww_image_optimizer_filesize( $pngfile );
				}
				// if lossy optimization is ON and full-size exclusion is not active
				if ( ewww_image_optimizer_get_option('ewww_image_optimizer_png_level') == 40 && $tools['PNGQUANT'] && ! $skip_lossy ) {
					ewwwio_debug_message( 'attempting lossy reduction' );
					exec( "$nice " . $tools['PNGQUANT'] . " " . ewww_image_optimizer_escapeshellarg( $pngfile ) );
					$quantfile = preg_replace('/\.\w+$/', '-fs8.png', $pngfile);
					if ( is_file( $quantfile ) && filesize( $pngfile ) > filesize( $quantfile ) ) {
						ewwwio_debug_message( "lossy reduction is better: original - " . filesize( $pngfile ) . " vs. lossy - " . filesize( $quantfile ) );
						rename( $quantfile, $pngfile );
					} elseif ( is_file( $quantfile ) ) {
						ewwwio_debug_message( "lossy reduction is worse: original - " . filesize( $pngfile ) . " vs. lossy - " . filesize( $quantfile ) );
						unlink( $quantfile );
					} else {
						ewwwio_debug_message( 'pngquant did not produce any output' );
					}
				}
				// if optipng isn't disabled
				if ( ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_disable_optipng' ) ) {
					// retrieve the optipng optimization level
					$optipng_level = (int) ewww_image_optimizer_get_option('ewww_image_optimizer_optipng_level');
					if (ewww_image_optimizer_get_option( 'ewww_image_optimizer_remove_meta' ) && preg_match( '/0.7/', ewww_image_optimizer_tool_found( $tools['OPTIPNG'], 'o' ) ) && ! $keep_metadata ) {
						$strip = '-strip all ';
					} else {
						$strip = '';
					}
					// if the PNG file was created
					if ( file_exists( $pngfile ) ) {
						ewwwio_debug_message( 'optimizing converted PNG with optipng' );
						// run optipng on the new PNG
						exec( "$nice " . $tools['OPTIPNG'] . " -o$optipng_level -quiet $strip " . ewww_image_optimizer_escapeshellarg( $pngfile ) );
					}
				}
				// if pngout isn't disabled
				if ( ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_disable_pngout' ) ) {
					// retrieve the pngout optimization level
					$pngout_level = (int) ewww_image_optimizer_get_option( 'ewww_image_optimizer_pngout_level' );
					// if the PNG file was created
					if ( file_exists( $pngfile ) ) {
						ewwwio_debug_message( 'optimizing converted PNG with pngout' );
						// run pngout on the new PNG
						exec( "$nice " . $tools['PNGOUT'] . " -s$pngout_level -q " . ewww_image_optimizer_escapeshellarg( $pngfile ) );
					}
				}
				$png_size = ewww_image_optimizer_filesize( $pngfile );
				ewwwio_debug_message( "converted PNG size: $png_size" );
				// if the PNG is smaller than the original JPG, and we didn't end up with an empty file
				if ( $new_size > $png_size && $png_size != 0 && ewww_image_optimizer_mimetype($pngfile, 'i') == 'image/png' ) {
					ewwwio_debug_message( "converted PNG is better: $png_size vs. $new_size" );
					// store the size of the converted PNG
					$new_size = $png_size;
					// check to see if the user wants the originals deleted
					if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_delete_originals' ) == TRUE ) {
						// delete the original JPG
						unlink( $file );
					}
					// store the location of the PNG file
					$file = $pngfile;
					// let webp know what we're dealing with now
					$type = 'image/png';
					// successful conversion and we store the increment
					$converted = $filenum;
				} else {
					ewwwio_debug_message( 'converted PNG is no good' );
					// otherwise delete the PNG
					$converted = FALSE;
					if ( is_file( $pngfile ) ) {
						unlink ( $pngfile );
					}
				}
			}
			ewww_image_optimizer_webp_create( $file, $new_size, $type, $tools['WEBP'], $orig_size != $new_size );
			break;
		case 'image/png':
			$jpg_size = 0;
			// png2jpg conversion is turned on, and the image is in the wordpress media library
			if ( ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_to_jpg' ) || ! empty( $_GET['ewww_convert'] ) )
				&& $gallery_type == 1 && ! $skip_lossy
				&& ( ! ewww_image_optimizer_png_alpha( $file ) || ewww_image_optimizer_jpg_background() ) ) {
				ewwwio_debug_message( 'PNG to JPG conversion turned on' );
				// if the user set a fill background for transparency
				$background = '';
				if ($background = ewww_image_optimizer_jpg_background()) {
					// set background color for GD
					$r = hexdec('0x' . strtoupper(substr($background, 0, 2)));
                                        $g = hexdec('0x' . strtoupper(substr($background, 2, 2)));
					$b = hexdec('0x' . strtoupper(substr($background, 4, 2)));
					// set the background flag for 'convert'
					$background = "-background " . '"' . "#$background" . '"';
				} else {
					$r = '';
					$g = '';
					$b = '';
				}
				// if the user manually set the JPG quality
				if ($quality = ewww_image_optimizer_jpg_quality()) {
					// set the quality for GD
					$gquality = $quality;
					// set the quality flag for 'convert'
					$cquality = "-quality $quality";
				} else {
					$cquality = '';
					$gquality = '92';
				}
				// if this is a resize version
				if ( $converted ) {
					// just replace the file extension with a .jpg
					$jpgfile = preg_replace('/\.\w+$/', '.jpg', $file);
				// if this is a full version
				} else {
					// construct the filename for the new JPG
					list( $jpgfile, $filenum ) = ewww_image_optimizer_unique_filename( $file, '.jpg' );
				}
			} else {
				ewwwio_debug_message( 'PNG to JPG conversion turned off' );
				// turn the conversion process OFF
				$convert = false;
				$jpgfile = '';
				$r = null;
				$g = null;
				$b = null;
				$gquality = null;
			}
			// check for previous optimization, so long as the force flag is on and this isn't a new image that needs converting
			if ( empty( $_REQUEST['ewww_force'] ) && empty( $ewwwio_cli->force ) && ! ( $new && $convert ) ) {
				if ( $results_msg = ewww_image_optimizer_check_table( $file, $orig_size ) ) {
					return array( $file, $results_msg, $converted, $original );
				}
			}
			if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_level' ) >= 20 && ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' ) ) {
				list( $file, $converted, $result, $new_size ) = ewww_image_optimizer_cloud_optimizer( $file, $type, $convert, $jpgfile, 'image/jpeg', $skip_lossy, array( 'r' => $r, 'g' => $g, 'b' => $b, 'quality' => $gquality ) );
				if ( $converted ) {
					if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_delete_originals' ) == TRUE ) {
						// delete the original JPG
						unlink( $original );
					}
					$converted = $filenum;
					ewww_image_optimizer_webp_create( $file, $new_size, 'image/jpeg', null, $orig_size != $new_size );
				} else {
					ewww_image_optimizer_webp_create( $file, $new_size, $type, null, $orig_size != $new_size );
				}
				break;
			}
			if ( $convert ) {
				$tools = ewww_image_optimizer_path_check(
					! $skip['jpegtran'],
					! $skip['optipng'],
					false,
					! $skip['pngout'],
					! $skip['pngquant'],
					! $skip['webp']
				);
			} else {
				$tools = ewww_image_optimizer_path_check(
					false,
					! $skip['optipng'],
					false,
					! $skip['pngout'],
					! $skip['pngquant'],
					! $skip['webp']
				);
			}
			// if pngout and optipng are disabled
			if ( ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_disable_optipng' ) && ewww_image_optimizer_get_option( 'ewww_image_optimizer_disable_pngout' ) ) || ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_level' ) == 0 ) {
				// tell the user all PNG tools are disabled
				$result = __( 'PNG optimization is disabled', EWWW_IMAGE_OPTIMIZER_DOMAIN );
			// if the utility checking is on, optipng is enabled, but optipng cannot be found
			} elseif ( ! $skip['optipng'] && ! $tools['OPTIPNG'] ) {
				// tell the user optipng is missing
				$result = sprintf( __( '%s is missing', EWWW_IMAGE_OPTIMIZER_DOMAIN ), '<em>optipng</em>' );
			// if the utility checking is on, pngout is enabled, but pngout cannot be found
			} elseif ( ! $skip['pngout'] && ! $tools['PNGOUT'] ) {
				// tell the user pngout is missing
				$result = sprintf( __( '%s is missing', EWWW_IMAGE_OPTIMIZER_DOMAIN ), '<em>pngout</em>' );
			} else {
				// turn optimization on if we made it through all the checks
				$optimize = true;
			}
			// if optimization is turned on
			if ( $optimize ) {
				// if lossy optimization is ON and full-size exclusion is not active
				if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_level' ) == 40 && $tools['PNGQUANT'] && ! $skip_lossy ) {
					ewwwio_debug_message( 'attempting lossy reduction' );
					exec( "$nice " . $tools['PNGQUANT'] . " " . ewww_image_optimizer_escapeshellarg( $file ) );
					$quantfile = preg_replace( '/\.\w+$/', '-fs8.png', $file );
					if ( is_file( $quantfile ) && filesize( $file ) > filesize( $quantfile ) && ewww_image_optimizer_mimetype($quantfile, 'i') == $type ) {
						ewwwio_debug_message( "lossy reduction is better: original - " . filesize( $file ) . " vs. lossy - " . filesize( $quantfile ) );
						rename( $quantfile, $file );
					} elseif ( is_file( $quantfile ) ) {
						ewwwio_debug_message( "lossy reduction is worse: original - " . filesize( $file ) . " vs. lossy - " . filesize( $quantfile ) );
						unlink( $quantfile );
					} else {
						ewwwio_debug_message( 'pngquant did not produce any output' );
					}
				}
				$tempfile = $file . '.tmp.png';
				copy( $file, $tempfile );
				// if optipng is enabled
				if( ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_disable_optipng' ) ) {
					// retrieve the optimization level for optipng
					$optipng_level = (int) ewww_image_optimizer_get_option( 'ewww_image_optimizer_optipng_level' );
					if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_remove_meta' ) && preg_match( '/0.7/', ewww_image_optimizer_tool_found( $tools['OPTIPNG'], 'o' ) ) && ! $keep_metadata ) {
						$strip = '-strip all ';
					} else {
						$strip = '';
					}
					// run optipng on the PNG file
					exec( "$nice " . $tools['OPTIPNG'] . " -o$optipng_level -quiet $strip " . ewww_image_optimizer_escapeshellarg( $tempfile ) );
				}
				// if pngout is enabled
				if( ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_disable_pngout' ) ) {
					// retrieve the optimization level for pngout
					$pngout_level = (int) ewww_image_optimizer_get_option( 'ewww_image_optimizer_pngout_level' );
					// run pngout on the PNG file
					exec( "$nice " . $tools['PNGOUT'] . " -s$pngout_level -q " . ewww_image_optimizer_escapeshellarg( $tempfile ) );
				}
				// retrieve the filesize of the temporary PNG
				$new_size = ewww_image_optimizer_filesize( $tempfile );
				// if the new PNG is smaller
				if ( $orig_size > $new_size && $new_size != 0 && ewww_image_optimizer_mimetype( $tempfile, 'i' ) == $type ) {
					// replace the original with the optimized file
					rename( $tempfile, $file );
					// store the results of the optimization
					$result = "$orig_size vs. $new_size";
				// if the optimization didn't produce a smaller PNG
				} else {
					if ( is_file( $tempfile ) ) {
						// delete the optimized file
						unlink( $tempfile );
					}
					// store the results
					$result = 'unchanged';
					$new_size = $orig_size;
				}
			// if conversion and optimization are both disabled we are done here
			} elseif ( ! $convert ) {
				ewwwio_debug_message( 'calling webp, but neither convert or optimize' );
				ewww_image_optimizer_webp_create( $file, $orig_size, $type, $tools['WEBP'] );
				break;
			}
			// retrieve the new filesize of the PNG
			$new_size = ewww_image_optimizer_filesize( $file );
			// if conversion is on and the PNG doesn't have transparency or the user set a background color to replace transparency
			if ( $convert ) {
				ewwwio_debug_message( "attempting to convert PNG to JPG: $jpgfile" );
				if ( empty( $new_size ) ) {
					$new_size = $orig_size;
				}
				$magick_background = ewww_image_optimizer_jpg_background();
				if ( empty( $magick_background ) ) {
					$magick_background = '000000';
				}
				// convert the PNG to a JPG with all the proper options
				if ( ewww_image_optimizer_gmagick_support() ) {
					try {
						if ( ewww_image_optimizer_png_alpha( $file ) ) {
							$gmagick_overlay = new Gmagick( $file );
							$gmagick = new Gmagick();
							$gmagick->newimage( $gmagick_overlay->getimagewidth(), $gmagick_overlay->getimageheight(), '#' . $magick_background );
							$gmagick->compositeimage( $gmagick_overlay, 1, 0, 0 );
						} else {
							$gmagick = new Gmagick( $file );
						}
						$gmagick->setimageformat( 'JPG' );
						$gmagick->setcompressionquality( $gquality );
						$gmagick->writeimage( $jpgfile );
					} catch ( Exception $gmagick_error ) {
						ewwwio_debug_message( $gmagick_error->getMessage() );
					}
					$jpg_size = ewww_image_optimizer_filesize( $jpgfile );
				}
				if ( ! $jpg_size && ewww_image_optimizer_imagick_support() ) {
					try {
						$imagick = new Imagick( $file );
						if ( ewww_image_optimizer_png_alpha( $file ) ) {
							$imagick->setImageBackgroundColor( new ImagickPixel( '#' . $magick_background ) );
							$imagick->setImageAlphaChannel( 11 );
						}
						$imagick->setImageFormat( 'JPG' );
						$imagick->setCompressionQuality( $gquality );
						$imagick->writeImage( $jpgfile );
					} catch ( Exception $imagick_error ) {
						ewwwio_debug_message( $imagick_error->getMessage() );
					}
					$jpg_size = ewww_image_optimizer_filesize( $jpgfile );
				}
				if ( ! $jpg_size ) {
					// retrieve version info for ImageMagick
					$convert_path = ewww_image_optimizer_find_nix_binary( 'convert', 'i' );
					if ( ! empty( $convert_path ) ) {
						ewwwio_debug_message( 'converting with ImageMagick' );
						ewwwio_debug_message( "using command: $convert_path $background -alpha remove $cquality $file $jpgfile" );
						exec ( "$convert_path $background -alpha remove $cquality " . ewww_image_optimizer_escapeshellarg( $file ) . " " . ewww_image_optimizer_escapeshellarg( $jpgfile ) );
						$jpg_size = ewww_image_optimizer_filesize( $jpgfile );
					}
				}
				if ( ! $jpg_size && ewww_image_optimizer_gd_support() ) {
					ewwwio_debug_message( 'converting with GD' );
					// retrieve the data from the PNG
					$input = imagecreatefrompng($file);
					// retrieve the dimensions of the PNG
					list($width, $height) = getimagesize($file);
					// create a new image with those dimensions
					$output = imagecreatetruecolor($width, $height);
					if ($r === '') {
						$r = 255;
						$g = 255;
						$b = 255;
					}
					// allocate the background color
					$rgb = imagecolorallocate($output, $r, $g, $b);
					// fill the new image with the background color
					imagefilledrectangle($output, 0, 0, $width, $height, $rgb);
					// copy the original image to the new image
					imagecopy($output, $input, 0, 0, 0, 0, $width, $height);
					// output the JPG with the quality setting
					imagejpeg($output, $jpgfile, $gquality);
				}
				$jpg_size = ewww_image_optimizer_filesize( $jpgfile );
				if ($jpg_size) {
					ewwwio_debug_message( "converted JPG filesize: $jpg_size" );
				} else {
					ewwwio_debug_message( 'unable to convert to JPG' );
				}
				// next we need to optimize that JPG if jpegtran is enabled
				if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_level' ) == 10 && file_exists( $jpgfile ) ) {
					// generate temporary file-name:
					$progfile = $jpgfile . ".prog"; // progressive jpeg
					// check to see if we are supposed to strip metadata (badly named)
					if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_remove_meta' ) && ! $keep_metadata ){
						// don't copy metadata
						$copy_opt = 'none';
					} else {
						// copy all the metadata
						$copy_opt = 'all';
					}
					// run jpegtran - progressive
					exec( "$nice " . $tools['JPEGTRAN'] . " -copy $copy_opt -optimize -progressive -outfile " . ewww_image_optimizer_escapeshellarg( $progfile ) . " " . ewww_image_optimizer_escapeshellarg( $jpgfile ) );
					// check the filesize of the progressive JPG
					$opt_jpg_size = ewww_image_optimizer_filesize( $progfile );
					// if the best-optimized is smaller than the original JPG, and we didn't create an empty JPG
					if ( $jpg_size > $opt_jpg_size && $opt_jpg_size != 0 ) {
						// replace the original with the optimized file
						rename( $progfile, $jpgfile );
						// store the size of the optimized JPG
						$jpg_size = $opt_jpg_size;
						ewwwio_debug_message( 'optimized JPG was smaller than un-optimized version' );
					// if the optimization didn't produce a smaller JPG
					} elseif ( is_file( $progfile ) ) {
						// delete the optimized file
						unlink( $progfile );
					}
				}
				ewwwio_debug_message( "converted JPG size: $jpg_size" );
				// if the new JPG is smaller than the original PNG
				if ( $new_size > $jpg_size && $jpg_size != 0 && ewww_image_optimizer_mimetype($jpgfile, 'i') == 'image/jpeg' ) {
					// store the size of the JPG as the new filesize
					$new_size = $jpg_size;
					// if the user wants originals delted after a conversion
					if (ewww_image_optimizer_get_option('ewww_image_optimizer_delete_originals') == TRUE) {
						// delete the original PNG
						unlink($file);
					}
					// update the $file location to the new JPG
					$file = $jpgfile;
					// let webp know what we're dealing with now
					$type = 'image/jpeg';
					// successful conversion, so we store the increment
					$converted = $filenum;
				} else {
					$converted = FALSE;
					if (is_file($jpgfile)) {
						// otherwise delete the new JPG
						unlink( $jpgfile );
					}
				}
			}
			ewww_image_optimizer_webp_create( $file, $new_size, $type, $tools['WEBP'], $orig_size != $new_size );
			break;
		case 'image/gif':
			// if gif2png is turned on, and the image is in the wordpress media library
			if ( ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_gif_to_png' ) || ! empty( $_GET['ewww_convert'] ) )
				&& ! ewww_image_optimizer_is_animated( $file ) ) {
				// generate the filename for a PNG
				// if this is a resize version
				if ($converted) {
					// just change the file extension
					$pngfile = preg_replace('/\.\w+$/', '.png', $file);
				// if this is the full version
				} else {
					// construct the filename for the new PNG
					list($pngfile, $filenum) = ewww_image_optimizer_unique_filename($file, '.png');
				}
			} else {
				// turn conversion OFF
				$convert = false;
				$pngfile = '';
			}
			// check for previous optimization, so long as the force flag is on and this isn't a new image that needs converting
			if ( empty( $_REQUEST['ewww_force'] ) && empty( $ewwwio_cli->force ) && ! ( $new && $convert ) ) {
				if ( $results_msg = ewww_image_optimizer_check_table( $file, $orig_size ) ) {
					return array( $file, $results_msg, $converted, $original );
				}
			}
			if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' ) && ewww_image_optimizer_get_option( 'ewww_image_optimizer_gif_level' ) == 10 ) {
				list( $file, $converted, $result, $new_size ) = ewww_image_optimizer_cloud_optimizer( $file, $type, $convert, $pngfile, 'image/png', $skip_lossy );
				if ( $converted ) {
					if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_delete_originals' ) == TRUE ) {
						// delete the original JPG
						unlink( $original );
					}
					$converted = $filenum;
					ewww_image_optimizer_webp_create( $file, $new_size, 'image/png', null, $orig_size != $new_size );
 				}
				break;
			}
			if ( $convert ) {
				$tools = ewww_image_optimizer_path_check(
					false,
					! $skip['optipng'],
					! $skip['gifsicle'],
					! $skip['pngout'],
					! $skip['pngquant'],
					! $skip['webp']
				);
			} else {
				$tools = ewww_image_optimizer_path_check(
					false,
					false,
					! $skip['gifsicle'],
					false,
					false,
					false
				);
			}
			// if gifsicle is disabled
			if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_gif_level' ) == 0 ) {
				// return an appropriate message
				$result = __( 'GIF optimization is disabled', EWWW_IMAGE_OPTIMIZER_DOMAIN );
			// if utility checking is on, and gifsicle is not installed
			} elseif ( ! $skip['gifsicle'] && ! $tools['GIFSICLE'] ) {
				// return an appropriate message
				$result = sprintf(__('%s is missing', EWWW_IMAGE_OPTIMIZER_DOMAIN), '<em>gifsicle</em>');
			} else {
				// otherwise, turn optimization ON
				$optimize = true;
			}
			// if optimization is turned ON
			if ($optimize) {
				$tempfile = $file . '.tmp'; //temporary GIF output
				// run gifsicle on the GIF
				exec( "$nice " . $tools['GIFSICLE'] . " -O3 --careful -o $tempfile " . ewww_image_optimizer_escapeshellarg( $file ) );
					// retrieve the filesize of the temporary GIF
					$new_size = ewww_image_optimizer_filesize( $tempfile );
					// if the new GIF is smaller
					if ($orig_size > $new_size && $new_size != 0 && ewww_image_optimizer_mimetype($tempfile, 'i') == $type ) {
						// replace the original with the optimized file
						rename($tempfile, $file);
						// store the results of the optimization
						$result = "$orig_size vs. $new_size";
					// if the optimization didn't produce a smaller GIF
					} else {
						if (is_file($tempfile)) {
							// delete the optimized file
							unlink($tempfile);
						}
						// store the results
						$result = 'unchanged';
						$new_size = $orig_size;
					}
			// if conversion and optimization are both turned OFF, we are done here
			} elseif (!$convert) {
				break;
			}
			// get the new filesize for the GIF
			$new_size = ewww_image_optimizer_filesize($file);
			// if conversion is ON and the GIF isn't animated
			if ( $convert && ! ewww_image_optimizer_is_animated( $file ) ) {
				if ( empty( $new_size ) ) {
					$new_size = $orig_size;
				}
				// if optipng is enabled
				if ( ! ewww_image_optimizer_get_option('ewww_image_optimizer_disable_optipng') && $tools['OPTIPNG']) {
					// retrieve the optipng optimization level
					$optipng_level = (int) ewww_image_optimizer_get_option('ewww_image_optimizer_optipng_level');
					if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_remove_meta' ) && preg_match( '/0.7/', ewww_image_optimizer_tool_found( $tools['OPTIPNG'], 'o' ) ) && ! $keep_metadata ) {
						$strip = '-strip all ';
					} else {
						$strip = '';
					}
					// run optipng on the GIF file
					exec( "$nice " . $tools['OPTIPNG'] . " -out " . ewww_image_optimizer_escapeshellarg( $pngfile ) . " -o$optipng_level -quiet $strip " . ewww_image_optimizer_escapeshellarg( $file ) );
				}
				// if pngout is enabled
				if ( ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_disable_pngout' ) && $tools['PNGOUT'] ) {
					// retrieve the pngout optimization level
					$pngout_level = (int) ewww_image_optimizer_get_option('ewww_image_optimizer_pngout_level');
					// if $pngfile exists (which means optipng was run already)
					if (file_exists($pngfile)) {
						// run pngout on the PNG file
						exec( "$nice " . $tools['PNGOUT'] . " -s$pngout_level -q " . ewww_image_optimizer_escapeshellarg( $pngfile ) );
					} else {
						// run pngout on the GIF file
						exec( "$nice " . $tools['PNGOUT'] . " -s$pngout_level -q " . ewww_image_optimizer_escapeshellarg( $file ) . " " . ewww_image_optimizer_escapeshellarg( $pngfile ) );
					}
				}
					// retrieve the filesize of the PNG
					$png_size = ewww_image_optimizer_filesize($pngfile);
					// if the new PNG is smaller than the original GIF
					if ( $new_size > $png_size && $png_size != 0 && ewww_image_optimizer_mimetype( $pngfile, 'i' ) == 'image/png' ) {
						// store the PNG size as the new filesize
						$new_size = $png_size;
						// if the user wants original GIFs deleted after successful conversion
						if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_delete_originals' ) == TRUE ) {
							// delete the original GIF
							unlink( $file );
						}
						// update the $file location with the new PNG
						$file = $pngfile;
						// let webp know what we're dealing with now
						$type = 'image/png';
						// normally this would be at the end of the section, but we only want to do webp if the image was successfully converted to a png
						ewww_image_optimizer_webp_create( $file, $new_size, $type, $tools['WEBP'], $orig_size != $new_size );
						// successful conversion (for now), so we store the increment
						$converted = $filenum;
					} else {
						$converted = FALSE;
						if ( is_file( $pngfile ) ) {
							unlink( $pngfile );
						}
					}
			}
			break;
		case 'application/pdf':
			if ( empty( $_REQUEST['ewww_force'] ) && empty( $ewwwio_cli->force ) ) {
				if ( $results_msg = ewww_image_optimizer_check_table( $file, $orig_size ) ) {
					return array( $file, $results_msg, false, $original );
				}
			}
			if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_pdf_level' ) > 0 ) {
				list( $file, $converted, $result, $new_size ) = ewww_image_optimizer_cloud_optimizer( $file, $type );
			}
			break;
		default:
			// if not a JPG, PNG, or GIF, tell the user we don't work with strangers
			return array( false, __( 'Unsupported file type', EWWW_IMAGE_OPTIMIZER_DOMAIN ) . ": $type", $converted, $original );
	}
	// allow other plugins to run operations on the images after optimization.
	// NOTE: it is recommended to do any image modifications prior to optimization, otherwise you risk un-optimizing your images here.
	do_action( 'ewww_image_optimizer_post_optimization', $file, $type );
	// if their cloud api license limit has been exceeded
	if ( $result == 'exceeded' ) {
		return array( false, __( 'License exceeded', EWWW_IMAGE_OPTIMIZER_DOMAIN ), $converted, $original );
	}
	if ( ! empty( $new_size ) ) {
		// Set correct file permissions
		$stat = stat( dirname( $file ) );
		$perms = $stat['mode'] & 0000666; //same permissions as parent folder, strip off the executable bits
		@chmod( $file, $perms );

		$results_msg = ewww_image_optimizer_update_table( $file, $new_size, $orig_size, $new );
		ewwwio_memory( __FUNCTION__ );
		return array( $file, $results_msg, $converted, $original );
	}
	ewwwio_memory( __FUNCTION__ );
	// otherwise, send back the filename, the results (some sort of error message), the $converted flag, and the name of the original image
	return array( false, $result, $converted, $original );
}

// creates webp images alongside JPG and PNG files
// needs a filename, the filesize, mimetype, and the path to the cwebp binary
function ewww_image_optimizer_webp_create( $file, $orig_size, $type, $tool, $recreate = false ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	// change the file extension
	$webpfile = $file . '.webp';
	if ( ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp' ) ) {
		return;
	} elseif ( is_file( $webpfile ) && empty( $_REQUEST['ewww_force'] ) && empty( $ewwwio_cli->force ) && ! $recreate ) {
		ewwwio_debug_message( 'webp file exists, not forcing or recreating' );
		return;
	}
	if ( empty( $tool ) ) {
		ewww_image_optimizer_cloud_optimizer( $file, $type, false, $webpfile, 'image/webp' );
	} else {
		// check to see if 'nice' exists
		$nice = ewww_image_optimizer_find_nix_binary( 'nice', 'n' );
		switch( $type ) {
			case 'image/jpeg':
				exec( "$nice " . $tool . " -q  85 -quiet " . ewww_image_optimizer_escapeshellarg( $file ) . " -o " . ewww_image_optimizer_escapeshellarg( $webpfile ) . ' 2>&1', $cli_output );
				break;
			case 'image/png':
				exec( "$nice " . $tool . " -lossless -quiet " . ewww_image_optimizer_escapeshellarg( $file ) . " -o " . ewww_image_optimizer_escapeshellarg( $webpfile ) . ' 2>&1', $cli_output );
				break;
		}
	}
	$webp_size = ewww_image_optimizer_filesize( $webpfile );
	ewwwio_debug_message( "webp is $webp_size vs. $type is $orig_size" );
	if ( is_file( $webpfile ) && $orig_size < $webp_size && ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp_force' ) ) {
		ewwwio_debug_message( 'webp file was too big, deleting' );
		unlink( $webpfile );
	} elseif ( is_file( $webpfile ) ) {
		// Set correct file permissions
		$stat = stat( dirname( $webpfile ) );
		$perms = $stat['mode'] & 0000666; //same permissions as parent folder, strip off the executable bits
		@chmod( $webpfile, $perms );
	}
	ewwwio_memory( __FUNCTION__ );
}

// retrieves the pngout linux package with wget, unpacks it with tar,
// copies the appropriate version to the plugin folder, and sends the user back where they came from
function ewww_image_optimizer_install_pngout() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	$permissions = apply_filters( 'ewww_image_optimizer_admin_permissions', '' );
	if ( FALSE === current_user_can( $permissions ) ) {
		wp_die( esc_html__( 'You do not have permission to install image optimizer utilities.', EWWW_IMAGE_OPTIMIZER_DOMAIN ) );
	}
	if ( PHP_OS != 'WINNT' ) {
		$tar = ewww_image_optimizer_find_nix_binary( 'tar', 't' );
	}
	if ( empty( $tar ) && PHP_OS != 'WINNT' ) {
		$pngout_error = __( 'tar command not found', EWWW_IMAGE_OPTIMIZER_DOMAIN );
	}
	if ( PHP_OS == 'Linux' ) {
		$os_string = 'linux';
	}
	if ( PHP_OS == 'FreeBSD' ) {
		$os_string = 'bsd';
	}
	$latest = '20150319';
	if ( empty( $pngout_error ) ) {
		if ( PHP_OS == 'Linux' || PHP_OS == 'FreeBSD' ) {
			$download_result = ewww_image_optimizer_escapeshellarg( download_url( 'http://static.jonof.id.au/dl/kenutils/pngout-' . $latest . '-' . $os_string . '-static.tar.gz' ) );
			if ( is_wp_error( $download_result ) ) {
				$pngout_error = $download_result->get_error_message();
			} else {
				$arch_type = php_uname( 'm' );
				exec( "$tar xzf $download_result -C " . ewww_image_optimizer_escapeshellarg( EWWW_IMAGE_OPTIMIZER_BINARY_PATH ) . ' pngout-' . $latest . '-' . $os_string . '-static/' . $arch_type . '/pngout-static' );
				if ( file_exists( EWWW_IMAGE_OPTIMIZER_BINARY_PATH . 'pngout-' . $latest . '-' . $os_string . '-static/' . $arch_type . '/pngout-static' ) ) {
					if ( ! rename( EWWW_IMAGE_OPTIMIZER_BINARY_PATH . 'pngout-' . $latest . '-' . $os_string . '-static/' . $arch_type . '/pngout-static', EWWW_IMAGE_OPTIMIZER_TOOL_PATH . 'pngout-static' ) ) {
						if ( empty( $pngout_error ) ) { $pngout_error = __( "could not move pngout", EWWW_IMAGE_OPTIMIZER_DOMAIN ); }
					}
					if ( ! chmod( EWWW_IMAGE_OPTIMIZER_TOOL_PATH . 'pngout-static', 0755 ) ) {
						if ( empty( $pngout_error ) ) { $pngout_error = __( "could not set permissions", EWWW_IMAGE_OPTIMIZER_DOMAIN ); }
					}
					$pngout_version = ewww_image_optimizer_tool_found( ewww_image_optimizer_escapeshellarg( EWWW_IMAGE_OPTIMIZER_TOOL_PATH ) . 'pngout-static', 'p' );
				} else {
					$pngout_error = __( 'extraction of files failed', EWWW_IMAGE_OPTIMIZER_DOMAIN );
				}
			}
		}
		if ( PHP_OS == 'Darwin' ) {
			$download_result = ewww_image_optimizer_escapeshellarg( download_url( 'http://static.jonof.id.au/dl/kenutils/pngout-' . $latest . '-darwin.tar.gz' ) );
			if ( is_wp_error( $download_result ) ) {
				$pngout_error = $download_result->get_error_message();
			} else {
				exec( "$tar xzf $download_result -C " . ewww_image_optimizer_escapeshellarg( EWWW_IMAGE_OPTIMIZER_BINARY_PATH ) . ' pngout-' . $latest . '-darwin/pngout' );
				if ( file_exists( EWWW_IMAGE_OPTIMIZER_BINARY_PATH . 'pngout-' . $latest . '-' . $os_string . '-static/' . $arch_type . '/pngout-static' ) ) {
					if ( ! rename( EWWW_IMAGE_OPTIMIZER_BINARY_PATH . 'pngout-' . $latest . '-darwin/pngout', EWWW_IMAGE_OPTIMIZER_TOOL_PATH . 'pngout-static' ) ) {
						if ( empty( $pngout_error ) ) { $pngout_error = __( 'could not move pngout', EWWW_IMAGE_OPTIMIZER_DOMAIN ); }
					}
					if ( ! chmod( EWWW_IMAGE_OPTIMIZER_TOOL_PATH . 'pngout-static', 0755 ) ) {
						if ( empty( $pngout_error ) ) { $pngout_error = __( 'could not set permissions', EWWW_IMAGE_OPTIMIZER_DOMAIN ); }
					}
					$pngout_version = ewww_image_optimizer_tool_found( ewww_image_optimizer_escapeshellarg( EWWW_IMAGE_OPTIMIZER_TOOL_PATH ) . 'pngout-static', 'p' );
				} else {
					$pngout_error = __( 'extraction of files failed', EWWW_IMAGE_OPTIMIZER_DOMAIN );
				}
			}
		}
	}
	if (PHP_OS == 'WINNT') {
		$download_result = download_url( 'http://advsys.net/ken/util/pngout.exe' );
		if ( is_wp_error( $download_result ) ) {
			$pngout_error = $download_result->get_error_message();
		} else {
			if ( ! rename( $download_result, EWWW_IMAGE_OPTIMIZER_TOOL_PATH . 'pngout.exe' ) ) {
				if ( empty( $pngout_error ) ) { $pngout_error = __( "could not move pngout", EWWW_IMAGE_OPTIMIZER_DOMAIN ); }
			}
			$pngout_version = ewww_image_optimizer_tool_found ( '"' . EWWW_IMAGE_OPTIMIZER_TOOL_PATH . 'pngout.exe"', 'p' );
		}
	}
	if ( ! empty( $pngout_version ) ) {
		$sendback = add_query_arg( 'ewww_pngout', 'success', remove_query_arg( array( 'ewww_pngout', 'ewww_error' ), wp_get_referer() ) );
	}
	if ( ! isset( $sendback ) ) {
		$sendback = add_query_arg( array( 'ewww_pngout' => 'failed', 'ewww_error' => urlencode( $pngout_error ) ), remove_query_arg( array( 'ewww_pngout', 'ewww_error' ), wp_get_referer() ) );
	}
	wp_redirect( esc_url_raw( $sendback) );
	ewwwio_memory( __FUNCTION__ );
	exit( 0 );
}

// removes any binaries that have been installed in wp-content/ewww/
function ewww_image_optimizer_remove_binaries() {
	if ( ! class_exists( 'RecursiveIteratorIterator' ) ) {
		return;
	}
	if ( ! is_dir( EWWW_IMAGE_OPTIMIZER_TOOL_PATH ) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( EWWW_IMAGE_OPTIMIZER_TOOL_PATH ), RecursiveIteratorIterator::CHILD_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD );
	foreach ( $iterator as $file ) {
		if ( $file->isFile() ) {
			$path = $file->getPathname();
			if ( is_writable( $path ) ) {
				unlink( $path );
			}
		}
	}
	if ( ! class_exists( 'FilesystemIterator' ) ) {
		return;
	}
	clearstatcache();
	$iterator = new FilesystemIterator( EWWW_IMAGE_OPTIMIZER_TOOL_PATH );
	if ( ! $iterator->valid() ) {
		rmdir( EWWW_IMAGE_OPTIMIZER_TOOL_PATH );
	}
}
