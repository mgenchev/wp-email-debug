<?php

$root = dirname( __DIR__ );

final class WP_CLI {
    public static $lines = array();
    public static $warnings = array();
    public static $commands = array();

    public static function line( $message = '' ) {
        self::$lines[] = $message;
    }

    public static function warning( $message ) {
        self::$warnings[] = $message;
    }

    public static function colorize( $message ) {
        return preg_replace( '/%[A-Za-z0-9_]/', '', $message );
    }

    public static function error( $message ) {
        throw new RuntimeException( $message );
    }

    public static function add_command( $name, $callable ) {
        self::$commands[ $name ] = $callable;
    }
}

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/srv/www/' );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
    define( 'WP_CONTENT_DIR', '/srv/www/wp-content' );
}
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
    define( 'WP_PLUGIN_DIR', '/srv/www/wp-content/plugins' );
}
if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
    define( 'WPMU_PLUGIN_DIR', '/srv/www/wp-content/mu-plugins' );
}

function wp_date( $format, $timestamp = null ) {
    return gmdate( $format, null === $timestamp ? time() : (int) $timestamp );
}

function remove_accents( $value ) {
    return $value;
}

function home_url( $path = '' ) {
    return 'https://example.test' . $path;
}

function wp_normalize_path( $path ) {
    return str_replace( '\\', '/', $path );
}

function wp_get_environment_type() {
    return 'local';
}

function has_filter( $hook ) {
    return false;
}

foreach ( glob( $root . '/src/*.php' ) as $file ) {
    if ( 'bootstrap.php' === basename( $file ) ) {
        continue;
    }
    require_once $file;
}

use WpEmailDebug\BridgeInstaller;
use WpEmailDebug\Console;
use WpEmailDebug\EmailLogWriter;
use WpEmailDebug\SessionManager;
use WpEmailDebug\SpoolReader;

$tests = 0;
$failures = 0;

function assert_true( $condition, $message ) {
    global $tests, $failures;
    $tests++;
    if ( ! $condition ) {
        $failures++;
        fwrite( STDERR, "FAIL: {$message}\n" );
    }
}

function assert_same( $expected, $actual, $message ) {
    assert_true( $expected === $actual, $message . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' );
}

function temp_dir( $prefix ) {
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . '-' . uniqid( '', true );
    mkdir( $path, 0700, true );
    return $path;
}

function remove_tree( $path ) {
    if ( ! is_dir( $path ) ) {
        if ( is_file( $path ) || is_link( $path ) ) {
            @unlink( $path );
        }
        return;
    }

    $items = scandir( $path );
    foreach ( $items as $item ) {
        if ( '.' === $item || '..' === $item ) {
            continue;
        }
        $target = $path . DIRECTORY_SEPARATOR . $item;
        if ( is_dir( $target ) && ! is_link( $target ) ) {
            remove_tree( $target );
        } else {
            @unlink( $target );
        }
    }
    @rmdir( $path );
}

require $root . '/src/bootstrap.php';
assert_same( 'WpEmailDebug\\Command', WP_CLI::$commands['email-debug'], 'Bootstrap registers wp email-debug directly.' );
assert_same( '0 B', Console::formatBytes( 0 ), 'Byte formatting handles zero.' );
assert_same( '1.0 KB', Console::formatBytes( 1024 ), 'Byte formatting handles kilobytes.' );
assert_same( '1.0 MB', Console::formatBytes( 1024 * 1024 ), 'Byte formatting handles megabytes.' );


WP_CLI::$lines = array();
$console = new Console( false );
$console->emailCaptured( 1, array(
    'subject' => 'WP Mail Test',
    'display_time' => '08:47:04',
    'to' => array( 'example@example.com' ),
    'from' => 'WordPress <wordpress@example.test>',
    'content_type' => 'text/html',
    'body_bytes' => 825,
    'attachments' => array(),
), '/tmp/email-debug/2026-09-17_08-47-04_wp-mail-test.log' );
$consoleOutput = implode( "\n", WP_CLI::$lines );
assert_true( false !== strpos( $consoleOutput, '#1  Status : Successful' ), 'Successful email console output keeps number and main status on the first line.' );
assert_true( false !== strpos( $consoleOutput, '    Time   : [08:47:04]' ), 'Captured email console output shows time on its own aligned row.' );
assert_true( false !== strpos( $consoleOutput, '    Subject: WP Mail Test' ), 'Captured email console output clearly labels the subject on its own row.' );
assert_true( false !== strpos( $consoleOutput, '    To     : example@example.com' ), 'Captured email console output includes aligned recipient metadata.' );
assert_true( false !== strpos( $consoleOutput, '    From   : WordPress <wordpress@example.test>' ), 'Captured email console output includes aligned sender metadata.' );
assert_true( false === strpos( $consoleOutput, 'HTML ·' ), 'Captured email console output omits the content type summary row.' );
assert_true( false === strpos( $consoleOutput, 'attachments' ), 'Captured email console output omits the attachment summary row.' );
WP_CLI::$lines = array();
$console->emailCaptured( 2, array(
    'status' => 'has_issues',
    'subject' => 'Attachment Warning',
    'display_time' => '08:48:00',
    'to' => array( 'example@example.com' ),
    'from' => 'WordPress <wordpress@example.test>',
    'issues' => array(
        array( 'level' => 'warning', 'code' => 'attachment_missing', 'message' => 'Attachment cannot be found.', 'detail' => 'File: /missing.pdf', 'result' => 'WordPress will send the email without this attachment.' ),
    ),
), '/tmp/email-debug/2026-09-17_08-48-00_attachment-warning.log' );
$issueConsoleOutput = implode( "\n", WP_CLI::$lines );
assert_true( false !== strpos( $issueConsoleOutput, '#2  Status : Has Issues' ), 'Issue email console output uses the Has Issues main status.' );
assert_true( false !== strpos( $issueConsoleOutput, '    Time   : [08:48:00]' ), 'Issue email console output shows time on its own aligned row.' );
assert_true( false !== strpos( $issueConsoleOutput, '    Issue  : Attachment cannot be found.' ), 'Issue email console output surfaces the first sending issue.' );

WP_CLI::$lines = array();
$console->emailCaptured( 3, array(
    'status' => 'failed',
    'subject' => 'Failed Mail Test',
    'display_time' => '08:48:05',
    'to' => array( 'example@example.com' ),
    'from' => 'WordPress <wordpress@example.test>',
    'error' => array(
        'code' => 'wp_mail_failed',
        'message' => 'Could not instantiate mail function.',
    ),
), '/tmp/email-debug/2026-09-17_08-48-05_FAILED_failed-mail-test.log' );
$failedConsoleOutput = implode( "\n", WP_CLI::$lines );
assert_true( false !== strpos( $failedConsoleOutput, '#3  Status : Failed' ), 'Failed email console output uses the Failed main status.' );
assert_true( false !== strpos( $failedConsoleOutput, '    Time   : [08:48:05]' ), 'Failed email console output shows time on its own aligned row.' );
assert_true( false !== strpos( $failedConsoleOutput, '    Error  : Could not instantiate mail function.' ), 'Failed email console output includes the failure reason.' );
WP_CLI::$lines = array();
$console->summary( 5, 2, 1, '/tmp/email-debug' );
$summaryOutput = implode( "\n", WP_CLI::$lines );
assert_true( false !== strpos( $summaryOutput, 'Total:         5' ), 'Session summary reports the total attempts.' );
assert_true( false !== strpos( $summaryOutput, 'Successful:    2' ), 'Session summary reports successful messages.' );
assert_true( false !== strpos( $summaryOutput, 'Has Issues:    1' ), 'Session summary reports messages with issues.' );
assert_true( false !== strpos( $summaryOutput, 'Failed:        2' ), 'Session summary reports failed wp_mail attempts.' );
WP_CLI::$lines = array();

// Session lifecycle and active-session protection.
$sessionRoot = temp_dir( 'wp-email-debug-session' );
$stateDir = $sessionRoot . '/state';
$session = new SessionManager( $stateDir, 'site-a' );
$token = $session->start();
assert_true( 1 === preg_match( '/^[a-f0-9]{64}$/', $token ), 'Session token is 32 cryptographic bytes encoded as hex.' );
assert_true( is_file( $session->getSessionFile() ), 'Session state is written.' );
assert_true( is_dir( $session->getSpoolDirectory() ), 'Session spool directory is created.' );
$sessionState = json_decode( file_get_contents( $session->getSessionFile() ), true );
assert_true( empty( $sessionState['probe_transport'] ), 'Transport probing is disabled by default.' );

file_put_contents( $session->getSpoolDirectory() . '/keep.json', '{}' );
$second = new SessionManager( $stateDir, 'site-a' );
$thrown = false;
try {
    $second->start();
} catch ( RuntimeException $e ) {
    $thrown = true;
}
assert_true( $thrown, 'Second active session is rejected.' );
$second->stop();
assert_true( is_file( $session->getSessionFile() ), 'Rejected second session cannot delete the active session state.' );
assert_true( is_file( $session->getSpoolDirectory() . '/keep.json' ), 'Rejected second session cannot delete active-session spool files.' );
$session->stop();
assert_true( ! file_exists( $session->getSessionFile() ), 'Owned session state is removed on stop.' );
remove_tree( $sessionRoot );

$probeSessionRoot = temp_dir( 'wp-email-debug-probe-session' );
$probeSession = new SessionManager( $probeSessionRoot . '/state', 'site-a', array( 'probe_transport' => true ) );
$probeSession->start();
$probeState = json_decode( file_get_contents( $probeSession->getSessionFile() ), true );
assert_true( ! empty( $probeState['probe_transport'] ), 'Transport probing is persisted only when explicitly enabled.' );
$probeSession->stop();
remove_tree( $probeSessionRoot );

$pipelineSessionRoot = temp_dir( 'wp-email-debug-pipeline-session' );
$pipelineSession = new SessionManager( $pipelineSessionRoot . '/state', 'site-a', array( 'pipeline_test' => true ) );
$pipelineSession->start();
$pipelineState = json_decode( file_get_contents( $pipelineSession->getSessionFile() ), true );
assert_true( ! empty( $pipelineState['pipeline_test'] ), 'Controlled test mode is persisted separately from transport probing.' );
assert_true( empty( $pipelineState['probe_transport'] ), 'Controlled test mode does not enable the live transport probe.' );
$pipelineSession->stop();
remove_tree( $pipelineSessionRoot );

// Stale session is safely replaced and stale spool is cleared.
$staleRoot = temp_dir( 'wp-email-debug-stale' );
$staleDir = $staleRoot . '/state';
mkdir( $staleDir . '/spool', 0700, true );
file_put_contents( $staleDir . '/session.json', json_encode( array(
    'token' => 'old',
    'site_hash' => 'site-a',
    'heartbeat' => time() - 30,
    'spool_directory' => $staleDir . '/spool',
) ) );
file_put_contents( $staleDir . '/spool/old.json', '{}' );
$staleSession = new SessionManager( $staleDir, 'site-a' );
$staleSession->start();
assert_true( ! file_exists( $staleDir . '/spool/old.json' ), 'Stale spool entries are removed before a new session starts.' );
$staleSession->stop();
remove_tree( $staleRoot );

// Bridge refuses unrelated MU files and removes only its own generated runtime.
$bridgeRoot = temp_dir( 'wp-email-debug-bridge' );
$bridgePath = $bridgeRoot . '/wp-email-debug-runtime.php';
file_put_contents( $bridgePath, '<?php // unrelated' );
$bridge = new BridgeInstaller( $bridgePath, $root . '/runtime/bridge-template.php', 'abc123', '/tmp/session.json', 'site-hash' );
$thrown = false;
try {
    $bridge->install();
} catch ( RuntimeException $e ) {
    $thrown = true;
}
assert_true( $thrown, 'Bridge refuses to overwrite a non-email-debug MU plugin.' );
unlink( $bridgePath );
$bridge->install();
$rendered = file_get_contents( $bridgePath );
assert_true( false !== strpos( $rendered, 'WP_EMAIL_DEBUG_RUNTIME_V1' ), 'Generated bridge contains the runtime marker.' );
assert_true( false !== strpos( $rendered, 'abc123' ), 'Generated bridge embeds the session token.' );
assert_true( false === strpos( $rendered, '__WP_EMAIL_DEBUG_TOKEN__' ), 'Generated bridge replaces token placeholders.' );
$bridge->uninstall();
assert_true( ! file_exists( $bridgePath ), 'Generated bridge is removed on uninstall.' );
file_put_contents( $bridgePath, '<?php /* WP_EMAIL_DEBUG_RUNTIME_V1 */' );
assert_true( BridgeInstaller::removeManagedBridge( $bridgePath ), 'BridgeInstaller can remove a stale managed runtime before a clean WP-CLI relaunch.' );
assert_true( ! file_exists( $bridgePath ), 'Stale managed runtime file is removed.' );
remove_tree( $bridgeRoot );

// Spool reader accepts only the active token and consumes files.
$spoolRoot = temp_dir( 'wp-email-debug-spool' );
file_put_contents( $spoolRoot . '/001.json', json_encode( array( 'token' => 'good', 'subject' => 'One' ) ) );
file_put_contents( $spoolRoot . '/002.json', json_encode( array( 'token' => 'bad', 'subject' => 'Two' ) ) );
$reader = new SpoolReader( $spoolRoot, 'good' );
$pending = $reader->readPending();
assert_same( 1, count( $pending ), 'Spool reader returns only active-session messages.' );
assert_same( 'One', $pending[0]['subject'], 'Spool reader preserves payload data.' );
assert_same( 0, count( glob( $spoolRoot . '/*.json' ) ), 'Spool entries are consumed after reading.' );
remove_tree( $spoolRoot );

// One timestamped log per message, with deterministic collision suffixes.
$logRoot = temp_dir( 'wp-email-debug-logs' );
$writer = new EmailLogWriter( $logRoot );
$email = array(
    'captured_at' => 1760000000.25,
    'subject' => 'New Order #1842',
    'from' => 'Store <store@example.test>',
    'to' => array( 'admin@example.test' ),
    'cc' => array(),
    'bcc' => array(),
    'reply_to' => array( 'customer@example.test' ),
    'content_type' => 'text/html',
    'charset' => 'UTF-8',
    'body_b64' => base64_encode( '<p>Hello</p>' ),
    'alt_body_b64' => base64_encode( 'Hello' ),
    'body_bytes' => 12,
    'attachments' => array(
        array( 'name' => 'invoice.pdf', 'type' => 'application/pdf', 'size' => 2048 ),
    ),
    'source' => array( 'label' => 'woocommerce', 'file' => '/srv/www/wp-content/plugins/woocommerce/mail.php', 'line' => 55 ),
    'request' => array( 'method' => 'GET', 'path' => '/checkout/', 'type' => 'frontend' ),
);
$firstLog = $writer->write( $email );
$secondLog = $writer->write( $email );
assert_true( false !== strpos( basename( $firstLog ), '_SUCCESSFUL_new-order-1842.log' ), 'Log filename contains the main status and sanitized subject.' );
assert_true( false !== strpos( basename( $secondLog ), '_SUCCESSFUL_new-order-1842-2.log' ), 'Same-second status/subject collision receives a numeric suffix.' );
$logContents = file_get_contents( $firstLog );
assert_true( false !== strpos( $logContents, 'Subject:      New Order #1842' ), 'Log includes subject metadata.' );
assert_true( false !== strpos( $logContents, '<p>Hello</p>' ), 'Log includes full captured message body.' );
assert_true( false !== strpos( $logContents, 'EMAIL CONTENT' . PHP_EOL . str_repeat( '-', 68 ) ), 'Main email content block uses the EMAIL CONTENT section heading.' );
assert_true( false !== strpos( $logContents, str_repeat( '-', 68 ) . PHP_EOL . PHP_EOL . PHP_EOL . '<p>Hello</p>' . PHP_EOL . PHP_EOL . PHP_EOL ), 'Main content body has additional whitespace before and after it.' );
assert_true( false === strpos( $logContents, 'END CONTENT' ), 'Main content block does not add a redundant end marker.' );
assert_true( false !== strpos( $logContents, 'ALTERNATIVE CONTENT' ), 'Log includes alternative content when present.' );
$noAltEmail = $email;
$noAltEmail['subject'] = 'No alternative body';
$noAltEmail['alt_body_b64'] = '';
$noAltLog = $writer->write( $noAltEmail );
$noAltContents = file_get_contents( $noAltLog );
assert_true( false === strpos( $noAltContents, 'ALTERNATIVE CONTENT' ), 'Log omits the alternative-content section when no alternative body is present.' );
assert_true( false !== strpos( $logContents, '[1]  invoice.pdf    application/pdf    2.0 KB' ), 'Log includes attachment metadata without binary duplication.' );
assert_true( false !== strpos( $logContents, 'Source:       woocommerce' ), 'Log includes source classification.' );
assert_true( false !== strpos( $logContents, 'Request:      GET /checkout/' ), 'Log includes the originating request path.' );
assert_true( false !== strpos( $logContents, 'Request type: frontend' ), 'Log includes the originating request type.' );
assert_true( false === strpos( $logContents, 'Request ID:' ), 'Human-readable logs omit the internal request identifier.' );
$failedEmail = $email;
$failedEmail['captured_at'] = 1760000001.25;
$failedEmail['status'] = 'failed';
$failedEmail['subject'] = 'Broken SMTP';
$failedEmail['error'] = array(
    'code' => 'wp_mail_failed',
    'message' => 'SMTP connect() failed.',
    'messages' => array( 'SMTP connect() failed.' ),
    'phpmailer_exception_code' => 110,
);
$failedLog = $writer->write( $failedEmail );
$failedLogContents = file_get_contents( $failedLog );
assert_true( false !== strpos( basename( $failedLog ), '_FAILED_broken-smtp.log' ), 'Failed mail log filename contains only the main status and subject.' );
assert_true( false !== strpos( $failedLogContents, 'Status:       FAILED' ), 'Failed mail log records FAILED status.' );
assert_true( false !== strpos( $failedLogContents, 'ERROR DETAILS' . PHP_EOL . str_repeat( '-', 68 ) ), 'Failed mail log includes a dedicated error-details section.' );
assert_true( false !== strpos( $failedLogContents, 'Issue  :    WordPress/PHPMailer failed to send this email.' ), 'Failed mail is surfaced first as a sending issue.' );
assert_true( false !== strpos( $failedLogContents, 'Error  :    SMTP connect() failed.' ), 'Failed-mail issue includes the reported failure details.' );
assert_true( false !== strpos( $failedLogContents, 'Details:    The email was not sent.' ), 'Failed-mail issue clearly states the practical result.' );
assert_true( false !== strpos( $failedLogContents, 'Code:         wp_mail_failed' ), 'Failed mail log includes the WordPress error code.' );
assert_true( false !== strpos( $failedLogContents, 'Message:      SMTP connect() failed.' ), 'Failed mail log includes the failure reason.' );
assert_true( false !== strpos( $failedLogContents, 'PHPMailer:    110' ), 'Failed mail log includes the PHPMailer exception code.' );
assert_true( false !== strpos( $failedLogContents, '<p>Hello</p>' ), 'Failed mail log preserves available message content.' );
remove_tree( $logRoot );

// Multi-line content is preserved without decoration.
$multilineRoot = temp_dir( 'wp-email-debug-multiline' );
$multilineWriter = new EmailLogWriter( $multilineRoot );
$multilineEmail = $email;
$multilineEmail['subject'] = 'Multiline body';
$multilineEmail['body_b64'] = base64_encode( "Line one\n\nLine three" );
$multilineLog = $multilineWriter->write( $multilineEmail );
$multilineContents = file_get_contents( $multilineLog );
assert_true( false !== strpos( $multilineContents, "Line one\n\nLine three" ), 'Content body preserves original multi-line formatting without prefixes.' );
remove_tree( $multilineRoot );

// Unicode subjects remain useful in filenames.
$unicodeRoot = temp_dir( 'wp-email-debug-unicode' );
$unicodeWriter = new EmailLogWriter( $unicodeRoot );
$unicodeEmail = $email;
$unicodeEmail['subject'] = 'Нова поръчка #1842';
$unicodeLog = $unicodeWriter->write( $unicodeEmail );
assert_true( false !== strpos( basename( $unicodeLog ), '_SUCCESSFUL_Нова-поръчка-1842.log' ), 'Unicode subject text is preserved safely in status-prefixed log filenames.' );
remove_tree( $unicodeRoot );


// Issue log formatting focuses on sending problems and their effect.
$issueLogRoot = temp_dir( 'wp-email-debug-issue-log' );
$issueLogWriter = new EmailLogWriter( $issueLogRoot );
$issueLogEmail = $email;
$issueLogEmail['issues'] = array(
    array(
        'level' => 'warning',
        'code' => 'attachment_missing',
        'message' => 'Attachment cannot be found.',
        'detail' => 'File: /missing.pdf',
        'result' => 'WordPress will send the email without this attachment.',
    ),
);
$issueLogEmail['status'] = 'has_issues';
$issueLog = $issueLogWriter->write( $issueLogEmail );
$issueLogContents = file_get_contents( $issueLog );
assert_true( false !== strpos( basename( $issueLog ), '_HAS-ISSUES_new-order-1842.log' ), 'Issue log filename contains only the main status and subject.' );
assert_true( false !== strpos( $issueLogContents, 'Status:       HAS ISSUES' ), 'Issue logs use the same HAS ISSUES main status as the console and filename.' );
assert_true( false !== strpos( $issueLogContents, 'ISSUES' . PHP_EOL . str_repeat( '-', 68 ) ), 'Log includes a focused ISSUES section.' );
assert_true( false !== strpos( $issueLogContents, 'Issue  :    Attachment cannot be found.' ), 'Issue log uses an aligned problem statement.' );
assert_true( false !== strpos( $issueLogContents, 'File   :    /missing.pdf' ), 'Issue log includes aligned useful problem context.' );
assert_true( false !== strpos( $issueLogContents, 'Details:    WordPress will send the email without this attachment.' ), 'Issue log explains the practical effect of the problem.' );
assert_true( false === strpos( $issueLogContents, 'DELIVERABILITY' ), 'Standard logs do not include deliverability or spam-analysis noise.' );
remove_tree( $issueLogRoot );

// Issue details use readable aligned labels, including malformed-header context.
$headerIssueRoot = temp_dir( 'wp-email-debug-header-issue' );
$headerIssueWriter = new EmailLogWriter( $headerIssueRoot );
$headerIssueEmail = $email;
$headerIssueEmail['status'] = 'has_issues';
$headerIssueEmail['issues'] = array(
    array(
        'level' => 'warning',
        'code' => 'malformed_header',
        'message' => 'Email header is malformed.',
        'detail' => 'Header: This Header Is Broken',
        'result' => 'WordPress may ignore this header, so the email can be sent with different headers than expected.',
    ),
);
$headerIssueLog = $headerIssueWriter->write( $headerIssueEmail );
$headerIssueContents = file_get_contents( $headerIssueLog );
assert_true( false !== strpos( $headerIssueContents, 'Issue  :    Email header is malformed.' ), 'Malformed-header issue uses the aligned Issue label.' );
assert_true( false !== strpos( $headerIssueContents, 'Header :    This Header Is Broken' ), 'Malformed-header issue exposes the offending header on its own aligned line.' );
assert_true( false !== strpos( $headerIssueContents, 'Details:    WordPress may ignore this header, so the email can be sent with different headers than expected.' ), 'Malformed-header issue explains the practical effect on its Details line.' );
remove_tree( $headerIssueRoot );

// Clean logs keep the issues section compact.
$cleanIssueRoot = temp_dir( 'wp-email-debug-clean-issues' );
$cleanIssueWriter = new EmailLogWriter( $cleanIssueRoot );
$cleanIssueEmail = $email;
$cleanIssueEmail['issues'] = array();
$cleanIssueLog = $cleanIssueWriter->write( $cleanIssueEmail );
$cleanIssueContents = file_get_contents( $cleanIssueLog );
assert_true( false !== strpos( $cleanIssueContents, '✓ No local configuration or message issues detected.' ), 'Clean logs distinguish local validation from live SMTP checks.' );
remove_tree( $cleanIssueRoot );

// Symlinked log/state paths are rejected when the platform permits symlink creation.
$symlinkRoot = temp_dir( 'wp-email-debug-symlink' );
$realTarget = $symlinkRoot . '/real';
mkdir( $realTarget, 0700, true );
$logLink = $symlinkRoot . '/logs-link';
if ( @symlink( $realTarget, $logLink ) ) {
    $thrown = false;
    try {
        ( new EmailLogWriter( $logLink ) )->prepare();
    } catch ( RuntimeException $e ) {
        $thrown = true;
    }
    assert_true( $thrown, 'Symlinked email log directory is rejected.' );
}
remove_tree( $symlinkRoot );

// Runtime smoke test: fresh matching session intercepts into spool; stale/mismatched site does not.
$runtimeRoot = temp_dir( 'wp-email-debug-runtime' );
$runtimeStateDir = $runtimeRoot . '/state';
mkdir( $runtimeStateDir . '/spool', 0700, true );
$runtimeSessionFile = $runtimeStateDir . '/session.json';
$runtimeSiteHash = hash( 'sha256', '/srv/www/|/srv/www/wp-content|https://example.test/' );
file_put_contents( $runtimeSessionFile, json_encode( array(
    'token' => 'runtime-token',
    'site_hash' => $runtimeSiteHash,
    'heartbeat' => time(),
    'spool_directory' => $runtimeStateDir . '/spool',
) ) );
$runtimeBridge = $runtimeRoot . '/runtime.php';
$installer = new BridgeInstaller( $runtimeBridge, $root . '/runtime/bridge-template.php', 'runtime-token', $runtimeSessionFile, $runtimeSiteHash );
$installer->install();
$runtimeTest = $runtimeRoot . '/smoke.php';
$runtimeBridgeLiteral = var_export( $runtimeBridge, true );
$runtimeSpoolLiteral = var_export( $runtimeStateDir . '/spool', true );
file_put_contents( $runtimeTest, <<<'PHPTEST'
<?php
namespace PHPMailer\PHPMailer {
    class SMTP {}
}
namespace {
    define( 'ABSPATH', '/srv/www/' );
    define( 'WP_CONTENT_DIR', '/srv/www/wp-content' );
    define( 'WP_PLUGIN_DIR', '/srv/www/wp-content/plugins' );
    define( 'WPMU_PLUGIN_DIR', '/srv/www/wp-content/mu-plugins' );
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/email-test/?token=secret';
    $_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );
    $GLOBALS['hooks'] = array();
    function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['hooks'][$hook] = $callback; }
    function add_filter( $hook, $callback, $priority = 10 ) { $GLOBALS['hooks'][$hook] = $callback; }
    function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
    function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
    function get_theme_root() { return '/srv/www/wp-content/themes'; }
    function home_url( $path = '' ) { return 'https://example.test' . $path; }
    class FakeMailer {
        public $Body = '<h1>Hello</h1>';
        public $AltBody = 'Hello';
        public $From = 'store@example.test';
        public $FromName = 'Store';
        public $Subject = 'Runtime Message';
        public $ContentType = 'text/html';
        public $CharSet = 'UTF-8';
        public $SMTPAuth = true;
        public $SMTPAutoTLS = true;
        public $SMTPSecure = 'tls';
        public $SMTPKeepAlive = true;
        public $Host = 'smtp.example.test';
        public $Port = 587;
        public $Username = 'user';
        public $Password = 'pass';
        public $Mailer = 'smtp';
        public $Sender = 'bounce@example.test';
        public $Sendmail = '';
        public $Timeout = 10;
        public $SMTPDebug = 2;
        public $smtp;
        public function getToAddresses() { return array( array( 'to@example.test', 'To User' ) ); }
        public function getCcAddresses() { return array(); }
        public function getBccAddresses() { return array(); }
        public function getReplyToAddresses() { return array(); }
        public function getAttachments() { return array(); }
        public function isSMTP() {}
        public function setSMTPInstance( $smtp ) { $this->smtp = $smtp; }
    }
PHPTEST
    . "require {$runtimeBridgeLiteral};\n"
    . <<<'PHPTEST'
    call_user_func( $GLOBALS['hooks']['wp_mail'], array(
        'to' => array( 'to@example.test' ),
        'subject' => 'Runtime Message',
        'message' => '<h1>Hello</h1>',
        'headers' => array( 'Content-Type: text/html; charset=UTF-8' ),
        'attachments' => array(),
        'embeds' => array(),
    ) );
    $mailer = new FakeMailer();
    call_user_func( $GLOBALS['hooks']['phpmailer_init'], $mailer );
    if ( ! is_object( $mailer->smtp ) ) { fwrite( STDERR, "no smtp\n" ); exit( 2 ); }
    if ( ! $mailer->smtp->data( "raw mime" ) ) { fwrite( STDERR, "spool write failed\n" ); exit( 3 ); }
    if ( ! $mailer->smtp->data( "raw mime again" ) ) { fwrite( STDERR, "second data call failed\n" ); exit( 12 ); }
PHPTEST
    . "    \$files = glob( {$runtimeSpoolLiteral} . '/*.json' );\n"
    . <<<'PHPTEST'
    if ( 1 !== count( $files ) ) { fwrite( STDERR, "wrong spool count\n" ); exit( 4 ); }
    $payload = json_decode( file_get_contents( $files[0] ), true );
    if ( 'Runtime Message' !== $payload['subject'] ) { exit( 5 ); }
    if ( 'successful' !== $payload['status'] ) { fwrite( STDERR, json_encode( $payload['issues'] ) ); exit( 15 ); }
    if ( 'To User <to@example.test>' !== $payload['to'][0] ) { exit( 6 ); }
    if ( 'wp-email-debug' !== $mailer->smtp->getLastTransactionID() ) { exit( 7 ); }
    if ( 'GET' !== $payload['request']['method'] || '/email-test/' !== $payload['request']['path'] || 'frontend' !== $payload['request']['type'] ) { exit( 13 ); }
    if ( true !== $mailer->smtp->getServerExt( 'SMTPUTF8' ) ) { exit( 8 ); }
    if ( false !== $mailer->smtp->getServerExt( 'STARTTLS' ) ) { exit( 9 ); }
    exit( 0 );
}
PHPTEST
);
$smokeOutput = array();
$smokeStatus = 0;
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $runtimeTest ) . ' 2>&1', $smokeOutput, $smokeStatus );
assert_same( 0, $smokeStatus, 'Generated runtime captures one spool entry per mail transport instance, even if SMTP data() is called more than once. ' . implode( ' | ', $smokeOutput ) );
foreach ( glob( $runtimeStateDir . '/spool/*.json' ) as $capturedFile ) {
    @unlink( $capturedFile );
}


// Runtime records pre_wp_mail short-circuits before PHPMailer instead of silently losing blocked messages.
$preemptRuntimeTest = $runtimeRoot . '/preempt-runtime.php';
file_put_contents( $preemptRuntimeTest, <<<'PHPTEST'
<?php
namespace PHPMailer\PHPMailer { class SMTP {} }
namespace {
    define( 'ABSPATH', '/srv/www/' ); define( 'WP_CONTENT_DIR', '/srv/www/wp-content' ); define( 'WP_PLUGIN_DIR', '/srv/www/wp-content/plugins' ); define( 'WPMU_PLUGIN_DIR', '/srv/www/wp-content/mu-plugins' );
    $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REQUEST_URI']='/blocked-mail/'; $GLOBALS['hooks']=array();
    function add_action($hook,$callback,$priority=10,$accepted=1){$GLOBALS['hooks'][$hook]=$callback;} function add_filter($hook,$callback,$priority=10,$accepted=1){$GLOBALS['hooks'][$hook]=$callback;}
    function wp_normalize_path($path){return str_replace('\\','/',$path);} function trailingslashit($path){return rtrim($path,'/\\').'/';} function get_theme_root(){return '/srv/www/wp-content/themes';} function home_url($path=''){return 'https://example.test'.$path;}
PHPTEST
    . "require {$runtimeBridgeLiteral};\n"
    . <<<'PHPTEST'
    $atts=array('to'=>array('blocked@example.test'),'subject'=>'Blocked Mail','message'=>'blocked','headers'=>array(),'attachments'=>array(),'embeds'=>array());
    $result=call_user_func($GLOBALS['hooks']['pre_wp_mail'],true,$atts); if(true!==$result){exit(70);}
    $result=call_user_func($GLOBALS['hooks']['pre_wp_mail'],false,$atts); if(false!==$result){exit(71);}
PHPTEST
    . "    \$files = glob( {$runtimeSpoolLiteral} . '/*.json' );\n"
    . <<<'PHPTEST'
    if(2!==count($files)){exit(72);} sort($files,SORT_STRING); $first=json_decode(file_get_contents($files[0]),true); $second=json_decode(file_get_contents($files[1]),true);
    $statuses=array($first['status'],$second['status']); sort($statuses,SORT_STRING); if(array('failed','has_issues')!==$statuses){exit(73);}
    $failed='failed'===$first['status']?$first:$second; if('pre_wp_mail_short_circuit'!==$failed['error']['code']){exit(74);} exit(0);
}
PHPTEST
);
$preemptOutput=array(); $preemptStatus=0;
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($preemptRuntimeTest).' 2>&1',$preemptOutput,$preemptStatus);
assert_same(0,$preemptStatus,'pre_wp_mail short-circuits are recorded as Failed events before PHPMailer. '.implode(' | ',$preemptOutput));
foreach ( glob( $runtimeStateDir . '/spool/*.json' ) as $capturedFile ) { @unlink( $capturedFile ); }

// Controlled pipeline-test sessions ignore transport configuration issues; transport belongs to `check`.
$pipelineStateData = json_decode( file_get_contents( $runtimeSessionFile ), true );
$pipelineStateData['pipeline_test'] = true;
$pipelineStateData['probe_transport'] = false;
$pipelineStateData['heartbeat'] = time();
file_put_contents( $runtimeSessionFile, json_encode( $pipelineStateData ) );
$pipelineRuntimeTest = $runtimeRoot . '/pipeline-runtime.php';
file_put_contents( $pipelineRuntimeTest, <<<'PHPTEST'
<?php
namespace PHPMailer\PHPMailer { class SMTP {} }
namespace {
    define('ABSPATH','/srv/www/'); define('WP_CONTENT_DIR','/srv/www/wp-content'); define('WP_PLUGIN_DIR','/srv/www/wp-content/plugins'); define('WPMU_PLUGIN_DIR','/srv/www/wp-content/mu-plugins');
    $_SERVER['REQUEST_METHOD']='CLI'; $_SERVER['REQUEST_URI']=''; $GLOBALS['hooks']=array();
    function add_action($hook,$callback,$priority=10,$accepted=1){$GLOBALS['hooks'][$hook]=$callback;} function add_filter($hook,$callback,$priority=10,$accepted=1){$GLOBALS['hooks'][$hook]=$callback;}
    function wp_normalize_path($path){return str_replace('\\','/',$path);} function trailingslashit($path){return rtrim($path,'/\\').'/';} function get_theme_root(){return '/srv/www/wp-content/themes';} function home_url($path=''){return 'https://example.test'.$path;}
    class FakeMailer {
        public $Body='x'; public $AltBody=''; public $From='wordpress@example.test'; public $FromName='WordPress'; public $Subject='Pipeline Test'; public $ContentType='text/plain'; public $CharSet='UTF-8';
        public $Mailer='smtp'; public $Host=''; public $Port=0; public $SMTPAuth=true; public $Username=''; public $Password=''; public $SMTPSecure='tls'; public $SMTPAutoTLS=true; public $SMTPKeepAlive=false; public $Sender=''; public $Sendmail=''; public $SMTPDebug=0; public $smtp;
        public function getToAddresses(){return array(array('wp-email-debug@example.com',''));} public function getCcAddresses(){return array();} public function getBccAddresses(){return array();} public function getReplyToAddresses(){return array();} public function getAttachments(){return array();}
        public function isSMTP(){} public function setSMTPInstance($smtp){$this->smtp=$smtp;}
    }
PHPTEST
    . "require {$runtimeBridgeLiteral};\n"
    . <<<'PHPTEST'
    call_user_func($GLOBALS['hooks']['wp_mail'],array('to'=>array('wp-email-debug@example.com'),'subject'=>'Pipeline Test','message'=>'x','headers'=>array(),'attachments'=>array(),'embeds'=>array()));
    $mailer=new FakeMailer(); call_user_func($GLOBALS['hooks']['phpmailer_init'],$mailer); if(!$mailer->smtp->data('mime')){exit(80);}
PHPTEST
    . "    \$files = glob( {$runtimeSpoolLiteral} . '/*.json' );\n"
    . <<<'PHPTEST'
    if(1!==count($files)){exit(81);} $payload=json_decode(file_get_contents($files[0]),true); if('successful'!==$payload['status']){fwrite(STDERR,json_encode($payload['issues']));exit(82);} exit(0);
}
PHPTEST
);
$pipelineRuntimeOutput=array(); $pipelineRuntimeStatus=0;
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($pipelineRuntimeTest).' 2>&1',$pipelineRuntimeOutput,$pipelineRuntimeStatus);
assert_same(0,$pipelineRuntimeStatus,'Controlled test mode ignores SMTP/PHP transport configuration issues and validates only the WordPress mail pipeline. '.implode(' | ',$pipelineRuntimeOutput));
foreach ( glob( $runtimeStateDir . '/spool/*.json' ) as $capturedFile ) { @unlink( $capturedFile ); }
$pipelineStateData['pipeline_test'] = false;
$pipelineStateData['heartbeat'] = time();
file_put_contents( $runtimeSessionFile, json_encode( $pipelineStateData ) );

// WordPress silently ignores attachment exceptions; preflight must still flag them.
$attachmentRuntimeTest = $runtimeRoot . '/attachment-runtime.php';
file_put_contents( $attachmentRuntimeTest, <<<'PHPTEST'
<?php
namespace PHPMailer\PHPMailer { class SMTP {} }
namespace {
    define( 'ABSPATH', '/srv/www/' );
    define( 'WP_CONTENT_DIR', '/srv/www/wp-content' );
    define( 'WP_PLUGIN_DIR', '/srv/www/wp-content/plugins' );
    define( 'WPMU_PLUGIN_DIR', '/srv/www/wp-content/mu-plugins' );
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/attachment-test/';
    $GLOBALS['hooks'] = array();
    function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['hooks'][$hook] = $callback; }
    function add_filter( $hook, $callback, $priority = 10 ) { $GLOBALS['hooks'][$hook] = $callback; }
    function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
    function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
    function get_theme_root() { return '/srv/www/wp-content/themes'; }
    function home_url( $path = '' ) { return 'https://example.test' . $path; }
    class FakeMailer {
        public $Body = 'Attachment test'; public $AltBody = ''; public $From = 'store@example.test'; public $FromName = 'Store';
        public $Subject = 'Missing Attachment'; public $ContentType = 'text/plain'; public $CharSet = 'UTF-8';
        public $Mailer = 'smtp'; public $SMTPAuth = true; public $SMTPAutoTLS = true; public $SMTPSecure = 'tls'; public $SMTPKeepAlive = false;
        public $Host = 'smtp.example.test'; public $Port = 587; public $Username = 'user'; public $Password = 'pass'; public $Timeout = 5;
        public $Sender = ''; public $Sendmail = ''; public $SMTPDebug = 0; public $smtp;
        public function getToAddresses() { return array( array( 'user@example.test', '' ) ); }
        public function getCcAddresses() { return array(); } public function getBccAddresses() { return array(); }
        public function getReplyToAddresses() { return array(); } public function getAttachments() { return array(); }
        public function isSMTP() {} public function setSMTPInstance( $smtp ) { $this->smtp = $smtp; }
    }
PHPTEST
    . "require {$runtimeBridgeLiteral};\n"
    . <<<'PHPTEST'
    call_user_func( $GLOBALS['hooks']['wp_mail'], array(
        'to' => array( 'user@example.test' ),
        'subject' => 'Missing Attachment',
        'message' => 'Attachment test',
        'headers' => array(),
        'attachments' => array( '/definitely/missing/file.pdf' ),
        'embeds' => array(),
    ) );
    $mailer = new FakeMailer();
    call_user_func( $GLOBALS['hooks']['phpmailer_init'], $mailer );
    if ( ! $mailer->smtp->data( 'raw mime' ) ) { exit( 40 ); }
PHPTEST
    . "    \$files = glob( {$runtimeSpoolLiteral} . '/*.json' );\n"
    . <<<'PHPTEST'
    if ( 1 !== count( $files ) ) { exit( 41 ); }
    $payload = json_decode( file_get_contents( $files[0] ), true );
    if ( 'has_issues' !== $payload['status'] ) { exit( 42 ); }
    $found = false;
    foreach ( $payload['issues'] as $issue ) {
        if ( 'attachment_missing' === $issue['code'] ) { $found = true; break; }
    }
    if ( ! $found ) { exit( 43 ); }
    exit( 0 );
}
PHPTEST
);
$attachmentRuntimeOutput = array();
$attachmentRuntimeStatus = 0;
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $attachmentRuntimeTest ) . ' 2>&1', $attachmentRuntimeOutput, $attachmentRuntimeStatus );
assert_same( 0, $attachmentRuntimeStatus, 'Missing attachment is flagged even though WordPress normally catches and ignores addAttachment() exceptions. ' . implode( ' | ', $attachmentRuntimeOutput ) );
foreach ( glob( $runtimeStateDir . '/spool/*.json' ) as $capturedFile ) {
    @unlink( $capturedFile );
}



// Static SMTP configuration errors are detected even when delivery is safely intercepted.
$configRuntimeTest = $runtimeRoot . '/config-runtime.php';
file_put_contents( $configRuntimeTest, <<<'PHPTEST'
<?php
namespace PHPMailer\PHPMailer { class SMTP {} }
namespace {
    define( 'ABSPATH', '/srv/www/' ); define( 'WP_CONTENT_DIR', '/srv/www/wp-content' ); define( 'WP_PLUGIN_DIR', '/srv/www/wp-content/plugins' ); define( 'WPMU_PLUGIN_DIR', '/srv/www/wp-content/mu-plugins' );
    $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REQUEST_URI']='/config-test/'; $GLOBALS['hooks']=array();
    function add_action($hook,$callback,$priority=10){$GLOBALS['hooks'][$hook]=$callback;} function add_filter($hook,$callback,$priority=10){$GLOBALS['hooks'][$hook]=$callback;}
    function wp_normalize_path($path){return str_replace('\\','/',$path);} function trailingslashit($path){return rtrim($path,'/\\').'/';} function get_theme_root(){return '/srv/www/wp-content/themes';} function home_url($path=''){return 'https://example.test'.$path;}
    class FakeMailer {
        public $Body='x'; public $AltBody=''; public $From='store@example.test'; public $FromName='Store'; public $Subject='Bad SMTP Config'; public $ContentType='text/plain'; public $CharSet='UTF-8';
        public $Mailer='smtp'; public $SMTPAuth=true; public $SMTPAutoTLS=true; public $SMTPSecure='tls'; public $SMTPKeepAlive=false; public $Host='smtp.example.test'; public $Port=587; public $Username=''; public $Password=''; public $Timeout=5;
        public $Sender=''; public $Sendmail=''; public $SMTPDebug=0; public $smtp;
        public function getToAddresses(){return array(array('user@example.test',''));} public function getCcAddresses(){return array();} public function getBccAddresses(){return array();} public function getReplyToAddresses(){return array();} public function getAttachments(){return array();}
        public function isSMTP(){$this->Mailer='smtp';} public function setSMTPInstance($smtp){$this->smtp=$smtp;}
    }
PHPTEST
    . "require {$runtimeBridgeLiteral};\n"
    . <<<'PHPTEST'
    call_user_func($GLOBALS['hooks']['wp_mail'],array('to'=>array('user@example.test'),'subject'=>'Bad SMTP Config','message'=>'x','headers'=>array(),'attachments'=>array(),'embeds'=>array()));
    $mailer=new FakeMailer(); call_user_func($GLOBALS['hooks']['phpmailer_init'],$mailer); if(!$mailer->smtp->data('captured')){exit(44);}
PHPTEST
    . "    \$files = glob( {$runtimeSpoolLiteral} . '/*.json' );\n"
    . <<<'PHPTEST'
    if(1!==count($files)){exit(45);} $payload=json_decode(file_get_contents($files[0]),true); if('has_issues'!==$payload['status']){exit(46);}
    $codes=array(); foreach($payload['issues'] as $d){$codes[]=$d['code'];}
    if(!in_array('smtp_username_missing',$codes,true) || !in_array('smtp_credentials_missing',$codes,true)){exit(47);} exit(0);
}
PHPTEST
);
$configRuntimeOutput=array(); $configRuntimeStatus=0;
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($configRuntimeTest).' 2>&1',$configRuntimeOutput,$configRuntimeStatus);
assert_same(0,$configRuntimeStatus,'Static SMTP authentication misconfiguration is detected before the capture transport replaces it. '.implode(' | ',$configRuntimeOutput));
foreach(glob($runtimeStateDir.'/spool/*.json') as $capturedFile){@unlink($capturedFile);}

// Opt-in transport probe checks SMTP connect/auth/envelope/recipient stages without DATA.
$probeStateData = json_decode( file_get_contents( $runtimeSessionFile ), true );
$probeStateData['heartbeat'] = time();
$probeStateData['probe_transport'] = true;
file_put_contents( $runtimeSessionFile, json_encode( $probeStateData ) );
$probeRuntimeTest = $runtimeRoot . '/probe-runtime.php';
file_put_contents( $probeRuntimeTest, <<<'PHPTEST'
<?php
namespace PHPMailer\PHPMailer { class SMTP {} }
namespace {
    define( 'ABSPATH', '/srv/www/' );
    define( 'WP_CONTENT_DIR', '/srv/www/wp-content' );
    define( 'WP_PLUGIN_DIR', '/srv/www/wp-content/plugins' );
    define( 'WPMU_PLUGIN_DIR', '/srv/www/wp-content/mu-plugins' );
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/probe-test/';
    $GLOBALS['hooks'] = array();
    function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['hooks'][$hook] = $callback; }
    function add_filter( $hook, $callback, $priority = 10 ) { $GLOBALS['hooks'][$hook] = $callback; }
    function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
    function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
    function get_theme_root() { return '/srv/www/wp-content/themes'; }
    function home_url( $path = '' ) { return 'https://example.test' . $path; }
    class ProbeSMTP {
        public $mailCalls = 0; public $recipientCalls = 0; public $resetCalls = 0; public $dataCalls = 0;
        public function mail( $from ) { $this->mailCalls++; return true; }
        public function recipient( $address, $dsn = '' ) { $this->recipientCalls++; return true; }
        public function reset() { $this->resetCalls++; return true; }
        public function getError() { return array(); }
        public function data( $data ) { $this->dataCalls++; return true; }
    }
    class FakeMailer {
        public $Body = 'Probe test'; public $AltBody = ''; public $From = 'store@example.test'; public $FromName = 'Store';
        public $Subject = 'Probe Test'; public $ContentType = 'text/plain'; public $CharSet = 'UTF-8';
        public $Mailer = 'smtp'; public $SMTPAuth = true; public $SMTPAutoTLS = true; public $SMTPSecure = 'tls'; public $SMTPKeepAlive = false;
        public $Host = 'smtp.example.test'; public $Port = 587; public $Username = 'user'; public $Password = 'pass'; public $Timeout = 30; public $Timelimit = 30;
        public $Sender = 'bounce@example.test'; public $Sendmail = ''; public $SMTPDebug = 0;
        public $ErrorInfo = ''; public $smtp; public $probeSmtp; public $connectCalls = 0; public $closeCalls = 0;
        public function __construct() { $this->probeSmtp = new ProbeSMTP(); $this->smtp = $this->probeSmtp; }
        public function getToAddresses() { return array( array( 'user@example.test', '' ) ); }
        public function getCcAddresses() { return array(); } public function getBccAddresses() { return array(); }
        public function getReplyToAddresses() { return array(); } public function getAttachments() { return array(); }
        public function smtpConnect() { $this->connectCalls++; return true; }
        public function smtpClose() { $this->closeCalls++; }
        public function getSMTPInstance() { return $this->smtp; }
        public function isSMTP() { $this->Mailer = 'smtp'; }
        public function setSMTPInstance( $smtp ) { $this->smtp = $smtp; }
    }
PHPTEST
    . "require {$runtimeBridgeLiteral};\n"
    . <<<'PHPTEST'
    call_user_func( $GLOBALS['hooks']['wp_mail'], array(
        'to' => array( 'user@example.test' ), 'subject' => 'Probe Test', 'message' => 'Probe test',
        'headers' => array(), 'attachments' => array(), 'embeds' => array(),
    ) );
    $mailer = new FakeMailer();
    call_user_func( $GLOBALS['hooks']['phpmailer_init'], $mailer );
    if ( 1 !== $mailer->connectCalls || 1 !== $mailer->closeCalls ) { exit( 50 ); }
    if ( 1 !== $mailer->probeSmtp->mailCalls || 1 !== $mailer->probeSmtp->recipientCalls || 1 !== $mailer->probeSmtp->resetCalls ) { exit( 51 ); }
    if ( 0 !== $mailer->probeSmtp->dataCalls ) { exit( 52 ); }
    if ( ! $mailer->smtp->data( 'captured mime' ) ) { exit( 53 ); }
PHPTEST
    . "    \$files = glob( {$runtimeSpoolLiteral} . '/*.json' );\n"
    . <<<'PHPTEST'
    if ( 1 !== count( $files ) ) { exit( 54 ); }
    $payload = json_decode( file_get_contents( $files[0] ), true );
    if ( 'ok' !== $payload['transport_probe']['status'] ) { exit( 55 ); }
    if ( false === strpos( $payload['transport_probe']['message'], 'without sending DATA' ) ) { exit( 56 ); }
    exit( 0 );
}
PHPTEST
);
$probeRuntimeOutput = array();
$probeRuntimeStatus = 0;
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $probeRuntimeTest ) . ' 2>&1', $probeRuntimeOutput, $probeRuntimeStatus );
assert_same( 0, $probeRuntimeStatus, 'Opt-in SMTP transport probe checks through RCPT TO without sending DATA. ' . implode( ' | ', $probeRuntimeOutput ) );
foreach ( glob( $runtimeStateDir . '/spool/*.json' ) as $capturedFile ) {
    @unlink( $capturedFile );
}

// Probe failure is surfaced as a sending issue while delivery remains intercepted.
$probeFailureRuntimeTest = $runtimeRoot . '/probe-failure-runtime.php';
file_put_contents( $probeFailureRuntimeTest, <<<'PHPTEST'
<?php
namespace PHPMailer\PHPMailer { class SMTP {} }
namespace {
    define( 'ABSPATH', '/srv/www/' ); define( 'WP_CONTENT_DIR', '/srv/www/wp-content' ); define( 'WP_PLUGIN_DIR', '/srv/www/wp-content/plugins' ); define( 'WPMU_PLUGIN_DIR', '/srv/www/wp-content/mu-plugins' );
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/probe-failure/'; $GLOBALS['hooks'] = array();
    function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['hooks'][$hook] = $callback; }
    function add_filter( $hook, $callback, $priority = 10 ) { $GLOBALS['hooks'][$hook] = $callback; }
    function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); } function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
    function get_theme_root() { return '/srv/www/wp-content/themes'; } function home_url( $path = '' ) { return 'https://example.test' . $path; }
    class FakeMailer {
        public $Body='x'; public $AltBody=''; public $From='store@example.test'; public $FromName='Store'; public $Subject='Probe Failure'; public $ContentType='text/plain'; public $CharSet='UTF-8';
        public $Mailer='smtp'; public $SMTPAuth=true; public $SMTPAutoTLS=true; public $SMTPSecure='tls'; public $SMTPKeepAlive=false; public $Host='smtp.example.test'; public $Port=587;
        public $Username='bad-user'; public $Password='bad-pass'; public $Timeout=30; public $Timelimit=30; public $Sender=''; public $Sendmail=''; public $SMTPDebug=0; public $ErrorInfo='SMTP Error: Could not authenticate.'; public $smtp;
        public function getToAddresses(){ return array(array('user@example.test','')); } public function getCcAddresses(){return array();} public function getBccAddresses(){return array();} public function getReplyToAddresses(){return array();} public function getAttachments(){return array();}
        public function smtpConnect(){ return false; } public function smtpClose(){} public function getSMTPInstance(){ return $this->smtp; } public function isSMTP(){ $this->Mailer='smtp'; } public function setSMTPInstance($smtp){ $this->smtp=$smtp; }
    }
PHPTEST
    . "require {$runtimeBridgeLiteral};\n"
    . <<<'PHPTEST'
    call_user_func( $GLOBALS['hooks']['wp_mail'], array('to'=>array('user@example.test'),'subject'=>'Probe Failure','message'=>'x','headers'=>array(),'attachments'=>array(),'embeds'=>array()) );
    $mailer = new FakeMailer(); call_user_func( $GLOBALS['hooks']['phpmailer_init'], $mailer ); if ( ! $mailer->smtp->data('captured') ) { exit(60); }
PHPTEST
    . "    \$files = glob( {$runtimeSpoolLiteral} . '/*.json' );\n"
    . <<<'PHPTEST'
    if (1 !== count($files)) { exit(61); } $payload=json_decode(file_get_contents($files[0]),true);
    if ('failed' !== $payload['transport_probe']['status']) { exit(62); }
    if ('has_issues' !== $payload['status']) { exit(63); }
    $found=false; foreach($payload['issues'] as $d){ if('transport_probe_failed'===$d['code']){$found=true; break;} } if(!$found){exit(64);} exit(0);
}
PHPTEST
);
$probeFailureOutput = array(); $probeFailureStatus = 0;
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $probeFailureRuntimeTest ) . ' 2>&1', $probeFailureOutput, $probeFailureStatus );
assert_same( 0, $probeFailureStatus, 'SMTP probe failures such as authentication errors become sending issues without sending email. ' . implode( ' | ', $probeFailureOutput ) );
foreach ( glob( $runtimeStateDir . '/spool/*.json' ) as $capturedFile ) { @unlink( $capturedFile ); }

$probeStateData['probe_transport'] = false;
$probeStateData['heartbeat'] = time();
file_put_contents( $runtimeSessionFile, json_encode( $probeStateData ) );

// Runtime failure after phpmailer_init keeps the final mailer context and records WP_Error details.
$failedRuntimeTest = $runtimeRoot . '/failed-runtime.php';
file_put_contents( $failedRuntimeTest, <<<'PHPTEST'
<?php
namespace PHPMailer\PHPMailer { class SMTP {} }
namespace {
    define( 'ABSPATH', '/srv/www/' );
    define( 'WP_CONTENT_DIR', '/srv/www/wp-content' );
    define( 'WP_PLUGIN_DIR', '/srv/www/wp-content/plugins' );
    define( 'WPMU_PLUGIN_DIR', '/srv/www/wp-content/mu-plugins' );
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/checkout/';
    $_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );
    $GLOBALS['hooks'] = array();
    function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['hooks'][$hook] = $callback; }
    function add_filter( $hook, $callback, $priority = 10 ) { $GLOBALS['hooks'][$hook] = $callback; }
    function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
    function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
    function get_theme_root() { return '/srv/www/wp-content/themes'; }
    function home_url( $path = '' ) { return 'https://example.test' . $path; }
    class WP_Error {
        private $code; private $message; private $data;
        public function __construct( $code, $message, $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
        public function get_error_codes() { return array( $this->code ); }
        public function get_error_messages() { return array( $this->message ); }
        public function get_error_data() { return $this->data; }
    }
    class FakeMailer {
        public $Body = '<h1>Failed body</h1>'; public $AltBody = 'Failed body';
        public $From = 'store@example.test'; public $FromName = 'Store'; public $Subject = 'Runtime Failed';
        public $ContentType = 'text/html'; public $CharSet = 'UTF-8'; public $SMTPAuth = true;
        public $SMTPAutoTLS = true; public $SMTPSecure = 'tls'; public $SMTPKeepAlive = true;
        public $Host = 'smtp.example.test'; public $Port = 587; public $SMTPDebug = 2; public $smtp;
        public function getToAddresses() { return array( array( 'to@example.test', 'To User' ) ); }
        public function getCcAddresses() { return array(); } public function getBccAddresses() { return array(); }
        public function getReplyToAddresses() { return array(); } public function getAttachments() { return array(); }
        public function isSMTP() {} public function setSMTPInstance( $smtp ) { $this->smtp = $smtp; }
    }
PHPTEST
    . "require {$runtimeBridgeLiteral};\n"
    . <<<'PHPTEST'
    $phpmailer = new FakeMailer();
    call_user_func( $GLOBALS['hooks']['phpmailer_init'], $phpmailer );
    $error = new WP_Error( 'wp_mail_failed', 'Simulated PHPMailer failure.', array(
        'to' => array( 'fallback@example.test' ),
        'subject' => 'Fallback subject',
        'message' => 'Fallback body',
        'headers' => array(),
        'attachments' => array(),
        'phpmailer_exception_code' => 77,
    ) );
    call_user_func( $GLOBALS['hooks']['wp_mail_failed'], $error );
PHPTEST
    . "    \$files = glob( {$runtimeSpoolLiteral} . '/*.json' );\n"
    . <<<'PHPTEST'
    if ( 1 !== count( $files ) ) { fwrite( STDERR, 'wrong failed spool count' ); exit( 20 ); }
    $payload = json_decode( file_get_contents( $files[0] ), true );
    if ( 'failed' !== $payload['status'] ) { exit( 21 ); }
    if ( 'Runtime Failed' !== $payload['subject'] ) { exit( 22 ); }
    if ( 'To User <to@example.test>' !== $payload['to'][0] ) { exit( 23 ); }
    if ( 'Simulated PHPMailer failure.' !== $payload['error']['message'] ) { exit( 24 ); }
    if ( 77 !== $payload['error']['phpmailer_exception_code'] ) { exit( 25 ); }
    exit( 0 );
}
PHPTEST
);
$failedRuntimeOutput = array();
$failedRuntimeStatus = 0;
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $failedRuntimeTest ) . ' 2>&1', $failedRuntimeOutput, $failedRuntimeStatus );
assert_same( 0, $failedRuntimeStatus, 'Generated runtime records wp_mail_failed with final PHPMailer context and error details. ' . implode( ' | ', $failedRuntimeOutput ) );
foreach ( glob( $runtimeStateDir . '/spool/*.json' ) as $capturedFile ) {
    @unlink( $capturedFile );
}

// Runtime failure before phpmailer_init still records the WP_Error mail data.
$earlyFailedRuntimeTest = $runtimeRoot . '/early-failed-runtime.php';
file_put_contents( $earlyFailedRuntimeTest, <<<'PHPTEST'
<?php
namespace PHPMailer\PHPMailer { class SMTP {} }
namespace {
    define( 'ABSPATH', '/srv/www/' );
    define( 'WP_CONTENT_DIR', '/srv/www/wp-content' );
    define( 'WP_PLUGIN_DIR', '/srv/www/wp-content/plugins' );
    define( 'WPMU_PLUGIN_DIR', '/srv/www/wp-content/mu-plugins' );
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/early-failure/';
    $GLOBALS['hooks'] = array();
    function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['hooks'][$hook] = $callback; }
    function add_filter( $hook, $callback, $priority = 10 ) { $GLOBALS['hooks'][$hook] = $callback; }
    function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
    function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
    function get_theme_root() { return '/srv/www/wp-content/themes'; }
    function home_url( $path = '' ) { return 'https://example.test' . $path; }
    class WP_Error {
        private $code; private $message; private $data;
        public function __construct( $code, $message, $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
        public function get_error_codes() { return array( $this->code ); }
        public function get_error_messages() { return array( $this->message ); }
        public function get_error_data() { return $this->data; }
    }
PHPTEST
    . "require {$runtimeBridgeLiteral};\n"
    . <<<'PHPTEST'
    $error = new WP_Error( 'wp_mail_failed', 'Invalid From address.', array(
        'to' => array( 'user@example.test' ),
        'subject' => 'Early Failure',
        'message' => 'Original body',
        'headers' => array( 'Content-Type: text/html; charset=UTF-8' ),
        'attachments' => array(),
        'phpmailer_exception_code' => 12,
    ) );
    call_user_func( $GLOBALS['hooks']['wp_mail_failed'], $error );
PHPTEST
    . "    \$files = glob( {$runtimeSpoolLiteral} . '/*.json' );\n"
    . <<<'PHPTEST'
    if ( 1 !== count( $files ) ) { exit( 30 ); }
    $payload = json_decode( file_get_contents( $files[0] ), true );
    if ( 'failed' !== $payload['status'] ) { exit( 31 ); }
    if ( 'Early Failure' !== $payload['subject'] ) { exit( 32 ); }
    if ( 'user@example.test' !== $payload['to'][0] ) { exit( 33 ); }
    if ( 'Original body' !== base64_decode( $payload['body_b64'] ) ) { exit( 34 ); }
    if ( 'text/html' !== $payload['content_type'] || 'UTF-8' !== $payload['charset'] ) { exit( 36 ); }
    if ( 'Invalid From address.' !== $payload['error']['message'] ) { exit( 35 ); }
    exit( 0 );
}
PHPTEST
);
$earlyFailedOutput = array();
$earlyFailedStatus = 0;
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $earlyFailedRuntimeTest ) . ' 2>&1', $earlyFailedOutput, $earlyFailedStatus );
assert_same( 0, $earlyFailedStatus, 'Generated runtime records wp_mail_failed even when failure happens before phpmailer_init. ' . implode( ' | ', $earlyFailedOutput ) );
foreach ( glob( $runtimeStateDir . '/spool/*.json' ) as $capturedFile ) {
    @unlink( $capturedFile );
}

// Negative security coverage: same token/session must remain inert on a different WordPress fingerprint.
$mismatchTest = $runtimeRoot . '/mismatch.php';
file_put_contents( $mismatchTest, <<<'PHPTEST'
<?php
namespace PHPMailer\PHPMailer { class SMTP {} }
namespace {
    define( 'ABSPATH', '/different/www/' );
    define( 'WP_CONTENT_DIR', '/different/www/wp-content' );
    define( 'WP_PLUGIN_DIR', '/different/www/wp-content/plugins' );
    define( 'WPMU_PLUGIN_DIR', '/different/www/wp-content/mu-plugins' );
    $GLOBALS['hooks'] = array();
    function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['hooks'][$hook] = $callback; }
    function add_filter( $hook, $callback, $priority = 10 ) { $GLOBALS['hooks'][$hook] = $callback; }
    function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
    function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
    function get_theme_root() { return '/different/www/wp-content/themes'; }
    function home_url( $path = '' ) { return 'https://different.test' . $path; }
    class FakeMailer {
        public $Body = 'x'; public $AltBody = ''; public $From = 'a@example.test'; public $FromName = '';
        public $Subject = 'Mismatch'; public $ContentType = 'text/plain'; public $CharSet = 'UTF-8';
        public $SMTPAuth = false; public $SMTPAutoTLS = false; public $SMTPSecure = ''; public $SMTPKeepAlive = false;
        public $Host = 'localhost'; public $Port = 25; public $SMTPDebug = 0; public $smtp = null;
        public function getToAddresses() { return array( array( 'b@example.test', '' ) ); }
        public function getCcAddresses() { return array(); } public function getBccAddresses() { return array(); }
        public function getReplyToAddresses() { return array(); } public function getAttachments() { return array(); }
        public function isSMTP() {} public function setSMTPInstance( $smtp ) { $this->smtp = $smtp; }
    }
PHPTEST
    . "require {$runtimeBridgeLiteral};\n"
    . <<<'PHPTEST'
    $mailer = new FakeMailer();
    call_user_func( $GLOBALS['hooks']['phpmailer_init'], $mailer );
    exit( null === $mailer->smtp ? 0 : 10 );
}
PHPTEST
);
$mismatchOutput = array();
$mismatchStatus = 0;
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $mismatchTest ) . ' 2>&1', $mismatchOutput, $mismatchStatus );
assert_same( 0, $mismatchStatus, 'Generated runtime remains inert when the WordPress installation fingerprint does not match.' );

// Negative security coverage: stale heartbeat disables interception even when token and site fingerprint match.
$state = json_decode( file_get_contents( $runtimeSessionFile ), true );
$state['heartbeat'] = time() - 30;
file_put_contents( $runtimeSessionFile, json_encode( $state ) );
$staleRuntimeTest = $runtimeRoot . '/stale-runtime.php';
file_put_contents( $staleRuntimeTest, <<<'PHPTEST'
<?php
namespace PHPMailer\PHPMailer { class SMTP {} }
namespace {
    define( 'ABSPATH', '/srv/www/' );
    define( 'WP_CONTENT_DIR', '/srv/www/wp-content' );
    define( 'WP_PLUGIN_DIR', '/srv/www/wp-content/plugins' );
    define( 'WPMU_PLUGIN_DIR', '/srv/www/wp-content/mu-plugins' );
    $GLOBALS['hooks'] = array();
    function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['hooks'][$hook] = $callback; }
    function add_filter( $hook, $callback, $priority = 10 ) { $GLOBALS['hooks'][$hook] = $callback; }
    function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
    function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
    function get_theme_root() { return '/srv/www/wp-content/themes'; }
    function home_url( $path = '' ) { return 'https://example.test' . $path; }
    class FakeMailer {
        public $Body = 'x'; public $AltBody = ''; public $From = 'a@example.test'; public $FromName = '';
        public $Subject = 'Stale'; public $ContentType = 'text/plain'; public $CharSet = 'UTF-8';
        public $SMTPAuth = false; public $SMTPAutoTLS = false; public $SMTPSecure = ''; public $SMTPKeepAlive = false;
        public $Host = 'localhost'; public $Port = 25; public $SMTPDebug = 0; public $smtp = null;
        public function getToAddresses() { return array( array( 'b@example.test', '' ) ); }
        public function getCcAddresses() { return array(); } public function getBccAddresses() { return array(); }
        public function getReplyToAddresses() { return array(); } public function getAttachments() { return array(); }
        public function isSMTP() {} public function setSMTPInstance( $smtp ) { $this->smtp = $smtp; }
    }
PHPTEST
    . "require {$runtimeBridgeLiteral};\n"
    . <<<'PHPTEST'
    if ( isset( $GLOBALS['hooks']['phpmailer_init'] ) ) {
        $mailer = new FakeMailer();
        call_user_func( $GLOBALS['hooks']['phpmailer_init'], $mailer );
        exit( null === $mailer->smtp ? 0 : 11 );
    }
    exit( 0 );
}
PHPTEST
);
$staleOutput = array();
$staleStatus = 0;
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $staleRuntimeTest ) . ' 2>&1', $staleOutput, $staleStatus );
assert_same( 0, $staleStatus, 'Generated runtime remains inert after the heartbeat expires.' );

$lintOutput = array();
$lintStatus = 0;
exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $runtimeBridge ) . ' 2>&1', $lintOutput, $lintStatus );
assert_same( 0, $lintStatus, 'Generated MU runtime passes PHP lint.' );
$installer->uninstall();
remove_tree( $runtimeRoot );

$probeDisabledEmail = $cleanIssueEmail;
$probeDisabledEmail['transport'] = array(
    'mailer' => 'smtp',
    'host' => '127.0.0.1',
    'port' => 1,
    'smtp_auth' => false,
    'smtp_secure' => '',
);
$probeDisabledEmail['transport_probe'] = array(
    'enabled' => false,
    'status' => 'not_run',
);
$probeDisabledPath = $writer->write( $probeDisabledEmail );
$probeDisabledContents = file_get_contents( $probeDisabledPath );
assert_true( false !== strpos( $probeDisabledContents, 'SMTP check:   not run' ), 'SMTP logs explicitly state when the live transport check was not run.' );
assert_true( false !== strpos( $probeDisabledContents, '✓ No local configuration or message issues detected.' ), 'A skipped SMTP probe is not described as a successful SMTP check.' );

$probePassedEmail = $probeDisabledEmail;
$probePassedEmail['transport_probe'] = array(
    'enabled' => true,
    'status' => 'ok',
);
$probePassedPath = $writer->write( $probePassedEmail );
$probePassedContents = file_get_contents( $probePassedPath );
assert_true( false !== strpos( $probePassedContents, 'SMTP check:   passed' ), 'SMTP logs state when the live transport check passed.' );
assert_true( false !== strpos( $probePassedContents, '✓ No local configuration, message, or SMTP pre-delivery issues detected.' ), 'Successful live SMTP checks are distinguished from local-only validation.' );

$probeFailedEmail = $probeDisabledEmail;
$probeFailedEmail['status'] = 'has_issues';
$probeFailedEmail['transport_probe'] = array(
    'enabled' => true,
    'status' => 'failed',
    'stage' => 'connect_auth',
    'message' => 'Connection refused',
);
$probeFailedEmail['issues'] = array(
    array(
        'level' => 'error',
        'code' => 'transport_probe_failed',
        'message' => 'SMTP connection or authentication failed.',
        'detail' => 'Details: Connection refused',
        'result' => 'The email would not reach the SMTP delivery stage with the current server settings or credentials.',
    ),
);
$probeFailedPath = $writer->write( $probeFailedEmail );
$probeFailedContents = file_get_contents( $probeFailedPath );
assert_true( false !== strpos( $probeFailedContents, 'SMTP check:   failed' ), 'SMTP logs state when the live transport check failed.' );
assert_true( false !== strpos( $probeFailedContents, 'SMTP connection or authentication failed.' ), 'Failed live SMTP checks remain visible in ISSUES.' );


// One-shot TEST/CHECK logs are clearly distinguishable from listener captures.
$modeLogRoot = temp_dir( 'wp-email-debug-mode-logs' );
$modeWriter = new EmailLogWriter( $modeLogRoot );
$modeEmail = $cleanIssueEmail;
$modeEmail['subject'] = '[WP Email Debug] Test Email';
$modeEmail['log_type'] = 'test';
$testModeLog = $modeWriter->write( $modeEmail );
assert_true( false !== strpos( basename( $testModeLog ), '_TEST-SUCCESSFUL_wp-email-debug-test-email.log' ), 'Test-email logs use a clear TEST-SUCCESSFUL filename token.' );
$testModeContents = file_get_contents( $testModeLog );
assert_true( false !== strpos( $testModeContents, 'WP EMAIL DEBUG - TEST' ), 'Test-email logs use a dedicated TEST heading.' );
assert_true( false !== strpos( $testModeContents, 'Test scope:   WordPress mail pipeline' ), 'Test logs explicitly state that they validate the WordPress mail pipeline.' );
assert_true( false !== strpos( $testModeContents, 'Transport:    not checked (use wp email-debug check)' ), 'Test logs clearly separate pipeline validation from transport checks.' );
$modeEmail['log_type'] = 'check';
$modeEmail['status'] = 'has_issues';
$modeEmail['subject'] = '[WP Email Debug] Mail Check';
$modeEmail['issues'] = array(
    array( 'level' => 'error', 'code' => 'transport_probe_failed', 'message' => 'SMTP connection was refused.', 'detail' => 'Details: Connection refused', 'result' => 'Check the SMTP host and port.' ),
);
$checkModeLog = $modeWriter->write( $modeEmail );
assert_true( false !== strpos( basename( $checkModeLog ), '_CHECK-HAS-ISSUES_wp-email-debug-mail-check.log' ), 'Mail-check logs use a clear CHECK-HAS-ISSUES filename token.' );
remove_tree( $modeLogRoot );

// Compact call traces are rendered only when present.
$traceLogRoot = temp_dir( 'wp-email-debug-trace-log' );
$traceWriter = new EmailLogWriter( $traceLogRoot );
$traceEmail = $cleanIssueEmail;
$traceEmail['subject'] = 'Trace Test';
$traceEmail['call_trace'] = array(
    'Origin: wp-content/themes/mytheme/functions.php:57',
    'WC_Email->send()',
    'WC_Emails->send_transactional_email()',
);
$traceLog = $traceWriter->write( $traceEmail );
$traceContents = file_get_contents( $traceLog );
assert_true( false !== strpos( $traceContents, 'CALL TRACE' . PHP_EOL . str_repeat( '-', 68 ) ), 'Logs always support the compact CALL TRACE section.' );
assert_true( false !== strpos( $traceContents, 'Origin: wp-content/themes/mytheme/functions.php:57' ), 'Call trace starts with a labeled wp_mail origin.' );
assert_true( false !== strpos( $traceContents, 'Called by:' ), 'Call trace labels meaningful outer callers.' );
assert_true( false !== strpos( $traceContents, '→ WC_Email->send()' ), 'Call trace renders outer callables compactly.' );
assert_true( false === strpos( $traceContents, 'require_once()' ) && false === strpos( $traceContents, 'include()' ), 'Call trace does not render bootstrap include/require noise.' );
remove_tree( $traceLogRoot );

// Duplicate detection marks, but does not block, the second matching email.
$duplicateRoot = temp_dir( 'wp-email-debug-duplicates' );
$duplicateState = $duplicateRoot . '/state';
$duplicateSession = new SessionManager( $duplicateState, 'duplicate-site' );
$duplicateToken = $duplicateSession->start();
$duplicatePayload = array(
    'token' => $duplicateToken,
    'status' => 'successful',
    'captured_at' => 1760000200.0,
    'subject' => 'Duplicate Test',
    'from' => 'WordPress <wordpress@example.test>',
    'to' => array( 'example@example.com' ),
    'cc' => array(), 'bcc' => array(), 'reply_to' => array(),
    'content_type' => 'text/plain', 'charset' => 'UTF-8',
    'body_b64' => base64_encode( 'Same body' ), 'alt_body_b64' => '',
    'attachments' => array(), 'issues' => array(),
    'source' => array( 'label' => 'mytheme', 'file' => '/srv/www/wp-content/themes/mytheme/functions.php', 'line' => 10 ),
    'request' => array( 'method' => 'GET', 'path' => '/', 'type' => 'frontend' ),
);
file_put_contents( $duplicateSession->getSpoolDirectory() . '/001.json', json_encode( $duplicatePayload ) );
$duplicatePayload['captured_at'] = 1760000200.8;
file_put_contents( $duplicateSession->getSpoolDirectory() . '/002.json', json_encode( $duplicatePayload ) );
$duplicateReader = new SpoolReader( $duplicateSession->getSpoolDirectory(), $duplicateToken );
$duplicateLogs = new EmailLogWriter( $duplicateRoot . '/logs' );
$duplicateConsole = new Console( false );
$duplicateListener = new WpEmailDebug\Listener( $duplicateConsole, $duplicateSession, $duplicateReader, $duplicateLogs );
assert_same( 2, $duplicateListener->drain(), 'Duplicate detection still consumes both email captures.' );
assert_same( 1, $duplicateListener->getDuplicateCount(), 'Second identical email inside the duplicate window is counted as a possible duplicate.' );
assert_same( 1, $duplicateListener->getHasIssuesCount(), 'Possible duplicate changes only the second successful email to Has Issues.' );
$duplicateIssueLogs = glob( $duplicateRoot . '/logs/*_HAS-ISSUES_*.log' );
assert_same( 1, count( $duplicateIssueLogs ), 'Possible duplicate receives a HAS-ISSUES log rather than being blocked.' );
assert_true( false !== strpos( file_get_contents( $duplicateIssueLogs[0] ), 'Possible duplicate email detected.' ), 'Duplicate log explains the detected duplicate.' );
$duplicateSession->stop();
remove_tree( $duplicateRoot );

// Controlled test runner uses wp_mail but consumes the safe capture payload instead of the real transport.
$testRunnerRoot = temp_dir( 'wp-email-debug-test-runner' );
$testRunnerScript = $testRunnerRoot . '/runner.php';
$testRunnerLogDir = $testRunnerRoot . '/logs';
$testRunnerSpoolDir = $testRunnerRoot . '/spool';
mkdir( $testRunnerSpoolDir, 0700, true );
file_put_contents( $testRunnerScript, <<<'PHPTEST'
<?php
namespace {
    final class WP_CLI {
        public static function line( $message = '' ) {}
        public static function warning( $message ) {}
        public static function colorize( $message ) { return preg_replace( '/%[A-Za-z0-9_]/', '', $message ); }
    }
    function home_url( $path = '' ) { return 'https://example.test' . $path; }
    function wp_date( $format, $timestamp = null ) { return gmdate( $format, null === $timestamp ? time() : $timestamp ); }
    function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
    function wp_mail( $to, $subject, $message, $headers = array(), $attachments = array() ) {
        $payload = array(
            'version' => 1,
            'token' => $GLOBALS['spool_token'],
            'status' => 'successful',
            'captured_at' => microtime( true ),
            'subject' => $subject,
            'from' => 'WordPress <wordpress@example.test>',
            'to' => array( $to ),
            'cc' => array(), 'bcc' => array(), 'reply_to' => array(),
            'content_type' => 'text/plain', 'charset' => 'UTF-8',
            'body_b64' => base64_encode( $message ), 'alt_body_b64' => '',
            'attachments' => array(), 'issues' => array(), 'error' => array(),
            'transport' => array( 'mailer' => 'mail', 'host' => '', 'port' => 0, 'smtp_auth' => false, 'smtp_secure' => '' ),
            'transport_probe' => array( 'enabled' => false, 'status' => 'not_run' ),
            'source' => array( 'type' => 'cli', 'label' => 'WP Email Debug', 'file' => '', 'line' => 0 ),
            'request' => array( 'method' => 'CLI', 'path' => 'wp email-debug test', 'type' => 'cli' ),
        );
        file_put_contents( $GLOBALS['spool_dir'] . '/001.json', json_encode( $payload ) );
        return true;
    }
}
namespace WpEmailDebugTest {
    require $argv[1] . '/src/Console.php';
    require $argv[1] . '/src/EmailLogWriter.php';
    require $argv[1] . '/src/SpoolReader.php';
    require $argv[1] . '/src/MailHookInspector.php';
    require $argv[1] . '/src/TestMailRunner.php';
    $GLOBALS['spool_dir'] = $argv[3];
    $GLOBALS['spool_token'] = 'test-token';
    $runner = new \WpEmailDebug\TestMailRunner(
        new \WpEmailDebug\Console( false ),
        new \WpEmailDebug\EmailLogWriter( $argv[2] ),
        new \WpEmailDebug\SpoolReader( $argv[3], 'test-token' ),
        new \WpEmailDebug\MailHookInspector()
    );
    $payload = $runner->run();
    if ( 'successful' !== $payload['status'] ) exit( 2 );
    $files = glob( $argv[2] . '/*.log' );
    if ( 1 !== count( $files ) ) exit( 3 );
    if ( false === strpos( basename( $files[0] ), '_TEST-SUCCESSFUL_wp-email-debug-pipeline-test.log' ) ) exit( 4 );
    $contents = file_get_contents( $files[0] );
    if ( false === strpos( $contents, 'completed through the safe test transport' ) ) exit( 5 );
    exit( 0 );
}
PHPTEST
);
$testRunnerOutput = array();
$testRunnerStatus = 0;
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $testRunnerScript ) . ' ' . escapeshellarg( $root ) . ' ' . escapeshellarg( $testRunnerLogDir ) . ' ' . escapeshellarg( $testRunnerSpoolDir ) . ' 2>&1', $testRunnerOutput, $testRunnerStatus );
assert_same( 0, $testRunnerStatus, 'wp email-debug test uses the controlled capture path and writes a TEST-SUCCESSFUL log without real delivery. ' . implode( ' | ', $testRunnerOutput ) );
remove_tree( $testRunnerRoot );

// Test runner converts unexpected wp_mail() exceptions into FAILED logs instead of losing diagnostics.
$testFailureRoot = temp_dir( 'wp-email-debug-test-failure' );
$testFailureScript = $testFailureRoot . '/runner.php';
$testFailureLogDir = $testFailureRoot . '/logs';
$testFailureSpoolDir = $testFailureRoot . '/spool';
mkdir( $testFailureSpoolDir, 0700, true );
file_put_contents( $testFailureScript, <<<'PHPTEST'
<?php
namespace {
    final class WP_CLI { public static function line($m=''){} public static function warning($m){} public static function colorize($m){return preg_replace('/%[A-Za-z0-9_]/','',$m);} }
    function home_url($p=''){return 'https://example.test'.$p;} function wp_date($f,$t=null){return gmdate($f,null===$t?time():$t);} function wp_normalize_path($p){return str_replace('\\','/',$p);}
    function wp_mail($to,$subject,$message,$headers=array(),$attachments=array()){ throw new \RuntimeException('Mailer bootstrap exploded'); }
}
namespace WpEmailDebugTestFailure {
    require $argv[1].'/src/Console.php'; require $argv[1].'/src/EmailLogWriter.php'; require $argv[1].'/src/SpoolReader.php'; require $argv[1].'/src/MailHookInspector.php'; require $argv[1].'/src/TestMailRunner.php';
    $runner=new \WpEmailDebug\TestMailRunner(new \WpEmailDebug\Console(false),new \WpEmailDebug\EmailLogWriter($argv[2]),new \WpEmailDebug\SpoolReader($argv[3],'test-token'),new \WpEmailDebug\MailHookInspector());
    $payload=$runner->run(); if('failed'!==$payload['status']) exit(2); if(false===strpos($payload['error']['message'],'Mailer bootstrap exploded')) exit(3);
    $files=glob($argv[2].'/*.log'); if(1!==count($files) || false===strpos(basename($files[0]),'_TEST-FAILED_')) exit(4); exit(0);
}
PHPTEST
);
$testFailureOutput=array(); $testFailureStatus=0;
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($testFailureScript).' '.escapeshellarg($root).' '.escapeshellarg($testFailureLogDir).' '.escapeshellarg($testFailureSpoolDir).' 2>&1',$testFailureOutput,$testFailureStatus);
assert_same(0,$testFailureStatus,'Unexpected wp_mail exceptions become TEST-FAILED logs. '.implode(' | ',$testFailureOutput));
remove_tree($testFailureRoot);

// Hook inspector identifies exact pre_wp_mail blockers and recipient rerouting callbacks.
$GLOBALS['wp_filter'] = array();
$preHook = new stdClass();
$blockingCallback = function ( $return, $atts ) { return false; };
$preHook->callbacks = array(
    10 => array(
        'blocker' => array( 'function' => $blockingCallback, 'accepted_args' => 2 ),
    ),
);
$GLOBALS['wp_filter']['pre_wp_mail'] = $preHook;
$inspector = new WpEmailDebug\MailHookInspector();
$inspector->install();
$wrappedBlocker = $GLOBALS['wp_filter']['pre_wp_mail']->callbacks[10]['blocker']['function'];
$wrappedBlocker( null, array( 'to' => 'wp-email-debug@example.com' ) );
$blockerIssues = $inspector->issues( 'wp-email-debug@example.com' );
assert_same( 'pre_wp_mail_short_circuit', $blockerIssues[0]['code'], 'Hook inspector identifies pre_wp_mail short-circuit callbacks as blocking issues.' );
assert_true( false !== strpos( $blockerIssues[0]['detail'], 'Callback:' ), 'Blocking hook issue identifies the callback responsible.' );
$inspector->restore();
assert_true( $GLOBALS['wp_filter']['pre_wp_mail']->callbacks[10]['blocker']['function'] === $blockingCallback, 'Hook inspector restores the original callback after the test.' );

$GLOBALS['wp_filter'] = array();
$wpMailHook = new stdClass();
$rerouteCallback = function ( $args ) { $args['to'] = 'sink@example.com'; return $args; };
$wpMailHook->callbacks = array(
    10 => array(
        'reroute' => array( 'function' => $rerouteCallback, 'accepted_args' => 1 ),
    ),
);
$GLOBALS['wp_filter']['wp_mail'] = $wpMailHook;
$rerouteInspector = new WpEmailDebug\MailHookInspector();
$rerouteInspector->install();
$wrappedReroute = $GLOBALS['wp_filter']['wp_mail']->callbacks[10]['reroute']['function'];
$wrappedReroute( array( 'to' => 'wp-email-debug@example.com', 'subject' => 'x', 'message' => 'x', 'headers' => array(), 'attachments' => array() ) );
$rerouteIssues = $rerouteInspector->issues( 'wp-email-debug@example.com' );
assert_same( 'wp_mail_recipient_changed', $rerouteIssues[0]['code'], 'Hook inspector flags plugins or theme code that reroute the test recipient.' );
$rerouteInspector->restore();
unset( $GLOBALS['wp_filter'] );

// Listener runtime also records pre_wp_mail short-circuits instead of silently missing blocked email.
$runtimeSource = file_get_contents( __DIR__ . '/../runtime/bridge-template.php' );
assert_true( false !== strpos( $runtimeSource, "'pre_wp_mail_short_circuit'" ), 'Runtime records pre_wp_mail short-circuits as failed email events.' );
assert_true( false !== strpos( $runtimeSource, "'pre_wp_mail'," ), 'Runtime installs a pre_wp_mail observer for blocked email.' );

// New transport diagnostics include specific SMTP failure categories and MIME-size protection.
$runtimeSource = file_get_contents( __DIR__ . '/../runtime/bridge-template.php' );
assert_true( false !== strpos( $runtimeSource, 'SMTP connection was refused.' ), 'SMTP probe classifies connection-refused failures explicitly.' );
assert_true( false !== strpos( $runtimeSource, 'SMTP authentication failed.' ), 'SMTP probe classifies authentication failures explicitly.' );
assert_true( false !== strpos( $runtimeSource, 'SMTP TLS negotiation failed.' ), 'SMTP probe classifies TLS failures explicitly.' );
assert_true( false !== strpos( $runtimeSource, "'message_large'" ), 'Capture transport adds an issue for unusually large final MIME messages.' );

// Main message status vocabulary is intentionally limited to successful / has_issues / failed.
$productionStatusSources = file_get_contents( __DIR__ . '/../runtime/bridge-template.php' )
    . file_get_contents( __DIR__ . '/../src/EmailLogWriter.php' )
    . file_get_contents( __DIR__ . '/../src/Console.php' )
    . file_get_contents( __DIR__ . '/../src/Listener.php' );
assert_true( false === strpos( $productionStatusSources, "'status' => 'captured'" ), 'Production code does not emit the deprecated captured main status.' );
assert_true( false === strpos( $productionStatusSources, "\$payload['status'] = 'issues'" ), 'Production code does not emit the deprecated issues main status.' );
assert_true( false === strpos( $productionStatusSources, "return 'INTERCEPTED';" ), 'Production log filenames do not expose the deprecated INTERCEPTED status token.' );

fwrite( STDOUT, sprintf( "%d tests, %d failures\n", $tests, $failures ) );
exit( $failures > 0 ? 1 : 0 );
