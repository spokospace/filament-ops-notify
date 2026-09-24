# Security policy

## Supported versions

Security fixes go to the latest minor release of the current major (`1.x`). Older releases do not
receive fixes; upgrade to the latest release to stay covered.

| Version | Supported |
| ------- | --------- |
| 1.x     | Yes       |
| < 1.0   | No        |

## Reporting a vulnerability

Please do not open a public issue for a security problem.

Report it privately through
[GitHub's private vulnerability reporting](https://github.com/spokospace/filament-ops-notify/security/advisories/new),
or by email to [contact@spoko.space](mailto:contact@spoko.space). Include the package version, the
steps to reproduce and, if you have one, a suggested fix.

You will get an acknowledgement within 3 working days and a fix or a mitigation as soon as one is
ready. The advisory is published together with the release that fixes it, and you are credited
unless you ask not to be.

## Scope

This package stores a Telegram bot token in your database (encrypted) and sends messages to the
chat you configure. Problems in that handling, in the panel pages it adds, or in the notification
forwarding are in scope. Vulnerabilities in Laravel, Filament or Telegram itself should be reported
to those projects.
