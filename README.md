# EWWW Image Optimizer - PHP library

License: GPLv3

This is a PHP library that you can use to integrate with the EWWW Image Optimizer [Compress API](https://docs.ewww.io/article/114-compress-api-reference). The Compress API can be used to reduce image filesize using lossless and lossy methods as well as image format conversion.

By default, EWWW Image Optimizer uses lossy JPG and lossless PNG optimization techniques, The lossy optimization for JPG and PNG files uses sophisticated algorithms to minimize perceptual quality loss, which is vastly different than setting a static quality/compression level.

### WebP Images

Can generate WebP versions of your images (will not remove originals, since you'll need both versions to support all browsers), and enables you to serve even smaller images to supported browsers.

## Usage
No Composer support yet, the library bundles it's own copy of Requests by rmccue and friends, and is known to be compatible with version 2.0.8.

Include the library, and start rolling:
```php
include_once( 'ewwwio-php/ewwwio.php' );
$ewwwio = new EWWWIO( 'abc123' ); // API key is required at instantiation.
$result = $ewwwio->optimize( '/var/www/images/sample.jpg' );
if ( ! $result ) { // find out what the problem was...
    echo $ewwwio->get_error() . "\n";
}
```

You can also verify your key like so:
```php
if ( $ewwwio->verify_key() ) {
        echo "huzzah\n";
} else {
        echo "booo\n";
}
```

Options are set as properties/attributes, and you can inspect the available API options in ewwwio.php:
```php
$ewwwio->debug = true; // enables logging to debug.log
$ewwwio->jpg_level = 30; // Maximum compression, 20 = regular lossy, and 10 = lossless
$ewwwio->webp = true; // Generates a .webp image alongside the optimized image if WebP is smaller.
$ewwwio->webp_force = true; // Always keep the generated WebP, even if it is a little bigger.
```


## Changelog

### 1.1
* updated to Requests 2.x
* updated code formatting

### 1.0
* fixed conversion bugs, fully tested and marking stable

### 0.90
* initial release, may eat your cat
