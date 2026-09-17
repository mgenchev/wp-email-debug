# Changelog

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
