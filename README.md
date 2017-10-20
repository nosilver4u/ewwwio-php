# EWWW Image Optimizer - PHP library

License: GPLv3

IMPORTANT: This is under development and not yet functional.

This is a PHP library that you can use to integrate with the [EWWW Image Optimizer API](https://ewww.io/). The API can be used to reduce image filesize using lossless and lossy methods as well as image format conversion.

By default, EWWW Image Optimizer uses lossy JPG and lossless PNG optimization techniques, The lossy optimization for JPG and PNG files uses sophisticated algorithms to minimize perceptual quality loss, which is vastly different than setting a static quality/compression level.

### Skips Previously Optimized Images

A record of optimized images can be stored in an (optional) SQLite3 or MySQL database so that the application does not attempt to re-optimize them unless they are modified.

### WebP Images

Can generate WebP versions of your images (will not remove originals, since you'll need both versions to support all browsers), and enables you to serve even smaller images to supported browsers.

### CDN Support (in the future)

Planning to add the ability to upload to the likes of Amazon S3, Azure Storage, and Cloudinary.

## Pre-requisites

The SQLite3 or Mysqli extensions are optional, but will allow EWWW IO to keep track of which images have been compressed already, if you intend to run it regularly. There is a sample config file at config.sample.php which you can copy to config.php and customize to your liking. If the SQLite3 or Mysqli extensions are available, options may also be stored in the database, otherwise, they will be read from the config file, or use the defaults.

## Frequently Asked Questions

### Can I resize my images with this library?

Not yet, but maybe in the future. [The WordPress plugin can though](https://ewww.io).


## Changelog

### 0.10
* initial release, may eat your cat
