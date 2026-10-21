# Mkhaya Agri — reliability, offline work and recovery

Phase 2 §15.3–15.5. This is the runbook for operations staff. It sets proposed targets, which need agreeing with the client, and says how each one is checked.

## 1. Service-level objectives (proposed, §15.3)

| Objective | Target | How it is measured |
|---|---|---|
| Routine transactions (save a timesheet, issue an input, record a reading) | p95 ≤ 2 s under the agreed test load | Load test against realistic farm sizes, users and data volumes, before go-live and after major releases |
| Duplicate financial, attendance or stock transactions on retry | Zero | Idempotency keys on every posting, clock event, stock movement, offline operation and integration message; tests in `tests/Feature/Agri` |
| External service failures (weather, devices, accounting export) | Recovered without loss or duplication | Integration log (`/agri/integrations`): failed messages retry with back-off and wait for an administrator once dead |
| Background jobs | Monitored | Each scheduled job writes to `storage/logs/agri-*.log`; alert on a missing run or a non-zero exit |

Scheduled jobs: `agri:scan-alerts` (daily 04:30), `agri:predictions` (daily 04:45), `agri:weather` (every 3 h), `agri:integrations-retry` (every 15 min).

## 2. Offline-first field work (§15.4)

**What can be captured offline.** These run through `POST /agri/my-work/sync` (`OfflineSync`):

- tasks and field activities (start, complete);
- scouting observations;
- clock in and out;
- work orders (start, pause, log, submit);
- piecework;
- safety reports;
- pre-start checks;
- store readings;
- safety checklists.

**Never offline.** These are refused by the phone (`OfflineSync::ONLINE_ONLY`):

- approvals of any kind;
- fuel and stock issues;
- harvest into store;
- payroll handoff;
- sales, dispatch and delivery;
- budget and contract changes;
- returning a machine to service.

**Storage on the device.** Queued changes are kept in the browser's localStorage, per user (`offlineQueue.ts`), until the server accepts or rejects them. They are **not encrypted on the device**, so the phone's own lock is what protects them. Nothing financial, and no other person's data, is ever queued. Encrypting the queue is an open item.

**Duplicates.** Every queued change carries a `client_id`, and the server records it in `agri_offline_ops`. A resend returns the first result, marked `duplicate`, and nothing is applied twice. The SHEQ reports, readings and checklists below also carry their own ids into their target tables.

**Conflicts.** Each change is re-checked on arrival under today's rules: permissions, farm access, and the record's current state. A change that no longer applies is **rejected with a reason**, never forced through. Examples: a job already completed by someone else, a meter reading lower than the current one, a machine taken out of service. Captured times later than the server clock allows are clamped to now.

**What the user sees.** Each queued change shows as pending, applied or rejected, with the server's message.

## 3. Backup and disaster recovery (§15.5)

A backup job finishing is not proof. A backup counts only once a restore of it has been verified.

| Item | Requirement |
|---|---|
| Database | Automated PostgreSQL backups: full daily, plus continuous WAL archiving (point-in-time recovery) |
| Documents | Farm documents, evidence and photos are stored **in the database** (base64), so the database backup covers them |
| Off-site | Copied to a second region or provider, not the hosting account's own storage |
| Encryption and access | Encrypted at rest and in transit; restore access limited to named operators; every restore logged |
| Retention | 35 daily, 12 monthly, 7 yearly (adjust to the client's legal retention) |
| Recovery point objective (RPO) | ≤ 15 minutes (WAL archiving) |
| Recovery time objective (RTO) | ≤ 4 hours to a working, verified system |
| Restore tests | Monthly, into an isolated environment, using the procedure below |

### Restore test procedure

1. On production, record a fingerprint:
   ```
   php artisan agri:integrity --save=storage/app/integrity-YYYY-MM-DD.json
   ```
   The command records row counts and money and quantity totals for the farm, stock and ledger tables. It also checks every document's checksum and verifies the ledger's hash chain.
2. Restore the backup taken at that point into an isolated database.
3. On the restored copy, compare:
   ```
   php artisan agri:integrity --compare=storage/app/integrity-YYYY-MM-DD.json
   ```
4. **Pass** only when the command prints `RESTORE VERIFIED`. That means every table and total matches, no document file differs from its checksum, and the ledger chain verifies. Any difference is listed table by table.
5. Record the result, timings (RTO achieved) and backup age (RPO achieved) in the DR log.

### Incident procedure (summary)

1. Declare the incident.
2. Freeze writes: put the site in maintenance mode.
3. Pick the restore point.
4. Restore into a fresh database.
5. Run `agri:integrity --compare` against the last good fingerprint.
6. Switch over.
7. Re-run `agri:integrations-retry` so queued device messages flow in without duplicates.
8. Tell users which period, if any, must be re-entered.

## 4. Audit and isolation (§15.1–15.2)

- **Tenant isolation:**
  - application scopes (organisation and farm access, default deny);
  - Postgres row-level security on every agri table;
  - all verified by `tests/Feature/Agri/TenantIsolationTest.php` and `AgriIntelligenceTest`.
- **Audit:**
  - sensitive changes are logged with who, when and what changed;
  - a database trigger (`activity_log_protect`) refuses any update or delete of an audit record;
  - the only exception is a retention purge that explicitly sets `app.audit_purge = 'on'` for its transaction;
  - verified by `AuditProtectionTest`.
