<?php

namespace WpEmailDebug;

final class EmailLogWriter {
    private $directory;

    public function __construct( $directory ) {
        $this->directory = rtrim( (string) $directory, '/\\' );
    }

    public function prepare() {
        if ( is_link( $this->directory ) ) {
            throw new \RuntimeException( 'Refusing to use a symlink for the email log directory: ' . $this->directory );
        }

        if ( is_dir( $this->directory ) ) {
            return;
        }

        if ( file_exists( $this->directory ) ) {
            throw new \RuntimeException( 'Email log path exists but is not a directory: ' . $this->directory );
        }

        if ( ! @mkdir( $this->directory, 0700, true ) && ! is_dir( $this->directory ) ) {
            throw new \RuntimeException( 'Unable to create email log directory: ' . $this->directory );
        }

        @chmod( $this->directory, 0700 );
    }

    public function write( array $email ) {
        $this->prepare();

        $timestamp = isset( $email['captured_at'] ) ? (float) $email['captured_at'] : microtime( true );
        $subject = isset( $email['subject'] ) ? (string) $email['subject'] : '';
        $status = isset( $email['status'] ) ? (string) $email['status'] : 'successful';
        $statusToken = $this->statusToken( $status );
        $base = $this->formatTimestamp( $timestamp ) . '_' . $statusToken . '_' . $this->slugify( '' !== trim( $subject ) ? $subject : 'no-subject' );
        $path = $this->uniquePath( $base );
        $content = $this->formatLog( $email, $timestamp );

        if ( false === @file_put_contents( $path, $content, LOCK_EX ) ) {
            throw new \RuntimeException( 'Unable to write email debug log: ' . $path );
        }

        @chmod( $path, 0600 );

        return $path;
    }

    public function getDirectory() {
        return $this->directory;
    }


    private function statusToken( $status ) {
        if ( 'failed' === $status ) {
            return 'FAILED';
        }

        if ( 'has_issues' === $status ) {
            return 'HAS-ISSUES';
        }

        return 'SUCCESSFUL';
    }

    private function formatLog( array $email, $timestamp ) {
        $lines = array();
        $lines[] = 'WP EMAIL DEBUG';
        $lines[] = str_repeat( '-', 68 );
        $lines[] = '';
        $status = isset( $email['status'] ) ? (string) $email['status'] : 'successful';
        $lines[] = $this->field( 'Status', 'failed' === $status ? 'FAILED' : ( 'has_issues' === $status ? 'HAS ISSUES' : 'SUCCESSFUL' ) );
        $lines[] = $this->field( 'Time', $this->displayDateTime( $timestamp ) );
        $lines[] = $this->field( 'Subject', isset( $email['subject'] ) && '' !== (string) $email['subject'] ? $email['subject'] : '(no subject)' );
        $lines[] = $this->field( 'From', ! empty( $email['from'] ) ? $email['from'] : '-' );
        $lines[] = $this->field( 'To', $this->joinList( isset( $email['to'] ) ? $email['to'] : array() ) );
        $lines[] = $this->field( 'CC', $this->joinList( isset( $email['cc'] ) ? $email['cc'] : array() ) );
        $lines[] = $this->field( 'BCC', $this->joinList( isset( $email['bcc'] ) ? $email['bcc'] : array() ) );
        $lines[] = $this->field( 'Reply-To', $this->joinList( isset( $email['reply_to'] ) ? $email['reply_to'] : array() ) );
        $lines[] = $this->field( 'Content-Type', isset( $email['content_type'] ) ? $email['content_type'] : '-' );
        $lines[] = $this->field( 'Charset', isset( $email['charset'] ) ? $email['charset'] : '-' );
        if ( ! empty( $email['transport'] ) && is_array( $email['transport'] ) ) {
            $transport = $email['transport'];
            $lines[] = $this->field( 'Mailer', isset( $transport['mailer'] ) ? $transport['mailer'] : '-' );
            if ( isset( $transport['mailer'] ) && 'smtp' === strtolower( (string) $transport['mailer'] ) ) {
                $host = isset( $transport['host'] ) ? (string) $transport['host'] : '-';
                $port = isset( $transport['port'] ) ? (int) $transport['port'] : 0;
                $lines[] = $this->field( 'SMTP', $host . ( $port > 0 ? ':' . $port : '' ) );
                $lines[] = $this->field( 'SMTP auth', ! empty( $transport['smtp_auth'] ) ? 'yes' : 'no' );
                $lines[] = $this->field( 'SMTP secure', ! empty( $transport['smtp_secure'] ) ? $transport['smtp_secure'] : '-' );
                $probe = isset( $email['transport_probe'] ) && is_array( $email['transport_probe'] ) ? $email['transport_probe'] : array();
                $probeStatus = isset( $probe['status'] ) ? (string) $probe['status'] : 'not_run';
                if ( empty( $probe['enabled'] ) ) {
                    $probeStatus = 'not run';
                } elseif ( 'ok' === $probeStatus ) {
                    $probeStatus = 'passed';
                } elseif ( 'failed' === $probeStatus ) {
                    $probeStatus = 'failed';
                } elseif ( 'unsupported' === $probeStatus ) {
                    $probeStatus = 'unsupported';
                } elseif ( 'not_applicable' === $probeStatus ) {
                    $probeStatus = 'not applicable';
                } else {
                    $probeStatus = str_replace( '_', ' ', $probeStatus );
                }
                $lines[] = $this->field( 'SMTP check', $probeStatus );
            }
        }
        $lines[] = $this->field( 'Attachments', isset( $email['attachments'] ) && is_array( $email['attachments'] ) ? count( $email['attachments'] ) : 0 );
        $lines[] = $this->field( 'Source', isset( $email['source']['label'] ) ? $email['source']['label'] : 'Other' );
        if ( ! empty( $email['source']['file'] ) ) {
            $source = $email['source']['file'];
            if ( ! empty( $email['source']['line'] ) ) {
                $source .= ':' . (int) $email['source']['line'];
            }
            $lines[] = $this->field( 'Source file', $source );
        }
        if ( ! empty( $email['request'] ) && is_array( $email['request'] ) ) {
            $request = $email['request'];
            $method = ! empty( $request['method'] ) ? (string) $request['method'] : '-';
            $path = isset( $request['path'] ) && '' !== (string) $request['path'] ? (string) $request['path'] : '-';
            $type = ! empty( $request['type'] ) ? (string) $request['type'] : 'unknown';
            $lines[] = $this->field( 'Request', $method . ' ' . $path );
            $lines[] = $this->field( 'Request type', $type );
        }
        if ( ! empty( $email['truncated'] ) ) {
            $lines[] = $this->field( 'Notice', 'Message body truncated by wp-email-debug size limit.' );
        }

        $issues = isset( $email['issues'] ) && is_array( $email['issues'] ) ? $email['issues'] : array();
        if ( 'failed' === $status ) {
            $error = isset( $email['error'] ) && is_array( $email['error'] ) ? $email['error'] : array();
            $failureMessage = isset( $error['message'] ) && '' !== trim( (string) $error['message'] )
                ? (string) $error['message']
                : 'WordPress reported an unknown wp_mail() failure.';
            array_unshift(
                $issues,
                array(
                    'level' => 'error',
                    'code' => 'wp_mail_failed',
                    'message' => 'WordPress/PHPMailer failed to send this email.',
                    'detail' => 'Error: ' . $failureMessage,
                    'result' => 'The email was not sent.',
                )
            );
        }

        $lines[] = '';
        $lines[] = 'ISSUES';
        $lines[] = str_repeat( '-', 68 );
        $lines[] = '';

        if ( empty( $issues ) ) {
            $probe = isset( $email['transport_probe'] ) && is_array( $email['transport_probe'] ) ? $email['transport_probe'] : array();
            if ( ! empty( $probe['enabled'] ) && 'ok' === ( isset( $probe['status'] ) ? $probe['status'] : '' ) ) {
                $lines[] = '✓ No local configuration, message, or SMTP pre-delivery issues detected.';
            } else {
                $lines[] = '✓ No local configuration or message issues detected.';
            }
        } else {
            foreach ( $issues as $issue ) {
                if ( ! is_array( $issue ) || empty( $issue['message'] ) ) {
                    continue;
                }

                $lines[] = $this->issueField( 'Issue', $issue['message'] );

                if ( ! empty( $issue['detail'] ) ) {
                    list( $detailLabel, $detailValue ) = $this->splitIssueDetail( $issue['detail'] );
                    $lines[] = $this->issueField( $detailLabel, $detailValue );
                }

                if ( ! empty( $issue['result'] ) ) {
                    $lines[] = $this->issueField( 'Details', $issue['result'] );
                }

                $lines[] = '';
            }
            if ( '' === end( $lines ) ) {
                array_pop( $lines );
            }
        }

        if ( 'failed' === $status ) {
            $error = isset( $email['error'] ) && is_array( $email['error'] ) ? $email['error'] : array();
            $lines[] = '';
            $lines[] = 'ERROR DETAILS';
            $lines[] = str_repeat( '-', 68 );
            $lines[] = '';
            $lines[] = $this->field( 'Code', isset( $error['code'] ) && '' !== (string) $error['code'] ? $error['code'] : 'wp_mail_failed' );
            $lines[] = $this->field( 'Message', isset( $error['message'] ) && '' !== (string) $error['message'] ? $error['message'] : 'Unknown wp_mail() failure.' );
            if ( isset( $error['phpmailer_exception_code'] ) && null !== $error['phpmailer_exception_code'] && '' !== (string) $error['phpmailer_exception_code'] ) {
                $lines[] = $this->field( 'PHPMailer', $error['phpmailer_exception_code'] );
            }
            if ( ! empty( $error['messages'] ) && is_array( $error['messages'] ) && count( $error['messages'] ) > 1 ) {
                $lines[] = $this->field( 'All messages', implode( ' | ', array_map( 'strval', $error['messages'] ) ) );
            }
        }

        $lines[] = '';
        $lines[] = 'EMAIL CONTENT';
        $lines[] = str_repeat( '-', 68 );
        $lines[] = '';
        $lines[] = '';
        $lines[] = $this->decodeBody( isset( $email['body_b64'] ) ? $email['body_b64'] : '' );
        $lines[] = '';
        $lines[] = '';

        $altBodyEncoded = isset( $email['alt_body_b64'] ) ? (string) $email['alt_body_b64'] : '';
        if ( '' !== $altBodyEncoded ) {
            $altBody = $this->decodeBody( $altBodyEncoded );
            if ( '[empty]' !== $altBody && '' !== trim( $altBody ) ) {
                $lines[] = '';
                $lines[] = 'ALTERNATIVE CONTENT';
                $lines[] = str_repeat( '-', 68 );
                $lines[] = '';
                $lines[] = $altBody;
            }
        }

        if ( ! empty( $email['attachments'] ) && is_array( $email['attachments'] ) ) {
            $lines[] = '';
            $lines[] = 'ATTACHMENTS';
            $lines[] = str_repeat( '-', 68 );
            $lines[] = '';
            $index = 1;
            foreach ( $email['attachments'] as $attachment ) {
                $name = ! empty( $attachment['name'] ) ? $attachment['name'] : '(unnamed)';
                $type = ! empty( $attachment['type'] ) ? $attachment['type'] : 'application/octet-stream';
                $size = isset( $attachment['size'] ) ? Console::formatBytes( (int) $attachment['size'] ) : '-';
                $lines[] = sprintf( '[%d]  %s    %s    %s', $index, $name, $type, $size );
                $index++;
            }
        }

        $lines[] = '';

        return implode( PHP_EOL, $lines );
    }

    private function field( $label, $value ) {
        return str_pad( $label . ':', 14, ' ', STR_PAD_RIGHT ) . $value;
    }

    private function issueField( $label, $value ) {
        return str_pad( (string) $label, 7, ' ', STR_PAD_RIGHT ) . ':    ' . (string) $value;
    }

    private function splitIssueDetail( $detail ) {
        $detail = trim( (string) $detail );

        if ( preg_match( '/^([A-Za-z][A-Za-z -]{0,30}):\s*(.*)$/', $detail, $matches ) ) {
            return array( trim( $matches[1] ), trim( $matches[2] ) );
        }

        return array( 'Context', $detail );
    }

    private function joinList( $values ) {
        if ( ! is_array( $values ) || empty( $values ) ) {
            return '-';
        }
        return implode( ', ', array_map( 'strval', $values ) );
    }

    private function decodeBody( $value ) {
        if ( '' === (string) $value ) {
            return '[empty]';
        }
        $decoded = base64_decode( (string) $value, true );
        return false === $decoded ? '[unable to decode captured body]' : $decoded;
    }

    private function formatTimestamp( $timestamp ) {
        $seconds = (int) floor( $timestamp );
        if ( function_exists( 'wp_date' ) ) {
            return wp_date( 'Y-m-d_H-i-s', $seconds );
        }
        return date( 'Y-m-d_H-i-s', $seconds );
    }

    private function displayDateTime( $timestamp ) {
        $seconds = (int) floor( $timestamp );
        if ( function_exists( 'wp_date' ) ) {
            return wp_date( 'Y-m-d H:i:s T', $seconds );
        }
        return date( 'Y-m-d H:i:s T', $seconds );
    }

    private function slugify( $subject ) {
        $subject = (string) $subject;
        if ( function_exists( 'remove_accents' ) ) {
            $subject = remove_accents( $subject );
        }
        if ( function_exists( 'mb_strtolower' ) ) {
            $subject = mb_strtolower( $subject, 'UTF-8' );
        } else {
            $subject = strtolower( $subject );
        }
        $subject = preg_replace( '/[^\p{L}\p{N}]+/u', '-', $subject );
        $subject = trim( (string) $subject, '-' );
        if ( '' === $subject ) {
            $subject = 'no-subject';
        }
        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $subject, 0, 80, 'UTF-8' );
        }
        return substr( $subject, 0, 80 );
    }

    private function uniquePath( $base ) {
        $path = $this->directory . DIRECTORY_SEPARATOR . $base . '.log';
        if ( ! file_exists( $path ) ) {
            return $path;
        }

        $suffix = 2;
        while ( true ) {
            $candidate = $this->directory . DIRECTORY_SEPARATOR . $base . '-' . $suffix . '.log';
            if ( ! file_exists( $candidate ) ) {
                return $candidate;
            }
            $suffix++;
        }
    }
}
