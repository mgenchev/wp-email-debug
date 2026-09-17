<?php

namespace WpEmailDebug;

/**
 * Listen for and intercept outgoing WordPress wp_mail() email.
 *
 * ## EXAMPLES
 *
 *     wp email-debug
 *     wp email-debug --probe-transport
 *
 * @synopsis [--probe-transport]
 * @when after_wp_load
 */
final class Command {
    public function __invoke( $args, $assocArgs ) {
        unset( $args );
        $probeTransport = isset( $assocArgs['probe-transport'] );

        $console = new Console();
        $paths = $this->resolvePaths();
        $siteHash = $this->siteHash();
        $session = new SessionManager( $paths['state_dir'], $siteHash, array( 'probe_transport' => $probeTransport ) );
        $bridge = null;
        $cleaned = false;

        try {
            $token = $session->start();
            $bridge = new BridgeInstaller(
                $paths['bridge'],
                $paths['template'],
                $token,
                $session->getSessionFile(),
                $siteHash
            );
            $bridge->install();

            $logs = new EmailLogWriter( $paths['logs'] );
            $logs->prepare();
            $spool = new SpoolReader( $session->getSpoolDirectory(), $token );
            $listener = new Listener( $console, $session, $spool, $logs );

            $cleanup = function () use ( &$cleaned, $bridge, $session ) {
                if ( $cleaned ) {
                    return;
                }
                $cleaned = true;
                $bridge->uninstall();
                $session->stop();
            };

            register_shutdown_function( $cleanup );

            $console->header( home_url( '/' ), $this->environmentLabel(), $logs->getDirectory() );
            $this->warnAboutPreemptedMail( $console );
            $listener->run();
            $cleanup();
            $console->line();
            $console->summary( $listener->getCount(), $listener->getFailedCount(), $listener->getHasIssuesCount(), $logs->getDirectory() );
        } catch ( \Throwable $e ) {
            if ( $bridge instanceof BridgeInstaller ) {
                $bridge->uninstall();
            }
            $session->stop();
            \WP_CLI::error( $e->getMessage() );
        }
    }

    private function resolvePaths() {
        $muDir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
        $cwd = getcwd();
        if ( false === $cwd || '' === $cwd ) {
            $cwd = ABSPATH;
        }

        return array(
            'bridge' => rtrim( $muDir, '/\\' ) . DIRECTORY_SEPARATOR . 'wp-email-debug-runtime.php',
            'state_dir' => rtrim( $muDir, '/\\' ) . DIRECTORY_SEPARATOR . '.wp-email-debug',
            'template' => dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'bridge-template.php',
            'logs' => rtrim( $cwd, '/\\' ) . DIRECTORY_SEPARATOR . 'email-debug',
        );
    }

    private function siteHash() {
        $parts = array(
            function_exists( 'wp_normalize_path' ) ? wp_normalize_path( ABSPATH ) : ABSPATH,
            function_exists( 'wp_normalize_path' ) ? wp_normalize_path( WP_CONTENT_DIR ) : WP_CONTENT_DIR,
            home_url( '/' ),
        );
        return hash( 'sha256', implode( '|', $parts ) );
    }

    private function environmentLabel() {
        if ( ! function_exists( 'wp_get_environment_type' ) ) {
            return 'unknown';
        }
        return wp_get_environment_type();
    }

    private function warnAboutPreemptedMail( Console $console ) {
        if ( ! function_exists( 'has_filter' ) || ! has_filter( 'pre_wp_mail' ) ) {
            return;
        }

        $console->warning( 'This site has pre_wp_mail filters. Mail sent by a plugin that bypasses PHPMailer may not be intercepted.' );
        $console->line();
    }
}
