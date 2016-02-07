<?php

use Aws\Lambda\LambdaClient;

class WP_Image_Editor_AWS_Lambda extends WP_Image_Editor {

    protected $_operations = [];

    /**
     * @var LambdaClient
     */
    protected $_lambda_client = null;

    protected $_file_s3_key = false;

    protected function _add_operation( $operation, $params )
    {
        $this->_operations[] = array_merge( [ 'action' => $operation ], $params );
    }

    /**
     * Checks to see if current environment supports the editor chosen.
     * Must be overridden in a sub-class.
     *
     * @since 3.5.0
     *
     * @static
     * @access public
     * @abstract
     *
     * @param array $args
     * @return bool
     */
    public static function test( $args = [] )
    {
        return defined( 'AWS_LAMBDA_IMAGE_BUCKET' ) && defined( 'AWS_LAMBDA_IMAGE_KEY' ) && defined( 'AWS_LAMBDA_IMAGE_SECRET' ) && defined( 'AWS_LAMBDA_IMAGE_REGION' );
    }

    /**
     * Checks to see if editor supports the mime-type specified.
     * Must be overridden in a sub-class.
     *
     * @since 3.5.0
     *
     * @static
     * @access public
     * @abstract
     *
     * @param string $mime_type
     * @return bool
     */
    public static function supports_mime_type( $mime_type )
    {
        return in_array( $mime_type, [
            'image/gif',
            'image/jpeg',
            'image/png',
            'image/svg+xml',
            'image/vnd.wap.wbmp',
        ] );
    }

    /**
     * Loads image from $this->file into editor.
     *
     * @since 3.5.0
     * @access protected
     *
     * @return bool|WP_Error True if loaded; WP_Error on failure.
     */
    public function load()
    {
        if ( ! is_file( $this->file ) && ! preg_match( '|^https?://|', $this->file ) ) {
            return new WP_Error( 'error_loading_image', __('File doesn&#8217;t exist?'), $this->file );
        }

        $this->_file_s3_key = $this->_filename_to_key( $this->file );
        if( ! $this->_file_s3_key ) {
            return new WP_Error( 'error_loading_image', __('File doesn&#8217;t exist on AWS S3?'), $this->file );
        }

        $this->_operations = [];

        $size = @getimagesize( $this->file );

        if( ! $size ) {
            return new WP_Error( 'invalid_image', 'getimagesize() error', $this->file );
        }

        $this->update_size( $size[0], $size[1] );
        $this->set_quality();

        $this->mime_type = $size['mime'];

        $this->_lambda_client = new LambdaClient([
            'credentials' => array(
                'key'    => AWS_LAMBDA_IMAGE_KEY,
                'secret' => AWS_LAMBDA_IMAGE_SECRET,
            ),
            'region' => AWS_LAMBDA_IMAGE_REGION,
            'version' => '2015-03-31',
        ]);

        return $this->set_quality();
    }

    /**
     * Saves current image to file.
     *
     * @since 3.5.0
     * @access public
     *
     * @param string $destfilename
     * @param string $mime_type
     * @return array|WP_Error {'path'=>string, 'file'=>string, 'width'=>int, 'height'=>int, 'mime-type'=>string}
     */
    public function save( $destfilename = null, $mime_type = null )
    {
        $saved = $this->_save( $destfilename, $mime_type );

        if ( ! is_wp_error( $saved ) ) {
            $this->file = $saved['meta']['path'];
            $this->mime_type = $saved['meta']['mime-type'];
        }

        return $saved;
    }


    protected function _save_async( $filename = null, $mime_type = null ) {
        list( $filename, $extension, $mime_type ) = $this->get_output_format( $filename, $mime_type );

        if ( ! $filename )
            $filename = $this->generate_filename( null, null, $extension );

        $new_key = $this->_filename_to_key( $filename );

        if( ! $new_key ) {
            return new WP_Error( 'image_save_error', __('Wrong file destination'), $filename );
        }

        $promise = $this->_run_lambda_async( $new_key );

        $this->_operations = [];

        return [
            'promise' => $promise,
            'meta' => [
                'path'      => $filename,
                'file'      => wp_basename( apply_filters( 'image_make_intermediate_size', $filename ) ),
                'width'     => $this->size['width'],
                'height'    => $this->size['height'],
                'mime-type' => $mime_type,
            ],
        ];
    }

    /**
     *
     * @param string $filename
     * @param string $mime_type
     * @return array|WP_Error
     */
    protected function _save( $filename = null, $mime_type = null ) {
        list( $filename, $extension, $mime_type ) = $this->get_output_format( $filename, $mime_type );

        if ( ! $filename )
            $filename = $this->generate_filename( null, null, $extension );

        $new_key = $this->_filename_to_key( $filename );

        if( ! $new_key ) {
            return new WP_Error( 'image_save_error', __('Wrong file destination'), $filename );
        }

        $result = $this->_run_lambda( $new_key );
        if( $result['StatusCode'] < 200 && $result['StatusCode'] >= 300 ) {
            return new WP_Error( 'image_save_error', $result['FunctionError'] );
        }

        $this->_operations = [];

        // Set correct file permissions
        //$stat = stat( dirname( $filename ) );
        //$perms = $stat['mode'] & 0000666; //same permissions as parent folder, strip off the executable bits
        //@ chmod( $filename, $perms );

        /** This filter is documented in wp-includes/class-wp-image-editor-gd.php */
        return [
            'meta' => [
                'path'      => $filename,
                'file'      => wp_basename( apply_filters( 'image_make_intermediate_size', $filename ) ),
                'width'     => $this->size['width'],
                'height'    => $this->size['height'],
                'mime-type' => $mime_type,
            ],
        ];
    }

    protected function _filename_to_key( $filename )
    {
        if( preg_match( '|^https?://|', $filename ) ) {
            $baseurl = $this->_get_s3fs_base_url();
            if( parse_url( $baseurl, PHP_URL_HOST ) !== parse_url( $filename, PHP_URL_HOST ) ) {
                return false;
            }
            return mb_substr( preg_replace( '|^https?://|', '',  $filename ), mb_strlen( preg_replace( '|^https?://|', '',  $baseurl ) ) + 1 );
        }
        else {
            $basedir = $this->_get_s3fs_base_dir();
            if( 0 !== mb_strpos( $filename, $basedir ) ) {
                return false;
            }
            return mb_substr( $filename, mb_strlen( $basedir ) + 1 );
        }
    }

    protected function _get_s3fs_base_dir()
    {
        if( defined( 'AWS_LAMBDA_IMAGE_S3FS_DIR' ) ) {
            return rtrim( AWS_LAMBDA_IMAGE_S3FS_DIR, DIRECTORY_SEPARATOR );
        }
        $dir = $this->_wp_upload_dir();
        return $dir['basedir'];
    }

    protected function _get_s3fs_base_url()
    {
        if( defined( 'AWS_LAMBDA_IMAGE_S3FS_URL' ) ) {
            return rtrim( AWS_LAMBDA_IMAGE_S3FS_URL, '/' );
        }
        $dir = $this->_wp_upload_dir();
        return $dir['baseurl'];
    }

    protected function _wp_upload_dir()
    {
        $siteurl = get_option( 'siteurl' );
        $upload_path = trim( get_option( 'upload_path' ) );

        if ( empty( $upload_path ) || 'wp-content/uploads' == $upload_path ) {
            $dir = WP_CONTENT_DIR . '/uploads';
        } elseif ( 0 !== strpos( $upload_path, ABSPATH ) ) {
            // $dir is absolute, $upload_path is (maybe) relative to ABSPATH
            $dir = path_join( ABSPATH, $upload_path );
        } else {
            $dir = $upload_path;
        }

        if ( !$url = get_option( 'upload_url_path' ) ) {
            if ( empty($upload_path) || ( 'wp-content/uploads' == $upload_path ) || ( $upload_path == $dir ) )
                $url = WP_CONTENT_URL . '/uploads';
            else
                $url = trailingslashit( $siteurl ) . $upload_path;
        }

        /*
         * Honor the value of UPLOADS. This happens as long as ms-files rewriting is disabled.
         * We also sometimes obey UPLOADS when rewriting is enabled -- see the next block.
         */
        if ( defined( 'UPLOADS' ) && ! ( is_multisite() && get_site_option( 'ms_files_rewriting' ) ) ) {
            $dir = ABSPATH . UPLOADS;
            $url = trailingslashit( $siteurl ) . UPLOADS;
        }
        return [
            'baseurl' => $url,
            'basedir' => $dir,
        ];
    }

    protected function _run_lambda_async( $new_key )
    {
        $args = [
            'bucket' => AWS_LAMBDA_IMAGE_BUCKET,
            'filename' => $this->_file_s3_key,
            'new_filename' => $new_key,
            'quality' => $this->get_quality(),
            'operations' => $this->_operations,
        ];

        $function = defined( 'AWS_LAMBDA_IMAGE_FUNCTION' ) ? AWS_LAMBDA_IMAGE_FUNCTION : 'wordpress_image_processor-production';

        return $this->_lambda_client->invokeAsync( [
            'FunctionName' => $function,
            'InvokeArgs' => json_encode( $args ),
            'Payload' => json_encode( $args ),
        ] );
    }

    protected function _run_lambda( $new_key )
    {
        $args = [
            'bucket' => AWS_LAMBDA_IMAGE_BUCKET,
            'filename' => $this->_file_s3_key,
            'new_filename' => $new_key,
            'quality' => $this->get_quality(),
            'operations' => $this->_operations,
        ];

        $function = defined( 'AWS_LAMBDA_IMAGE_FUNCTION' ) ? AWS_LAMBDA_IMAGE_FUNCTION : 'wordpress_image_processor-production';

        return $this->_lambda_client->invoke( [
            'FunctionName' => $function,
            'Payload' => json_encode( $args ),
        ] );
    }

    /**
     * Resizes current image.
     *
     * At minimum, either a height or width must be provided.
     * If one of the two is set to null, the resize will
     * maintain aspect ratio according to the provided dimension.
     *
     * @since 3.5.0
     * @access public
     *
     * @param  int|null $max_w Image width.
     * @param  int|null $max_h Image height.
     * @param  bool $crop
     * @return bool|WP_Error
     */
    public function resize( $max_w, $max_h, $crop = false )
    {
        if ( ( $this->size['width'] == $max_w ) && ( $this->size['height'] == $max_h ) )
            return true;

        $dims = image_resize_dimensions( $this->size['width'], $this->size['height'], $max_w, $max_h, $crop );
        if ( ! $dims )
            return new WP_Error( 'error_getting_dimensions', __('Could not calculate resized image dimensions') );
        list( $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h ) = $dims;

        if ( $crop ) {
            return $this->crop( $src_x, $src_y, $src_w, $src_h, $dst_w, $dst_h );
        }

        $this->_add_operation( 'resize', [
            'width' => $dst_w,
            'height' => $dst_h,
        ] );

        return $this->update_size( $dst_w, $dst_h );
    }

    /**
     * Resize multiple images from a single source.
     *
     * @since 3.5.0
     * @access public
     *
     * @param array $sizes {
     *     An array of image size arrays. Default sizes are 'small', 'medium', 'large'.
     *
     * @type array $size {
     * @type int $width Image width.
     * @type int $height Image height.
     * @type bool $crop Optional. Whether to crop the image. Default false.
     *     }
     * }
     * @return array An array of resized images metadata by size.
     */
    public function multi_resize( $sizes )
    {
        $metadata = [];
        $orig_size = $this->size;
        $orig_operations = $this->_operations;
        $first = true;
        $promises = [];

        foreach ( $sizes as $size => $size_data ) {
            $this->_operations = $orig_operations;

            if ( ! isset( $size_data['width'] ) && ! isset( $size_data['height'] ) ) {
                continue;
            }

            if ( ! isset( $size_data['width'] ) ) {
                $size_data['width'] = null;
            }
            if ( ! isset( $size_data['height'] ) ) {
                $size_data['height'] = null;
            }

            if ( ! isset( $size_data['crop'] ) ) {
                $size_data['crop'] = false;
            }

            $this->resize( $size_data['width'], $size_data['height'], $size_data['crop'] );
            $duplicate = ( ( $orig_size['width'] == $size_data['width'] ) && ( $orig_size['height'] == $size_data['height'] ) );

            if ( ! $duplicate ) {

                if( $first ) {
                    $resized = $this->_save();
                    $first = false;

                    if ( ! is_wp_error( $resized ) && $resized ) {
                        unset( $resized['meta']['path'] );
                        $metadata[ $size ] = $resized['meta'];
                    }
                }
                else {
                    $resized = $this->_save_async();

                    if ( ! is_wp_error( $resized ) && $resized ) {
                        $promises[] = $resized['promise'];
                        unset( $resized['meta']['path'] );
                        $metadata[ $size ] = $resized['meta'];
                    }
                }

            }

            $this->size = $orig_size;
        }

        foreach( $promises as $promise ) {
            $promise->wait();
        }

        $this->_operations = $orig_operations;

        return $metadata;
    }

    /**
     * Crops Image.
     *
     * @since 3.5.0
     * @access public
     *
     * @param int $src_x The start x position to crop from.
     * @param int $src_y The start y position to crop from.
     * @param int $src_w The width to crop.
     * @param int $src_h The height to crop.
     * @param int $dst_w Optional. The destination width.
     * @param int $dst_h Optional. The destination height.
     * @param bool $src_abs Optional. If the source crop points are absolute.
     * @return bool|WP_Error
     */
    public function crop( $src_x, $src_y, $src_w, $src_h, $dst_w = null, $dst_h = null, $src_abs = false )
    {
        if ( $src_abs ) {
            $src_w -= $src_x;
            $src_h -= $src_y;
        }

        $this->_add_operation( 'crop', [
            'src_x' => $src_x,
            'src_y' => $src_y,
            'src_width' => $src_w,
            'src_height' => $src_h,
            'destination_width' => $dst_w,
            'destination_height' => $dst_h,
        ] );


        $new_width = $dst_w ? $dst_w : $src_w;
        $new_height = $dst_h ? $dst_h : $src_h;

        return $this->update_size( $new_width, $new_height );
    }

    /**
     * Rotates current image counter-clockwise by $angle.
     *
     * @since 3.5.0
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

        if( absint( $angle ) % 180 === 0 ) {
            return true;
        }
        elseif( absint( $angle ) % 90 === 0 ) {
            $old_size = $this->get_size();
            return $this->update_size( $old_size['height'], $old_size['width'] );
        }

        //todo: calculate new size
        return true;
    }

    /**
     * Flips current image.
     *
     * @since 3.5.0
     * @access public
     *
     * @param bool $horz Flip along Horizontal Axis
     * @param bool $vert Flip along Vertical Axis
     * @return bool|WP_Error
     */
    public function flip( $horz, $vert )
    {
        $this->_add_operation( 'flip', [
            'horizontal' => $horz,
            'vertical' => $vert,
        ] );
        return true;
    }

    /**
     * Streams current image to browser.
     *
     * @since 3.5.0
     * @access public
     *
     * @param string $mime_type
     * @return bool|WP_Error
     */
    public function stream( $mime_type = null )
    {
        // TODO: Implement stream() method.
    }
}