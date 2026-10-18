# 10. Operations

## Monitoring

| Signal | Where | Act when |
|---|---|---|
| Overall health | `php artisan wms:health` (exit 1 on red; wire into the monitor), `/warehouse/health` | Any red |
| Scheduled commands | Heartbeats per command (`wms_heartbeats`); stale = not finished within 3× its interval | Red: the scheduler or that command has stopped |
| Queue | Oldest waiting job age; failed jobs in 24 h | Waiting > 30 min, or failures |
| Events and webhooks | Backlog > 10 min, dead letters | Any dead letter |
| ERP messages | Integration events `DEAD` | Any |
| Offline scans | Conflicts waiting | Daily review |
| Ledger | `wms:verify-ledger` nightly; result on the health page | Any difference |
| Audit chain | `wms:verify-audit` nightly | Any break — treat as a security incident |
| Accounting | `wms:reconcile` nightly → Accounting screen issues | Open issues |

Every scheduled command and its timing: [generated/schedule-and-policies.md](generated/schedule-and-policies.md).

## Backups and recovery (WMS-213)

- The warehouse lives in the platform's PostgreSQL database, including documents (`tenant_files`, encrypted). The platform's database backup covers the ledger, configuration, audit history and document contents together; there is no separate file store to back up.
- **RPO and RTO are business decisions** to record here: RPO ____ , RTO ____ (agreed by ____ on ____).
- A backup is trusted only after a restore drill: restore to staging, then `php artisan wms:verify-restore` — ledger agrees with projections, audit chain whole, every referenced document present; it lists what was in flight (undelivered events, open ERP messages, offline conflicts, half-finished API requests, unfinished reports).
- After a restore: events not yet delivered are delivered again by `wms:outbox` (receivers de-duplicate on `event_id`); ERP messages retry by their idempotency keys; devices resend queued offline scans, which are applied once or held as conflicts; API clients retrying with the same `Idempotency-Key` receive the stored response or redo work that was lost.

## Runbooks

**A dead letter (event not delivered).** `/warehouse/integrations/events?state=dead` shows the error. Fix the receiver, then *Send again*. If an endpoint was switched off after 20 failures, switch it back on there.

**An ERP message failed.** `/warehouse/integrations/systems` → Messages and errors. Usually a missing mapping: add it, then *Try again*. If the message is wrong at source, *Set aside* with a reason.

**A ledger difference.** The health page lists it (kind, item, warehouse, figures). A projection differing from the ledger is never edited by hand: find the cause (a manual database change, a failed migration), then correct through the ledger — an adjustment with approval, or a count. Re-run `wms:verify-ledger`.

**The audit chain is broken.** Someone changed the ledger outside the application (the trigger had to be disabled). Preserve the database, compare with the last backup to find the change, and treat it as a security incident.

**An offline conflict.** `/warehouse/m/conflicts`: decide each held scan — apply, or discard with a reason — after checking the bin.

**An emergency override was used.** Managers are alerted at once; review it on Audit and governance → Emergency overrides, recording what the review found. The alert stays open until then.

**A carrier event did not arrive.** Record tracking by hand on the order; a delivered event with the recipient's name records proof of delivery.

**Retention.** `wms:retention` runs nightly per company settings (Audit and governance → Retention). `--dry-run` shows what would go.

## Support facts

- Tests run against a separate database (`DB_DATABASE=…`), never the development or production database.
- Notifications: in the app always; e-mail where a rule adds it and mail is configured. Delivery per channel is on the Alerts screen.
- The warehouse assistant answers from records and drafts actions; it never approves, posts or writes off.
