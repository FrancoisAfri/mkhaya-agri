# Definition of done (§32) — status

Status on 13 October 2026. "Done" means proven by the evidence named; "Outstanding" means it needs the deployment architecture or people, and blocks production release.

| Condition | Status | Evidence / what remains |
|---|---|---|
| All core warehouse workflows operate end to end | Done (server side) | Receiving → put-away → allocation → pick → pack → dispatch → POD through the routes (`FulfilmentTest`, `InboundTest`, `PutawayTest`, `IntegrationsTest`); transfers, counts, returns, recalls in their suites. Browser end-to-end on supported devices outstanding. |
| The inventory ledger and displayed balances reconcile | Done | `LedgerVerifier` (nightly `wms:verify-ledger`), QA-027; status-change false positives fixed |
| Concurrent operations cannot create invalid stock balances | Done locally | Row locks on allocation and receiving, idempotency keys; QA-002/003; 8 concurrent processes with a whole ledger afterwards ([performance-results.md](performance-results.md)). Repeat on the deployment architecture. |
| Company isolation and server-side permissions pass security tests | Done (application layer) | `TenantIsolationTest` (QA-001), `WarehouseRoutesAuthorizeTest`; RLS verification with the non-superuser role on staging outstanding |
| Receipts, transfers, picks, dispatches, returns and adjustments have complete audit trails | Done | Immutable ledger + hash-chained audit ledger + outbox events; `GovernanceTest` |
| Procurement, Sales and Finance integrations reconcile correctly | Done | GRN posting and GRNI, sales delivery at dispatch, journals per accounting rule, `wms:reconcile`; Finance sign-off of the mapping outstanding ([06-accounting-mapping.md](06-accounting-mapping.md)) |
| Barcode and mobile workflows pass testing on supported devices | Outstanding | Server-side scanning, GS1 parsing and offline sync tested (`ProductMasterTest`, `FloorOfflineTest`); device testing (camera, wedge scanners, offline) not done |
| Exception handling, retries, reversals and recovery procedures are tested | Done (automated); drill outstanding | Retries and dead letters (`IntegrationsTest`, `WorkflowTest`), reversals, atomic multi-line posting (QA-020), restore check (QA-026); a real restore drill on staging outstanding |
| Operational dashboards and exports respect permissions and data scope | Done | `InsightsTest`, warehouse-scoped visibility, money only with the accounting grant, audited sensitive downloads (QA-023) |
| Performance targets are met under an agreed representative workload | Done locally; outstanding on deployment | Local p95: stock lookup 3.3 ms, scan 0.7 ms, receipt 11.6 ms, allocation 18.8 ms against the 300 ms target. The workload must be agreed and the run repeated on the deployment architecture. |
| Documentation, monitoring, backup and support procedures are in place | Done | This handover; `wms:health`, heartbeats, health page; [10-operations.md](10-operations.md). RPO/RTO to be agreed. |
| Warehouse users and finance personnel approve the acceptance test results | Outstanding | UAT not yet held |

## Also outstanding before release

- Platform dependency advisories (1 critical, 18 high in Composer; 2 critical, 29 high in npm) — resolve or risk-accept ([security-and-privacy.md](security-and-privacy.md)).
- Accessibility audit to WCAG 2.2 AA with a screen reader and on handhelds.
- Migration rollback drill on staging.
- Live carrier drivers (only the manual carrier exists) and live ERP connections are proven only against test doubles.
