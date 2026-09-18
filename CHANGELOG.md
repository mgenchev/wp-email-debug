# Changelog

## Unreleased

### Added

- Controlled `wp email-debug test` pipeline inspection can attribute blocking `pre_wp_mail` callbacks, mail-hook exceptions, and recipient rewrites to the responsible callback/source when available.
- Listener runtime records `pre_wp_mail` short-circuits instead of silently missing email that is stopped before PHPMailer.
- Detection for custom `wp_mail()` replacements when the standard WordPress/PHPMailer path cannot be verified.

### Changed

- `wp email-debug test` is now a safe WordPress mail-pipeline test rather than a real delivery test. It uses a fixed test recipient and temporary capture transport, so PHP `mail()`, Sendmail, and SMTP availability do not create false test failures.
- Transport and connectivity diagnostics are now explicitly owned by `wp email-debug check`; controlled test sessions skip transport configuration checks.
- `pre_wp_mail` returning `false` is classified as `Failed`; a non-null success/bypass is classified as `Has Issues` because alternate delivery cannot be verified.

## 0.2.0 - 2026-09-18

### Added

- `wp email-debug test` one-step real delivery test using the site's WordPress `admin_email`, with fixed test content and clearly prefixed `TEST-*` logs.
- `wp email-debug check` one-shot mail configuration / SMTP pre-delivery check that does not send SMTP `DATA`, with `CHECK-*` logs.
- Automatic possible-duplicate detection for identical recipient/subject/body captures within three seconds.
- Compact call trace in captured logs without requiring an extra flag.
- More specific SMTP probe failure classification for DNS, connection refused, timeout, TLS/certificate, authentication, sender, and recipient failures.
- Final MIME-size warning for messages larger than 10 MB.
- Duplicate count in the shutdown summary when duplicates were detected.

### Changed

- Call traces are now captured at the `wp_mail()` call site and reduced to an `Origin` plus meaningful application callers; bootstrap `include`/`require` noise is omitted.
- Stale generated MU runtimes now bail out before declaring runtime functions. `wp email-debug check` can remove an older stale managed bridge and relaunch itself in a clean WP-CLI process instead of failing with a PHP fatal error.
- PHP `mail()` instantiation failures now explain that the real server/PHP mail transport failed even though listener captures can still be `Successful` because external delivery is blocked during capture.
- The real test command refuses to run while an active listener is blocking external mail.
- `wp email-debug test` reports success only as acceptance by the configured WordPress mail transport, not guaranteed inbox delivery.

## 0.1.0 - 2026-09-17

### Added

- `wp email-debug` long-running WP-CLI listener for outgoing WordPress `wp_mail()` / PHPMailer email.
- Temporary token-, fingerprint-, and heartbeat-protected MU runtime for cross-process interception.
- Safe PHPMailer capture transport that prevents normal external delivery while the listener is active.
- One-second spool polling with one timestamped log file per email.
- Three consistent message statuses: `Successful`, `Has Issues`, and `Failed`; filename tokens are `SUCCESSFUL`, `HAS-ISSUES`, and `FAILED`.
- Focused `ISSUES` diagnostics for conditions that can prevent delivery or cause incomplete/different email output.
- Preflight checks for recipients, attachments, embedded files, malformed headers, SMTP configuration, PHP `mail()`, Sendmail/Qmail, TLS/OpenSSL, and unsupported transports.
- `wp_mail_failed` capture with WordPress/PHPMailer error details.
- Optional `--probe-transport` SMTP pre-delivery probe covering connect, TLS, authentication, `MAIL FROM`, and `RCPT TO` without sending SMTP `DATA`.
- Per-email metadata including recipients, sender, content type, charset, safe transport details, source classification, request context, and attachment metadata.
- `EMAIL CONTENT` and conditional `ALTERNATIVE CONTENT` log sections.
- Session shutdown summary with `Successful`, `Has Issues`, and `Failed` counts.
- Stale-session cleanup, symlink protections, bounded message bodies, and safe log permissions.
- Warning when `pre_wp_mail` filters may provide an alternate delivery path.

### Notes

- External APIs that bypass `wp_mail()` / PHPMailer, such as direct Mailgun, Amazon SES, or SendGrid API calls, are outside the interception boundary.
- The SMTP probe intentionally does not send `DATA`, so it cannot verify final content acceptance or inbox placement.
