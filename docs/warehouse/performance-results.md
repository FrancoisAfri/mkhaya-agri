# Warehouse benchmark results

Generated 2026-10-12T23:09:29+00:00 by `php artisan wms:benchmark`. **These figures describe the machine below, not production.** Targets (WMS-210) are validated only on the deployment architecture.

Environment: local, PHP 8.4.22, PostgreSQL 18.4 (Homebrew) on aarch64-apple-darwin25.6.0, compiled by Apple clang version 21.0.0 (clang-2100.1.1.101), 64-bit, CPU Apple M1, queue database.

Volumes: 2000 items, 3 warehouses, 600 bins, 6390 positions, 6500 movements (seeded in 79.6 s).

| Operation | n | p50 ms | p95 ms | p99 ms | max ms | per second | errors |
|---|---|---|---|---|---|---|---|
| stock_lookup | 200 | 2.8 | 3.3 | 4.6 | 7.9 | 348.2 | 0 |
| barcode_scan | 200 | 0.6 | 0.7 | 0.8 | 2.4 | 1661.7 | 0 |
| receipt_posting | 200 | 8.7 | 11.6 | 13.8 | 21.4 | 109.4 | 0 |
| bin_move | 100 | 12.1 | 13.8 | 18.3 | 18.3 | 80.5 | 0 |
| allocation | 100 | 11.1 | 18.8 | 26.1 | 26.1 | 82.3 | 0 |
| task_queue | 50 | 2 | 2.3 | 4.8 | 4.8 | 497.4 | 0 |
| inventory_dashboard | 10 | 33.2 | 37.2 | 37.2 | 37.2 | 29.5 | 0 |
| report_stock_on_hand | 3 | 27.9 | 57.9 | 57.9 | 57.9 | 26.6 | 0 |

Concurrency: 8 processes × 25 operations on the same 5 items (half allocating 7 units per order, half receiving): 200 operations, p50 36.2 ms, p95 127.8 ms, p99 264.3 ms, 0 error(s), 0 deadlock(s), 0 refusal(s). An order short of stock waits as a backorder rather than failing; the ledger check below (which includes over-reservation) is what proves no unit was promised twice.

Ledger after the run: balances, positions, on-hand and reservations all agree with the ledger.

Peak memory 94.5 MB.
