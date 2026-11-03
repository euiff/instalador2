# Igreja Master 3.7.5 validation

- 22 isolated report/portal checks: opening and closing balances, date boundaries, reversals, wrong-account and cross-tenant exclusion, inactive and empty departments, financial access, leader scopes and member identity.
- 19 PHP page/CSV rendering checks: report figures and controls, CSV values and statuses, direct financial URL restriction, personal exports, department exports and unauthorized filter handling.
- 81 regression checks: 37 automation service, 17 automation template and 27 budget checks.
- Syntax validation of 59 PHP files using PHP 8.3.

Tests use isolated SQLite fixtures, test authentication and MySQL dialect/function adapters as needed. They do not validate production MySQL, live installation, browser visual appearance or the live scheduler. No WhatsApp messages were sent. No database migration is introduced.
