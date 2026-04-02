<?php
/**
 * Plugin Name: DRC Postie Image Handler
 * Description: Processes [PostIMG:] markers in Postie emails and limits posts to one image.
 * Version: 1.0.0
 * Author: DRC
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_filter( 'postie_post_pre', 'drc_postie_handle_images' );

function drc_postie_handle_images( $parsedEmail ) {

    // --- Part A: Download image from [PostIMG:...] marker ---

    $marker_pattern = '/\[PostIMG:(.*?)\]/';

    if ( preg_match( $marker_pattern, $parsedEmail['text'], $matches ) ) {
        $image_url = trim( $matches[1] );

        if ( $image_url !== '' ) {
            $response = wp_remote_get( $image_url, array( 'timeout' => 15 ) );

            if (
                is_wp_error( $response ) ||
                wp_remote_retrieve_response_code( $response ) !== 200 ||
                empty( wp_remote_retrieve_body( $response ) )
            ) {
                $error_msg = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $response );
                error_log( '[DRC Postie Image Handler] Failed to download image from ' . $image_url . ': ' . $error_msg );
            } else {
                $mime_type = wp_remote_retrieve_header( $response, 'content-type' );
                // Strip any parameters (e.g. "image/jpeg; charset=...")
                if ( strpos( $mime_type, ';' ) !== false ) {
                    $mime_type = trim( explode( ';', $mime_type )[0] );
                }

                $url_path = parse_url( $image_url, PHP_URL_PATH );
                $filename = $url_path ? basename( $url_path ) : '';

                if ( $filename === '' || pathinfo( $filename, PATHINFO_EXTENSION ) === '' ) {
                    $ext_map = array(
                        'image/jpeg' => 'jpg',
                        'image/png'  => 'png',
                        'image/gif'  => 'gif',
                        'image/webp' => 'webp',
                    );
                    $ext      = isset( $ext_map[ $mime_type ] ) ? $ext_map[ $mime_type ] : 'jpg';
                    $filename = 'postimg.' . $ext;
                }

                if ( ! isset( $parsedEmail['attachment'] ) ) {
                    $parsedEmail['attachment'] = array();
                }

                array_unshift( $parsedEmail['attachment'], array(
                    'filename' => $filename,
                    'mimetype' => $mime_type,
                    'data'     => wp_remote_retrieve_body( $response ),
                ) );
            }
        }

        // Strip all [PostIMG:...] occurrences from text body
        $parsedEmail['text'] = preg_replace( $marker_pattern, '', $parsedEmail['text'] );
        $parsedEmail['text'] = ltrim( $parsedEmail['text'] );

        // Strip from HTML body if present
        if ( ! empty( $parsedEmail['html'] ) ) {
            $parsedEmail['html'] = preg_replace( $marker_pattern, '', $parsedEmail['html'] );
            $parsedEmail['html'] = ltrim( $parsedEmail['html'] );
        }
    }

    // --- Part B: Keep only the first image in attachments and inline ---

    foreach ( array( 'attachment', 'inline' ) as $key ) {
        if ( empty( $parsedEmail[ $key ] ) ) {
            continue;
        }

        $found_image = false;
        foreach ( $parsedEmail[ $key ] as $index => $item ) {
            if ( isset( $item['mimetype'] ) && strpos( $item['mimetype'], 'image/' ) === 0 ) {
                if ( $found_image ) {
                    unset( $parsedEmail[ $key ][ $index ] );
                } else {
                    $found_image = true;
                }
            }
        }

        $parsedEmail[ $key ] = array_values( $parsedEmail[ $key ] );
    }

    return $parsedEmail;
}
