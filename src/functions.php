<?php

namespace Innocode\ImageEditorAWSLambda;

use Aws\S3\Exception\S3Exception;

/**
 * @param array $implementations
 *
 * @return array
 */
function add_implementation( array $implementations ) : array {
    return array_merge( [ 'WP_Image_Editor_AWS_Lambda' ], $implementations );
}

/**
 * @param array $file
 *
 * @return array
 */
function handle_upload( array $file ) : array {
    $type = $file['type'];
    $filename = $file['file'];

    if ( 0 === mb_strpos( $type, 'image/' ) ) {
        $editor = wp_get_image_editor( $filename );

        if ( ! is_wp_error( $editor ) ) {
            $editor->save( $filename );
        }
    }

    return $file;
}

/**
 * @param array $meta
 * @param string $file
 *
 * @return array
 */
function read_image_metadata( array $meta, string $file ) : array {
    $s3_meta = is_s3_uploads_enabled() ? get_s3_uploads_file_meta( $file ) : get_file_meta( $file );

    if ( empty( $s3_meta ) ) {
        return [];
    }

    foreach ( $s3_meta as $key => $value ) {
        $s3_meta[ $key == 'created' ? 'created_timestamp' : $key ] = sanitize_meta_value( $key, $value );
    }

    foreach ( [ 'title', 'caption', 'credit', 'copyright', 'camera', 'iso' ] as $key ) {
        if ( $s3_meta[ $key ] && ! seems_utf8( $s3_meta[ $key ] ) ) {
            $s3_meta[ $key ] = utf8_encode( $s3_meta[ $key ] );
        }
    }

    foreach ( $s3_meta['keywords'] as $key => $keyword ) {
        if ( ! seems_utf8( $keyword ) ) {
            $s3_meta['keywords'][ $key ] = utf8_encode( $keyword );
        }
    }

    $s3_meta = wp_kses_post_deep( $s3_meta );

    foreach ( $s3_meta as $key => $value ) {
        if ( empty( $meta[ $key ] ) && ! empty( $s3_meta[ $key ] ) ) {
            $meta[ $key ] = $s3_meta[ $key ];
        }
    }

    return $meta;
}

/**
 * @return bool
 */
function is_s3_uploads_enabled() : bool {
    return defined( 'S3_UPLOADS_BUCKET' )
        && function_exists( 's3_uploads_enabled' )
        && s3_uploads_enabled();
}

/**
 * @param string $file
 *
 * @return array
 */
function get_s3_uploads_file_meta( string $file ) : array {
    $s3_uploads = \S3_Uploads::get_instance();
    $bucket = $s3_uploads->get_s3_bucket();
    $prefix = "s3://$bucket";
    $prefix_len = strlen( $prefix );

    if ( substr( $file, 0, $prefix_len ) != $prefix ) {
        return [];
    }

    $cache_key = sanitize_key( $file );

    if ( false !== ( $metadata = wp_cache_get( $cache_key, 'innocode_image_editor_aws_lambda' ) ) ) {
        return $metadata;
    }

    $key = ltrim( substr( $file, $prefix_len ), '/' );

    try {
        $headers = $s3_uploads->s3()->headObject( [
            'Bucket' => $bucket,
            'Key'    => $key,
        ] );
    } catch ( S3Exception $exception ) {
        error_log( $exception->getMessage() );

        return [];
    }

    $metadata = $headers->get( 'Metadata' );

    if ( ! is_array( $metadata ) ) {
        $metadata = [];
    }

    wp_cache_set( $cache_key, $metadata, 'innocode_image_editor_aws_lambda' );

    return $metadata;
}

/**
 * @param string $file
 *
 * @return array
 */
function get_file_meta( string $file ) : array {
    $upload_dir = wp_upload_dir();
    $url = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $file );
    $context = stream_context_create( [
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ] );

    if ( false === ( $headers = get_headers( $url, true, $context ) ) ) {
        return [];
    }

    $prefix = 'x-amz-meta-';
    $prefix_len = strlen( $prefix );
    $metadata = [];

    foreach ( $headers as $name => $header ) {
        if ( substr( $name, 0, $prefix_len ) == $prefix ) {
            $key = substr( $name, $prefix_len );
            $metadata[ $key ] = $header;
        }
    }

    return $metadata;
}

/**
 * @param string $key
 * @param string $value
 *
 * @return false|float|int|string|string[]
 */
function sanitize_meta_value( string $key, string $value ) {
    if ( ! function_exists( 'wp_exif_frac2dec' ) ) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    switch ( $key ) {
        case 'aperture':
            return round( wp_exif_frac2dec( $value ), 2 );
        case 'focal_length':
        case 'shutter_speed':
            return (string) wp_exif_frac2dec( $value );
        case 'created':
            return strtotime( $value );
        case 'keywords':
            return explode( ',', $value );
        default:
            return trim( $value );
    }
}

function remove_s3_uploads_hooks() {
    if ( ! is_s3_uploads_enabled() ) {
        return;
    }

    remove_filter( 'wp_read_image_metadata', [ \S3_Uploads::get_instance(), 'wp_filter_read_image_metadata' ] );
}
