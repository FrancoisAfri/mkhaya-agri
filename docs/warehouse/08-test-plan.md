# 8. Test plan

## Layers

| Layer | Where | Runs |
|---|---|---|
| Feature and domain tests | `tests/Feature/Warehouse` (≈ 220 tests, 2,000+ assertions), plus the Finance, Procurement and Manufacturing suites WHMI touches | Every change; `php -d memory_limit=3G vendor/bin/phpunit tests/Feature/Warehouse` on a test database |
| Acceptance matrix QA-001..028 | `tests/Feature/Warehouse/QaMatrix.php` → [qa-matrix.md](qa-matrix.md) | `php artisan wms:qa-evidence` before a release |
| Tenant isolation | `TenantIsolationTest` (every route with another company's identifiers), `WarehouseRoutesAuthorizeTest` (every route refuses a person without permissions) | Every change |
| Performance and concurrency | `php artisan wms:benchmark` → [performance-results.md](performance-results.md) | Before a release, on the deployment architecture |
| Restore drill | `php artisan wms:verify-restore` on a restored copy | Quarterly and after any restore |
| Accessibility and devices | Manual, assisted | Before a release |

Test databases: never the development database; a separate database per session, migrated (`php artisan migrate`), not rebuilt with `migrate:fresh` (1,700 tables exhaust the lock pool). The benchmark writes a company that the immutable ledger cannot delete — run it only on a disposable copy.

## Requirement → tests

| Requirements | Tests |
|---|---|
| WMS-001..005 warehouses, locations, labels, history | `WarehouseLayoutTest`, `PolicyVersionTest` |
| WMS-010..015 product master, units, barcodes, GS1 | `ProductMasterTest` |
| WMS-020..025 stock ledger, states, availability, adjustments, clients | `InventoryLedgerTest`, `ReliabilityTest`, Finance `StockLedgerControlsTest` |
| WMS-030..035 inbound | `InboundTest` |
| WMS-040..043 put-away | `PutawayTest` |
| WMS-050..053 moves, transfers, replenishment | `MovesTest`, `ReliabilityTest` |
| WMS-060..066 fulfilment | `FulfilmentTest` |
| WMS-070..073 stocktakes | `StocktakeTest` |
| WMS-080..093 returns, quality, recalls, batches, expiry | `ReturnsQualityRecallTest`, `BatchesExpiryTest` |
| WMS-100..104 accounting | `AccountingTest`, `AcceptanceQaTest` (QA-013/014) |
| WMS-110..114 profit protection | `ProtectionTest` |
| WMS-120..124 forecasting and buying | `PlanningTest` |
| WMS-130..135 tasks, labour, slotting, simulation | `WorkTest` |
| WMS-140..144 assistant | `AssistantTest`, `AcceptanceQaTest` (QA-025) |
| WMS-150..154 floor, scanning, labels, offline | `FloorOfflineTest` |
| WMS-160..165 manufacturing | `ProductionMaterialsTest` |
| WMS-170..173 carriers, ERP, API/events, EPCIS | `IntegrationsTest` |
| WMS-180..185 dashboards, reports | `InsightsTest` |
| WMS-190..192 alerts, workflow | `WorkflowTest` |
| WMS-200..203, 220..222 roles, SoD, audit, privacy, security | `GovernanceTest`, `TenantIsolationTest`, `WarehouseRoutesAuthorizeTest` |
| WMS-210..213 performance, reliability, recovery | `ReliabilityTest`, `wms:benchmark` |
| WMS-240..241 policies | `PolicyVersionTest` |
| §33 KPIs | `KpiTest` |
| Navigation | `WarehouseRoutesAuthorizeTest`, hub card tests, "every screen renders" tests in each suite |

## Release gates (Part D)

| Gate | Evidence | Status |
|---|---|---|
| Unit tests for domain rules | Feature suite (domain services exercised directly) | Done |
| Integration tests for Procurement, Sales, Finance, DMS | `InboundTest` (Procurement GRN), `FulfilmentTest` (sales orders, delivery notes), `AccountingTest` (Finance), evidence storage | Done (DMS: files via shared storage, not the DMS module) |
| End-to-end receiving through dispatch | `FulfilmentTest::test_every_outbound_screen_renders_and_the_whole_flow_runs_through_the_routes`, `IntegrationsTest` carrier test | Done (server side); browser end-to-end outstanding |
| Tenant isolation and authorisation | `TenantIsolationTest`, `WarehouseRoutesAuthorizeTest` | Done (app layer; RLS needs a non-superuser connection — see security doc) |
| Migration and rollback | Migrations have `down()`; tested forward on the test database | Rollback drill outstanding on staging |
| Performance and concurrency | [performance-results.md](performance-results.md) | Local run done; deployment run outstanding |
| Accessibility and mobile devices | — | Outstanding |
| Backup restoration and recovery | `wms:verify-restore` | Drill outstanding |
| Security review | Threat model in [security-and-privacy.md](security-and-privacy.md); dependency audit | Review outstanding |
| UAT by warehouse and finance staff | — | Outstanding |
