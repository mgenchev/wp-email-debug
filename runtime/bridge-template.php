<?php
/* WP_EMAIL_DEBUG_RUNTIME_V1 */

if ( ! defined( 'ABSPATH' ) ) {
    return;
}

/*
 * A stale bridge can survive an interrupted listener session. Bail out before
 * declaring any runtime functions unless the embedded session is still live.
 * This keeps later WP-CLI commands such as `wp email-debug check` clean.
 */
$wpEmailDebugBootstrapSessionFile = '__WP_EMAIL_DEBUG_SESSION_FILE__';
$wpEmailDebugBootstrapToken = '__WP_EMAIL_DEBUG_TOKEN__';
$wpEmailDebugBootstrapActive = false;

if ( is_file( $wpEmailDebugBootstrapSessionFile ) && ! is_link( $wpEmailDebugBootstrapSessionFile ) ) {
    $wpEmailDebugBootstrapContents = @file_get_contents( $wpEmailDebugBootstrapSessionFile );
    $wpEmailDebugBootstrapState = false !== $wpEmailDebugBootstrapContents
        ? json_decode( $wpEmailDebugBootstrapContents, true )
        : null;

    if (
        is_array( $wpEmailDebugBootstrapState )
        && ! empty( $wpEmailDebugBootstrapState['token'] )
        && hash_equals( $wpEmailDebugBootstrapToken, (string) $wpEmailDebugBootstrapState['token'] )
        && ! empty( $wpEmailDebugBootstrapState['heartbeat'] )
        && ( time() - (int) $wpEmailDebugBootstrapState['heartbeat'] ) <= 5
        && ! empty( $wpEmailDebugBootstrapState['spool_directory'] )
        && is_dir( $wpEmailDebugBootstrapState['spool_directory'] )
        && ! is_link( $wpEmailDebugBootstrapState['spool_directory'] )
    ) {
        $wpEmailDebugBootstrapActive = true;
    }
}

unset(
    $wpEmailDebugBootstrapContents,
    $wpEmailDebugBootstrapState
);

if ( ! $wpEmailDebugBootstrapActive ) {
    return;
}

unset(
    $wpEmailDebugBootstrapSessionFile,
    $wpEmailDebugBootstrapToken,
    $wpEmailDebugBootstrapActive
);

if ( ! function_exists( 'wp_email_debug_runtime_state' ) ) {
    function wp_email_debug_runtime_state() {
        $sessionFile = '__WP_EMAIL_DEBUG_SESSION_FILE__';
        $token = '__WP_EMAIL_DEBUG_TOKEN__';
        $siteHash = '__WP_EMAIL_DEBUG_SITE_HASH__';

        if ( ! is_file( $sessionFile ) || is_link( $sessionFile ) ) {
            return null;
        }

        $contents = @file_get_contents( $sessionFile );
        if ( false === $contents || '' === trim( $contents ) ) {
            return null;
        }

        $state = json_decode( $contents, true );
        if ( ! is_array( $state ) ) {
            return null;
        }

        if ( empty( $state['token'] ) || ! hash_equals( $token, (string) $state['token'] ) ) {
            return null;
        }

        if ( empty( $state['site_hash'] ) || ! hash_equals( $siteHash, (string) $state['site_hash'] ) ) {
            return null;
        }

        $currentSiteHash = hash(
            'sha256',
            wp_normalize_path( ABSPATH ) . '|' . wp_normalize_path( WP_CONTENT_DIR ) . '|' . home_url( '/' )
        );
        if ( ! hash_equals( $siteHash, $currentSiteHash ) ) {
            return null;
        }

        if ( empty( $state['heartbeat'] ) || ( time() - (int) $state['heartbeat'] ) > 5 ) {
            return null;
        }

        if ( empty( $state['spool_directory'] ) || ! is_dir( $state['spool_directory'] ) || is_link( $state['spool_directory'] ) ) {
            return null;
        }

        return $state;
    }
}

if ( ! function_exists( 'wp_email_debug_format_address_list' ) ) {
    function wp_email_debug_format_address_list( $values ) {
        $result = array();
        if ( ! is_array( $values ) ) {
            return $result;
        }

        foreach ( $values as $value ) {
            if ( ! is_array( $value ) || empty( $value[0] ) ) {
                continue;
            }
            $address = (string) $value[0];
            $name = isset( $value[1] ) ? trim( (string) $value[1] ) : '';
            $result[] = '' !== $name ? $name . ' <' . $address . '>' : $address;
        }

        return $result;
    }
}

if ( ! function_exists( 'wp_email_debug_source_from_trace' ) ) {
    function wp_email_debug_source_from_trace() {
        $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
        $bridgeFile = wp_normalize_path( __FILE__ );
        $pluginDir = defined( 'WP_PLUGIN_DIR' ) ? trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) ) : '';
        $muDir = defined( 'WPMU_PLUGIN_DIR' ) ? trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) ) : '';
        $themeRoot = function_exists( 'get_theme_root' ) ? trailingslashit( wp_normalize_path( get_theme_root() ) ) : '';
        $abspath = trailingslashit( wp_normalize_path( ABSPATH ) );

        foreach ( $trace as $frame ) {
            if ( empty( $frame['file'] ) ) {
                continue;
            }

            $file = wp_normalize_path( $frame['file'] );
            if ( $file === $bridgeFile ) {
                continue;
            }

            if ( '' !== $pluginDir && 0 === strpos( $file, $pluginDir ) ) {
                $relative = substr( $file, strlen( $pluginDir ) );
                $parts = explode( '/', $relative );
                return array(
                    'type' => 'plugin',
                    'label' => isset( $parts[0] ) && '' !== $parts[0] ? $parts[0] : 'Plugin',
                    'file' => $file,
                    'line' => isset( $frame['line'] ) ? (int) $frame['line'] : 0,
                );
            }

            if ( '' !== $muDir && 0 === strpos( $file, $muDir ) ) {
                $relative = substr( $file, strlen( $muDir ) );
                $parts = explode( '/', $relative );
                return array(
                    'type' => 'plugin',
                    'label' => isset( $parts[0] ) && '' !== $parts[0] ? $parts[0] : 'MU Plugin',
                    'file' => $file,
                    'line' => isset( $frame['line'] ) ? (int) $frame['line'] : 0,
                );
            }

            if ( '' !== $themeRoot && 0 === strpos( $file, $themeRoot ) ) {
                $relative = substr( $file, strlen( $themeRoot ) );
                $parts = explode( '/', $relative );
                return array(
                    'type' => 'theme',
                    'label' => isset( $parts[0] ) && '' !== $parts[0] ? $parts[0] : 'Theme',
                    'file' => $file,
                    'line' => isset( $frame['line'] ) ? (int) $frame['line'] : 0,
                );
            }

            if ( 0 !== strpos( $file, $abspath ) ) {
                return array(
                    'type' => 'other',
                    'label' => 'Other',
                    'file' => $file,
                    'line' => isset( $frame['line'] ) ? (int) $frame['line'] : 0,
                );
            }
        }

        return array(
            'type' => 'core',
            'label' => 'WordPress Core',
            'file' => '',
            'line' => 0,
        );
    }
}

if ( ! function_exists( 'wp_email_debug_call_trace' ) ) {
    function wp_email_debug_call_trace() {
        $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 48 );
        $abspath = trailingslashit( wp_normalize_path( ABSPATH ) );
        $lines = array();
        $seen = array();
        $afterWpMail = false;
        $skipFunctions = array(
            'apply_filters',
            'apply_filters_ref_array',
            'do_action',
            'do_action_ref_array',
            'call_user_func',
            'call_user_func_array',
            'include',
            'include_once',
            'require',
            'require_once',
            '{closure}',
        );

        foreach ( $trace as $frame ) {
            $function = isset( $frame['function'] ) ? (string) $frame['function'] : '';
            $file = ! empty( $frame['file'] ) ? wp_normalize_path( $frame['file'] ) : '';

            if ( ! $afterWpMail ) {
                if ( 'wp_mail' !== $function ) {
                    continue;
                }

                $afterWpMail = true;
                if ( '' !== $file ) {
                    $displayFile = 0 === strpos( $file, $abspath ) ? substr( $file, strlen( $abspath ) ) : $file;
                    $line = isset( $frame['line'] ) ? (int) $frame['line'] : 0;
                    $lines[] = 'Origin: ' . $displayFile . ( $line > 0 ? ':' . $line : '' );
                }
                continue;
            }

            if ( '' === $function || in_array( strtolower( $function ), $skipFunctions, true ) ) {
                continue;
            }

            if ( 0 === strpos( $function, 'WP_CLI' ) ) {
                continue;
            }

            $callable = '';
            if ( ! empty( $frame['class'] ) ) {
                $class = (string) $frame['class'];
                if ( 0 === strpos( $class, 'WP_Hook' ) ) {
                    continue;
                }
                $callable = $class . ( isset( $frame['type'] ) ? (string) $frame['type'] : '::' ) . $function . '()';
            } else {
                $callable = $function . '()';
            }

            if ( isset( $seen[ $callable ] ) ) {
                continue;
            }
            $seen[ $callable ] = true;
            $lines[] = $callable;

            if ( count( $lines ) >= 4 ) {
                break;
            }
        }

        return $lines;
    }
}

if ( ! function_exists( 'wp_email_debug_attachment_metadata' ) ) {
    function wp_email_debug_attachment_metadata( $phpmailer ) {
        if ( ! method_exists( $phpmailer, 'getAttachments' ) ) {
            return array();
        }

        $attachments = $phpmailer->getAttachments();
        if ( ! is_array( $attachments ) ) {
            return array();
        }

        $result = array();
        foreach ( $attachments as $attachment ) {
            if ( ! is_array( $attachment ) ) {
                continue;
            }

            $path = isset( $attachment[0] ) ? (string) $attachment[0] : '';
            $name = isset( $attachment[2] ) && '' !== (string) $attachment[2] ? (string) $attachment[2] : basename( $path );
            $type = isset( $attachment[4] ) && '' !== (string) $attachment[4] ? (string) $attachment[4] : 'application/octet-stream';
            $size = '' !== $path && is_file( $path ) ? @filesize( $path ) : false;

            $result[] = array(
                'name' => $name,
                'type' => $type,
                'size' => false === $size ? null : (int) $size,
            );
        }

        return $result;
    }
}

if ( ! function_exists( 'wp_email_debug_request_context' ) ) {
    function wp_email_debug_request_context() {
        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'CLI';
        $requestUri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = '' !== $requestUri ? parse_url( $requestUri, PHP_URL_PATH ) : '';
        if ( false === $path || null === $path ) {
            $path = '';
        }

        $type = 'frontend';
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            $type = 'cli';
        } elseif ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
            $type = 'cron';
        } elseif ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
            $type = 'ajax';
        } elseif ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            $type = 'rest';
        } elseif ( function_exists( 'is_admin' ) && is_admin() ) {
            $type = 'admin';
        }

        return array(
            'method' => $method,
            'path' => (string) $path,
            'type' => $type,
        );
    }
}


if ( ! function_exists( 'wp_email_debug_mail_args_registry' ) ) {
    function wp_email_debug_mail_args_registry( $action, $value = null ) {
        static $stack = array();

        if ( 'push' === $action ) {
            $stack[] = is_array( $value ) ? $value : array();
            return end( $stack );
        }

        if ( 'pop' === $action ) {
            return empty( $stack ) ? array() : array_pop( $stack );
        }

        if ( 'peek' === $action ) {
            return empty( $stack ) ? array() : end( $stack );
        }

        return null;
    }
}

if ( ! function_exists( 'wp_email_debug_parse_address_for_validation' ) ) {
    function wp_email_debug_parse_address_for_validation( $value ) {
        $value = trim( (string) $value );
        if ( preg_match( '/<([^>]+)>/', $value, $matches ) ) {
            $value = trim( $matches[1] );
        }
        return $value;
    }
}

if ( ! function_exists( 'wp_email_debug_validation_issue' ) ) {
    function wp_email_debug_validation_issue( $level, $code, $message, $detail = '', $result = '' ) {
        return array(
            'level' => $level,
            'code' => $code,
            'message' => $message,
            'detail' => (string) $detail,
            'result' => (string) $result,
        );
    }
}

if ( ! function_exists( 'wp_email_debug_validate_mail_args' ) ) {
    function wp_email_debug_validate_mail_args( array $args ) {
        $issues = array();
        $recipients = wp_email_debug_normalize_mail_list( isset( $args['to'] ) ? $args['to'] : array() );

        if ( empty( $recipients ) ) {
            $issues[] = wp_email_debug_validation_issue(
                'error',
                'missing_recipient',
                'No recipient address was provided.',
                '',
                'The email cannot be delivered because there is nowhere to send it.'
            );
        }

        foreach ( $recipients as $recipient ) {
            $address = wp_email_debug_parse_address_for_validation( $recipient );
            $valid = function_exists( 'is_email' ) ? is_email( $address ) : filter_var( $address, FILTER_VALIDATE_EMAIL );
            if ( ! $valid ) {
                $issues[] = wp_email_debug_validation_issue(
                    'warning',
                    'invalid_recipient',
                    'Recipient address is invalid.',
                    'Address: ' . $recipient,
                    'WordPress/PHPMailer may reject or ignore this recipient. If no valid recipients remain, the email cannot be delivered.'
                );
            }
        }

        $attachments = isset( $args['attachments'] ) ? $args['attachments'] : array();
        foreach ( wp_email_debug_normalize_mail_list( $attachments ) as $attachment ) {
            if ( ! is_file( $attachment ) ) {
                $issues[] = wp_email_debug_validation_issue(
                    'warning',
                    'attachment_missing',
                    'Attachment cannot be found.',
                    'File: ' . $attachment,
                    'WordPress will send the email without this attachment.'
                );
                continue;
            }
            if ( ! is_readable( $attachment ) ) {
                $issues[] = wp_email_debug_validation_issue(
                    'warning',
                    'attachment_unreadable',
                    'Attachment cannot be read.',
                    'File: ' . $attachment,
                    'WordPress may send the email without this attachment.'
                );
            }
        }

        $embeds = isset( $args['embeds'] ) ? $args['embeds'] : array();
        foreach ( wp_email_debug_normalize_mail_list( $embeds ) as $embed ) {
            if ( ! is_file( $embed ) ) {
                $issues[] = wp_email_debug_validation_issue(
                    'warning',
                    'embed_missing',
                    'Embedded file cannot be found.',
                    'File: ' . $embed,
                    'WordPress will send the email without this embedded file.'
                );
                continue;
            }
            if ( ! is_readable( $embed ) ) {
                $issues[] = wp_email_debug_validation_issue(
                    'warning',
                    'embed_unreadable',
                    'Embedded file cannot be read.',
                    'File: ' . $embed,
                    'WordPress may send the email without this embedded file.'
                );
            }
        }

        $headers = isset( $args['headers'] ) ? $args['headers'] : array();
        $headerLines = array();
        if ( is_string( $headers ) ) {
            $headerLines = preg_split( '/\r?\n/', $headers );
        } elseif ( is_array( $headers ) ) {
            foreach ( $headers as $name => $value ) {
                $headerLines[] = is_string( $name ) && ! is_int( $name ) ? $name . ': ' . $value : (string) $value;
            }
        }
        foreach ( $headerLines as $header ) {
            $header = trim( (string) $header );
            if ( '' === $header ) {
                continue;
            }
            if ( false === strpos( $header, ':' ) ) {
                $issues[] = wp_email_debug_validation_issue(
                    'warning',
                    'malformed_header',
                    'Email header is malformed.',
                    'Header: ' . $header,
                    'WordPress may ignore this header, so the email can be sent with different headers than expected.'
                );
            }
        }

        return $issues;
    }
}

if ( ! function_exists( 'wp_email_debug_sendmail_binary' ) ) {
    function wp_email_debug_sendmail_binary( $command ) {
        $command = trim( (string) $command );
        if ( '' === $command ) {
            return '';
        }
        if ( '"' === substr( $command, 0, 1 ) && preg_match( '/^"([^"]+)"/', $command, $matches ) ) {
            return $matches[1];
        }
        $parts = preg_split( '/\s+/', $command );
        return isset( $parts[0] ) ? trim( $parts[0], "'\"" ) : '';
    }
}

if ( ! function_exists( 'wp_email_debug_transport_snapshot' ) ) {
    function wp_email_debug_transport_snapshot( $phpmailer ) {
        $mailer = isset( $phpmailer->Mailer ) ? strtolower( (string) $phpmailer->Mailer ) : 'mail';
        $oauthConfigured = false;
        if ( method_exists( $phpmailer, 'getOAuth' ) ) {
            try {
                $oauthConfigured = null !== $phpmailer->getOAuth();
            } catch ( Exception $e ) {
                $oauthConfigured = false;
            }
        }

        return array(
            'mailer' => $mailer,
            'host' => isset( $phpmailer->Host ) ? (string) $phpmailer->Host : '',
            'port' => isset( $phpmailer->Port ) ? (int) $phpmailer->Port : 0,
            'smtp_auth' => ! empty( $phpmailer->SMTPAuth ),
            'smtp_secure' => isset( $phpmailer->SMTPSecure ) ? (string) $phpmailer->SMTPSecure : '',
            'smtp_auto_tls' => isset( $phpmailer->SMTPAutoTLS ) ? (bool) $phpmailer->SMTPAutoTLS : false,
            'username_configured' => isset( $phpmailer->Username ) && '' !== trim( (string) $phpmailer->Username ),
            'password_configured' => isset( $phpmailer->Password ) && '' !== (string) $phpmailer->Password,
            'oauth_configured' => $oauthConfigured,
            'timeout' => isset( $phpmailer->Timeout ) ? (int) $phpmailer->Timeout : 0,
            'sendmail' => isset( $phpmailer->Sendmail ) ? (string) $phpmailer->Sendmail : (string) ini_get( 'sendmail_path' ),
            'sender' => isset( $phpmailer->Sender ) ? (string) $phpmailer->Sender : '',
            'php_smtp' => (string) ini_get( 'SMTP' ),
            'php_smtp_port' => (int) ini_get( 'smtp_port' ),
            'php_sendmail_path' => (string) ini_get( 'sendmail_path' ),
        );
    }
}

if ( ! function_exists( 'wp_email_debug_transport_issues' ) ) {
    function wp_email_debug_transport_issues( array $transport ) {
        $issues = array();
        $mailer = isset( $transport['mailer'] ) ? strtolower( (string) $transport['mailer'] ) : 'mail';

        if ( 'smtp' === $mailer ) {
            if ( empty( $transport['host'] ) ) {
                $issues[] = wp_email_debug_validation_issue(
                    'error',
                    'smtp_host_missing',
                    'SMTP server is not configured.',
                    '',
                    'The email cannot be sent through SMTP until a server host is configured.'
                );
            }
            $port = isset( $transport['port'] ) ? (int) $transport['port'] : 0;
            if ( $port < 1 || $port > 65535 ) {
                $issues[] = wp_email_debug_validation_issue(
                    'error',
                    'smtp_port_invalid',
                    'SMTP port is invalid.',
                    'Port: ' . $port,
                    'PHPMailer cannot connect to the SMTP server with this port.'
                );
            }
            if ( ! empty( $transport['smtp_auth'] ) && empty( $transport['username_configured'] ) ) {
                $issues[] = wp_email_debug_validation_issue(
                    'error',
                    'smtp_username_missing',
                    'SMTP authentication has no username.',
                    '',
                    'The SMTP server cannot authenticate this connection until a username is configured.'
                );
            }
            if ( ! empty( $transport['smtp_auth'] ) && empty( $transport['password_configured'] ) && empty( $transport['oauth_configured'] ) ) {
                $issues[] = wp_email_debug_validation_issue(
                    'error',
                    'smtp_credentials_missing',
                    'SMTP authentication has no password or OAuth provider.',
                    '',
                    'The SMTP server cannot authenticate this connection with the current configuration.'
                );
            }
            if ( ! empty( $transport['smtp_secure'] ) && ! extension_loaded( 'openssl' ) ) {
                $issues[] = wp_email_debug_validation_issue(
                    'error',
                    'openssl_missing',
                    'SMTP encryption is configured, but OpenSSL is unavailable.',
                    'Encryption: ' . $transport['smtp_secure'],
                    'The secure SMTP connection cannot be established on this PHP installation.'
                );
            }
        } elseif ( 'sendmail' === $mailer || 'qmail' === $mailer ) {
            $binary = wp_email_debug_sendmail_binary( isset( $transport['sendmail'] ) ? $transport['sendmail'] : '' );
            if ( '' === $binary ) {
                $issues[] = wp_email_debug_validation_issue(
                    'error',
                    'sendmail_path_missing',
                    'Sendmail executable is not configured.',
                    '',
                    'PHPMailer cannot hand the email to Sendmail/Qmail.'
                );
            } elseif ( false !== strpos( $binary, DIRECTORY_SEPARATOR ) && ( ! is_file( $binary ) || ! is_executable( $binary ) ) ) {
                $issues[] = wp_email_debug_validation_issue(
                    'error',
                    'sendmail_unavailable',
                    'Configured Sendmail executable is unavailable.',
                    'File: ' . $binary,
                    'PHPMailer cannot hand the email to Sendmail/Qmail.'
                );
            }
        } elseif ( 'mail' === $mailer ) {
            $disabled = array_filter( array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ) );
            if ( ! function_exists( 'mail' ) || in_array( 'mail', $disabled, true ) ) {
                $issues[] = wp_email_debug_validation_issue(
                    'error',
                    'php_mail_unavailable',
                    'PHP mail() is unavailable or disabled.',
                    '',
                    'WordPress cannot hand the email to the PHP mail transport.'
                );
            }

            $sendmailPath = isset( $transport['php_sendmail_path'] ) ? trim( (string) $transport['php_sendmail_path'] ) : '';
            if ( '' !== $sendmailPath ) {
                $binary = wp_email_debug_sendmail_binary( $sendmailPath );
                if ( false !== strpos( $binary, DIRECTORY_SEPARATOR ) && ( ! is_file( $binary ) || ! is_executable( $binary ) ) ) {
                    $issues[] = wp_email_debug_validation_issue(
                        'error',
                        'php_sendmail_unavailable',
                        'PHP sendmail_path points to an unavailable executable.',
                        'File: ' . $binary,
                        'PHP mail() cannot pass the message to the configured local mail program.'
                    );
                }
            } elseif ( 0 === stripos( PHP_OS, 'WIN' ) ) {
                if ( empty( $transport['php_smtp'] ) ) {
                    $issues[] = wp_email_debug_validation_issue(
                        'error',
                        'php_smtp_missing',
                        'PHP mail() has no SMTP server configured.',
                        '',
                        'On Windows, PHP mail() cannot send until the SMTP setting is configured.'
                    );
                }
                $port = isset( $transport['php_smtp_port'] ) ? (int) $transport['php_smtp_port'] : 0;
                if ( $port < 1 || $port > 65535 ) {
                    $issues[] = wp_email_debug_validation_issue(
                        'error',
                        'php_smtp_port_invalid',
                        'PHP mail() has an invalid SMTP port.',
                        'Port: ' . $port,
                        'On Windows, PHP mail() cannot connect to the configured SMTP server with this port.'
                    );
                }
            }
        } else {
            $issues[] = wp_email_debug_validation_issue(
                'warning',
                'unknown_mailer',
                'PHPMailer is using an unknown mail transport.',
                'Mailer: ' . $mailer,
                'wp-email-debug cannot verify whether this transport would send successfully.'
            );
        }

        return $issues;
    }
}


if ( ! function_exists( 'wp_email_debug_smtp_error_message' ) ) {
    function wp_email_debug_smtp_error_message( $phpmailer, $fallback ) {
        $message = isset( $phpmailer->ErrorInfo ) ? trim( (string) $phpmailer->ErrorInfo ) : '';
        if ( '' !== $message ) {
            return $message;
        }

        if ( method_exists( $phpmailer, 'getSMTPInstance' ) ) {
            try {
                $smtp = $phpmailer->getSMTPInstance();
                if ( is_object( $smtp ) && method_exists( $smtp, 'getError' ) ) {
                    $error = $smtp->getError();
                    if ( is_array( $error ) ) {
                        $parts = array();
                        foreach ( array( 'error', 'detail', 'smtp_code', 'smtp_code_ex' ) as $key ) {
                            if ( isset( $error[ $key ] ) && '' !== trim( (string) $error[ $key ] ) ) {
                                $parts[] = (string) $error[ $key ];
                            }
                        }
                        if ( ! empty( $parts ) ) {
                            return implode( ' | ', $parts );
                        }
                    }
                }
            } catch ( Throwable $e ) {
                return $e->getMessage();
            }
        }

        return $fallback;
    }
}

if ( ! function_exists( 'wp_email_debug_probe_transport' ) ) {
    function wp_email_debug_probe_transport( $phpmailer, array $state ) {
        $result = array(
            'enabled' => ! empty( $state['probe_transport'] ),
            'attempted' => false,
            'status' => 'not_run',
            'stage' => '',
            'message' => '',
            'duration_ms' => 0,
            'rejected_recipients' => array(),
        );

        if ( empty( $state['probe_transport'] ) ) {
            return $result;
        }

        $mailer = isset( $phpmailer->Mailer ) ? strtolower( (string) $phpmailer->Mailer ) : 'mail';
        if ( 'smtp' !== $mailer ) {
            $result['status'] = 'not_applicable';
            $result['message'] = 'Live transport probe is available only for SMTP-configured PHPMailer transports.';
            return $result;
        }

        if ( ! method_exists( $phpmailer, 'smtpConnect' ) || ! method_exists( $phpmailer, 'getSMTPInstance' ) ) {
            $result['status'] = 'unsupported';
            $result['message'] = 'This PHPMailer version does not expose the SMTP methods required for a safe probe.';
            return $result;
        }

        $result['attempted'] = true;
        $started = microtime( true );
        $originalTimeout = isset( $phpmailer->Timeout ) ? $phpmailer->Timeout : null;
        $originalTimelimit = isset( $phpmailer->Timelimit ) ? $phpmailer->Timelimit : null;
        if ( isset( $phpmailer->Timeout ) ) {
            $phpmailer->Timeout = max( 1, min( 5, (int) $phpmailer->Timeout ) );
        }
        if ( isset( $phpmailer->Timelimit ) ) {
            $phpmailer->Timelimit = max( 1, min( 5, (int) $phpmailer->Timelimit ) );
        }

        try {
            $result['stage'] = 'connect_auth';
            if ( ! $phpmailer->smtpConnect() ) {
                $result['status'] = 'failed';
                $result['message'] = wp_email_debug_smtp_error_message( $phpmailer, 'SMTP connection/authentication failed.' );
                return $result;
            }

            $smtp = $phpmailer->getSMTPInstance();
            if ( ! is_object( $smtp ) ) {
                $result['status'] = 'failed';
                $result['message'] = 'PHPMailer did not expose an active SMTP connection after smtpConnect().';
                return $result;
            }

            $from = isset( $phpmailer->Sender ) && '' !== trim( (string) $phpmailer->Sender )
                ? (string) $phpmailer->Sender
                : ( isset( $phpmailer->From ) ? (string) $phpmailer->From : '' );

            if ( '' !== $from && method_exists( $smtp, 'mail' ) ) {
                $result['stage'] = 'mail_from';
                if ( ! $smtp->mail( $from ) ) {
                    $result['status'] = 'failed';
                    $result['message'] = wp_email_debug_smtp_error_message( $phpmailer, 'SMTP server rejected MAIL FROM: ' . $from );
                    return $result;
                }
            }

            $recipients = array();
            foreach ( array( 'getToAddresses', 'getCcAddresses', 'getBccAddresses' ) as $method ) {
                if ( ! method_exists( $phpmailer, $method ) ) {
                    continue;
                }
                foreach ( (array) $phpmailer->{$method}() as $recipient ) {
                    if ( is_array( $recipient ) && ! empty( $recipient[0] ) ) {
                        $recipients[] = (string) $recipient[0];
                    }
                }
            }

            if ( method_exists( $smtp, 'recipient' ) ) {
                $result['stage'] = 'rcpt_to';
                foreach ( array_values( array_unique( $recipients ) ) as $recipient ) {
                    if ( ! $smtp->recipient( $recipient ) ) {
                        $result['rejected_recipients'][] = $recipient;
                    }
                }
            }

            if ( method_exists( $smtp, 'reset' ) ) {
                $smtp->reset();
            }

            if ( ! empty( $result['rejected_recipients'] ) ) {
                $result['status'] = 'failed';
                $result['message'] = 'SMTP server rejected recipient(s): ' . implode( ', ', $result['rejected_recipients'] );
                return $result;
            }

            $result['status'] = 'ok';
            $result['stage'] = 'complete';
            $result['message'] = 'SMTP connect/TLS/auth/MAIL FROM/RCPT TO probe completed without sending DATA.';
            return $result;
        } catch ( Throwable $e ) {
            $result['status'] = 'failed';
            $result['message'] = $e->getMessage();
            return $result;
        } finally {
            if ( method_exists( $phpmailer, 'smtpClose' ) ) {
                try {
                    $phpmailer->smtpClose();
                } catch ( Throwable $e ) {
                    // The connection is diagnostic-only; cleanup errors are not promoted over the probe result.
                }
            }
            if ( null !== $originalTimeout && isset( $phpmailer->Timeout ) ) {
                $phpmailer->Timeout = $originalTimeout;
            }
            if ( null !== $originalTimelimit && isset( $phpmailer->Timelimit ) ) {
                $phpmailer->Timelimit = $originalTimelimit;
            }
            $result['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
        }
    }
}

if ( ! function_exists( 'wp_email_debug_format_bytes' ) ) {
    function wp_email_debug_format_bytes( $bytes ) {
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

if ( ! function_exists( 'wp_email_debug_classify_smtp_failure' ) ) {
    function wp_email_debug_classify_smtp_failure( $message ) {
        $message = trim( (string) $message );
        $lower = strtolower( $message );

        if ( false !== strpos( $lower, 'getaddrinfo' ) || false !== strpos( $lower, 'php_network_getaddresses' ) || false !== strpos( $lower, 'name or service not known' ) || false !== strpos( $lower, 'could not resolve' ) || false !== strpos( $lower, 'host not found' ) ) {
            return array( 'SMTP server hostname could not be resolved.', 'Check the SMTP hostname and DNS resolution on this server.' );
        }
        if ( false !== strpos( $lower, 'connection refused' ) ) {
            return array( 'SMTP connection was refused.', 'Check the SMTP host, port, service availability and firewall rules.' );
        }
        if ( false !== strpos( $lower, 'timed out' ) || false !== strpos( $lower, 'timeout' ) ) {
            return array( 'SMTP connection timed out.', 'The SMTP server could not be reached within the connection timeout. Check routing, firewall and host/port settings.' );
        }
        if ( false !== strpos( $lower, 'certificate' ) || false !== strpos( $lower, 'peer certificate' ) ) {
            return array( 'SMTP TLS certificate validation failed.', 'Check the SMTP certificate, hostname and local CA configuration.' );
        }
        if ( false !== strpos( $lower, 'starttls' ) || false !== strpos( $lower, 'tls' ) || false !== strpos( $lower, 'ssl' ) || false !== strpos( $lower, 'crypto' ) ) {
            return array( 'SMTP TLS negotiation failed.', 'Check the encryption mode, port and TLS support on the server.' );
        }
        if ( false !== strpos( $lower, 'authenticate' ) || false !== strpos( $lower, 'authentication' ) || false !== strpos( $lower, 'auth' ) ) {
            return array( 'SMTP authentication failed.', 'Check the SMTP username, password/OAuth configuration and authentication method.' );
        }

        return array( 'SMTP connection or authentication failed.', 'The email would not reach the SMTP delivery stage with the current server settings or credentials.' );
    }
}

if ( ! function_exists( 'wp_email_debug_probe_issues' ) ) {
    function wp_email_debug_probe_issues( array $probe ) {
        if ( empty( $probe['enabled'] ) || 'not_run' === $probe['status'] || 'not_applicable' === $probe['status'] || 'ok' === $probe['status'] ) {
            return array();
        }

        if ( 'unsupported' === $probe['status'] ) {
            return array(
                wp_email_debug_validation_issue(
                    'warning',
                    'transport_probe_unsupported',
                    'SMTP server check could not be completed.',
                    ! empty( $probe['message'] ) ? 'Details: ' . $probe['message'] : '',
                    'Server-side connection, TLS and authentication problems were not verified.'
                ),
            );
        }

        if ( 'failed' !== $probe['status'] ) {
            return array();
        }

        $stage = isset( $probe['stage'] ) ? (string) $probe['stage'] : '';
        $message = isset( $probe['message'] ) ? (string) $probe['message'] : 'Unknown SMTP error.';
        $detail = 'Details: ' . $message;

        if ( 'connect_auth' === $stage ) {
            $classification = wp_email_debug_classify_smtp_failure( $message );
            return array(
                wp_email_debug_validation_issue(
                    'error',
                    'transport_probe_failed',
                    $classification[0],
                    $detail,
                    $classification[1]
                ),
            );
        }

        if ( 'mail_from' === $stage ) {
            return array(
                wp_email_debug_validation_issue(
                    'error',
                    'transport_probe_failed',
                    'SMTP server rejected the sender address.',
                    $detail,
                    'The email would be rejected before the message body is sent.'
                ),
            );
        }

        if ( 'rcpt_to' === $stage ) {
            $rejected = ! empty( $probe['rejected_recipients'] ) && is_array( $probe['rejected_recipients'] )
                ? 'Recipients: ' . implode( ', ', array_map( 'strval', $probe['rejected_recipients'] ) )
                : $detail;
            return array(
                wp_email_debug_validation_issue(
                    'error',
                    'transport_probe_failed',
                    'SMTP server rejected one or more recipients.',
                    $rejected,
                    'The rejected recipients would not receive the email.'
                ),
            );
        }

        return array(
            wp_email_debug_validation_issue(
                'error',
                'transport_probe_failed',
                'SMTP transport check failed.',
                $detail,
                'The real email may fail before its message body is accepted by the SMTP server.'
            ),
        );
    }
}

if ( ! function_exists( 'wp_email_debug_build_payload' ) ) {
    function wp_email_debug_build_payload( $phpmailer, $state ) {
        $maxBodyBytes = 5 * 1024 * 1024;
        $body = isset( $phpmailer->Body ) ? (string) $phpmailer->Body : '';
        $altBody = isset( $phpmailer->AltBody ) ? (string) $phpmailer->AltBody : '';
        $originalBodyBytes = strlen( $body );
        $truncated = false;

        if ( strlen( $body ) > $maxBodyBytes ) {
            $body = substr( $body, 0, $maxBodyBytes );
            $truncated = true;
        }
        if ( strlen( $altBody ) > $maxBodyBytes ) {
            $altBody = substr( $altBody, 0, $maxBodyBytes );
            $truncated = true;
        }

        $fromAddress = isset( $phpmailer->From ) ? (string) $phpmailer->From : '';
        $fromName = isset( $phpmailer->FromName ) ? trim( (string) $phpmailer->FromName ) : '';
        $from = '' !== $fromName ? $fromName . ' <' . $fromAddress . '>' : $fromAddress;

        return array(
            'version' => 1,
            'status' => 'successful',
            'token' => (string) $state['token'],
            'captured_at' => microtime( true ),
            'subject' => isset( $phpmailer->Subject ) ? (string) $phpmailer->Subject : '',
            'from' => $from,
            'to' => method_exists( $phpmailer, 'getToAddresses' ) ? wp_email_debug_format_address_list( $phpmailer->getToAddresses() ) : array(),
            'cc' => method_exists( $phpmailer, 'getCcAddresses' ) ? wp_email_debug_format_address_list( $phpmailer->getCcAddresses() ) : array(),
            'bcc' => method_exists( $phpmailer, 'getBccAddresses' ) ? wp_email_debug_format_address_list( $phpmailer->getBccAddresses() ) : array(),
            'reply_to' => method_exists( $phpmailer, 'getReplyToAddresses' ) ? wp_email_debug_format_address_list( $phpmailer->getReplyToAddresses() ) : array(),
            'content_type' => isset( $phpmailer->ContentType ) ? (string) $phpmailer->ContentType : 'text/plain',
            'charset' => isset( $phpmailer->CharSet ) ? (string) $phpmailer->CharSet : '',
            'body_b64' => base64_encode( $body ),
            'alt_body_b64' => base64_encode( $altBody ),
            'body_bytes' => $originalBodyBytes,
            'truncated' => $truncated,
            'attachments' => wp_email_debug_attachment_metadata( $phpmailer ),
            'original_headers' => array(),
            'transport' => array(),
            'transport_probe' => array(),
            'issues' => array(),
            'source' => wp_email_debug_source_from_trace(),
            'request' => wp_email_debug_request_context(),
        );
    }
}

if ( ! function_exists( 'wp_email_debug_write_spool' ) ) {
    function wp_email_debug_write_spool( array $state, array $payload ) {
        if ( empty( $state['spool_directory'] ) || ! is_dir( $state['spool_directory'] ) || is_link( $state['spool_directory'] ) ) {
            return false;
        }

        $json = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
        if ( false === $json ) {
            return false;
        }

        try {
            $random = bin2hex( random_bytes( 6 ) );
        } catch ( Exception $e ) {
            $random = str_replace( '.', '', uniqid( '', true ) );
        }

        $directory = rtrim( (string) $state['spool_directory'], '/\\' );
        $name = sprintf( '%020.6f-%s-%s', microtime( true ), getmypid(), $random );
        $tmp = $directory . DIRECTORY_SEPARATOR . $name . '.tmp';
        $final = $directory . DIRECTORY_SEPARATOR . $name . '.json';

        if ( false === @file_put_contents( $tmp, $json, LOCK_EX ) ) {
            return false;
        }

        @chmod( $tmp, 0600 );
        if ( ! @rename( $tmp, $final ) ) {
            @unlink( $tmp );
            return false;
        }

        return true;
    }
}

if ( ! function_exists( 'wp_email_debug_payload_registry' ) ) {
    function wp_email_debug_payload_registry( $action, $phpmailer = null, $payload = null ) {
        static $payloads = array();

        if ( ! is_object( $phpmailer ) ) {
            return null;
        }

        $key = spl_object_hash( $phpmailer );
        if ( 'set' === $action ) {
            $payloads[ $key ] = is_array( $payload ) ? $payload : array();
            return $payloads[ $key ];
        }

        if ( 'get' === $action ) {
            return isset( $payloads[ $key ] ) ? $payloads[ $key ] : null;
        }

        if ( 'clear' === $action ) {
            unset( $payloads[ $key ] );
        }

        return null;
    }
}

if ( ! function_exists( 'wp_email_debug_normalize_mail_list' ) ) {
    function wp_email_debug_normalize_mail_list( $value ) {
        if ( is_array( $value ) ) {
            return array_values( array_filter( array_map( 'strval', $value ), 'strlen' ) );
        }

        if ( null === $value || '' === trim( (string) $value ) ) {
            return array();
        }

        return array_values( array_filter( array_map( 'trim', explode( ',', (string) $value ) ), 'strlen' ) );
    }
}

if ( ! function_exists( 'wp_email_debug_parse_failed_headers' ) ) {
    function wp_email_debug_parse_failed_headers( $headers ) {
        $lines = array();
        if ( is_string( $headers ) ) {
            $lines = preg_split( '/\r?\n/', $headers );
        } elseif ( is_array( $headers ) ) {
            foreach ( $headers as $name => $value ) {
                if ( is_string( $name ) && ! is_int( $name ) ) {
                    $lines[] = $name . ': ' . $value;
                } else {
                    $lines[] = (string) $value;
                }
            }
        }

        $result = array(
            'from' => '',
            'cc' => array(),
            'bcc' => array(),
            'reply_to' => array(),
            'content_type' => '-',
            'charset' => '',
            'raw' => array(),
        );

        foreach ( $lines as $line ) {
            $line = trim( (string) $line );
            if ( '' === $line ) {
                continue;
            }
            $result['raw'][] = $line;

            $position = strpos( $line, ':' );
            if ( false === $position ) {
                continue;
            }

            $name = strtolower( trim( substr( $line, 0, $position ) ) );
            $value = trim( substr( $line, $position + 1 ) );
            if ( 'from' === $name ) {
                $result['from'] = $value;
            } elseif ( 'cc' === $name ) {
                $result['cc'] = wp_email_debug_normalize_mail_list( $value );
            } elseif ( 'bcc' === $name ) {
                $result['bcc'] = wp_email_debug_normalize_mail_list( $value );
            } elseif ( 'reply-to' === $name ) {
                $result['reply_to'] = wp_email_debug_normalize_mail_list( $value );
            } elseif ( 'content-type' === $name ) {
                $parts = array_map( 'trim', explode( ';', $value ) );
                if ( ! empty( $parts[0] ) ) {
                    $result['content_type'] = $parts[0];
                }
                foreach ( array_slice( $parts, 1 ) as $part ) {
                    if ( 0 === stripos( $part, 'charset=' ) ) {
                        $result['charset'] = trim( substr( $part, 8 ), " \t\n\r\0\x0B\"'" );
                    }
                }
            }
        }

        return $result;
    }
}

if ( ! function_exists( 'wp_email_debug_failed_attachment_metadata' ) ) {
    function wp_email_debug_failed_attachment_metadata( $attachments ) {
        $result = array();
        foreach ( wp_email_debug_normalize_mail_list( $attachments ) as $path ) {
            $size = is_file( $path ) ? @filesize( $path ) : false;
            $type = 'application/octet-stream';
            if ( function_exists( 'wp_check_filetype' ) ) {
                $filetype = wp_check_filetype( basename( $path ) );
                if ( is_array( $filetype ) && ! empty( $filetype['type'] ) ) {
                    $type = (string) $filetype['type'];
                }
            }
            $result[] = array(
                'name' => basename( $path ),
                'type' => $type,
                'size' => false === $size ? null : (int) $size,
            );
        }
        return $result;
    }
}

if ( ! function_exists( 'wp_email_debug_preempted_payload' ) ) {
    function wp_email_debug_preempted_payload( $return, array $atts, array $state ) {
        $headers = wp_email_debug_parse_failed_headers( isset( $atts['headers'] ) ? $atts['headers'] : array() );
        $body = isset( $atts['message'] ) ? (string) $atts['message'] : '';
        $maxBodyBytes = 5 * 1024 * 1024;
        $truncated = strlen( $body ) > $maxBodyBytes;
        if ( $truncated ) {
            $body = substr( $body, 0, $maxBodyBytes );
        }

        $reportedFailure = false === $return;
        $issues = wp_email_debug_validate_mail_args( $atts );
        array_unshift(
            $issues,
            wp_email_debug_validation_issue(
                $reportedFailure ? 'error' : 'warning',
                'pre_wp_mail_short_circuit',
                $reportedFailure ? 'A pre_wp_mail callback blocked the email.' : 'A pre_wp_mail callback bypassed PHPMailer.',
                'Hook: pre_wp_mail',
                $reportedFailure
                    ? 'The callback returned false, so WordPress stopped the normal mail pipeline and reported failure.'
                    : 'The callback reported success without running the normal WordPress/PHPMailer delivery path. Alternate delivery cannot be verified by wp-email-debug.'
            )
        );

        return array(
            'version' => 1,
            'token' => (string) $state['token'],
            'status' => $reportedFailure ? 'failed' : 'has_issues',
            'captured_at' => microtime( true ),
            'subject' => isset( $atts['subject'] ) ? (string) $atts['subject'] : '',
            'from' => $headers['from'],
            'to' => wp_email_debug_normalize_mail_list( isset( $atts['to'] ) ? $atts['to'] : array() ),
            'cc' => $headers['cc'],
            'bcc' => $headers['bcc'],
            'reply_to' => $headers['reply_to'],
            'content_type' => '-' !== $headers['content_type'] ? $headers['content_type'] : 'text/plain',
            'charset' => $headers['charset'],
            'body_b64' => base64_encode( $body ),
            'alt_body_b64' => '',
            'body_bytes' => isset( $atts['message'] ) ? strlen( (string) $atts['message'] ) : 0,
            'truncated' => $truncated,
            'attachments' => wp_email_debug_failed_attachment_metadata( isset( $atts['attachments'] ) ? $atts['attachments'] : array() ),
            'original_headers' => $headers['raw'],
            'transport' => array(),
            'transport_probe' => array( 'enabled' => false, 'status' => 'not_run' ),
            'issues' => $issues,
            'error' => $reportedFailure ? array(
                'code' => 'pre_wp_mail_short_circuit',
                'message' => 'pre_wp_mail returned false and stopped wp_mail().',
            ) : array(),
            'source' => wp_email_debug_source_from_trace(),
            'request' => wp_email_debug_request_context(),
            'call_trace' => wp_email_debug_call_trace(),
        );
    }
}

if ( ! function_exists( 'wp_email_debug_failed_payload' ) ) {
    function wp_email_debug_failed_payload( $error, array $state ) {
        global $phpmailer;

        $payload = is_object( $phpmailer ) ? wp_email_debug_payload_registry( 'get', $phpmailer ) : null;
        $data = array();
        if ( is_object( $error ) && method_exists( $error, 'get_error_data' ) ) {
            $errorData = $error->get_error_data();
            if ( is_array( $errorData ) ) {
                $data = $errorData;
            }
        }

        if ( ! is_array( $payload ) ) {
            $headers = wp_email_debug_parse_failed_headers( isset( $data['headers'] ) ? $data['headers'] : array() );
            $body = isset( $data['message'] ) ? (string) $data['message'] : '';
            $maxBodyBytes = 5 * 1024 * 1024;
            $truncated = strlen( $body ) > $maxBodyBytes;
            if ( $truncated ) {
                $body = substr( $body, 0, $maxBodyBytes );
            }

            $payload = array(
                'version' => 1,
                'token' => (string) $state['token'],
                'captured_at' => microtime( true ),
                'subject' => isset( $data['subject'] ) ? (string) $data['subject'] : '',
                'from' => $headers['from'],
                'to' => wp_email_debug_normalize_mail_list( isset( $data['to'] ) ? $data['to'] : array() ),
                'cc' => $headers['cc'],
                'bcc' => $headers['bcc'],
                'reply_to' => $headers['reply_to'],
                'content_type' => $headers['content_type'],
                'charset' => $headers['charset'],
                'body_b64' => base64_encode( $body ),
                'alt_body_b64' => '',
                'body_bytes' => isset( $data['message'] ) ? strlen( (string) $data['message'] ) : 0,
                'truncated' => $truncated,
                'attachments' => wp_email_debug_failed_attachment_metadata( isset( $data['attachments'] ) ? $data['attachments'] : array() ),
                'source' => wp_email_debug_source_from_trace(),
                'request' => wp_email_debug_request_context(),
            );
        }

        $codes = array();
        $messages = array();
        if ( is_object( $error ) ) {
            if ( method_exists( $error, 'get_error_codes' ) ) {
                $codes = array_map( 'strval', (array) $error->get_error_codes() );
            } elseif ( method_exists( $error, 'get_error_code' ) ) {
                $code = $error->get_error_code();
                if ( null !== $code && '' !== (string) $code ) {
                    $codes[] = (string) $code;
                }
            }

            if ( method_exists( $error, 'get_error_messages' ) ) {
                $messages = array_map( 'strval', (array) $error->get_error_messages() );
            } elseif ( method_exists( $error, 'get_error_message' ) ) {
                $message = $error->get_error_message();
                if ( '' !== (string) $message ) {
                    $messages[] = (string) $message;
                }
            }
        }

        $primaryCode = ! empty( $codes ) ? $codes[0] : 'wp_mail_failed';
        $primaryMessage = ! empty( $messages ) ? $messages[0] : 'WordPress reported an unknown wp_mail() failure.';

        $payload['status'] = 'failed';
        $payload['captured_at'] = microtime( true );
        $payload['original_headers'] = wp_email_debug_parse_failed_headers( isset( $data['headers'] ) ? $data['headers'] : array() )['raw'];
        $payload['error'] = array(
            'code' => $primaryCode,
            'message' => $primaryMessage,
            'codes' => $codes,
            'messages' => $messages,
            'phpmailer_exception_code' => isset( $data['phpmailer_exception_code'] ) ? $data['phpmailer_exception_code'] : null,
        );

        return $payload;
    }
}

if ( ! trait_exists( 'WP_Email_Debug_Capture_SMTP_Trait', false ) ) {
    trait WP_Email_Debug_Capture_SMTP_Trait {
        private $wpEmailDebugPayload;
        private $wpEmailDebugSpoolDirectory;
        private $wpEmailDebugCaptured = false;

        public function wpEmailDebugInit( array $payload, $spoolDirectory ) {
            $this->wpEmailDebugPayload = $payload;
            $this->wpEmailDebugSpoolDirectory = rtrim( (string) $spoolDirectory, '/\\' );
            $this->wpEmailDebugCaptured = false;
        }

        public function connected() {
            return true;
        }

        public function connect( $host, $port = null, $timeout = 30, $options = array() ) {
            return true;
        }

        public function hello( $host = '' ) {
            return true;
        }

        public function authenticate( $username, $password, $authtype = null, $OAuth = null ) {
            return true;
        }

        public function startTLS() {
            return true;
        }

        public function mail( $from ) {
            return true;
        }

        public function recipient( $address, $dsn = '' ) {
            return true;
        }

        public function getServerExt( $name ) {
            return 'SMTPUTF8' === strtoupper( (string) $name );
        }

        public function xclient( array $vars ) {
            return true;
        }

        public function data( $msg_data ) {
            if ( $this->wpEmailDebugCaptured ) {
                return true;
            }

            $payload = $this->wpEmailDebugPayload;
            $payload['raw_mime_bytes'] = strlen( (string) $msg_data );
            if ( $payload['raw_mime_bytes'] > 10 * 1024 * 1024 ) {
                $issues = isset( $payload['issues'] ) && is_array( $payload['issues'] ) ? $payload['issues'] : array();
                $issues[] = wp_email_debug_validation_issue(
                    'warning',
                    'message_large',
                    'Email is unusually large.',
                    'Size: ' . wp_email_debug_format_bytes( $payload['raw_mime_bytes'] ),
                    'Large messages may be rejected by the SMTP or recipient server. The exact limit depends on the provider.'
                );
                $payload['issues'] = $issues;
                if ( 'failed' !== ( isset( $payload['status'] ) ? $payload['status'] : 'successful' ) ) {
                    $payload['status'] = 'has_issues';
                }
            }
            $written = $this->wpEmailDebugWriteSpool( $payload );
            if ( $written ) {
                $this->wpEmailDebugCaptured = true;
            }

            return $written;
        }

        public function reset() {
            return true;
        }

        public function quit( $close_on_error = true ) {
            return true;
        }

        public function close() {
        }

        public function getLastTransactionID() {
            return 'wp-email-debug';
        }

        private function wpEmailDebugWriteSpool( array $payload ) {
            return wp_email_debug_write_spool(
                array(
                    'spool_directory' => $this->wpEmailDebugSpoolDirectory,
                ),
                $payload
            );
        }
    }
}

if ( ! function_exists( 'wp_email_debug_ensure_capture_smtp' ) ) {
    function wp_email_debug_ensure_capture_smtp() {
        if ( class_exists( 'WP_Email_Debug_Capture_SMTP', false ) ) {
            return true;
        }

        if ( class_exists( '\\PHPMailer\\PHPMailer\\SMTP' ) ) {
            class WP_Email_Debug_Capture_SMTP extends \PHPMailer\PHPMailer\SMTP {
                use WP_Email_Debug_Capture_SMTP_Trait;
            }
            return true;
        }

        if ( class_exists( 'SMTP' ) ) {
            class WP_Email_Debug_Capture_SMTP extends SMTP {
                use WP_Email_Debug_Capture_SMTP_Trait;
            }
            return true;
        }

        return false;
    }
}

add_filter(
    'pre_wp_mail',
    function ( $return, $atts ) {
        $state = wp_email_debug_runtime_state();
        if ( ! is_array( $state ) || null === $return || ! is_array( $atts ) ) {
            return $return;
        }

        $payload = wp_email_debug_preempted_payload( $return, $atts, $state );
        wp_email_debug_write_spool( $state, $payload );
        return $return;
    },
    PHP_INT_MAX,
    2
);

add_filter(
    'wp_mail',
    function ( $args ) {
        $state = wp_email_debug_runtime_state();
        if ( is_array( $state ) && is_array( $args ) ) {
            wp_email_debug_mail_args_registry(
                'push',
                array(
                    'args' => $args,
                    'call_trace' => wp_email_debug_call_trace(),
                )
            );
        }
        return $args;
    },
    PHP_INT_MAX
);

add_action(
    'phpmailer_init',
    function ( $phpmailer ) {
        $state = wp_email_debug_runtime_state();
        if ( ! is_array( $state ) || ! wp_email_debug_ensure_capture_smtp() ) {
            return;
        }

        $mailEntry = wp_email_debug_mail_args_registry( 'pop' );
        $mailArgs = isset( $mailEntry['args'] ) && is_array( $mailEntry['args'] ) ? $mailEntry['args'] : array();
        $transportProbe = wp_email_debug_probe_transport( $phpmailer, $state );
        $payload = wp_email_debug_build_payload( $phpmailer, $state );
        $payload['call_trace'] = isset( $mailEntry['call_trace'] ) && is_array( $mailEntry['call_trace'] ) ? $mailEntry['call_trace'] : array();
        $payload['original_headers'] = wp_email_debug_parse_failed_headers( isset( $mailArgs['headers'] ) ? $mailArgs['headers'] : array() )['raw'];
        $payload['transport'] = wp_email_debug_transport_snapshot( $phpmailer );
        $payload['transport_probe'] = $transportProbe;
        $transportIssues = ! empty( $state['pipeline_test'] ) ? array() : wp_email_debug_transport_issues( $payload['transport'] );
        $probeIssues = ! empty( $state['pipeline_test'] ) ? array() : wp_email_debug_probe_issues( $transportProbe );
        $payload['issues'] = array_merge(
            wp_email_debug_validate_mail_args( is_array( $mailArgs ) ? $mailArgs : array() ),
            $transportIssues,
            $probeIssues
        );
        foreach ( $payload['issues'] as $issue ) {
            if ( is_array( $issue ) && isset( $issue['level'] ) && ( 'warning' === $issue['level'] || 'error' === $issue['level'] ) ) {
                $payload['status'] = 'has_issues';
                break;
            }
        }
        wp_email_debug_payload_registry( 'set', $phpmailer, $payload );
        $smtp = new WP_Email_Debug_Capture_SMTP();
        $smtp->wpEmailDebugInit( $payload, $state['spool_directory'] );

        if ( method_exists( $phpmailer, 'isSMTP' ) ) {
            $phpmailer->isSMTP();
        } else {
            $phpmailer->Mailer = 'smtp';
        }

        $phpmailer->SMTPAuth = false;
        $phpmailer->SMTPAutoTLS = false;
        $phpmailer->SMTPSecure = '';
        $phpmailer->SMTPKeepAlive = false;
        $phpmailer->Host = 'localhost';
        $phpmailer->Port = 25;
        $phpmailer->SMTPDebug = 0;

        if ( method_exists( $phpmailer, 'setSMTPInstance' ) ) {
            $phpmailer->setSMTPInstance( $smtp );
        }
    },
    PHP_INT_MAX
);


add_action(
    'wp_mail_failed',
    function ( $error ) {
        $state = wp_email_debug_runtime_state();
        if ( ! is_array( $state ) ) {
            return;
        }

        global $phpmailer;
        $pendingEntry = wp_email_debug_mail_args_registry( 'pop' );
        $pendingArgs = isset( $pendingEntry['args'] ) && is_array( $pendingEntry['args'] ) ? $pendingEntry['args'] : array();
        if ( ! empty( $pendingEntry ) && is_object( $phpmailer ) ) {
            wp_email_debug_payload_registry( 'clear', $phpmailer );
        }

        $payload = wp_email_debug_failed_payload( $error, $state );
        $payload['call_trace'] = isset( $pendingEntry['call_trace'] ) && is_array( $pendingEntry['call_trace'] ) ? $pendingEntry['call_trace'] : array();
        if ( empty( $payload['issues'] ) ) {
            $payload['issues'] = wp_email_debug_validate_mail_args( $pendingArgs );
        }
        wp_email_debug_write_spool( $state, $payload );
        if ( is_object( $phpmailer ) ) {
            wp_email_debug_payload_registry( 'clear', $phpmailer );
        }
    },
    PHP_INT_MAX
);

add_action(
    'wp_mail_succeeded',
    function () {
        global $phpmailer;
        if ( is_object( $phpmailer ) ) {
            wp_email_debug_payload_registry( 'clear', $phpmailer );
        }
    },
    PHP_INT_MAX
);

