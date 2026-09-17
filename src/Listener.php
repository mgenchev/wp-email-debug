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

    public function drain() {
        $messages = $this->spool->readPending();
        foreach ( $messages as $message ) {
            $this->count++;
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
