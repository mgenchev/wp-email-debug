<?php

namespace WpEmailDebug;

final class Listener {
    private const POLL_INTERVAL_US = 1000000;

    private $console;
    private $session;
    private $spool;
    private $logs;
    private $running = true;
    private $count = 0;
    private $failedCount = 0;
    private $hasIssuesCount = 0;
    private $duplicateCount = 0;
    private $recentFingerprints = array();

    public function __construct( Console $console, SessionManager $session, SpoolReader $spool, EmailLogWriter $logs ) {
        $this->console = $console;
        $this->session = $session;
        $this->spool = $spool;
        $this->logs = $logs;
    }

    public function run() {
        $this->installSignalHandlers();

        while ( $this->running ) {
            if ( function_exists( 'pcntl_signal_dispatch' ) && ! function_exists( 'pcntl_async_signals' ) ) {
                pcntl_signal_dispatch();
            }

            $this->session->heartbeat();
            $this->drain();
            usleep( self::POLL_INTERVAL_US );
        }

        $this->drain();
    }

    public function stop() {
        $this->running = false;
    }

    public function getCount() {
        return $this->count;
    }

    public function getFailedCount() {
        return $this->failedCount;
    }

    public function getHasIssuesCount() {
        return $this->hasIssuesCount;
    }

    public function getDuplicateCount() {
        return $this->duplicateCount;
    }

    public function drain() {
        $messages = $this->spool->readPending();
        foreach ( $messages as $message ) {
            $this->count++;
            $message = $this->detectDuplicate( $message );
            $status = isset( $message['status'] ) ? (string) $message['status'] : 'successful';
            if ( 'failed' === $status ) {
                $this->failedCount++;
            } elseif ( 'has_issues' === $status ) {
                $this->hasIssuesCount++;
            }

            $message['display_time'] = $this->displayTime( isset( $message['captured_at'] ) ? (float) $message['captured_at'] : microtime( true ) );
            $logPath = $this->logs->write( $message );
            $this->console->emailCaptured( $this->count, $message, $logPath );
        }

        return count( $messages );
    }


    private function detectDuplicate( array $message ) {
        $timestamp = isset( $message['captured_at'] ) ? (float) $message['captured_at'] : microtime( true );
        $this->purgeFingerprints( $timestamp );

        $fingerprint = $this->fingerprint( $message );
        if ( '' === $fingerprint ) {
            return $message;
        }

        if ( isset( $this->recentFingerprints[ $fingerprint ] ) ) {
            $previous = (float) $this->recentFingerprints[ $fingerprint ];
            $seconds = max( 0, $timestamp - $previous );
            $issues = isset( $message['issues'] ) && is_array( $message['issues'] ) ? $message['issues'] : array();
            $issues[] = array(
                'level' => 'warning',
                'code' => 'possible_duplicate',
                'message' => 'Possible duplicate email detected.',
                'detail' => 'Previous: ' . number_format( $seconds, 1 ) . ' seconds earlier',
                'result' => 'An email with the same recipient, subject and body was captured within 3 seconds.',
            );
            $message['issues'] = $issues;
            if ( 'failed' !== ( isset( $message['status'] ) ? $message['status'] : 'successful' ) ) {
                $message['status'] = 'has_issues';
            }
            $this->duplicateCount++;
        }

        $this->recentFingerprints[ $fingerprint ] = $timestamp;
        return $message;
    }

    private function purgeFingerprints( $timestamp ) {
        foreach ( $this->recentFingerprints as $fingerprint => $seenAt ) {
            if ( ( $timestamp - (float) $seenAt ) > 3.0 ) {
                unset( $this->recentFingerprints[ $fingerprint ] );
            }
        }
    }

    private function fingerprint( array $message ) {
        $to = isset( $message['to'] ) && is_array( $message['to'] ) ? array_map( 'strtolower', array_map( 'trim', array_map( 'strval', $message['to'] ) ) ) : array();
        sort( $to, SORT_STRING );
        $subject = isset( $message['subject'] ) ? strtolower( trim( (string) $message['subject'] ) ) : '';
        $body = isset( $message['body_b64'] ) ? base64_decode( (string) $message['body_b64'], true ) : '';
        if ( false === $body ) {
            $body = '';
        }
        $body = str_replace( array( "
", "" ), "
", (string) $body );
        $body = trim( $body );

        if ( empty( $to ) && '' === $subject && '' === $body ) {
            return '';
        }

        return hash( 'sha256', implode( ',', $to ) . "
" . $subject . "
" . $body );
    }

    private function installSignalHandlers() {
        if ( ! function_exists( 'pcntl_signal' ) ) {
            return;
        }

        if ( function_exists( 'pcntl_async_signals' ) ) {
            pcntl_async_signals( true );
        }

        if ( defined( 'SIGINT' ) ) {
            pcntl_signal( SIGINT, array( $this, 'stop' ) );
        }
        if ( defined( 'SIGTERM' ) ) {
            pcntl_signal( SIGTERM, array( $this, 'stop' ) );
        }
    }

    private function displayTime( $timestamp ) {
        $seconds = (int) floor( $timestamp );
        if ( function_exists( 'wp_date' ) ) {
            return wp_date( 'H:i:s', $seconds );
        }
        return date( 'H:i:s', $seconds );
    }
}
