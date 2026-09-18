<?php

namespace WpEmailDebug;

final class Console {
    private const SPINNER_FRAMES = array( '⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏' );

    private $interactive;
    private $frameIndex = 0;
    private $lastTick = 0.0;

    public function __construct( $interactive = null ) {
        if ( null !== $interactive ) {
            $this->interactive = (bool) $interactive;
            return;
        }

        if ( function_exists( 'stream_isatty' ) ) {
            $this->interactive = @stream_isatty( STDOUT );
            return;
        }

        $this->interactive = function_exists( 'posix_isatty' ) ? @posix_isatty( STDOUT ) : false;
    }

    public function line( $message = '' ) {
        \WP_CLI::line( $message );
    }

    public function warning( $message ) {
        \WP_CLI::warning( $message );
    }

    public function success( $message ) {
        $this->clearSpinner();
        $this->line( $this->statusText( 'ok', '✓' ) . ' ' . $message );
    }

    public function errorLine( $message ) {
        $this->clearSpinner();
        $this->line( $this->statusText( 'error', '✗' ) . ' ' . $message );
    }

    public function tick( $message, $force = false ) {
        $now = microtime( true );

        if ( ! $force && ( $now - $this->lastTick ) < 0.08 ) {
            return;
        }

        $frame = self::SPINNER_FRAMES[ $this->frameIndex % count( self::SPINNER_FRAMES ) ];
        $this->frameIndex++;
        $this->lastTick = $now;

        if ( $this->interactive ) {
            fwrite( STDOUT, "\r\033[2K" . $frame . ' ' . $message );
            return;
        }

        $this->line( $frame . ' ' . $message );
    }

    public function clearSpinner() {
        if ( $this->interactive ) {
            fwrite( STDOUT, "\r\033[2K" );
        }
    }

    public function statusText( $level, $text ) {
        $tokens = array(
            'ok' => '%G',
            'warning' => '%Y',
            'error' => '%R',
        );

        if ( ! isset( $tokens[ $level ] ) || ! method_exists( '\\WP_CLI', 'colorize' ) ) {
            return $text;
        }

        return \WP_CLI::colorize( $tokens[ $level ] . $text . '%n' );
    }

    public function header( $site, $environment, $logDirectory ) {
        $this->line( 'Email Debug' );
        $this->line();
        $this->line( 'Site:          ' . $site );
        $this->line( 'Environment:   ' . $environment );
        $this->line( 'External mail: BLOCKED (captured locally)' );
        $this->line( 'Logs:          ' . $logDirectory );
        $this->line( 'Poll interval: 1 second' );
        $this->line();
        $this->line( 'Listening for outgoing WordPress email...' );
        $this->line( 'Press Ctrl+C to stop.' );
        $this->line();
    }

    public function emailCaptured( $number, array $email, $logPath ) {
        $subject = isset( $email['subject'] ) && '' !== trim( (string) $email['subject'] ) ? $email['subject'] : '(no subject)';
        $time = isset( $email['display_time'] ) ? $email['display_time'] : date( 'H:i:s' );
        $to = ! empty( $email['to'] ) ? implode( ', ', $email['to'] ) : '-';
        $from = ! empty( $email['from'] ) ? $email['from'] : '-';
        $statusValue = isset( $email['status'] ) ? (string) $email['status'] : 'successful';
        $failed = 'failed' === $statusValue;
        $hasIssues = 'has_issues' === $statusValue;
        if ( $failed ) {
            $status = $this->statusText( 'error', 'Failed' );
        } elseif ( $hasIssues ) {
            $status = $this->statusText( 'warning', 'Has Issues' );
        } else {
            $status = $this->statusText( 'ok', 'Successful' );
        }
        $this->line( sprintf( '#%d  Status : %s', $number, $status ) );
        $this->line( '    Time   : [' . $time . ']' );
        $this->line( '    Subject: ' . $subject );
        $this->line( '    To     : ' . $to );
        $this->line( '    From   : ' . $from );
        if ( $failed ) {
            $error = isset( $email['error'] ) && is_array( $email['error'] ) ? $email['error'] : array();
            $message = isset( $error['message'] ) && '' !== trim( (string) $error['message'] ) ? $error['message'] : 'Unknown wp_mail() failure.';
            $this->line( '    Error  : ' . $message );
        } elseif ( $hasIssues ) {
            $issues = isset( $email['issues'] ) && is_array( $email['issues'] ) ? $email['issues'] : array();
            foreach ( $issues as $issue ) {
                if ( ! is_array( $issue ) || empty( $issue['message'] ) ) {
                    continue;
                }
                $level = isset( $issue['level'] ) ? (string) $issue['level'] : 'warning';
                if ( 'warning' === $level || 'error' === $level ) {
                    $this->line( '    Issue  : ' . $issue['message'] );
                    break;
                }
            }
        }
        $this->line( '    Log    : ' . $logPath );
        $this->line();
    }

    public function summary( $total, $failed, $hasIssues, $logDirectory, $duplicates = 0 ) {
        $total = max( 0, (int) $total );
        $failed = max( 0, (int) $failed );
        $hasIssues = max( 0, (int) $hasIssues );
        $successful = max( 0, $total - $failed - $hasIssues );
        $duplicates = max( 0, (int) $duplicates );

        $this->line( 'Email Debug stopped.' );
        $this->line();
        $this->line( 'Total:         ' . $total );
        $this->line( 'Successful:    ' . $successful );
        $this->line( 'Has Issues:    ' . $hasIssues );
        $this->line( 'Failed:        ' . $failed );
        if ( $duplicates > 0 ) {
            $this->line( 'Duplicates:    ' . $duplicates );
        }
        $this->line( 'Logs:          ' . $logDirectory );
        $this->line( 'Mail delivery: restored' );
    }

    public static function formatBytes( $bytes ) {
        $bytes = max( 0, (int) $bytes );
        if ( $bytes < 1024 ) {
            return $bytes . ' B';
        }
        if ( $bytes < 1024 * 1024 ) {
            return number_format( $bytes / 1024, 1 ) . ' KB';
        }
        return number_format( $bytes / ( 1024 * 1024 ), 1 ) . ' MB';
    }
}
