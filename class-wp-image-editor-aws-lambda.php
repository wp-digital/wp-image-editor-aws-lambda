<?php

use Aws\Lambda\LambdaClient;

/**
 * Class WP_Image_Editor_AWS_Lambda
 */
class WP_Image_Editor_AWS_Lambda extends WP_Image_Editor
{
    /**
     * @var array
     */
    protected $_operations = [];
    /**
     * @var LambdaClient|null
     */
    protected $_lambda_client = null;

    /**
     * Checks to see if current environment supports the editor chosen.
     *
     * @static
     * @access public
     *
     * @param array $args
     *
     * @return bool
     */
    public static function test( $args = [] )
    {
        return defined( 'AWS_LAMBDA_IMAGE_BUCKET' )
            && defined( 'AWS_LAMBDA_IMAGE_KEY' )
            && defined( 'AWS_LAMBDA_IMAGE_SECRET' )
            && defined( 'AWS_LAMBDA_IMAGE_REGION' );
    }

    /**
     * Checks to see if editor supports the mime-type specified.
     *
     * @static
     * @access public
     *
     * @param string $mime_type
     *
     * @return bool
     */
    public static function supports_mime_type( $mime_type )
    {
        return in_array( $mime_type, [
            'image/gif',
            'image/jpeg',
            'image/pjpeg',
            'image/png',
            'image/svg+xml',
            'image/vnd.wap.wbmp',
            'image/webp',
        ] );
    }

    /**
     * @param string $filename
     *
     * @return string
     */
    public static function filename_to_s3_key( $filename )
    {
        if ( false !== ( $start = strpos( $filename, AWS_LAMBDA_IMAGE_BUCKET ) ) ) {
            return substr( $filename, $start + strlen( AWS_LAMBDA_IMAGE_BUCKET ) + 1 );
        }

        $upload_dir = wp_get_upload_dir();
        $filename = str_replace( $upload_dir['basedir'], '', $filename );
        $filename = ltrim( $filename, '/' );

        return $filename;
    }

    /**
     * Loads image from $this->file into editor.
     *
     * @access protected
     *
     * @return bool|WP_Error True if loaded; WP_Error on failure.
     */
    public function load()
    {
        if (
            !is_file( $this->file ) && !preg_match( '|^https?://|', $this->file )
            && false === strpos( $this->file, AWS_LAMBDA_IMAGE_BUCKET )
        ) {
            return new WP_Error( 'error_loading_image', __( 'File doesn&#8217;t exist?' ), $this->file );
        }

        $updated_size = $this->update_size();

        if ( is_wp_error( $updated_size ) ) {
            return $updated_size;
        }

        $this->_lambda_client = new LambdaClient( [
            'credentials' => [
                'key'    => AWS_LAMBDA_IMAGE_KEY,
                'secret' => AWS_LAMBDA_IMAGE_SECRET,
            ],
            'region'      => AWS_LAMBDA_IMAGE_REGION,
            'version'     => 'latest',
        ] );
        $this->_operations = [];

        return $this->set_quality();
    }

    /**
     * Saves current image to file.
     *
     * @access public
     *
     * @param string $destfilename
     * @param string $mime_type
     *
     * @return array|WP_Error {'path'=>string, 'file'=>string, 'width'=>int, 'height'=>int, 'mime-type'=>string}
     */
    public function save( $destfilename = null, $mime_type = null )
    {
        $saved = $this->_save( $destfilename, $mime_type );

        if ( !is_wp_error( $saved ) ) {
            $this->file = $saved['path'];
            $this->mime_type = $saved['mime-type'];
        }

        return $saved;
    }

    /**
     * Sets or updates current image size.
     *
     * @param int $width
     * @param int $height
     *
     * @return true|WP_Error
     */
    protected function update_size( $width = null, $height = null )
    {
        if ( !$width || !$height ) {
            $size = @getimagesize( $this->file );

            if ( !$size ) {
                return new WP_Error( 'invalid_image', __( 'Could not read image size.' ), $this->file );
            }

            if ( !$width ) {
                $width = $size[0];
            }

            if ( !$height ) {
                $height = $size[1];
            }

            $this->mime_type = $size['mime'];
        }

        return parent::update_size( $width, $height );
    }

    /**
     * @param string|null $filename
     * @param string|null $mime_type
     *
     * @return array|WP_Error
     */
    protected function _save( $filename = null, $mime_type = null )
    {
        list( $filename, $extension, $mime_type, $s3_key ) = $this->_get_output_format( $filename, $mime_type );

        try {
            $result = $this->_run_lambda( [
                'new_filename' => $s3_key,
            ] );
        } catch ( Exception $exception ) {
            return new WP_Error( 'image_save_error', $exception->getMessage(), $filename );
        }

        if ( $result['StatusCode'] < WP_Http::OK || $result['StatusCode'] >= WP_Http::MULTIPLE_CHOICES ) {
            return new WP_Error( 'image_save_error', $result['FunctionError'], $filename );
        }

        return $this->_get_output( $filename, $mime_type );
    }

    /**
     * @param string|null $filename
     * @param string|null $mime_type
     *
     * @return array|WP_Error
     */
    protected function _save_async( $filename = null, $mime_type = null )
    {
        list( $filename, $extension, $mime_type, $s3_key ) = $this->_get_output_format( $filename, $mime_type );

        if ( is_wp_error( $s3_key ) ) {
            return $s3_key;
        }

        $promise = $this->_run_lambda_async( [
            'new_filename' => $s3_key,
        ] );

        return [
            $promise,
            $this->_get_output( $filename, $mime_type ),
        ];
    }

    /**
     * @param string|null $filename
     * @param string|null $mime_type
     *
     * @return array
     */
    protected function _get_output_format( $filename, $mime_type )
    {
        list( $filename, $extension, $mime_type ) = $this->get_output_format( $filename, $mime_type );

        if ( !$filename ) {
            $filename = $this->generate_filename( null, null, $extension );
        }

        return [
            $filename,
            $extension,
            $mime_type,
            static::filename_to_s3_key( $filename ),
        ];
    }

    /**
     * @param string $filename
     * @param string $mime_type
     *
     * @return array
     */
    protected function _get_output( $filename, $mime_type )
    {
        /** This filter is documented in wp-includes/class-wp-image-editor-gd.php */
        return [
            'path'      => $filename,
            'file'      => wp_basename( apply_filters( 'image_make_intermediate_size', $filename ) ),
            'width'     => $this->size['width'],
            'height'    => $this->size['height'],
            'mime-type' => $mime_type,
        ];
    }

    /**
     * @param array $args
     *
     * @return \Aws\Result
     */
    protected function _run_lambda( array $args )
    {
        $payload = json_encode( $this->_get_lambda_args( $args ) );

        return $this->_lambda_client->invoke( [
            'FunctionName' => $this->_get_lambda_function(),
            'Payload'      => $payload,
        ] );
    }

    /**
     * @param array $args
     *
     * @return \GuzzleHttp\Promise\Promise
     */
    protected function _run_lambda_async( array $args )
    {
        $payload = json_encode( $this->_get_lambda_args( $args ) );

        return $this->_lambda_client->invokeAsync( [
            'FunctionName' => $this->_get_lambda_function(),
            'InvokeArgs'   => $payload,
            'Payload'      => $payload,
        ] );
    }

    /**
     * @return string
     */
    protected function _get_lambda_function()
    {
        return defined( 'AWS_LAMBDA_IMAGE_FUNCTION' )
            ? AWS_LAMBDA_IMAGE_FUNCTION
            : 'wordpress_image_processor-production';
    }

    /**
     * @param array $args
     *
     * @return array
     */
    protected function _get_lambda_args( $args = [] )
    {
        return wp_parse_args( $args, [
            'bucket'       => AWS_LAMBDA_IMAGE_BUCKET,
            'filename'     => static::filename_to_s3_key( $this->file ),
            'new_filename' => '',
            'quality'      => $this->get_quality(),
            'operations'   => $this->_operations,
            'return'       => 'bucket',
        ] );
    }

    /**
     * Resizes current image.
     *
     * At minimum, either a height or width must be provided.
     * If one of the two is set to null, the resize will
     * maintain aspect ratio according to the provided dimension.
     *
     * @access public
     *
     * @param  int|null $max_w Image width.
     * @param  int|null $max_h Image height.
     * @param  bool $crop
     *
     * @return bool|WP_Error
     */
    public function resize( $max_w, $max_h, $crop = false )
    {
        if ( $this->size['width'] == $max_w && $this->size['height'] == $max_h ) {
            return true;
        }

        $dims = image_resize_dimensions( $this->size['width'], $this->size['height'], $max_w, $max_h, $crop );

        if ( !$dims ) {
            return new WP_Error( 'error_getting_dimensions', __( 'Could not calculate resized image dimensions' ) );
        }

        list( $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h ) = $dims;

        if ( $crop ) {
            return $this->crop( $src_x, $src_y, $src_w, $src_h, $dst_w, $dst_h );
        }

        $this->_add_operation( 'resize', [
            'width'  => $dst_w,
            'height' => $dst_h,
        ] );

        return $this->update_size( $dst_w, $dst_h );
    }

    /**
     * Resize multiple images from a single source.
     *
     * @param array $sizes {
     *     An array of image size arrays. Default sizes are 'small', 'medium', 'medium_large', 'large'.
     *
     *     Either a height or width must be provided.
     *     If one of the two is set to null, the resize will
     *     maintain aspect ratio according to the provided dimension.
     *
     *     @type array $size {
     *         Array of height, width values, and whether to crop.
     *
     *         @type int  $width  Image width. Optional if `$height` is specified.
     *         @type int  $height Image height. Optional if `$width` is specified.
     *         @type bool $crop   Optional. Whether to crop the image. Default false.
     *     }
     * }
     *
     * @return array An array of resized images' metadata by size.
     */
    public function multi_resize( $sizes )
    {
        $metadata = [];
        $orig_size = $this->size;
        $orig_operations = $this->_operations;
        $first = true;
        $promises = [];

        foreach ( $sizes as $size => $size_data ) {
            $this->_operations = [];

            if ( !isset( $size_data['width'] ) && !isset( $size_data['height'] ) ) {
                continue;
            }

            if ( !isset( $size_data['width'] ) ) {
                $size_data['width'] = null;
            }
            if ( !isset( $size_data['height'] ) ) {
                $size_data['height'] = null;
            }

            if ( !isset( $size_data['crop'] ) ) {
                $size_data['crop'] = false;
            }

            $this->resize( $size_data['width'], $size_data['height'], $size_data['crop'] );
            $duplicate = $orig_size['width'] == $size_data['width'] && $orig_size['height'] == $size_data['height'];

            if ( !$duplicate ) {
                if ( $first ) {
                    $resized = $this->_save();
                    $first = false;

                    if ( !is_wp_error( $resized ) && $resized ) {
                        unset( $resized['path'] );
                        $metadata[ $size ] = $resized;
                    }
                } else {
                    $resized = $this->_save_async();

                    if ( !is_wp_error( $resized ) && $resized ) {
                        list( $promise, $resized ) = $resized;
                        $promises[] = $promise;
                        unset( $resized['path'] );
                        $metadata[ $size ] = $resized;
                    }
                }
            }

            $this->size = $orig_size;
        }

        $this->_operations = $orig_operations;

        foreach ( $promises as $promise ) {
            $promise->wait();
        }

        return $metadata;
    }

    /**
     * Crops Image.
     *
     * @access public
     *
     * @param int $src_x The start x position to crop from.
     * @param int $src_y The start y position to crop from.
     * @param int $src_w The width to crop.
     * @param int $src_h The height to crop.
     * @param int $dst_w Optional. The destination width.
     * @param int $dst_h Optional. The destination height.
     * @param bool $src_abs Optional. If the source crop points are absolute.
     *
     * @return bool|WP_Error
     */
    public function crop( $src_x, $src_y, $src_w, $src_h, $dst_w = null, $dst_h = null, $src_abs = false )
    {
        if ( $src_abs ) {
            $src_w -= $src_x;
            $src_h -= $src_y;
        }

        $this->_add_operation( 'crop', [
            'src_x'              => $src_x,
            'src_y'              => $src_y,
            'src_width'          => $src_w,
            'src_height'         => $src_h,
            'destination_width'  => $dst_w,
            'destination_height' => $dst_h,
        ] );

        $new_width = $dst_w ? $dst_w : $src_w;
        $new_height = $dst_h ? $dst_h : $src_h;

        return $this->update_size( $new_width, $new_height );
    }

    /**
     * Rotates current image counter-clockwise by $angle.
     *
     * @access public
     *
     * @param float $angle
     * @return bool|WP_Error
     */
    public function rotate( $angle )
    {
        $this->_add_operation( 'rotate', [
            'angle' => $angle,
        ] );

        if ( absint( $angle ) % 180 === 0 ) {
            return true;
        } elseif ( absint( $angle ) % 90 === 0 ) {
            $old_size = $this->get_size();

            return $this->update_size( $old_size['height'], $old_size['width'] );
        }

        return true;
    }

    /**
     * Flips current image.
     *
     * @access public
     *
     * @param bool $horz Flip along Horizontal Axis
     * @param bool $vert Flip along Vertical Axis
     *
     * @return bool|WP_Error
     */
    public function flip( $horz, $vert )
    {
        $this->_add_operation( 'flip', [
            'horizontal' => $horz,
            'vertical'   => $vert,
        ] );

        return true;
    }

    /**
     * @param $operation
     * @param $params
     */
    protected function _add_operation( $operation, $params )
    {
        $this->_operations[] = array_merge( [
            'action' => $operation,
        ], $params );
    }

    /**
     * Streams current image to browser.
     *
     * @access public
     *
     * @param string $mime_type
     *
     * @return bool|WP_Error
     */
    public function stream( $mime_type = null )
    {
        $ext = $this->get_extension( $mime_type );
        list( $filename, $extension, $mime_type ) = $this->get_output_format( "stream.{$ext}", $mime_type );

        try {
            $result = $this->_run_lambda( [
                'new_filename' => $filename,
                'return'       => 'stream',
            ] );
        } catch ( Exception $exception ) {
            return new WP_Error( 'image_save_error', $exception->getMessage() );
        }

        if ( $result['StatusCode'] < WP_Http::OK || $result['StatusCode'] >= WP_Http::MULTIPLE_CHOICES ) {
            return new WP_Error( 'image_stream_error', $result['FunctionError'] );
        }

        /**
         * @var \GuzzleHttp\Psr7\Stream $payload
         */
        $payload = $result['Payload'];
        $base64_data = $payload->getContents();
        header( "Content-Type: $mime_type" );
        print base64_decode( $base64_data );

        return true;
    }
}