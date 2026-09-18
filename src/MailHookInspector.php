<?php

namespace WpEmailDebug;

final class MailHookInspector {
    private $wrapped = array();
    private $events = array();

    private const HOOKS = array(
        'pre_wp_mail',
        'wp_mail',
        'wp_mail_from',
        'wp_mail_from_name',
        'wp_mail_content_type',
        'wp_mail_charset',
        'phpmailer_init',
    );

    public function install() {
        global $wp_filter;

        if ( ! is_array( $wp_filter ) ) {
            return;
        }

        foreach ( self::HOOKS as $hook ) {
            if ( empty( $wp_filter[ $hook ] ) || ! is_object( $wp_filter[ $hook ] ) || ! isset( $wp_filter[ $hook ]->callbacks ) || ! is_array( $wp_filter[ $hook ]->callbacks ) ) {
                continue;
            }

            foreach ( $wp_filter[ $hook ]->callbacks as $priority => &$callbacks ) {
                if ( ! is_array( $callbacks ) ) {
                    continue;
                }

                foreach ( $callbacks as $key => &$entry ) {
                    if ( ! is_array( $entry ) || empty( $entry['function'] ) || ! is_callable( $entry['function'] ) ) {
                        continue;
                    }

                    $original = $entry['function'];
                    $acceptedArgs = isset( $entry['accepted_args'] ) ? max( 0, (int) $entry['accepted_args'] ) : 1;
                    $descriptor = $this->describeCallback( $original );
                    $wrapper = function ( ...$args ) use ( $hook, $original, $acceptedArgs, $descriptor ) {
                        $before = $this->snapshotBefore( $hook, $args );

                        try {
                            $result = call_user_func_array( $original, array_slice( $args, 0, $acceptedArgs ) );
                        } catch ( \Throwable $e ) {
                            $this->events[] = array(
                                'type' => 'exception',
                                'hook' => $hook,
                                'callback' => $descriptor,
                                'exception_class' => get_class( $e ),
                                'exception_message' => $e->getMessage(),
                            );
                            throw $e;
                        }

                        $this->recordAfter( $hook, $before, $result, $args, $descriptor );
                        return $result;
                    };

                    $this->wrapped[] = array(
                        'hook' => $hook,
                        'priority' => $priority,
                        'key' => $key,
                        'original' => $original,
                        'wrapper' => $wrapper,
                    );
                    $entry['function'] = $wrapper;
                }
                unset( $entry );
            }
            unset( $callbacks );
        }
    }

    public function restore() {
        global $wp_filter;

        if ( ! is_array( $wp_filter ) ) {
            $this->wrapped = array();
            return;
        }

        foreach ( $this->wrapped as $item ) {
            $hook = $item['hook'];
            $priority = $item['priority'];
            $key = $item['key'];

            if ( empty( $wp_filter[ $hook ] ) || ! is_object( $wp_filter[ $hook ] ) || ! isset( $wp_filter[ $hook ]->callbacks[ $priority ][ $key ]['function'] ) ) {
                continue;
            }

            if ( $wp_filter[ $hook ]->callbacks[ $priority ][ $key ]['function'] === $item['wrapper'] ) {
                $wp_filter[ $hook ]->callbacks[ $priority ][ $key ]['function'] = $item['original'];
            }
        }

        $this->wrapped = array();
    }

    public function issues( $expectedRecipient, $includeShortCircuit = true ) {
        $issues = array();
        $lastRecipientChange = null;

        foreach ( $this->events as $event ) {
            if ( 'exception' === $event['type'] ) {
                $issues[] = array(
                    'level' => 'error',
                    'code' => 'mail_hook_exception',
                    'message' => 'A mail hook callback threw an exception.',
                    'detail' => 'Callback: ' . $this->callbackSummary( $event['callback'] ),
                    'result' => 'Hook ' . $event['hook'] . ' threw ' . $event['exception_class'] . ': ' . $event['exception_message'],
                );
                continue;
            }

            if ( 'short_circuit' === $event['type'] ) {
                if ( ! $includeShortCircuit ) {
                    continue;
                }
                $return = $event['return'];
                $reportedFailure = false === $return;
                $issues[] = array(
                    'level' => $reportedFailure ? 'error' : 'warning',
                    'code' => 'pre_wp_mail_short_circuit',
                    'message' => $reportedFailure ? 'A pre_wp_mail callback blocked the email.' : 'A pre_wp_mail callback bypassed PHPMailer.',
                    'detail' => 'Callback: ' . $this->callbackSummary( $event['callback'] ),
                    'result' => $reportedFailure
                        ? 'The callback returned false, so WordPress stopped the normal mail pipeline and reported failure.'
                        : 'The callback returned ' . $this->displayValue( $return ) . ', so the normal WordPress/PHPMailer path did not run. Alternate delivery cannot be verified by this test.',
                );
                continue;
            }

            if ( 'wp_mail_changed' === $event['type'] && in_array( 'to', $event['changes'], true ) ) {
                $lastRecipientChange = $event;
            }
        }

        if ( is_array( $lastRecipientChange ) ) {
            $after = isset( $lastRecipientChange['after']['to'] ) ? $this->normalizeRecipients( $lastRecipientChange['after']['to'] ) : array();
            $expected = $this->normalizeRecipients( $expectedRecipient );
            if ( $after !== $expected ) {
                $issues[] = array(
                    'level' => 'warning',
                    'code' => 'wp_mail_recipient_changed',
                    'message' => 'A wp_mail filter changed the test recipient.',
                    'detail' => 'Callback: ' . $this->callbackSummary( $lastRecipientChange['callback'] ),
                    'result' => 'Expected: ' . implode( ', ', $expected ) . '; After filter: ' . ( empty( $after ) ? '(none)' : implode( ', ', $after ) ) . '. This code can redirect or suppress outgoing email recipients.',
                );
            }
        }

        return $this->deduplicateIssues( $issues );
    }

    public function getEvents() {
        return $this->events;
    }

    private function snapshotBefore( $hook, array $args ) {
        if ( 'phpmailer_init' === $hook ) {
            return isset( $args[0] ) && is_object( $args[0] ) ? $this->mailerSnapshot( $args[0] ) : array();
        }

        return isset( $args[0] ) ? $args[0] : null;
    }

    private function recordAfter( $hook, $before, $result, array $args, array $descriptor ) {
        if ( 'pre_wp_mail' === $hook ) {
            if ( null === $before && null !== $result ) {
                $this->events[] = array(
                    'type' => 'short_circuit',
                    'hook' => $hook,
                    'callback' => $descriptor,
                    'return' => $result,
                );
            }
            return;
        }

        if ( 'wp_mail' === $hook && is_array( $before ) && is_array( $result ) ) {
            $changes = array();
            foreach ( array( 'to', 'subject', 'message', 'headers', 'attachments', 'embeds' ) as $field ) {
                $beforeValue = array_key_exists( $field, $before ) ? $before[ $field ] : null;
                $afterValue = array_key_exists( $field, $result ) ? $result[ $field ] : null;
                if ( $beforeValue !== $afterValue ) {
                    $changes[] = $field;
                }
            }

            if ( ! empty( $changes ) ) {
                $this->events[] = array(
                    'type' => 'wp_mail_changed',
                    'hook' => $hook,
                    'callback' => $descriptor,
                    'changes' => $changes,
                    'before' => $before,
                    'after' => $result,
                );
            }
            return;
        }

        if ( 'phpmailer_init' === $hook && isset( $args[0] ) && is_object( $args[0] ) ) {
            $after = $this->mailerSnapshot( $args[0] );
            if ( $before !== $after ) {
                $this->events[] = array(
                    'type' => 'phpmailer_changed',
                    'hook' => $hook,
                    'callback' => $descriptor,
                    'before' => $before,
                    'after' => $after,
                );
            }
            return;
        }

        if ( in_array( $hook, array( 'wp_mail_from', 'wp_mail_from_name', 'wp_mail_content_type', 'wp_mail_charset' ), true ) && $before !== $result ) {
            $this->events[] = array(
                'type' => 'filter_changed',
                'hook' => $hook,
                'callback' => $descriptor,
                'before' => $before,
                'after' => $result,
            );
        }
    }

    private function mailerSnapshot( $phpmailer ) {
        $result = array();
        foreach ( array( 'Mailer', 'Host', 'Port', 'SMTPAuth', 'SMTPSecure', 'From', 'FromName' ) as $property ) {
            if ( isset( $phpmailer->{$property} ) || property_exists( $phpmailer, $property ) ) {
                $result[ $property ] = $phpmailer->{$property};
            }
        }
        return $result;
    }

    private function describeCallback( $callback ) {
        $label = 'Unknown callback';
        $file = '';
        $line = 0;

        try {
            if ( is_array( $callback ) && 2 === count( $callback ) ) {
                $class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
                $method = (string) $callback[1];
                $label = $class . ( is_object( $callback[0] ) ? '->' : '::' ) . $method . '()';
                $reflection = new \ReflectionMethod( $callback[0], $method );
                $file = (string) $reflection->getFileName();
                $line = (int) $reflection->getStartLine();
            } elseif ( is_string( $callback ) ) {
                $label = $callback . '()';
                $reflection = new \ReflectionFunction( $callback );
                $file = (string) $reflection->getFileName();
                $line = (int) $reflection->getStartLine();
            } elseif ( $callback instanceof \Closure ) {
                $reflection = new \ReflectionFunction( $callback );
                $file = (string) $reflection->getFileName();
                $line = (int) $reflection->getStartLine();
                $label = 'Closure';
            } elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
                $label = get_class( $callback ) . '->__invoke()';
                $reflection = new \ReflectionMethod( $callback, '__invoke' );
                $file = (string) $reflection->getFileName();
                $line = (int) $reflection->getStartLine();
            }
        } catch ( \ReflectionException $e ) {
        }

        $file = $this->normalizePath( $file );
        $source = $this->classifyFile( $file );

        return array(
            'label' => $label,
            'file' => $file,
            'line' => $line,
            'source' => $source,
        );
    }

    private function callbackSummary( array $descriptor ) {
        $summary = isset( $descriptor['label'] ) ? $descriptor['label'] : 'Unknown callback';
        if ( ! empty( $descriptor['source'] ) ) {
            $summary .= ' [' . $descriptor['source'] . ']';
        }
        if ( ! empty( $descriptor['file'] ) ) {
            $summary .= ' ' . $descriptor['file'];
            if ( ! empty( $descriptor['line'] ) ) {
                $summary .= ':' . (int) $descriptor['line'];
            }
        }
        return $summary;
    }

    private function classifyFile( $file ) {
        if ( '' === $file ) {
            return 'unknown';
        }

        $pluginDir = defined( 'WP_PLUGIN_DIR' ) ? $this->withTrailingSlash( $this->normalizePath( WP_PLUGIN_DIR ) ) : '';
        $muDir = defined( 'WPMU_PLUGIN_DIR' ) ? $this->withTrailingSlash( $this->normalizePath( WPMU_PLUGIN_DIR ) ) : '';
        $themeRoot = function_exists( 'get_theme_root' ) ? $this->withTrailingSlash( $this->normalizePath( get_theme_root() ) ) : $this->withTrailingSlash( $this->normalizePath( WP_CONTENT_DIR . '/themes' ) );
        $abspath = defined( 'ABSPATH' ) ? $this->withTrailingSlash( $this->normalizePath( ABSPATH ) ) : '';

        if ( '' !== $pluginDir && 0 === strpos( $file, $pluginDir ) ) {
            $relative = substr( $file, strlen( $pluginDir ) );
            $parts = explode( '/', $relative );
            return 'plugin: ' . ( ! empty( $parts[0] ) ? $parts[0] : 'unknown' );
        }
        if ( '' !== $muDir && 0 === strpos( $file, $muDir ) ) {
            $relative = substr( $file, strlen( $muDir ) );
            $parts = explode( '/', $relative );
            return 'mu-plugin: ' . ( ! empty( $parts[0] ) ? $parts[0] : 'unknown' );
        }
        if ( '' !== $themeRoot && 0 === strpos( $file, $themeRoot ) ) {
            $relative = substr( $file, strlen( $themeRoot ) );
            $parts = explode( '/', $relative );
            return 'theme: ' . ( ! empty( $parts[0] ) ? $parts[0] : 'unknown' );
        }
        if ( '' !== $abspath && 0 === strpos( $file, $abspath ) ) {
            return 'WordPress Core';
        }

        return 'other';
    }

    private function withTrailingSlash( $path ) {
        return rtrim( (string) $path, '/' ) . '/';
    }

    private function normalizePath( $path ) {
        $path = (string) $path;
        if ( function_exists( 'wp_normalize_path' ) ) {
            return wp_normalize_path( $path );
        }
        return str_replace( '\\', '/', $path );
    }

    private function normalizeRecipients( $value ) {
        if ( is_array( $value ) ) {
            $items = $value;
        } else {
            $items = explode( ',', (string) $value );
        }

        $items = array_values( array_filter( array_map( 'trim', array_map( 'strval', $items ) ), 'strlen' ) );
        sort( $items, SORT_STRING );
        return $items;
    }

    private function displayValue( $value ) {
        if ( true === $value ) {
            return 'true';
        }
        if ( false === $value ) {
            return 'false';
        }
        if ( null === $value ) {
            return 'null';
        }
        if ( is_scalar( $value ) ) {
            return var_export( $value, true );
        }
        return gettype( $value );
    }

    private function deduplicateIssues( array $issues ) {
        $result = array();
        $seen = array();

        foreach ( $issues as $issue ) {
            $key = isset( $issue['code'] ) ? (string) $issue['code'] . '|' . ( isset( $issue['detail'] ) ? (string) $issue['detail'] : '' ) : serialize( $issue );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $result[] = $issue;
        }

        return $result;
    }
}
