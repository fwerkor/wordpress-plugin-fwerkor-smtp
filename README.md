# FWERKOR SMTP

A small SMTP transport plugin for FWERKOR WordPress sites.

## Features

- Configures WordPress PHPMailer through `phpmailer_init`.
- SMTP AUTH with STARTTLS or implicit TLS.
- TLS certificate verification is always enabled.
- Migrates existing Easy WP SMTP configuration on first activation.
- Keeps credentials in WordPress options (or use `FWERKOR_SMTP_PASSWORD` in server configuration).
- Minimal settings page under **Settings → FWERKOR SMTP**.

License: MIT.
