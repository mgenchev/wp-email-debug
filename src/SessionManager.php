<?php

namespace WpEmailDebug;

final class SessionManager {
    public const HEARTBEAT_TTL = 5;

    private $stateDirectory;
    private $sessionFile;
    private $spoolDirectory;
    private $token;
    private $siteHash;
    private $started = false;
    private $options = array();

    public function __construct( $stateDirectory, $siteHash, array $options = array() ) {
        $this->stateDirectory = rtrim( (string) $stateDirectory, '/\\' );
        $this->sessionFile = $this->stateDirectory . DIRECTORY_SEPARATOR . 'session.json';
        $this->spoolDirectory = $this->stateDirectory . DIRECTORY_SEPARATOR . 'spool';
        $this->siteHash = (string) $siteHash;
        $this->options = $options;
    }

    public function start() {
        $this->ensureDirectory( $this->stateDirectory );
        $this->assertNoActiveSession();
        $this->cleanupStaleFiles();
        $this->ensureDirectory( $this->spoolDirectory );

        try {
            $this->token = bin2hex( random_bytes( 32 ) );
        } catch ( \Exception $e ) {
            throw new \RuntimeException( 'Unable to generate a secure email debug session token.' );
        }

        $this->writeState();
        $this->started = true;

        return $this->token;
    }

    public function heartbeat() {
        if ( '' === (string) $this->token ) {
            return;
        }

        $this->writeState();
    }

    public function stop() {
        if ( ! $this->started || '' === (string) $this->token ) {
            return;
        }

        $ownsSession = false;
        if ( is_file( $this->sessionFile ) ) {
            $state = $this->readState();
            if ( is_array( $state ) && isset( $state['token'] ) && hash_equals( (string) $state['token'], (string) $this->token ) ) {
                $ownsSession = true;
                @unlink( $this->sessionFile );
            }
        }

        if ( $ownsSession || ! is_file( $this->sessionFile ) ) {
            $this->cleanupSpoolFiles();
            @rmdir( $this->spoolDirectory );
            @rmdir( $this->stateDirectory );
        }

        $this->started = false;
    }

    public function getSessionFile() {
        return $this->sessionFile;
    }

    public function getSpoolDirectory() {
        return $this->spoolDirectory;
    }

    public function getToken() {
        return $this->token;
    }

    private function writeState() {
        $payload = array(
            'version' => 1,
            'token' => $this->token,
            'site_hash' => $this->siteHash,
            'heartbeat' => time(),
            'spool_directory' => $this->spoolDirectory,
            'probe_transport' => ! empty( $this->options['probe_transport'] ),
        );

        $json = json_encode( $payload, JSON_UNESCAPED_SLASHES );
        if ( false === $json ) {
            throw new \RuntimeException( 'Unable to encode the email debug session state.' );
        }

        $tmp = $this->sessionFile . '.tmp';
        if ( false === @file_put_contents( $tmp, $json, LOCK_EX ) ) {
            throw new \RuntimeException( 'Unable to write the email debug session state: ' . $tmp );
        }

        @chmod( $tmp, 0600 );

        if ( ! @rename( $tmp, $this->sessionFile ) ) {
            @unlink( $tmp );
            throw new \RuntimeException( 'Unable to publish the email debug session state: ' . $this->sessionFile );
        }
    }

    private function assertNoActiveSession() {
        if ( is_link( $this->sessionFile ) ) {
            throw new \RuntimeException( 'Refusing to use a symlinked email debug session file: ' . $this->sessionFile );
        }

        if ( ! is_file( $this->sessionFile ) ) {
            return;
        }

        $state = $this->readState();
        if ( ! is_array( $state ) || empty( $state['heartbeat'] ) ) {
            return;
        }

        if ( ( time() - (int) $state['heartbeat'] ) <= self::HEARTBEAT_TTL ) {
            throw new \RuntimeException( 'Another wp email-debug session appears to be active for this WordPress installation.' );
        }
    }

    private function readState() {
        $contents = @file_get_contents( $this->sessionFile );
        if ( false === $contents || '' === trim( $contents ) ) {
            return null;
        }

        $state = json_decode( $contents, true );
        return is_array( $state ) ? $state : null;
    }

    private function cleanupStaleFiles() {
        if ( is_file( $this->sessionFile ) ) {
            @unlink( $this->sessionFile );
        }
        $this->cleanupSpoolFiles();
    }

    private function cleanupSpoolFiles() {
        if ( ! is_dir( $this->spoolDirectory ) ) {
            return;
        }

        $files = glob( $this->spoolDirectory . DIRECTORY_SEPARATOR . '*.json' );
        if ( is_array( $files ) ) {
            foreach ( $files as $file ) {
                if ( is_file( $file ) && ! is_link( $file ) ) {
                    @unlink( $file );
                }
            }
        }

        $tmpFiles = glob( $this->spoolDirectory . DIRECTORY_SEPARATOR . '*.tmp' );
        if ( is_array( $tmpFiles ) ) {
            foreach ( $tmpFiles as $file ) {
                if ( is_file( $file ) && ! is_link( $file ) ) {
                    @unlink( $file );
                }
            }
        }
    }

    private function ensureDirectory( $path ) {
        if ( is_link( $path ) ) {
            throw new \RuntimeException( 'Refusing to use a symlink for email debug state: ' . $path );
        }

        if ( is_dir( $path ) ) {
            return;
        }

        if ( file_exists( $path ) ) {
            throw new \RuntimeException( 'Email debug state path exists but is not a directory: ' . $path );
        }

        if ( ! @mkdir( $path, 0700, true ) && ! is_dir( $path ) ) {
            throw new \RuntimeException( 'Unable to create email debug directory: ' . $path );
        }

        @chmod( $path, 0700 );
    }
}
