<?php
/**
 * Plugin Name: DRC Postie Image Handler
 * Description: Processes [PostIMG:] markers in Postie emails, verifies sender authorization, and controls image attachments.
 * Version: 2.0.0
 * Author: DRC
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'DRC_POSTIE_ALLOWED_SENDERS', [
    // Add known sender addresses here as an extra safety net.
    // e.g. 'editor@example.com',
] );

add_filter( 'postie_post_pre', 'drc_postie_handle_images' );

function drc_postie_handle_images( $parsedEmail ) {

    $postimg_injected = false;

    // --- Step 0: Sender Verification ---

    $from = isset( $parsedEmail['headers']['from'] ) ? $parsedEmail['headers']['from'] : null;

    if (
        ! is_array( $from ) ||
        empty( $from['mailbox'] ) ||
        empty( $from['host'] )
    ) {
        error_log( '[DRC Postie Image Handler] Missing or malformed from header — skipping all processing.' );
        return $parsedEmail;
    }

    $sender = strtolower( $from['mailbox'] . '@' . $from['host'] );

    $authorized = false;

    if ( ! empty( DRC_POSTIE_ALLOWED_SENDERS ) ) {
        // If the constant list is non-empty, the sender must appear in it.
        $allowed = array_map( 'strtolower', DRC_POSTIE_ALLOWED_SENDERS );
        $authorized = in_array( $sender, $allowed, true );
    } else {
        // Fall back to Postie's own authorized_addresses setting.
        $postie_settings = get_option( 'postie-settings', [] );

        if ( ! empty( $postie_settings['authorized_addresses'] ) ) {
            $postie_addresses = array_map(
                'strtolower',
                array_filter( array_map( 'trim', explode( "\n", $postie_settings['authorized_addresses'] ) ) )
            );
            if ( in_array( $sender, $postie_addresses, true ) ) {
                $authorized = true;
            }
        }

        // Also authorize any WordPress user whose email matches the sender.
        if ( ! $authorized ) {
            $wp_user = get_user_by( 'email', $sender );
            if ( $wp_user instanceof WP_User ) {
                $authorized = true;
            }
        }
    }

    if ( ! $authorized ) {
        error_log( '[DRC Postie Image Handler] Unauthorized sender: ' . $sender . ' — skipping all processing.' );
        return $parsedEmail;
    }

    // --- Part A: Download image from [PostIMG:...] marker ---

    $marker_pattern = '/\[PostIMG:(.*?)\]/';

    if ( preg_match( $marker_pattern, $parsedEmail['text'], $matches ) ) {
        $image_url = trim( $matches[1] );

        if ( $image_url !== '' ) {
            $url_valid = false;

            // Validate scheme.
            if ( preg_match( '/^https?:\/\//i', $image_url ) ) {
                // Validate host — prevent SSRF against private/internal addresses.
                $host = wp_parse_url( $image_url, PHP_URL_HOST );
                if ( $host ) {
                    $ip = gethostbyname( $host );
                    if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                        $url_valid = true;
                    } else {
                        error_log( '[DRC Postie Image Handler] SSRF validation failed for URL: ' . $image_url . ' (resolved to ' . $ip . ')' );
                    }
                } else {
                    error_log( '[DRC Postie Image Handler] Could not parse host from URL: ' . $image_url );
                }
            } else {
                error_log( '[DRC Postie Image Handler] Rejected URL with invalid scheme: ' . $image_url );
            }

            if ( $url_valid ) {
                $response = wp_remote_get( $image_url, [ 'timeout' => 15 ] );

                if (
                    is_wp_error( $response ) ||
                    wp_remote_retrieve_response_code( $response ) !== 200 ||
                    empty( wp_remote_retrieve_body( $response ) )
                ) {
                    $error_msg = is_wp_error( $response )
                        ? $response->get_error_message()
                        : 'HTTP ' . wp_remote_retrieve_response_code( $response );
                    error_log( '[DRC Postie Image Handler] Failed to download image from ' . $image_url . ': ' . $error_msg );
                } else {
                    $mime_type = wp_remote_retrieve_header( $response, 'content-type' );
                    if ( strpos( $mime_type, ';' ) !== false ) {
                        $mime_type = trim( explode( ';', $mime_type )[0] );
                    }

                    $url_path = wp_parse_url( $image_url, PHP_URL_PATH );
                    $filename = $url_path ? basename( $url_path ) : '';

                    if ( $filename === '' || pathinfo( $filename, PATHINFO_EXTENSION ) === '' ) {
                        $ext_map = [
                            'image/jpeg' => 'jpg',
                            'image/png'  => 'png',
                            'image/gif'  => 'gif',
                            'image/webp' => 'webp',
                        ];
                        $ext      = isset( $ext_map[ $mime_type ] ) ? $ext_map[ $mime_type ] : 'jpg';
                        $filename = 'postimg.' . $ext;
                    }

                    if ( ! isset( $parsedEmail['attachment'] ) ) {
                        $parsedEmail['attachment'] = [];
                    }

                    array_unshift( $parsedEmail['attachment'], [
                        'filename' => $filename,
                        'mimetype' => $mime_type,
                        'data'     => wp_remote_retrieve_body( $response ),
                    ] );

                    $postimg_injected = true;
                }
            }
        }

        // Strip all [PostIMG:...] occurrences from text and html bodies.
        $parsedEmail['text'] = ltrim( preg_replace( $marker_pattern, '', $parsedEmail['text'] ) );

        if ( ! empty( $parsedEmail['html'] ) ) {
            $parsedEmail['html'] = ltrim( preg_replace( $marker_pattern, '', $parsedEmail['html'] ) );
        }
    }

    // --- Part B: Image cleanup ---

    $indexed_keys = [ 'attachment', 'inline' ];

    if ( $postimg_injected ) {
        // Keep the injected image (first entry in attachment); remove all other images
        // from attachment, inline, and related.
        $first_skipped = false;
        foreach ( $indexed_keys as $key ) {
            if ( empty( $parsedEmail[ $key ] ) ) {
                continue;
            }
            foreach ( $parsedEmail[ $key ] as $index => $item ) {
                if ( isset( $item['mimetype'] ) && strpos( $item['mimetype'], 'image/' ) === 0 ) {
                    if ( $key === 'attachment' && ! $first_skipped ) {
                        $first_skipped = true; // preserve the injected image
                        continue;
                    }
                    unset( $parsedEmail[ $key ][ $index ] );
                }
            }
            $parsedEmail[ $key ] = array_values( $parsedEmail[ $key ] );
        }
    } else {
        // Remove ALL images from attachment and inline.
        foreach ( $indexed_keys as $key ) {
            if ( empty( $parsedEmail[ $key ] ) ) {
                continue;
            }
            foreach ( $parsedEmail[ $key ] as $index => $item ) {
                if ( isset( $item['mimetype'] ) && strpos( $item['mimetype'], 'image/' ) === 0 ) {
                    unset( $parsedEmail[ $key ][ $index ] );
                }
            }
            $parsedEmail[ $key ] = array_values( $parsedEmail[ $key ] );
        }
    }

    // Clean up related (keyed by cid:..., values have mimetype and data but no filename).
    if ( ! empty( $parsedEmail['related'] ) && is_array( $parsedEmail['related'] ) ) {
        foreach ( $parsedEmail['related'] as $cid => $item ) {
            if ( isset( $item['mimetype'] ) && strpos( $item['mimetype'], 'image/' ) === 0 ) {
                unset( $parsedEmail['related'][ $cid ] );
            }
        }
    }

    return $parsedEmail;
}
