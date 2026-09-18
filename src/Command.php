<?php

namespace WpEmailDebug;

/**
 * Debug outgoing WordPress wp_mail() email.
 *
 * ## EXAMPLES
 *
 *     wp email-debug
 *     wp email-debug --probe-transport
 *     wp email-debug check
 *     wp email-debug test
 *
 * @synopsis [<mode>] [--probe-transport]
 * @when after_wp_load
 */
final class Command {
    public function __invoke( $args, $assocArgs ) {
        $mode = isset( $args[0] ) ? strtolower( trim( (string) $args[0] ) ) : '';

        if ( '' === $mode ) {
            $this->runListener( isset( $assocArgs['probe-transport'] ) );
            return;
        }

        if ( 'check' === $mode ) {
            $this->runCheck();
            return;
        }

        if ( 'test' === $mode ) {
            $this->runTest();
            return;
        }

        \WP_CLI::error( 'Unknown email-debug mode: ' . $mode . '. Use wp email-debug, wp email-debug check, or wp email-debug test.' );
    }

    private function runListener( $probeTransport ) {
        $console = new Console();
        $paths = $this->resolvePaths();
        $siteHash = $this->siteHash();
        $session = new SessionManager( $paths['state_dir'], $siteHash, array( 'probe_transport' => (bool) $probeTransport ) );
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
            $listener->run();
            $cleanup();
            $console->line();
            $console->summary(
                $listener->getCount(),
                $listener->getFailedCount(),
                $listener->getHasIssuesCount(),
                $logs->getDirectory(),
                $listener->getDuplicateCount()
            );
        } catch ( \Throwable $e ) {
            if ( $bridge instanceof BridgeInstaller ) {
                $bridge->uninstall();
            }
            $session->stop();
            \WP_CLI::error( $e->getMessage() );
        }
    }

    private function runTest() {
        $paths = $this->resolvePaths();

        if ( function_exists( 'wp_email_debug_runtime_state' ) ) {
            $runtimeState = wp_email_debug_runtime_state();
            if ( is_array( $runtimeState ) ) {
                \WP_CLI::error( 'An active wp-email-debug listener is running. Stop it before running wp email-debug test.' );
                return;
            }

            if ( BridgeInstaller::removeManagedBridge( $paths['bridge'] ) ) {
                \WP_CLI::runcommand(
                    'email-debug test',
                    array(
                        'launch' => true,
                        'exit_error' => true,
                        'return' => false,
                    )
                );
                return;
            }
        }

        $console = new Console();
        $siteHash = $this->siteHash();
        $session = new SessionManager( $paths['state_dir'], $siteHash, array( 'probe_transport' => false, 'pipeline_test' => true ) );
        $bridge = null;
        $inspector = new MailHookInspector();

        try {
            $token = $session->start();
            $inspector->install();
            $bridge = new BridgeInstaller(
                $paths['bridge'],
                $paths['template'],
                $token,
                $session->getSessionFile(),
                $siteHash
            );
            $bridge->install();
            require $bridge->getBridgePath();

            $logs = new EmailLogWriter( $paths['logs'] );
            $logs->prepare();
            $spool = new SpoolReader( $session->getSpoolDirectory(), $token );
            $runner = new TestMailRunner( $console, $logs, $spool, $inspector );
            $session->heartbeat();
            $runner->run();
        } catch ( \Throwable $e ) {
            \WP_CLI::error( $e->getMessage() );
        } finally {
            $inspector->restore();
            if ( $bridge instanceof BridgeInstaller ) {
                $bridge->uninstall();
            }
            $session->stop();
        }
    }

    private function runCheck() {
        $paths = $this->resolvePaths();

        if ( function_exists( 'wp_email_debug_runtime_state' ) ) {
            $runtimeState = wp_email_debug_runtime_state();
            if ( is_array( $runtimeState ) ) {
                \WP_CLI::error( 'An active wp-email-debug listener is running. Stop it before running wp email-debug check.' );
                return;
            }

            if ( BridgeInstaller::removeManagedBridge( $paths['bridge'] ) ) {
                \WP_CLI::runcommand(
                    'email-debug check',
                    array(
                        'launch' => true,
                        'exit_error' => true,
                        'return' => false,
                    )
                );
                return;
            }
        }

        $console = new Console();
        $siteHash = $this->siteHash();
        $session = new SessionManager( $paths['state_dir'], $siteHash, array( 'probe_transport' => true ) );
        $bridge = null;

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
            require $bridge->getBridgePath();

            $logs = new EmailLogWriter( $paths['logs'] );
            $logs->prepare();
            $spool = new SpoolReader( $session->getSpoolDirectory(), $token );
            $recipient = trim( (string) get_option( 'admin_email' ) );
            if ( '' === $recipient || ( function_exists( 'is_email' ) && ! is_email( $recipient ) ) ) {
                throw new \RuntimeException( 'The WordPress admin_email option does not contain a valid recipient address.' );
            }

            $console->line( 'Email Check' );
            $console->line();
            $console->line( 'Site: ' . home_url( '/' ) );
            $console->line( 'To:   ' . $recipient );
            $console->line();
            $console->tick( 'Checking WordPress mail configuration and transport...', true );

            $session->heartbeat();
            $sent = wp_mail(
                $recipient,
                '[WP Email Debug] Mail Check',
                'This message is intercepted locally. The SMTP check does not send DATA.'
            );

            $messages = $spool->readPending();
            $console->clearSpinner();

            if ( empty( $messages ) ) {
                $message = $this->missingCheckPayload( $recipient, (bool) $sent );
            } else {
                $message = $messages[0];
                $message['log_type'] = 'check';
            }

            $logPath = $logs->write( $message );
            $this->printCheckResult( $console, $message, $logPath );
        } catch ( \Throwable $e ) {
            \WP_CLI::error( $e->getMessage() );
        } finally {
            if ( $bridge instanceof BridgeInstaller ) {
                $bridge->uninstall();
            }
            $session->stop();
        }
    }

    private function printCheckResult( Console $console, array $message, $logPath ) {
        $status = isset( $message['status'] ) ? (string) $message['status'] : 'has_issues';

        if ( 'failed' === $status ) {
            $console->errorLine( 'Mail check failed.' );
            if ( ! empty( $message['error']['message'] ) ) {
                $console->line( 'Error: ' . $message['error']['message'] );
            }
        } elseif ( 'has_issues' === $status ) {
            $console->line( $console->statusText( 'warning', '!' ) . ' Mail check found issues.' );
            if ( ! empty( $message['issues'][0]['message'] ) ) {
                $console->line( 'Issue: ' . $message['issues'][0]['message'] );
            }
        } else {
            $console->success( 'No blocking mail configuration or SMTP pre-delivery issues detected.' );
        }

        $console->line( 'Log:   ' . $logPath );
    }

    private function missingCheckPayload( $recipient, $sent ) {
        $status = $sent ? 'has_issues' : 'failed';
        $issue = array(
            'level' => 'error',
            'code' => 'mail_check_not_captured',
            'message' => 'The mail check could not inspect the PHPMailer transport.',
            'detail' => 'Context: No wp-email-debug capture was produced.',
            'result' => $sent
                ? 'A pre_wp_mail filter may have short-circuited wp_mail(), so the configured transport was not verified.'
                : 'WordPress returned failure before the configured transport could be inspected.',
        );

        return array(
            'version' => 1,
            'log_type' => 'check',
            'status' => $status,
            'captured_at' => microtime( true ),
            'subject' => '[WP Email Debug] Mail Check',
            'from' => '',
            'to' => array( $recipient ),
            'cc' => array(),
            'bcc' => array(),
            'reply_to' => array(),
            'content_type' => 'text/plain',
            'charset' => 'UTF-8',
            'body_b64' => base64_encode( 'This message is intercepted locally. The SMTP check does not send DATA.' ),
            'alt_body_b64' => '',
            'attachments' => array(),
            'issues' => array( $issue ),
            'error' => 'failed' === $status ? array( 'code' => 'mail_check_failed', 'message' => $issue['result'] ) : array(),
            'source' => array( 'type' => 'cli', 'label' => 'WP Email Debug', 'file' => '', 'line' => 0 ),
            'request' => array( 'method' => 'CLI', 'path' => 'wp email-debug check', 'type' => 'cli' ),
        );
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

}
