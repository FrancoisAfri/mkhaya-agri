# Security and privacy

No claim of POPIA compliance or security certification is made: the controls are listed, the assessments are not done (WMS-222).

## Threat model (summary)

| Threat | Where | Control | Evidence |
|---|---|---|---|
| Another tenant's data by changing an identifier (IDOR) | Every route and API endpoint | Company scoping on every query (`BelongsToCompany`, explicit filters on raw queries), Postgres RLS with forced policies | `TenantIsolationTest` (every route × foreign identifiers; no marker leaks, no change) |
| Action without permission | Every route | Permission checked in the controller before validation; services re-check; policies | `WarehouseRoutesAuthorizeTest`, generated permission matrix |
| Approving one's own risky change | Adjustments, revaluations, slotting, losses | Service checks plus database four-eyes constraints; override only time-boxed, audited, reviewed | `InventoryLedgerTest`, `WorkflowTest`, `GovernanceTest` |
| Tampering with history | Ledger, audit, events, counts, location history | Append-only triggers; hash-chained audit ledger checked nightly | `GovernanceTest`, Finance `StockLedgerControlsTest` |
| Server-side request forgery | Webhooks, printers | Webhooks: https, plain host, port 443, resolved addresses must be public, checked at every delivery, redirects not followed. Printers: private addresses only | `IntegrationsTest`, `FloorOfflineTest` |
| Replay / forgery of webhooks | Receivers | HMAC-SHA256 over timestamp and body, event ids | Contract in [04-api-contract.md](04-api-contract.md) |
| Duplicate effects from retries | API, offline, ERP, postings | Idempotency keys at every layer | QA-007, QA-014, QA-015 |
| Malicious uploads | Evidence, PODs, receipt attachments | Platform `MalwareScanner` (structural checks; ClamAV where configured), extension from content not name, private storage, encrypted | `GovernanceTest` (EICAR refused) |
| Formula injection in exports | CSV, Excel | Cells starting `= + - @` neutralised | `InsightsTest` |
| Stolen or stale tokens | API | Abilities, warehouse-scoped tokens, rate limit, refused for inactive or locked accounts | `GovernanceTest`, `IntegrationsTest` |
| Secrets in logs or audit | Integrations, audit | Credentials encrypted at rest and never sent to screens; audit redacts secret-like keys; webhook secret shown once | `GovernanceTest` |
| Malformed input causing errors that leak detail | Routes | Polite refusals; SQL errors never shown to users; malformed identifiers answer 404 | `TenantIsolationTest` |

**Note on row-level security in tests:** the test database connects as a superuser, which ignores RLS; the tests prove the application's own scoping. RLS is the second wall and must be verified with the production (non-superuser) role on staging: connect as the app role without `app.company_id` set to another company and confirm cross-tenant rows are invisible.

## Dependency audit (WMS-221) — release-blocking platform findings

Run on 13 October 2026:

- `composer audit`: 54 advisories in 17 packages (1 critical, 18 high, 28 medium, 5 low, 2 unrated). Packages the warehouse uses directly: **phpoffice/phpspreadsheet** (critical CVE-2026-45034 and highs — WHMI only writes spreadsheets, never reads uploaded ones), **guzzlehttp/guzzle** (high CVE-2026-69246, noncanonical host bypasses host checks — WHMI's webhook check now refuses noncanonical hosts regardless), **maatwebsite/excel** (high CVE-2026-84374), **laravel/framework**, **dompdf/dompdf**, **symfony/http-kernel**.
- `yarn audit` (production dependencies): 56 (2 critical: @casl/ability, swiper; 29 high, mostly axios, prosemirror, pdfjs-dist, postcss toolchain). None in the packages WHMI added (@zxing).

These are shared by every module; upgrading them is a platform change to schedule and regression-test, not part of this module. They block a release under the Part D gate until resolved or risk-accepted.

## Personal information (WMS-220, POPIA)

| Personal information | Where | Who sees it | Kept |
|---|---|---|---|
| Staff names, user accounts, activities, productivity | Users, warehouse staff, tasks, performance | Supervisors (`wms.supervise`) for per-person figures | While employed (platform policy) |
| Drivers' and recipients' names, signatures, vehicle registrations | Shipments, proof of delivery | Dispatch and order screens | `personal_data_days` (default 2 years), then replaced by `[removed]`; signature images deleted |
| Web-shop customers' names and addresses | Fulfilment orders, returns | Order screens | Same; only zone, province, city, country and postal code remain |
| Investigation subjects and notes | Loss findings | Investigators only (`wms.investigate`); every opening audited | Business record |
| Device scans with user and time | Offline actions | Supervisors | `offline_action_days` (default 180) |
| Audit trail (who did what) | Audit ledger | Auditors (`wms.audit.view`) | `audit_retention_days` (default 7 years, minimum 5) |

Data minimisation: the floor device stores only its own tasks, encrypted, never expected quantities or balances. Exports carry their filters and are deleted after `export_file_days`; downloads of sensitive exports are audited. Third parties: carriers (shipment data), ERP connections and webhook receivers receive only the events and fields subscribed; operator agreements for these are to be documented per customer.

Network: traffic is encrypted by the platform's TLS termination; webhooks require https.
