<?php
/**
 * Plugin Name: AWS Lambda Image Editor
 * Description: Image Editor Class for Image Manipulation through Node.js modules and AWS Lambda.
 * Version: 2.0.0
 * Author: Innocode
 * Author URI: https://innocode.com
 * Requires at least: 4.9.8
 * Tested up to: 5.6
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

define( 'AWS_LAMBDA_IMAGE_EDITOR_VERSION', '2.0.0' );

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once __DIR__ . '/src/class-wp-image-editor-aws-lambda.php';
require_once __DIR__ . '/src/functions.php';

add_filter( 'wp_image_editors', 'Innocode\ImageEditorAWSLambda\add_implementation' );
add_filter( 'wp_read_image_metadata', 'Innocode\ImageEditorAWSLambda\read_image_metadata', 10, 3 );

if ( Innocode\ImageEditorAWSLambda\is_s3_uploads_enabled() ) {
    add_filter( 'plugins_loaded', 'Innocode\ImageEditorAWSLambda\remove_s3_uploads_hooks', 999 );
}
