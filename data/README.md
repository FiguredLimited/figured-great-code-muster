# Fixture data

Everything in here is **fictional** — farms, people, amounts, the lot.

| File / folder | Feeds |
| --- | --- |
| `categories.csv` | Bank Coding, Invoice Entry (the 12-account chart) |
| `farms.csv` | Monthly Report |
| `bank_transactions.csv` | Bank Coding |
| `report_lines.csv` | Monthly Report |
| `report_commentary.csv` | Monthly Report (the one worked example) |
| `weather.csv` | Monthly Report (regional monthly climate summaries) |
| `emails/*.txt` | Inbox |
| `invoices/*.txt` | Invoice Entry |
| `stock_classes.csv`, `stock_records.csv`, `stock_movements.csv` | Stock Reconciliation |

The seeders in `database/seeders/` load all of this on `php artisan
migrate:fresh --seed`.

Email fixtures are plain text: header lines, a blank line, then the body.
An optional `--- reply sent <datetime> ---` line marks a reply that has
already been sent (the worked example on the Inbox page).
