<?php
/**
 * Plugin Name: AWS Lambda Image Editor
 * Description: Image Editor Class for Image Manipulation through Node.js modules and AWS Lambda.
 * Version: 1.0.0
 * Author: Innocode
 * Author URI: https://innocode.com
 * Requires at least: 4.9.8
 * Tested up to: 4.9.8
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

define( 'AWS_LAMBDA_IMAGE_EDITOR_VERSION', '1.0.0' );

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once __DIR__ . '/class-wp-image-editor-aws-lambda.php';

add_filter( 'wp_image_editors', function( $implementations ) {
    return array_merge( [ 'WP_Image_Editor_AWS_Lambda' ], $implementations );
} );

if( !defined( 'MAX_IMAGE_SIZE' ) ) {
    define( 'MAX_IMAGE_SIZE', 2600 );
}

add_filter( 'wp_handle_upload', function( $args ) {
    $filename = $args['file'];
    $type = $args['type'];

    if ( 0 === mb_strpos( $type, 'image/' ) && file_exists( $filename ) ) {
        $editor = wp_get_image_editor( $filename );

        if ( !is_wp_error( $editor ) ) {
            $size = $editor->get_size();

            if ( $size['width'] > MAX_IMAGE_SIZE || $size['height'] > MAX_IMAGE_SIZE ) {
                $editor->resize( MAX_IMAGE_SIZE, MAX_IMAGE_SIZE );
            }

            $editor->save( $filename );
        }
    }

    return $args;
}, 999 );

add_filter( 'big_image_size_threshold', function () {
    return false;
} );
