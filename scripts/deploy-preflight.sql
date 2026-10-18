-- Deploy pre-flight for the 2026-08 release. READ ONLY — this changes nothing.
--
-- Run it against production BEFORE `php artisan migrate`. Every query below corresponds to
-- something a migration in this release is about to do to data that already exists. If every count
-- is 0, the release only adds tables and columns and there is nothing to think about.
--
--   psql -d <production-db> -f scripts/deploy-preflight.sql
--
-- The three unique indexes are the ones that can stop the deploy: Postgres refuses to build a unique
-- index over rows that already violate it. The migration soft-deletes the losers first, keeping the
-- newest of each set — so a non-zero count here is not a failure, it is a number of rows that are
-- about to be soft-deleted, and you should be willing to lose them before you proceed.

\echo '== 1. Duplicate RFx responses (same supplier bidding twice on one event) =='
\echo '   The migration keeps the newest and soft-deletes the rest.'
SELECT rfx_id, supplier_id, COUNT(*) AS copies
FROM rfx_responses
WHERE deleted_at IS NULL
GROUP BY rfx_id, supplier_id
HAVING COUNT(*) > 1
ORDER BY copies DESC;

\echo ''
\echo '== 2. Duplicate supplier invoices (same number twice for one supplier) =='
\echo '   These are the double-payables the unique index exists to stop. Check them by hand:'
\echo '   if any pair is two genuinely different invoices that share a number, fix the number'
\echo '   BEFORE deploying, or the migration will soft-delete the older one.'
SELECT company_id, supplier_id, invoice_number, COUNT(*) AS copies,
       SUM(amount) AS total_at_risk
FROM supplier_invoices
WHERE deleted_at IS NULL
GROUP BY company_id, supplier_id, invoice_number
HAVING COUNT(*) > 1
ORDER BY total_at_risk DESC;

\echo ''
\echo '== 3. Duplicate supplier registration numbers within one tenant =='
\echo '   This index has NO dedupe step in front of it — a non-zero count here FAILS the deploy.'
\echo '   Merge or clear the duplicates first.'
SELECT company_id, company_registration_number, COUNT(*) AS copies,
       string_agg(company_name, ' | ') AS names
FROM suppliers
WHERE deleted_at IS NULL
  AND company_registration_number IS NOT NULL
  AND company_registration_number <> ''
GROUP BY company_id, company_registration_number
HAVING COUNT(*) > 1;

\echo ''
\echo '== 4. Companies that will be moved off the retired "standard" plan =='
\echo '   `standard` was deactivated by the launch-pricing release; the column default still pointed'
\echo '   at it, so anything created outside Eloquent landed on a plan nobody can buy. Companies with'
\echo '   a subscription row are left alone. Confirm this list is what you expect — it is billing data.'
SELECT c.id, c.name, c.plan
FROM companies c
WHERE c.plan = 'standard'
  AND NOT EXISTS (SELECT 1 FROM subscriptions s WHERE s.company_id = c.id);

\echo ''
\echo '== 5. Budget ribbons that will be retired from the sidebar =='
\echo '   The budget release consolidates flat ribbons into hub groups and deletes the old ones,'
\echo '   re-pointing their how-to articles first. Nothing else references them.'
SELECT r.id, r.ribbon_name, r.ribbon_path
FROM security_module_ribbons r
JOIN security_modules m ON m.id = r.module_id
WHERE m.code_name = 'budget_management';

\echo ''
\echo '== 6. Sanity: is a queue worker actually running? =='
\echo '   Several notifications in this release are queued (ShouldQueue). Without a worker they are'
\echo '   written to `jobs` and never sent — silently. A growing number here after deploy means'
\echo '   supplier invitations and KYC notices are piling up unsent.'
SELECT COUNT(*) AS jobs_waiting FROM jobs;
SELECT COUNT(*) AS jobs_failed FROM failed_jobs;
