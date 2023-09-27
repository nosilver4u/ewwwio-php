<?php
/**
 * PHP Library to interact with EWWW IO API.
 */

if ( ! defined( 'EWWWIO_PATH' ) ) {
	// this is the full system path to the plugin folder
	define( 'EWWWIO_PATH', __DIR__ . '/' );
}

define( 'EWWW_IMAGE_OPTIMIZER_VERSION', 1.1 );

require_once __DIR__ . '/vendor/rmccue/requests/src/Autoload.php';
WpOrg\Requests\Autoload::register();

class EWWWIO {

	// primary options
	public $remove_meta = true; // Removes metadata like EXIF and ICC information.
	public $jpg_level   = 20; // Defaults to regular lossy, 10 is lossless, 30 is highest compression.
	public $png_level   = 10; // Defaults to lossless, 20 is lossy, 30 is highest compression.
	public $gif_level   = 10; // Lossless only, set to zero to disable.
	public $pdf_level   = 10; // Defaults to lossless, 20 is lossy.
	public $svg_level   = 10; // Set to 1 for minimal optimization.

	public $backup        = false; // You must collect and store the backup_hash attribute after optimization to retrieve the files later.
	public $backup_domain = ''; // set this to have backups stored on our server in unique folders per-website.

	public $webp         = false; // Enable creation of a .webp image alongside the optimized image, only if smaller.
	public $webp_force   = false; // Force keeping .webp images even if they are bigger.
	public $webp_quality = false; // Defaults to 82.

	// conversion options
	public $jpg_to_png       = false; // Enable JPG to PNG conversion, PNG image saved only if smaller.
	public $png_to_jpg       = false; // Enable PNG to JPG conversion, JPG image saved only if smaller.
	public $gif_to_png       = false; // Enable GIF to PNG conversion, PNG image saved only if smaller.
	public $delete_originals = true; // Deletes the original image after successful, does not apply unless conversion options are enabled, as regular optimization is done in-place.
	public $jpg_background   = ''; // Set to something like #ffffff (with # symbol) for a white fill during PNG to JPG. If left empty, no conversion will be attempted on transparent PNG images.
	public $jpg_quality      = 82; // only for conversion, not for regular compression/optimization.

	// status and results - look but don't touch (modify)
	public $converted   = false; // If you enable conversion options (see above), this will indicate a successful conversion after running optimize();
	public $savings     = 0; // This will store the bytes saved during optimization.
	public $backup_hash = ''; // A unique string for the image file last optimized, store this somewhere if you want to be able to retrieve the original later.
	public $debug       = false; // Enables logging to debug.log file in lib folder.

	protected $debug_data = ''; // The current debugging contents.
	protected $last_error = ''; // use get_error() to inspect.
	protected $api_key    = ''; // Pass to constructor to define.
	protected $exceeded   = false; // You've run out of credits, go get some more :)

	public function __construct( $api_key = '' ) {
		if ( empty( $api_key ) ) {
			throw new Exception( 'missing API key' );
			return;
		}
		$this->api_key = $api_key;
		if ( $this->debug ) {
			$this->debug_data .= 'EWWW IO version: ' . EWWW_IMAGE_OPTIMIZER_VERSION . "\n";

			if ( defined( 'PHP_VERSION_ID' ) ) {
				$this->debug_data .= 'PHP version: ' . PHP_VERSION_ID . "\n";
			}
		}
	}

	public function debug_message( $message ) {
		if ( ! \is_string( $message ) && ! \is_int( $message ) && ! \is_float( $message ) ) {
			return;
		}
		if ( $this->debug ) {
			$message           = str_replace( "\n\n\n", "\n", $message );
			$message           = str_replace( "\n\n", "\n", $message );
			$this->debug_data .= "$message\n";
		}
	}

	// used to output debug messages to a logfile in the plugin folder in cases where output to the screen is a bad idea
	public function debug_log() {
		if ( ! empty( $this->debug_data ) && $this->debug && is_writable( EWWWIO_PATH ) ) {
			$timestamp = gmdate( 'y-m-d h:i:s.u' ) . "\n";
			if ( ! is_file( EWWWIO_PATH . 'debug.log' ) ) {
				touch( EWWWIO_PATH . 'debug.log' );
			}
			$this->debug_data = str_replace( '<br>', "\n", $this->debug_data );
			file_put_contents( EWWWIO_PATH . 'debug.log', $timestamp . $this->debug_data, FILE_APPEND );
		}
		$this->debug_data = '';
	}

	/**
	 * Checks the filename for an S3 or GCS stream wrapper.
	 *
	 * @param string $filename The filename to be searched.
	 * @return bool True if a stream wrapper is found, false otherwise.
	 */
	public function stream_wrapped( $filename ) {
		if ( false !== \strpos( $filename, '://' ) ) {
			if ( \strpos( $filename, 's3' ) === 0 ) {
				return true;
			}
			if ( \strpos( $filename, 'gs' ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check the mimetype of the given file based on magic strings.
	 *
	 * @param string $path The absolute path to the file.
	 * @return bool|string A valid mime-type or false.
	 */
	public function mimetype( $path ) {
		$this->debug_message( '<b>' . __FUNCTION__ . '()</b>' );
		$this->debug_message( "testing mimetype: $path" );
		$type = false;
		if ( $this->stream_wrapped( $path ) ) {
			return $this->quick_mimetype( $path );
		}
		$path = \realpath( $path );
		if ( ! \is_file( $path ) ) {
			$this->debug_message( "$path is not a file, or out of bounds" );
			return $type;
		}
		if ( ! \is_readable( $path ) ) {
			$this->debug_message( "$path is not readable" );
			return $type;
		}
		$fhandle       = \fopen( $path, 'rb' );
		$file_contents = \fread( $fhandle, 4096 );
		if ( $fhandle ) {
			// Read first 12 bytes, which equates to 24 hex characters.
			$magic = bin2hex( \substr( $file_contents, 0, 12 ) );
			if ( 0 === strpos( $magic, '52494646' ) && 16 === strpos( $magic, '57454250' ) ) {
				$type = 'image/webp';
				$this->debug_message( "ewwwio type: $type" );
				return $type;
			}
			if ( 'ffd8ff' === substr( $magic, 0, 6 ) ) {
				$type = 'image/jpeg';
				$this->debug_message( "ewwwio type: $type" );
				return $type;
			}
			if ( '89504e470d0a1a0a' === substr( $magic, 0, 16 ) ) {
				$type = 'image/png';
				$this->debug_message( "ewwwio type: $type" );
				return $type;
			}
			if ( '474946383761' === substr( $magic, 0, 12 ) || '474946383961' === substr( $magic, 0, 12 ) ) {
				$type = 'image/gif';
				$this->debug_message( "ewwwio type: $type" );
				return $type;
			}
			if ( '25504446' === substr( $magic, 0, 8 ) ) {
				$type = 'application/pdf';
				$this->debug_message( "ewwwio type: $type" );
				return $type;
			}
			if ( \preg_match( '/<svg/', $file_contents ) ) {
				$type = 'image/svg+xml';
				$this->debug_message( "ewwwio type: $type" );
				return $type;
			}
			$this->debug_message( "match not found for image: $magic" );
		} else {
			$this->debug_message( 'could not open for reading' );
		}
		$this->debug_message( 'no mime type match' );
		return false;
	}

	// test mimetype based on file extension instead of file contents
	// only use for places where speed outweighs accuracy
	public function quick_mimetype( $path ) {
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
			case 'webp':
				return 'image/webp';
			case 'pdf':
				return 'application/pdf';
			case 'svg':
				return 'image/svg+xml';
			default:
				return false;
		}
	}

	/**
	 * Check the submitted GIF to see if it is animated
	 *
	 * @param string $filename Name of the GIF to test for animation.
	 * @return bool True if animation found.
	 */
	public function is_animated( $filename ) {
		$this->debug_message( '<b>' . __FUNCTION__ . '()</b>' );
		if ( ! is_file( $filename ) ) {
			return false;
		}
		// If we can, open the file in read-only buffered mode.
		$fh = fopen( $filename, 'rb' );
		if ( ! $fh ) {
			return false;
		}
		$count = 0;
		// We read through the file til we reach the end of the file, or we've found at least 2 frame headers.
		while ( ! feof( $fh ) && $count < 2 ) {
			$chunk  = fread( $fh, 1024 * 100 ); // Read 100kb at a time.
			$count += preg_match_all( '#\x00\x21\xF9\x04.{4}\x00(\x2C|\x21)#s', $chunk, $matches );
		}
		fclose( $fh );
		$this->debug_message( "scanned GIF and found $count frames" );
		return $count > 1;
	}


	/**
	 * Retrieves/sanitizes jpg background fill setting or returns null for png2jpg conversions.
	 *
	 * @param string $background The hexadecimal value entered by the user.
	 * @return string The background color sanitized.
	 */
	public function sanitize_background( $background ) {
		$this->debug_message( '<b>' . __FUNCTION__ . '()</b>' );
		if ( empty( $background ) ) {
			return '';
		}
		// Verify that the supplied value is in hex notation.
		if ( is_string( $background ) && preg_match( '/^\#*([0-9a-fA-F]){6}$/', $background ) ) {
			// We remove a leading # symbol, since we take care of it later.
			$background = ltrim( $background, '#' );
			// Send back the verified, cleaned-up background color.
			$this->debug_message( "background: $background" );
			return '#' . $background;
		} else {
			// Send back a blank value.
			return '';
		}
	}

	public function get_error() {
		return $this->last_error;
	}

	// Used to generate random strings.
	public function generate_password( $length = 12 ) {
		$chars    = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$password = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$password .= substr( $chars, rand( 0, strlen( $chars ) - 1 ), 1 );
		}
		return $password;
	}

	/**
	* Find out if set_time_limit() is allowed
	*/
	protected function stl_check() {
		$this->debug_message( '<b>' . __FUNCTION__ . '()</b>' );
		if ( defined( 'EWWW_IMAGE_OPTIMIZER_DISABLE_STL' ) && EWWW_IMAGE_OPTIMIZER_DISABLE_STL ) {
			$this->debug_message( 'stl disabled by user' );
			return false;
		}
		if ( ! $this->is_ini_value_changeable( 'max_execution_time' ) ) {
			$this->debug_message( 'max_execution_time not configurable' );
			return false;
		}
		return $this->function_exists( 'set_time_limit' );
	}

	protected function is_ini_value_changeable( $setting ) {
		static $ini_all;

		if ( ! isset( $ini_all ) ) {
			$ini_all = false;
			// Sometimes `ini_get_all()` is disabled via the `disable_functions` option for "security purposes".
			if ( $this->function_exists( 'ini_get_all' ) ) {
				$ini_all = ini_get_all();
			}
		}
		// Bit operator to workaround https://bugs.php.net/bug.php?id=44936 which changes access level to 63 in PHP 5.2.6 - 5.2.17.
		if ( isset( $ini_all[ $setting ]['access'] ) && ( INI_ALL === ( $ini_all[ $setting ]['access'] & 7 ) || INI_USER === ( $ini_all[ $setting ]['access'] & 7 ) ) ) {
			return true;
		}
		// If we were unable to retrieve the details, fail gracefully to assume it's changeable.
		if ( ! is_array( $ini_all ) ) {
			return true;
		}
		return false;
	}

	/**
	* Checks if a function is disabled or does not exist.
	*
	* @param string $function_name The name of a function to test.
	* @return bool True if the function is available, False if not.
	*/
	protected function function_exists( $function_name ) {
		if ( function_exists( 'ini_get' ) ) {
			$disabled = @ini_get( 'disable_functions' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$this->debug_message( "disable_functions: $disabled" );
		}
		if ( extension_loaded( 'suhosin' ) && function_exists( 'ini_get' ) ) {
			$suhosin_disabled = @ini_get( 'suhosin.executor.func.blacklist' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$this->debug_message( "suhosin_blacklist: $suhosin_disabled" );
			if ( ! empty( $suhosin_disabled ) ) {
				$suhosin_disabled = explode( ',', $suhosin_disabled );
				$suhosin_disabled = array_map( 'trim', $suhosin_disabled );
				$suhosin_disabled = array_map( 'strtolower', $suhosin_disabled );
				if ( function_exists( $function_name ) && ! in_array( trim( $function_name, '\\' ), $suhosin_disabled, true ) ) {
					return true;
				}
				return false;
			}
		}
		return function_exists( $function_name );
	}

	// check filesize, and prevent errors by ensuring file exists, and that the cache has been cleared
	protected function filesize( $file ) {
		$file = realpath( $file );
		if ( is_file( $file ) ) {
			// flush the cache for filesize
			clearstatcache();
			// find out the size of the new PNG file
			return filesize( $file );
		} else {
			return 0;
		}
	}

	/**
	 * Generate a unique filename for a converted image.
	 *
	 * @param string $file The filename to test for uniqueness.
	 * @param string $fileext An iterator to append to the base filename, starts empty usually.
	 * @return array {
	 *     Filename information.
	 *
	 *     @type string A unique filename for converting an image.
	 *     @type int|string The iterator used for uniqueness.
	 * }
	 */
	public function unique_filename( $file, $fileext ) {
		// Strip the file extension.
		$filename = preg_replace( '/\.\w+$/', '', $file );
		if ( ! is_file( $filename . $fileext ) ) {
			return array( $filename . $fileext, '' );
		}
		// Set the increment to 1 for starters.
		$filenum = 1;
		// But it must be only letters, numbers, or underscores.
		$filenum = ( preg_match( '/^[\w\d]*$/', $filenum ) ? $filenum : 1 );
		$suffix  = ( ! empty( $filenum ) ? '-' . $filenum : '' );
		// While a file exists with the current increment.
		while ( file_exists( $filename . $suffix . $fileext ) ) {
			// Increment the increment...
			++$filenum;
			$suffix = '-' . $filenum;
		}
		// All done, let's reconstruct the filename.
		return array( $filename . $suffix . $fileext, $filenum );
	}

	/**
	 * Process an image.
	 *
	 * @param string $file Full absolute path to the image file.
	 * @return string The filename or false on error.
	 */
	public function optimize( $file ) {
		$this->debug_message( '<b>' . __FUNCTION__ . '()</b>' );
		session_write_close();
		// Initialize the original filename.
		$original         = $file;
		$result           = '';
		$this->converted  = false;
		$this->savings    = 0;
		$this->last_error = '';
		// Check that the file exists.
		if ( ! is_file( $file ) ) {
			$this->last_error = sprintf( 'Could not find %s', $file );
			$this->debug_message( "file does not appear to exist: $file" );
			$this->debug_log();
			return false;
		}
		// Check that the file is writable.
		if ( ! is_writable( $file ) ) {
			$this->last_error = sprintf( '%s is not writable', $file );
			$this->debug_message( "could not write to the file $file" );
			$this->debug_log();
			return false;
		}
		if ( $this->function_exists( 'fileperms' ) ) {
			$file_perms = substr( sprintf( '%o', fileperms( $file ) ), -4 );
		}
		$file_owner = 'unknown';
		$file_group = 'unknown';
		if ( $this->function_exists( 'posix_getpwuid' ) ) {
			$file_owner = posix_getpwuid( fileowner( $file ) );
			$file_owner = $file_owner['name'];
		}
		if ( $this->function_exists( 'posix_getgrgid' ) ) {
			$file_group = posix_getgrgid( filegroup( $file ) );
			$file_group = $file_group['name'];
		}
		$this->debug_message( "permissions: $file_perms, owner: $file_owner, group: $file_group" );
		$type = $this->mimetype( $file, 'i' );
		if ( ! $type ) {
			$this->debug_message( 'could not find any functions for mimetype detection' );
			//otherwise we store an error message since we couldn't get the mime-type
			$this->last_error = 'Unknown file type';
			$this->debug_log();
			return false;
		}
		// Not an image or pdf.
		if ( strpos( $type, 'image' ) === false && strpos( $type, 'pdf' ) === false ) {
			$this->debug_message( "unsupported mimetype: $type" );
			$this->last_error = "Unsupported file type: $type";
			$this->debug_log();
			return false;
		}
		if ( ini_get( 'max_execution_time' ) && ini_get( 'max_execution_time' ) < 90 && $this->stl_check() ) {
			set_time_limit( 0 );
		}
		// get the original image size
		$orig_size = $this->filesize( $file );
		$this->debug_message( "original filesize: $orig_size" );
		$new_size          = 0;
		$this->backup_hash = '';
		// set the optimization process to OFF
		$optimize = false;
		// toggle the convert process to ON
		$convert = true;
		// run the appropriate optimization/conversion for the mime-type
		switch ( $type ) {
			case 'image/jpeg':
				$png_size = 0;
				// if jpg2png conversion is enabled.
				if ( $this->jpg_to_png ) {
					// get a unique filename for the png image
					list( $pngfile, $filenum ) = $this->unique_filename( $file, '.png' );
				} else {
					// otherwise, set it to OFF
					$convert = false;
					$pngfile = '';
				}
				if ( $this->jpg_level ) {
					list( $file, $result, $new_size ) = $this->cloud_optimizer( $file, $type, $convert, $pngfile, 'image/png' );
					if ( $this->converted ) {
						if ( $this->delete_originals ) {
							// delete the original JPG
							unlink( $original );
						}
						$this->webp_create( $file, $new_size, 'image/png' );
					} else {
						$this->webp_create( $file, $new_size, $type );
					}
					break;
				} else {
					$this->webp_create( $file, $new_size, $type );
				}
				break;
			case 'image/png':
				if ( $this->png_to_jpg ) {
					$this->debug_message( 'PNG to JPG conversion turned on' );
					list( $jpgfile, $filenum ) = $this->unique_filename( $file, '.jpg' );
				} else {
					$this->debug_message( 'PNG to JPG conversion turned off' );
					// turn the conversion process OFF
					$convert = false;
					$jpgfile = '';
				}
				if ( $this->png_level ) {
					list( $file, $result, $new_size ) = $this->cloud_optimizer( $file, $type, $convert, $jpgfile, 'image/jpeg', $this->jpg_background, $this->jpg_quality );
					if ( $this->converted ) {
						if ( $this->delete_originals ) {
							// delete the original JPG
							unlink( $original );
						}
						$this->webp_create( $file, $new_size, 'image/jpeg' );
					} else {
						$this->webp_create( $file, $new_size, $type );
					}
				} else {
					$this->webp_create( $file, $new_size, $type );
				}
				break;
			case 'image/gif':
				// If gif2png is turned on.
				if ( $this->gif_to_png && ! $this->is_animated( $file ) ) {
					// Construct the filename for the new PNG.
					list( $pngfile, $filenum ) = $this->unique_filename( $file, '.png' );
				} else {
					// turn conversion OFF
					$convert = false;
					$pngfile = '';
				}
				if ( $this->gif_level ) {
					list( $file, $result, $new_size ) = $this->cloud_optimizer( $file, $type, $convert, $pngfile, 'image/png' );
					if ( $this->converted ) {
						if ( $this->delete_originals ) {
							// delete the original JPG
							unlink( $original );
						}
						$this->webp_create( $file, $new_size, 'image/png' );
					}
				}
				break;
			case 'application/pdf':
				if ( $this->pdf_level ) {
					list( $file, $result, $new_size ) = $this->cloud_optimizer( $file, $type );
				}
				break;
			case 'image/svg+xml':
				if ( $this->svg_level ) {
					list( $file, $result, $new_size ) = $this->cloud_optimizer( $file, $type );
				}
				break;
			default:
				// If not a JPG, PNG, GIF, PDF, or SVG tell the user we don't work with strangers.
				$this->last_error = "Unsupported file type: $type";
				$this->debug_log();
				return false;
		} // End switch();
		// If the cloud api license limit has been exceeded.
		if ( 'exceeded' === $result ) {
			$this->last_error = 'License exceeded';
			$this->debug_log();
			return false;
		}
		if ( ! empty( $new_size ) ) {
			// Set correct file permissions.
			$stat  = stat( dirname( $file ) );
			$perms = $stat['mode'] & 0000666; //same permissions as parent folder, strip off the executable bits
			chmod( $file, $perms );
			$this->savings = $orig_size - $new_size;
			$this->debug_log();
			return $file;
		}
		$this->debug_log();
		// otherwise, send back the filename
		return $file;
	}

	/**
	 * Creates webp images alongside JPG and PNG files.
	 *
	 * @param string $file The name of the JPG/PNG file.
	 * @param int    $orig_size The filesize of the JPG/PNG file.
	 * @param string $type The mime-type of the incoming file.
	 * @param bool   $recreate True to keep the .webp image even if it is larger than the JPG/PNG.
	 */
	public function webp_create( $file, $orig_size, $type ) {
		$this->debug_message( '<b>' . __FUNCTION__ . '()</b>' );
		$webpfile = $file . '.webp';
		if ( ! $this->webp ) {
			return;
		} elseif ( is_file( $webpfile ) ) {
			$this->debug_message( 'webp file exists, not recreating' );
			return;
		}
		$this->cloud_optimizer( $file, $type, false, $webpfile, 'image/webp' );
		$webp_size = $this->filesize( $webpfile );
		$this->debug_message( "webp is $webp_size vs. $type is $orig_size" );
		if ( is_file( $webpfile ) && $orig_size < $webp_size && ! $this->$webp_force ) {
			$this->debug_message( 'webp file was too big, deleting' );
			unlink( $webpfile );
		} elseif ( is_file( $webpfile ) ) {
			// Set correct file permissions.
			$stat  = stat( dirname( $webpfile ) );
			$perms = $stat['mode'] & 0000666; // same permissions as parent folder, strip off the executable bits.
			chmod( $webpfile, $perms );
		}
	}

	// adds our version to the useragent for http requests
	public function cloud_useragent() {
		return 'EWWWIO PHP/' . EWWW_IMAGE_OPTIMIZER_VERSION;
	}

	// submits the api key for verification
	public function verify_key() {
		$this->debug_message( '<b>' . __FUNCTION__ . '()</b>' );
		if ( empty( $this->api_key ) ) {
			$this->debug_message( 'no api key' );
			$this->jpg_level = 0;
			$this->png_level = 0;
			$this->gif_level = 0;
			$this->pdf_level = 0;
			$this->debug_log();
			return false;
		}
		$result = $this->post_key( 'optimize.exactlywww.com', 'https', $this->api_key );
		if ( empty( $result->success ) ) {
			$result->throw_for_status( false );
			$this->debug_message( 'verification failed' );
		} elseif ( ! empty( $result->body ) && preg_match( '/(great|exceeded)/', $result->body ) ) {
			$verified = $result->body;
			if ( preg_match( '/exceeded/', $verified ) ) {
				$this->exceeded = true;
			}
			if ( false !== strpos( $result->body, 'expired' ) ) {
				$this->api_key = '';
			}
			$this->debug_message( 'verification success' );
		} else {
			if ( false !== strpos( $result->body, 'invalid' ) ) {
				$this->api_key = '';
			}
			$this->debug_message( 'verification failed' );
			if ( $this->function_exists( 'print_r' ) ) {
				$this->debug_message( print_r( $result, true ) );
			}
		}
		if ( empty( $verified ) ) {
			$this->debug_log();
			return false;
		}
		$this->debug_message( 'verification body contents: ' . $result->body );
		$this->debug_log();
		return $verified;
	}

	public function post_key( $ip, $transport, $key ) {
		$useragent = $this->cloud_useragent();
		$result    = WpOrg\Requests\Requests::post(
			"$transport://$ip/verify/",
			array(),
			array(
				'api_key' => $key,
			),
			array(
				'timeout'   => 5,
				'useragent' => $useragent,
			)
		);
		return $result;
	}

	// checks the provided api key for quota information
	public function quota() {
		$this->debug_message( '<b>' . __FUNCTION__ . '()</b>' );
		$url    = 'https://optimize.exactlywww.com/quota/';
		$result = WpOrg\Requests\Requests::post(
			$url,
			array(),
			array(
				'api_key' => $this->api_key,
			),
			array(
				'timeout'   => 5,
				'useragent' => $this->cloud_useragent(),
			)
		);
		if ( ! $result->success ) {
			$result->throw_for_status( false );
			$this->debug_message( 'quota request failed: ' . $result->status_code );
			return '';
		} elseif ( ! empty( $result->body ) ) {
			$this->debug_message( 'quota data retrieved: ' . $result->body );
			$quota = explode( ' ', $result->body );
			if ( 0 === (int) $quota[0] && $quota[1] > 0 ) {
				return sprintf( 'optimized %1$d images, usage will reset in %2$d days.', $quota[1], $quota[2] );
			} elseif ( 0 === (int) $quota[0] && $quota[1] < 0 ) {
				return sprintf( '%1$d image credits remaining.', abs( $quota[1] ) );
			} elseif ( $quota[0] > 0 && $quota[1] < 0 ) {
				$real_quota = $quota[0] - $quota[1];
				return sprintf( '%1$d image credits remaining.', $real_quota );
			} elseif ( 0 === (int) $quota[0] && 0 === (int) $quota[1] && 0 === (int) $quota[2] ) {
				return 'no credits remaining, please purchase more.';
			} else {
				return sprintf( 'used %1$d of %2$d, usage will reset in %3$d days.', $quota[1], $quota[0], $quota[2] );
			}
		}
		return '';
	}

	/**
	 * Submits an image to the cloud optimizer and saves the optimized image to disk.
	 *
	 *
	 * @global object $ewww_image Contains more information about the image currently being processed.
	 *
	 * @param string $file Full absolute path to the image file.
	 * @param string $type Mimetype of $file.
	 * @param bool   $convert Optional. True if we want to attempt conversion of $file. Default false.
	 * @param string $newfile Optional. Filename to be used if image is converted. Default null.
	 * @param string $newtype Optional. Mimetype expected if image is converted. Default null.
	 * @param bool   $fullsize Optional. True if this is an original upload. Default false.
	 * @param array  $jpg_fill Optional. Fill color for PNG to JPG conversion in hex format.
	 * @param int    $jpg_quality Optional. JPG quality level. Default null. Accepts 1-100.
	 * @return array {
	 *     Information about the cloud optimization.
	 *
	 *     @type string Filename of the optimized version.
	 *     @type string Set to 'exceeded' if the API key is out of credits.
	 *     @type int File size of the (new) image.
	 * }
	 */
	public function cloud_optimizer( $file, $type, $convert = false, $newfile = null, $newtype = null, $jpg_fill = '', $jpg_quality = 82 ) {
		$this->debug_message( '<b>' . __FUNCTION__ . '()</b>' );
		if ( $this->exceeded || empty( $this->api_key ) ) {
			return array( $file, 'exceeded', 0 );
		}
		if ( ! $this->remove_meta ) {
			$metadata = 1;
		} else {
			$metadata = 0;
		}
		if ( empty( $convert ) ) {
			$convert = 0;
		} else {
			$convert = 1;
		}
		$lossy_fast = 0;
		$compress   = 0;
		if ( 'image/svg+xml' === $type && 10 === (int) $this->svg_level ) {
			$compress = 1;
		}
		if ( 'image/png' === $type && $this->png_level >= 20 ) {
			$lossy = 1;
			if ( 20 === (int) $this->png_level ) {
				$lossy_fast = 1;
			}
		} elseif ( 'image/jpeg' === $type && $this->jpg_level >= 20 ) {
			$lossy = 1;
			if ( 20 === (int) $this->jpg_level ) {
				$lossy_fast = 1;
			}
		} elseif ( 'application/pdf' === $type && 20 === (int) $this->pdf_level ) {
			$lossy = 1;
		} else {
			$lossy = 0;
		}
		if ( 'image/webp' === $newtype ) {
			$webp        = 1;
			$jpg_quality = $this->webp_quality ? $this->webp_quality : 82;
		} else {
			$webp = 0;
		}
		if ( ! $webp && $this->backup ) {
			$this->backup_hash = uniqid() . hash( 'sha256', $file );
		}
		$domain      = $this->backup_domain;
		$jpg_fill    = $this->sanitize_background( $jpg_fill );
		$jpg_quality = (int) $jpg_quality;
		$this->debug_message( "file: $file " );
		$this->debug_message( "type: $type" );
		$this->debug_message( "convert: $convert" );
		$this->debug_message( "newfile: $newfile" );
		$this->debug_message( "newtype: $newtype" );
		$this->debug_message( "webp: $webp" );
		$this->debug_message( "jpg fill: $jpg_fill" );
		$this->debug_message( "jpg quality: $jpg_quality" );
		$this->debug_message( "svg compress: $compress" );
		$url      = 'https://optimize.exactlywww.com/v2/';
		$boundary = $this->generate_password( 24 );

		$useragent   = $this->cloud_useragent();
		$headers     = array(
			'content-type' => 'multipart/form-data; boundary=' . $boundary,
		);
		$post_fields = array(
			'filename'   => $file,
			'convert'    => $convert,
			'metadata'   => $metadata,
			'api_key'    => $this->api_key,
			'jpg_fill'   => $jpg_fill,
			'quality'    => $jpg_quality,
			'compress'   => $compress,
			'lossy'      => $lossy,
			'lossy_fast' => $lossy_fast,
			'webp'       => $webp,
			'backup'     => $this->backup_hash,
			'domain'     => $domain,
		);

		$payload = '';
		foreach ( $post_fields as $name => $value ) {
			$payload .= '--' . $boundary;
			$payload .= "\r\n";
			$payload .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
			$payload .= $value;
			$payload .= "\r\n";
		}

		$payload .= '--' . $boundary;
		$payload .= "\r\n";
		$payload .= 'Content-Disposition: form-data; name="file"; filename="' . basename( $file ) . '"' . "\r\n";
		$payload .= 'Content-Type: ' . $type . "\r\n";
		$payload .= "\r\n";
		$payload .= file_get_contents( $file );
		$payload .= "\r\n";
		$payload .= '--' . $boundary;
		$payload .= 'Content-Disposition: form-data; name="submitHandler"' . "\r\n";
		$payload .= "\r\n";
		$payload .= "Upload\r\n";
		$payload .= '--' . $boundary . '--';

		$response = WpOrg\Requests\Requests::post(
			$url,
			$headers,
			$payload,
			array(
				'timeout'   => 300,
				'useragent' => $useragent,
			)
		);
		if ( ! $response->success ) {
			$response->throw_for_status( false );
			$this->debug_message( 'optimize failed, see exception' );
			return array( $file, 'cloud optimize failed', 0 );
		} else {
			$tempfile = $file . '.tmp';
			file_put_contents( $tempfile, $response->body );
			$orig_size = filesize( $file );
			$newsize   = $orig_size;
			$msg       = '';
			if ( 100 > strlen( $response->body ) && strpos( $response->body, 'invalid' ) ) {
				$this->debug_message( 'License invalid' );
				$this->api_key = '';
			} elseif ( 100 > strlen( $response->body ) && strpos( $response->body, 'exceeded quota' ) ) {
				$this->debug_message( 'Soft quota Exceeded' );
				$this->exceeded = true;
				$msg            = 'exceeded quota';
			} elseif ( 100 > strlen( $response->body ) && strpos( $response->body, 'exceeded' ) ) {
				$this->debug_message( 'License Exceeded' );
				$this->exceeded = true;
				$msg            = 'exceeded';
			} elseif ( $this->mimetype( $tempfile, 'i' ) === $type ) {
				$newsize = filesize( $tempfile );
				$this->debug_message( "cloud results: $newsize (new) vs. $orig_size (original)" );
				rename( $tempfile, $file );
			} elseif ( $this->mimetype( $tempfile, 'i' ) === 'image/webp' ) {
				$newsize = filesize( $tempfile );
				$this->debug_message( "cloud results: $newsize (new) vs. $orig_size (original)" );
				rename( $tempfile, $newfile );
			} elseif ( ! is_null( $newtype ) && ! is_null( $newfile ) && $this->mimetype( $tempfile, 'i' ) === $newtype ) {
				$this->converted = true;
				$newsize         = filesize( $tempfile );
				$this->debug_message( "cloud results: $newsize (new) vs. $orig_size (original)" );
				rename( $tempfile, $newfile );
				$file = $newfile;
			}
			clearstatcache();
			if ( is_file( $tempfile ) ) {
				unlink( $tempfile );
			}
			return array( $file, $msg, $newsize );
		}
	}
}
