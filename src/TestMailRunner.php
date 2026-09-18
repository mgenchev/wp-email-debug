<?php

namespace WpEmailDebug;

final class TestMailRunner {
    private const RECIPIENT = 'wp-email-debug@example.com';

    private $console;
    private $logs;
    private $spool;
    private $inspector;

    public function __construct( Console $console, EmailLogWriter $logs, SpoolReader $spool, MailHookInspector $inspector ) {
        $this->console = $console;
        $this->logs = $logs;
        $this->spool = $spool;
        $this->inspector = $inspector;
    }

    public function run() {
        $recipient = self::RECIPIENT;
        $subject = '[WP Email Debug] Pipeline Test';
        $message = $this->testMessage();

        $this->console->line( 'Email Pipeline Test' );
        $this->console->line();
        $this->console->line( 'To:      ' . $recipient );
        $this->console->line( 'Subject: ' . $subject );
        $this->console->line();
        $this->console->tick( 'Testing WordPress mail pipeline...', true );

        $sent = false;
        $thrown = null;

        try {
            $sent = wp_mail( $recipient, $subject, $message );
        } catch ( \Throwable $e ) {
            $thrown = $e;
        }

        $this->console->clearSpinner();
        $messages = $this->spool->readPending();

        if ( ! empty( $messages ) ) {
            $payload = $messages[0];
            $payload['log_type'] = 'test';
        } else {
            $payload = $this->missingPayload( $recipient, $subject, $message, (bool) $sent, $thrown );
        }

        $payload = $this->applyInspectorIssues( $payload, $recipient, $thrown );
        $payload['source'] = array(
            'type' => 'cli',
            'label' => 'WP Email Debug',
            'file' => '',
            'line' => 0,
        );
        $payload['request'] = array(
            'method' => 'CLI',
            'path' => 'wp email-debug test',
            'type' => 'cli',
        );
        $payload['call_trace'] = array();

        $logPath = $this->logs->write( $payload );
        $this->printResult( $payload, $logPath );

        return $payload;
    }

    private function applyInspectorIssues( array $payload, $recipient, $thrown ) {
        $issues = isset( $payload['issues'] ) && is_array( $payload['issues'] ) ? $payload['issues'] : array();
        $errorCode = isset( $payload['error']['code'] ) ? (string) $payload['error']['code'] : '';
        $includeShortCircuit = 'pre_wp_mail_short_circuit' === $errorCode || 'mail_pipeline_not_captured' === $errorCode;
        $inspectorIssues = $this->inspector->issues( $recipient, $includeShortCircuit );

        if ( ! empty( $inspectorIssues ) ) {
            $issues = array_merge( $inspectorIssues, $issues );
        }

        if ( $thrown instanceof \Throwable && empty( $inspectorIssues ) ) {
            array_unshift(
                $issues,
                array(
                    'level' => 'error',
                    'code' => 'mail_pipeline_exception',
                    'message' => 'The WordPress mail pipeline threw an exception.',
                    'detail' => 'Error: ' . get_class( $thrown ) . ': ' . $thrown->getMessage(),
                    'result' => 'The test did not reach the safe mail capture transport.',
                )
            );
        }

        $payload['issues'] = $this->deduplicateIssues( $issues );

        $hasError = false;
        $hasWarning = false;
        foreach ( $payload['issues'] as $issue ) {
            if ( ! is_array( $issue ) ) {
                continue;
            }
            $level = isset( $issue['level'] ) ? (string) $issue['level'] : 'warning';
            if ( 'error' === $level ) {
                $hasError = true;
            } elseif ( 'warning' === $level ) {
                $hasWarning = true;
            }
        }

        if ( $hasError ) {
            $payload['status'] = 'failed';
        } elseif ( $hasWarning && 'failed' !== ( isset( $payload['status'] ) ? $payload['status'] : '' ) ) {
            $payload['status'] = 'has_issues';
        }

        if ( 'failed' === ( isset( $payload['status'] ) ? $payload['status'] : '' ) && empty( $payload['error'] ) ) {
            $payload['error'] = array(
                'code' => 'mail_pipeline_failed',
                'message' => 'The WordPress mail pipeline test did not complete successfully.',
            );
        }

        return $payload;
    }

    private function missingPayload( $recipient, $subject, $message, $sent, $thrown ) {
        $customWpMail = $this->customWpMailSource();
        $issues = array();
        $status = $sent ? 'has_issues' : 'failed';
        $errorMessage = $thrown instanceof \Throwable
            ? get_class( $thrown ) . ': ' . $thrown->getMessage()
            : ( $sent ? 'wp_mail() returned success without reaching the safe PHPMailer capture transport.' : 'wp_mail() returned false before the safe PHPMailer capture transport was reached.' );

        if ( '' !== $customWpMail ) {
            $issues[] = array(
                'level' => $sent ? 'warning' : 'error',
                'code' => 'custom_wp_mail',
                'message' => 'A custom wp_mail() implementation is active.',
                'detail' => 'Source: ' . $customWpMail,
                'result' => 'The standard WordPress/PHPMailer mail pipeline could not be fully verified by this test.',
            );
        } else {
            $issues[] = array(
                'level' => 'error',
                'code' => 'mail_pipeline_not_captured',
                'message' => 'The test email did not reach the PHPMailer capture transport.',
                'detail' => 'Context: No wp-email-debug capture was produced.',
                'result' => 'A mail hook or custom code may have stopped or bypassed the normal WordPress mail pipeline.',
            );
            $status = 'failed';
        }

        return array(
            'version' => 1,
            'log_type' => 'test',
            'status' => $status,
            'captured_at' => microtime( true ),
            'subject' => $subject,
            'from' => '',
            'to' => array( $recipient ),
            'cc' => array(),
            'bcc' => array(),
            'reply_to' => array(),
            'content_type' => 'text/plain',
            'charset' => 'UTF-8',
            'body_b64' => base64_encode( $message ),
            'alt_body_b64' => '',
            'body_bytes' => strlen( $message ),
            'raw_mime_bytes' => 0,
            'truncated' => false,
            'attachments' => array(),
            'transport' => array(),
            'transport_probe' => array( 'enabled' => false, 'status' => 'not_run' ),
            'issues' => $issues,
            'error' => 'failed' === $status ? array( 'code' => 'mail_pipeline_failed', 'message' => $errorMessage ) : array(),
        );
    }

    private function printResult( array $payload, $logPath ) {
        $status = isset( $payload['status'] ) ? (string) $payload['status'] : 'failed';

        if ( 'failed' === $status ) {
            $this->console->errorLine( 'Mail pipeline test failed.' );
            if ( ! empty( $payload['issues'][0]['message'] ) ) {
                $this->console->line( 'Issue: ' . $payload['issues'][0]['message'] );
            }
        } elseif ( 'has_issues' === $status ) {
            $this->console->warning( 'Mail pipeline test completed with issues.' );
            if ( ! empty( $payload['issues'][0]['message'] ) ) {
                $this->console->line( 'Issue: ' . $payload['issues'][0]['message'] );
            }
        } else {
            $this->console->success( 'WordPress mail pipeline completed successfully.' );
        }

        $this->console->line( 'Log:   ' . $logPath );
        $this->console->line();
        $this->console->line( 'External delivery was blocked by the safe test transport. Use wp email-debug check to validate the configured transport.' );
    }

    private function testMessage() {
        $time = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i:s T' ) : date( 'Y-m-d H:i:s T' );
        return "This is a controlled WordPress mail pipeline test from wp-email-debug.\n\n"
            . 'Site: ' . home_url( '/' ) . "\n"
            . 'Time: ' . $time . "\n\n"
            . "External delivery is intentionally blocked by the test transport.\n";
    }

    private function customWpMailSource() {
        if ( ! function_exists( 'wp_mail' ) ) {
            return '';
        }

        try {
            $reflection = new \ReflectionFunction( 'wp_mail' );
            $file = (string) $reflection->getFileName();
        } catch ( \ReflectionException $e ) {
            return '';
        }

        if ( '' === $file ) {
            return '';
        }

        $normalized = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( $file ) : str_replace( '\\', '/', $file );
        if ( false !== strpos( $normalized, '/wp-includes/pluggable.php' ) ) {
            return '';
        }

        return $normalized;
    }

    private function deduplicateIssues( array $issues ) {
        $result = array();
        $seen = array();

        foreach ( $issues as $issue ) {
            if ( ! is_array( $issue ) ) {
                continue;
            }
            $key = ( isset( $issue['code'] ) ? (string) $issue['code'] : '' ) . '|'
                . ( isset( $issue['detail'] ) ? (string) $issue['detail'] : '' ) . '|'
                . ( isset( $issue['result'] ) ? (string) $issue['result'] : '' );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $result[] = $issue;
        }

        return $result;
    }
}
