<?php

namespace WpEmailDebug;

final class BridgeInstaller {
    private const MARKER = 'WP_EMAIL_DEBUG_RUNTIME_V1';

    private $bridgePath;
    private $templatePath;
    private $token;
    private $sessionFile;
    private $siteHash;

    public function __construct( $bridgePath, $templatePath, $token, $sessionFile, $siteHash ) {
        $this->bridgePath = (string) $bridgePath;
        $this->templatePath = (string) $templatePath;
        $this->token = (string) $token;
        $this->sessionFile = (string) $sessionFile;
        $this->siteHash = (string) $siteHash;
    }

    public function install() {
        $directory = dirname( $this->bridgePath );
        if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) {
            throw new \RuntimeException( 'Unable to create the mu-plugins directory: ' . $directory );
        }

        if ( is_link( $this->bridgePath ) ) {
            throw new \RuntimeException( 'Refusing to replace a symlinked MU runtime: ' . $this->bridgePath );
        }

        if ( file_exists( $this->bridgePath ) ) {
            $existing = @file_get_contents( $this->bridgePath );
            if ( false === $existing || false === strpos( $existing, self::MARKER ) ) {
                throw new \RuntimeException( 'Refusing to replace an existing non-email-debug MU plugin: ' . $this->bridgePath );
            }
        }

        $template = @file_get_contents( $this->templatePath );
        if ( false === $template ) {
            throw new \RuntimeException( 'Unable to read the email debug runtime template.' );
        }

        $rendered = str_replace(
            array( '__WP_EMAIL_DEBUG_TOKEN__', '__WP_EMAIL_DEBUG_SESSION_FILE__', '__WP_EMAIL_DEBUG_SITE_HASH__' ),
            array( $this->escapeSingleQuoted( $this->token ), $this->escapeSingleQuoted( $this->sessionFile ), $this->escapeSingleQuoted( $this->siteHash ) ),
            $template
        );

        $tmp = $this->bridgePath . '.tmp';
        if ( false === @file_put_contents( $tmp, $rendered, LOCK_EX ) ) {
            throw new \RuntimeException( 'Unable to write the temporary email debug MU runtime.' );
        }

        @chmod( $tmp, 0644 );

        if ( ! @rename( $tmp, $this->bridgePath ) ) {
            @unlink( $tmp );
            throw new \RuntimeException( 'Unable to activate the temporary email debug MU runtime.' );
        }
    }

    public function uninstall() {
        if ( ! is_file( $this->bridgePath ) || is_link( $this->bridgePath ) ) {
            return;
        }

        $contents = @file_get_contents( $this->bridgePath );
        if ( false === $contents ) {
            return;
        }

        if ( false !== strpos( $contents, self::MARKER ) && false !== strpos( $contents, $this->token ) ) {
            @unlink( $this->bridgePath );
        }
    }

    public function getBridgePath() {
        return $this->bridgePath;
    }

    private function escapeSingleQuoted( $value ) {
        return str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), (string) $value );
    }
}
