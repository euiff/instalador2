# Igreja Master 3.7.6 validation

- 38 directory service checks using more than 600 fixture members: complete pagination/export, all filters, combined filters, tenant validation, secretary access, literal wildcard search, legacy department assignments, CSV protection and printable filter labels.
- 26 PHP page/CSV/print checks: all results beyond the old 500 cap, filtered counts, full export regardless of page, complete print list, escaped names, foreign filters and direct access restrictions.
- 122 regression checks across budget, automation and departmental reports.
- Syntax validation of 61 cumulative PHP files using PHP 8.3.

Tests use SQLite with a MONTH function adapter and fixture authentication. They do not validate production MySQL, live installation or visual browser layout. No WhatsApp messages were sent. No database migration is introduced.
