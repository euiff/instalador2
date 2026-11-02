# Igreja Master 3.7.4 validation

- 37 isolated automation tests: worker failure isolation, active tenant filtering, lock contention and release, scoped/global logs, access restrictions, queue scope, heartbeat age/status and history pagination.
- 17 PHP template rendering checks using fixture authentication/data: Master controls, client controls, absence of server command and other tenant data, direct URL rejection, filters, clean PHP output.
- 27 budget regression checks from version 3.7.3.
- Syntax verification of 57 PHP files in the cumulative release using PHP 8.3.

Tests use SQLite with minimal MySQL function/dialect adapters and fake workers. They do not send WhatsApp messages and do not validate the production MySQL database or live cPanel scheduler. Browser visual validation could not run because Chromium was unavailable. This release is published to the update channel; production installation and scheduler heartbeat still require confirmation after installation.
