# Security Policy

## Reporting a Vulnerability

Please do **not** open a public GitHub issue for security vulnerabilities.

Report privately via GitHub's "Report a vulnerability" (Security tab → Advisories) on this
repository, or email **security@zhylon.net**. Include steps to reproduce and, if possible,
the affected version. Expect an initial response within 5 business days.

## Supported Versions

Only the latest tagged major version receives security fixes.

## Scope Notes

This package holds OAuth2 `client_credentials` secrets in-memory at runtime via Laravel
config (`.env`-sourced). It never writes secrets to logs, cache values, or exception
messages. If you believe a secret has leaked through this package (e.g. via an exception
message, log line, or cached value), please report it as a vulnerability — that is a bug
in this package, not expected behavior.
