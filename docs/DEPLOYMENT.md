# Mkhaya Hub — Installation & Deployment Guide

Three scenarios are covered:

1. **[A] Test/staging site on a production server** — for UAT before go-live
2. **[B] Live site, STANDALONE (self-hosted)** — one customer, licensed
3. **[C] Live site, SAAS (multi-tenant)** — many companies, plans & billing

---

## 0. Requirements (all scenarios)

| Component | Version / notes |
|---|---|
| PHP | 8.4 (extensions: pdo_pgsql, pgsql, mbstring, intl, zip, gd, fileinfo, curl, bcmath) |
| PostgreSQL | 15+ — **required**. Tenant isolation uses Postgres Row-Level Security; MySQL is not supported. |
| Composer | 2.x |
| Node.js | 20+ (only needed where you build assets) |
| Web server | Apache (docroot → `public/`) or Nginx |
| Supervisor / cron | for queue workers + scheduler |

**PHP memory:** artisan commands on this app need headroom. Set `memory_limit = 1024M`
for CLI (`/etc/php.d/` or run `php -d memory_limit=1G artisan ...`).

**Postgres user:** create a dedicated role (e.g. `mkhaya_app`) that **owns** the app
database. Do **not** grant `BYPASSRLS` or superuser — the tables are created with
`FORCE ROW LEVEL SECURITY`, so isolation applies even to the owner, which is what
we want.

```sql
CREATE ROLE mkhaya_app LOGIN PASSWORD '********';
CREATE DATABASE mkhaya_hub OWNER mkhaya_app;
```

### 0.1 Base install steps (identical for A/B/C)

```bash
# 1. Get the code
git clone <repo> mkhaya-hub && cd mkhaya-hub
git checkout main            # or the release tag

# 2. PHP dependencies
composer install --no-dev --optimize-autoloader

# 3. Frontend build
#    NOTE: Linux is case-sensitive. Always build from a clean checkout.
npm ci && npm run build
#    (Alternative: build on a CI/dev machine and deploy public/build/.)

# 4. Environment
cp .env.example .env
php artisan key:generate
#    ...then edit .env per the scenario sections below.

# 5. Storage permissions
chmod -R ug+rw storage bootstrap/cache
chown -R <webuser>:<webuser> storage bootstrap/cache

# 6. Database schema + reference data
php -d memory_limit=1G artisan migrate --force
php -d memory_limit=1G artisan db:seed --force          # full seed on a FRESH database
php -d memory_limit=1G artisan storage:recompute        # first per-tenant storage numbers

# 7. Caches
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

### 0.2 `.env` essentials (all scenarios)

```ini
APP_NAME="Mkhaya Hub"
APP_URL=https://your-domain
APP_KEY=            # from key:generate — BACK THIS UP (whistleblower tokens & PII encryption depend on it)

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_DATABASE=mkhaya_hub
DB_USERNAME=mkhaya_app
DB_PASSWORD=********

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local        # KEEP as 'local' — uploads are stored in the DATABASE
                             # (tenant_files) via the custom 'database' filesystem driver.

MAIL_MAILER=smtp             # + your SMTP credentials
```

Optional integrations (leave empty to disable):

```ini
ANTHROPIC_API_KEY=           # AI features (or OPENAI_API_KEY + AI_PROVIDER)
TENDER_DISCOVERY_URL=        # OCDS releases endpoint (SA eTenders) for tenders:discover
```

### 0.3 Queue workers (all scenarios) — REQUIRED

Many features run through queues. Supervisor config (adjust paths/user):

```ini
[program:mkhaya-worker]
command=php -d memory_limit=1G /var/www/mkhaya-hub/artisan queue:work --queue=default,notifications,tenants,mailboxes,tenders,documents --sleep=3 --tries=3 --max-time=3600
numprocs=2
autostart=true
autorestart=true
user=<webuser>
stdout_logfile=/var/www/mkhaya-hub/storage/logs/worker.log

[program:mkhaya-worker-ai]
command=php -d memory_limit=1G /var/www/mkhaya-hub/artisan queue:work --queue=ai-heavy,ai-fast --sleep=3 --tries=2 --max-time=3600
numprocs=1
autostart=true
autorestart=true
user=<webuser>
stdout_logfile=/var/www/mkhaya-hub/storage/logs/worker-ai.log
```

Queues in use: `default, notifications, tenants, mailboxes, tenders, documents, ai-heavy, ai-fast`.
The `tenants` queue carries the per-company fan-out of scheduled jobs (leave accrual,
absence processing, tender discovery, …) — without a worker on it, those crons dispatch
but never execute.

### 0.4 Scheduler (all scenarios) — REQUIRED

```cron
* * * * * cd /var/www/mkhaya-hub && php -d memory_limit=1G artisan schedule:run >> /dev/null 2>&1
```

If you run **multiple app servers**, the schedule entries use `onOneServer()` —
that only works with a **shared cache** (`CACHE_STORE=database` is fine since the DB
is shared).

---

## A. Test/staging site on a production server

Goal: a real-server copy for UAT that can't touch live data or email real people.

1. Use a **subdomain** (e.g. `test.yourdomain.co.za`) with its own vhost → `public/`.
2. Use a **separate database** (`mkhaya_hub_test_site`) and separate `.env`:

```ini
APP_ENV=staging
APP_DEBUG=false              # never true on an internet-facing box
APP_URL=https://test.yourdomain.co.za
DB_DATABASE=mkhaya_hub_staging

MAIL_MAILER=log              # emails go to storage/logs — nobody gets spammed
APP_EDITION=saas             # match whatever you're testing (saas or self_hosted)
```

3. Follow the base install (0.1). `db:seed` gives you the demo/reference data and
   the platform admin login.
4. Keep it private:
   - Add HTTP basic auth at the web-server level, and/or IP-allowlist.
   - `public/robots.txt` → `User-agent: *` / `Disallow: /`.
5. Run its own workers + scheduler (same as 0.3/0.4, pointing at this directory) —
   otherwise queued features will appear broken during UAT.
6. To refresh staging from live later: `pg_dump` live → restore into the staging DB,
   then run `php artisan migrate --force` on staging. Never point staging `.env`
   at the live DB.

---

## B. Live site — STANDALONE (self-hosted, one customer)

One company; module access comes from a **signed Ed25519 licence** instead of a plan.

### B.1 `.env`

```ini
APP_ENV=production
APP_DEBUG=false
APP_EDITION=self_hosted

LICENSE_ENFORCE=true
LICENSE_PUBLIC_KEY=<vendor public key>
LICENSE_REMOTE=false          # true only if you run a licensing authority server
```

### B.2 Licence flow

On **your** (vendor) machine — once ever:

```bash
php artisan license:keygen            # Ed25519 keypair; keep the PRIVATE key offline
```

Per customer install:

```bash
# 1. On the CUSTOMER server — get its fingerprint + domain
php artisan license:fingerprint

# 2. On the VENDOR machine — issue the licence bound to that fingerprint,
#    with the purchased modules & limits
php artisan license:issue ...         # see command help for module/limit flags

# 3. Copy the produced licence file to the customer server
#    (default path storage/app/license — see LICENSE_PATH), then verify:
php artisan license:status
```

### B.3 First company + admin

The full seed creates the default company and platform admin. For a clean
customer-specific setup you can instead create theirs directly:

```bash
php -d memory_limit=1G artisan tenancy:create-company "Customer (Pty) Ltd" admin@customer.co.za --admin-name="Their Admin" --password="TempPass123!"
```

Then follow base steps 0.1–0.4. Since there is exactly **one** company, the public
portals (job applications `/apply/{token}`, anonymous whistleblowing `/report`)
resolve the tenant automatically.

### B.4 Go-live checklist (standalone)

- [ ] `license:status` shows valid + correct modules
- [ ] HTTPS enforced; `APP_DEBUG=false`
- [ ] Workers + scheduler running (0.3 / 0.4)
- [ ] SMTP verified (password reset email arrives)
- [ ] Backups: nightly `pg_dump` (files live IN the DB, so a DB backup includes
      all uploads) + keep a copy of `APP_KEY`

---

## C. Live site — SAAS (multi-tenant)

Many companies on one install; isolation via Postgres RLS; module access & limits
from each company's **plan/subscription**.

### C.1 `.env`

```ini
APP_ENV=production
APP_DEBUG=false
APP_EDITION=saas

TRIAL_DAYS=30
LAUNCH_DATE=2026-07-01        # launch-pricing window start
LAUNCH_MONTHS=6
FOUNDING_LOCK_MONTHS=12
```

### C.2 What the seed gives you

`php artisan db:seed --force` on a fresh DB seeds:
- the **plans** (Starter/Growth/Business/Enterprise with launch + regular pricing),
  module catalog, add-ons (users / AI packs / storage packs), service offerings
- the **platform admin** account (full rights on all modules)
- reference data cloned into every newly provisioned company
- access-gate permissions (whistleblower/offboarding/boarding)

### C.3 Onboarding companies

Two ways:

- **Self-service signup**: `https://your-domain/signup` — creates the company on a
  trial (`TRIAL_DAYS`) with its first admin.
- **Platform admin**: *Platform → Companies → New Company* (choose plan + trial
  period), or CLI:
  ```bash
  php -d memory_limit=1G artisan tenancy:create-company "Acme (Pty) Ltd" admin@acme.co.za --plan=growth
  ```

### C.4 SaaS-specific configuration

- **Payments**: configure a payment gateway under Platform settings so subscription
  invoices, top-ups (AI/SMS/storage) and self-service upgrades can be paid online.
  Without one, purchases fall back to invoice/manual flows.
- **Public whistleblower portals**: each company gets its own link —
  `https://your-domain/report?c={company-slug}` (slug is on the Companies page).
  Publish the right link per tenant; a bare `/report` only works when a single
  company exists.
- **Per-tenant crons**: already wired — the scheduler fans out one queued job per
  company for leave accrual/cycle advance, sick-leave cycles, birthday leave,
  absence processing, tender discovery, mailbox polling, report generation.
  **The `tenants` + `mailboxes` + `tenders` queues must have workers** (0.3).
- **Storage metering**: `storage:recompute` runs nightly (01:15); run it once at
  install for initial numbers. Tenants see usage under *Usage & Add-ons*; you see
  per-company storage on *Platform → Companies / Overview*.

### C.5 Go-live checklist (SaaS)

- [ ] `APP_EDITION=saas`, HTTPS, `APP_DEBUG=false`
- [ ] Plans/pricing reviewed on *Platform → Plans* (launch window dates correct)
- [ ] Payment gateway configured & test payment done
- [ ] Workers on **all** queues + scheduler cron (0.3 / 0.4)
- [ ] `php artisan schedule:list` shows the leave/tender/storage jobs
- [ ] Signup flow tested end-to-end (company created, admin can log in, RLS
      verified: new company sees no other company's data)
- [ ] Platform admin 2FA enabled
- [ ] Nightly `pg_dump` backups + offsite copy of `APP_KEY`

---

## Upgrading an EXISTING install (any scenario)

```bash
php artisan down
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build                      # or deploy prebuilt public/build
php -d memory_limit=1G artisan migrate --force
php -d memory_limit=1G artisan db:seed --class=WhistleblowerPermissionSeeder --force
php -d memory_limit=1G artisan db:seed --class=OffboardingPermissionSeeder --force
php -d memory_limit=1G artisan storage:recompute
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan up
```

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| Vite build fails on Linux but not macOS (`Could not resolve ...Index.vue`) | Case-sensitive filesystem: an import's case doesn't match the file. Fix the import case. |
| Cron features "do nothing" (leave accrual, tender discovery, mailbox tickets) | No worker on the `tenants`/`mailboxes`/`tenders` queues — see 0.3. |
| A company can't see records you know exist | Rows have `company_id = NULL` (created outside a tenant context) — investigate the code path; per-tenant data must be written under `TenantContext`. |
| artisan dies with "Allowed memory size exhausted" | Run with `php -d memory_limit=1G artisan ...` (route table is large). |
| Uploads "missing" on disk | Expected — files are stored in the database (`tenant_files`), served via `/dbfiles/...`. |
