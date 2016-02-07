<?php
/*
Plugin Name: AWS Lambda Image Editor
Description:
Version: 0.1
Plugin
Author: Oleksandr Strikha
Author URI: https://github.com/shtrihstr
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/


require_once __DIR__ . '/vendor/autoload.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once __DIR__ . '/class-wp-image-editor-aws-lambda.php';

add_filter( 'wp_image_editors', function( $implementations ) {
    return array_merge( ['WP_Image_Editor_AWS_Lambda'], $implementations );
} );



if( ! defined( 'MAX_IMAGE_SIZE' ) ) {
    define( 'MAX_IMAGE_SIZE', 2600 );
}


add_filter( 'wp_handle_upload', function( $args ) {

    $filename = $args[ 'file' ];
    $type = $args[ 'type' ];

    if( 0 === mb_strpos( $type, 'image/' ) && file_exists( $filename ) ) {


        $editor = wp_get_image_editor( $filename );
        if ( ! is_wp_error( $editor ) ) {
            $size = $editor->get_size();
            if( $size['width'] > MAX_IMAGE_SIZE || $size['height'] > MAX_IMAGE_SIZE  ) {
                $editor->resize( MAX_IMAGE_SIZE, MAX_IMAGE_SIZE );
            }
            $editor->save( $filename );
        }

    }

    return $args;
}, 999 );
