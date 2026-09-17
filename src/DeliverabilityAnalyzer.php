<?php

namespace WpEmailDebug;

final class DeliverabilityAnalyzer {
    private $resolver;
    private $cache = array();

    public function __construct( $resolver = null ) {
        $this->resolver = is_callable( $resolver ) ? $resolver : array( $this, 'resolveTxt' );
    }

    public function analyze( array $email ) {
        $checks = array();
        $from = isset( $email['from'] ) ? (string) $email['from'] : '';
        $domain = $this->extractDomain( $from );

        if ( '' === $domain ) {
            $checks[] = $this->check( 'error', 'from', 'From address is missing or invalid.' );
        } else {
            $checks[] = $this->check( 'ok', 'from', 'From domain: ' . $domain );
        }

        if ( empty( $email['to'] ) ) {
            $checks[] = $this->check( 'error', 'recipients', 'No valid recipient was captured.' );
        } else {
            $checks[] = $this->check( 'ok', 'recipients', 'At least one recipient is present.' );
        }

        $subject = isset( $email['subject'] ) ? trim( (string) $email['subject'] ) : '';
        $checks[] = '' === $subject
            ? $this->check( 'warning', 'subject', 'Subject is empty.' )
            : $this->check( 'ok', 'subject', 'Subject is present.' );

        $body = $this->decodeBody( isset( $email['body_b64'] ) ? $email['body_b64'] : '' );
        $altBody = $this->decodeBody( isset( $email['alt_body_b64'] ) ? $email['alt_body_b64'] : '' );
        if ( '' === trim( $body ) ) {
            $checks[] = $this->check( 'warning', 'body', 'Message body is empty.' );
        } else {
            $checks[] = $this->check( 'ok', 'body', 'Message body is present.' );
        }

        $contentType = isset( $email['content_type'] ) ? strtolower( (string) $email['content_type'] ) : '';
        if ( false !== strpos( $contentType, 'text/html' ) ) {
            $checks[] = '' === trim( $altBody )
                ? $this->check( 'warning', 'alt_body', 'HTML email has no plain-text alternative body.' )
                : $this->check( 'ok', 'alt_body', 'HTML email includes a plain-text alternative body.' );
        }

        $headers = isset( $email['original_headers'] ) && is_array( $email['original_headers'] ) ? $email['original_headers'] : array();
        $hasListUnsubscribe = $this->hasHeader( $headers, 'list-unsubscribe' );
        $hasListUnsubscribePost = $this->hasHeader( $headers, 'list-unsubscribe-post' );
        if ( $hasListUnsubscribe ) {
            $checks[] = $this->check( 'ok', 'list_unsubscribe', $hasListUnsubscribePost ? 'List-Unsubscribe and one-click unsubscribe headers are present.' : 'List-Unsubscribe header is present.' );
        } else {
            $checks[] = $this->check( 'unknown', 'list_unsubscribe', 'No List-Unsubscribe header detected. This matters for promotional/bulk email, not all transactional email.' );
        }

        if ( '' !== $domain && ! $this->isLocalDomain( $domain ) ) {
            $transport = isset( $email['transport'] ) && is_array( $email['transport'] ) ? $email['transport'] : array();
            $senderDomain = $this->extractDomain( isset( $transport['sender'] ) ? (string) $transport['sender'] : '' );
            $spfDomain = '' !== $senderDomain ? $senderDomain : $domain;
            $spfRecords = $this->txtRecords( $spfDomain );
            if ( null === $spfRecords ) {
                $checks[] = $this->check( 'unknown', 'spf', 'SPF DNS lookup could not be completed for ' . $spfDomain . '.' );
            } else {
                $hasSpf = $this->containsPrefix( $spfRecords, 'v=spf1' );
                $checks[] = $hasSpf
                    ? $this->check( 'ok', 'spf', 'SPF record found for ' . $spfDomain . '. Actual SPF pass still depends on the sending IP.' )
                    : $this->check( 'warning', 'spf', 'No SPF record found for ' . $spfDomain . '.' );
            }

            $dmarcRecords = $this->txtRecords( '_dmarc.' . $domain );
            if ( null === $dmarcRecords ) {
                $checks[] = $this->check( 'unknown', 'dmarc', 'DMARC DNS lookup could not be completed for ' . $domain . '.' );
            } else {
                $hasDmarc = $this->containsPrefix( $dmarcRecords, 'v=dmarc1' );
                $checks[] = $hasDmarc
                    ? $this->check( 'ok', 'dmarc', 'DMARC record found for ' . $domain . '.' )
                    : $this->check( 'warning', 'dmarc', 'No DMARC record found directly for ' . $domain . '. Parent-domain policy is not inferred without a public-suffix database.' );
            }


            $selector = isset( $transport['dkim_selector'] ) ? trim( (string) $transport['dkim_selector'] ) : '';
            $dkimDomain = isset( $transport['dkim_domain'] ) ? trim( (string) $transport['dkim_domain'] ) : '';
            if ( '' !== $selector && '' !== $dkimDomain ) {
                $dkimRecords = $this->txtRecords( $selector . '._domainkey.' . $dkimDomain );
                if ( null === $dkimRecords ) {
                    $checks[] = $this->check( 'unknown', 'dkim', 'DKIM DNS lookup could not be completed for the configured selector.' );
                } else {
                    $hasDkim = $this->containsPrefix( $dkimRecords, 'v=dkim1' ) || $this->containsFragment( $dkimRecords, 'p=' );
                    $checks[] = $hasDkim
                        ? $this->check( 'ok', 'dkim', 'DKIM record found for selector ' . $selector . ' on ' . $dkimDomain . '.' )
                        : $this->check( 'warning', 'dkim', 'Configured DKIM selector was not found in DNS.' );
                }
            } else {
                $checks[] = $this->check( 'unknown', 'dkim', 'DKIM cannot be verified locally because no selector/domain is exposed by PHPMailer. SMTP providers may sign after handoff.' );
            }
        } elseif ( '' !== $domain ) {
            $checks[] = $this->check( 'unknown', 'dns_auth', 'SPF/DKIM/DMARC checks skipped for local/test domain.' );
        }

        $checks[] = $this->check( 'unknown', 'reputation', 'Inbox placement also depends on sender/IP reputation, complaint rate, sending history, recipient engagement, and provider-specific filtering.' );

        return array(
            'domain' => $domain,
            'checks' => $checks,
        );
    }

    public function resolveTxt( $name ) {
        if ( ! function_exists( 'dns_get_record' ) || ! defined( 'DNS_TXT' ) ) {
            return null;
        }

        $records = @dns_get_record( $name, DNS_TXT );
        if ( false === $records || ! is_array( $records ) ) {
            return null;
        }

        $values = array();
        foreach ( $records as $record ) {
            if ( isset( $record['txt'] ) ) {
                $values[] = (string) $record['txt'];
            } elseif ( isset( $record['entries'] ) && is_array( $record['entries'] ) ) {
                $values[] = implode( '', array_map( 'strval', $record['entries'] ) );
            }
        }
        return $values;
    }

    private function txtRecords( $name ) {
        $key = strtolower( $name );
        if ( array_key_exists( $key, $this->cache ) ) {
            return $this->cache[ $key ];
        }
        $records = call_user_func( $this->resolver, $name );
        if ( null === $records || false === $records ) {
            $this->cache[ $key ] = null;
            return null;
        }
        $this->cache[ $key ] = is_array( $records ) ? array_values( array_map( 'strval', $records ) ) : array();
        return $this->cache[ $key ];
    }

    private function extractDomain( $from ) {
        $address = trim( (string) $from );
        if ( preg_match( '/<([^>]+)>/', $address, $matches ) ) {
            $address = trim( $matches[1] );
        }
        if ( false === filter_var( $address, FILTER_VALIDATE_EMAIL ) ) {
            return '';
        }
        $position = strrpos( $address, '@' );
        return false === $position ? '' : strtolower( substr( $address, $position + 1 ) );
    }

    private function isLocalDomain( $domain ) {
        return 'localhost' === $domain
            || 1 === preg_match( '/\.(?:test|local|localhost|invalid)$/i', $domain );
    }

    private function containsPrefix( array $records, $prefix ) {
        foreach ( $records as $record ) {
            if ( 0 === stripos( trim( $record ), $prefix ) ) {
                return true;
            }
        }
        return false;
    }

    private function containsFragment( array $records, $fragment ) {
        foreach ( $records as $record ) {
            if ( false !== stripos( $record, $fragment ) ) {
                return true;
            }
        }
        return false;
    }

    private function hasHeader( array $headers, $name ) {
        $needle = strtolower( $name ) . ':';
        foreach ( $headers as $header ) {
            if ( 0 === strpos( strtolower( ltrim( (string) $header ) ), $needle ) ) {
                return true;
            }
        }
        return false;
    }

    private function decodeBody( $encoded ) {
        if ( '' === (string) $encoded ) {
            return '';
        }
        $decoded = base64_decode( (string) $encoded, true );
        return false === $decoded ? '' : $decoded;
    }

    private function check( $level, $code, $message ) {
        return array(
            'level' => $level,
            'code' => $code,
            'message' => $message,
        );
    }
}
