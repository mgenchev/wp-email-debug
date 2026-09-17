<?php

namespace WpEmailDebug;

final class SpoolReader {
    private $directory;
    private $token;

    public function __construct( $directory, $token ) {
        $this->directory = rtrim( (string) $directory, '/\\' );
        $this->token = (string) $token;
    }

    public function readPending() {
        $files = glob( $this->directory . DIRECTORY_SEPARATOR . '*.json' );
        if ( ! is_array( $files ) || empty( $files ) ) {
            return array();
        }

        sort( $files, SORT_STRING );
        $messages = array();

        foreach ( $files as $file ) {
            if ( ! is_file( $file ) || is_link( $file ) ) {
                continue;
            }

            $contents = @file_get_contents( $file );
            if ( false === $contents ) {
                continue;
            }

            $payload = json_decode( $contents, true );
            if ( ! is_array( $payload ) || empty( $payload['token'] ) || ! hash_equals( $this->token, (string) $payload['token'] ) ) {
                @unlink( $file );
                continue;
            }

            $messages[] = $payload;
            @unlink( $file );
        }

        return $messages;
    }
}
