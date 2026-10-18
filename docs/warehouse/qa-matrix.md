# Warehouse acceptance matrix — evidence

Generated Mon, Oct 12, 2026 11:35 PM by `php artisan wms:qa-evidence`. The cases are Part D §31 of the specification; `tests/Feature/Warehouse/QaMatrix.php` maps each to the tests that prove it, and `QaMatrixTest` fails if a cited test disappears.

A case passes only on its automated tests here. Benchmarks and drills marked outstanding must be run on the deployment architecture before release (Part D release gates).

| ID | Scenario | Required result | Tests | Status |
|---|---|---|---|---|
| QA-001 | User accesses another company's warehouse by changing an ID | Access denied; no data disclosed | 2 | PASS |
| QA-002 | Two users receive the same purchase order simultaneously | No duplicate receipt posting | 2 | PASS |
| QA-003 | Two orders attempt to reserve the last available unit | Only valid available stock can be committed | 1 | PASS (automated) — BENCHMARK on staging outstanding |
| QA-004 | Receipt contains damaged and accepted items | Quantities and stock states are correctly separated | 2 | PASS |
| QA-005 | Product is placed into a blocked bin | Movement rejected unless an authorised workflow permits it | 3 | PASS |
| QA-006 | Transfer is dispatched but only partly received | In-transit and received quantities reconcile | 1 | PASS |
| QA-007 | User retries a posted inventory transaction | No duplicate stock movement | 3 | PASS |
| QA-008 | User changes a posted stock transaction | Direct mutation blocked; authorised correction recorded | 3 | PASS |
| QA-009 | Blind stocktake identifies a discrepancy | Variance created; stock changes only after required approval | 2 | PASS |
| QA-010 | Expired or recalled stock is allocated to an order | Allocation blocked according to policy | 3 | PASS |
| QA-011 | Customer returns an item awaiting inspection | Item remains unavailable when policy requires quarantine | 1 | PASS |
| QA-012 | Batch recall is initiated | Affected on-hand, in-transit and traceable downstream stock is identified | 2 | PASS |
| QA-013 | Accounting posting service is temporarily unavailable | Inventory transaction and posting status remain consistent and recoverable | 3 | PASS |
| QA-014 | Posting request is retried after a timeout | No duplicate accounting journal | 1 | PASS |
| QA-015 | Integration receives an invalid or duplicate external event | Event rejected or safely deduplicated with an auditable result | 3 | PASS |
| QA-016 | User attempts to approve their own restricted adjustment | Approval denied when segregation-of-duties policy prohibits it | 3 | PASS |
| QA-017 | Offline device submits a conflicting movement | Conflict detected and handled safely without silent stock corruption | 1 | PASS (automated) — DRILL on staging outstanding |
| QA-018 | Barcode contains an invalid batch or serial attribute | Validation error shown; no incorrect posting | 3 | PASS |
| QA-019 | Large warehouse report is requested | Export completes without blocking core inventory operations | 1 | PASS (automated) — BENCHMARK on staging outstanding |
| QA-020 | Application restarts during a multi-line transfer | Transaction remains atomic; no partial posting | 1 | PASS (automated) — DRILL on staging outstanding |
| QA-021 | Warehouse changes its local timezone | Historic timestamps remain accurate | 1 | PASS |
| QA-022 | Product unit conversion changes | Historic quantities remain unchanged and new conversions are validated | 2 | PASS |
| QA-023 | User attempts an unauthorised export | Export denied and sensitive access logged as required | 2 | PASS |
| QA-024 | Forecasting data is insufficient | Recommendation displays the limitation instead of presenting unsupported certainty | 1 | PASS |
| QA-025 | AI attempts to approve a financial write-off without authority | Action blocked and logged | 2 | PASS |
| QA-026 | Backup is restored to a test environment | Ledger integrity and required references reconcile | 1 | PASS (automated) — DRILL on staging outstanding |
| QA-027 | Inventory balance projection is intentionally corrupted in testing | Reconciliation identifies the discrepancy against the ledger | 2 | PASS |
| QA-028 | High-volume concurrent stock operations run | Agreed performance targets met without ledger corruption | 1 | PASS (automated) — BENCHMARK on staging outstanding |

### QA-001 — User accesses another company's warehouse by changing an ID

**Setup.** Two companies; the second holds warehouses, items, lots, orders, carriers, webhooks, report runs and approval steps carrying marker values.

**Steps.** A manager of the first company with every warehouse permission calls every warehouse web and API route with each of the second company's identifiers.

**Expected.** Access denied; no data disclosed

**Actual.** Every automated test for this case passed.

**Evidence.**
- `TenantIsolationTest::test_another_companys_identifiers_reveal_and_change_nothing_on_any_route` — **PASS** (tests/Feature/Warehouse/TenantIsolationTest.php:71, 4 assertions, 27.71 s)
- `WarehouseLayoutTest::test_another_companys_warehouses_are_invisible_and_untouchable` — **PASS** (tests/Feature/Warehouse/WarehouseLayoutTest.php:346, 12 assertions, 1.23 s)

**Status.** PASS

### QA-002 — Two users receive the same purchase order simultaneously

**Setup.** An approved purchase order for 10; two receiving clerks.

**Steps.** Both receive the same delivery note; then the second receives again without one. Receipt is serialised by a row lock on the order.

**Expected.** No duplicate receipt posting

**Actual.** Every automated test for this case passed.

**Evidence.**
- `InboundTest::test_two_people_receiving_the_same_delivery_do_not_post_it_twice` — **PASS** (tests/Feature/Warehouse/InboundTest.php:274, 4 assertions, 0.94 s)
- `InboundTest::test_a_retried_receipt_does_not_receive_twice` — **PASS** (tests/Feature/Warehouse/InboundTest.php:259, 2 assertions, 1.13 s)

**Status.** PASS

### QA-003 — Two orders attempt to reserve the last available unit

**Setup.** One unit on hand; two orders for it.

**Steps.** Allocate both; then run 8 concurrent processes allocating and receiving the same items.

**Expected.** Only valid available stock can be committed

**Actual.** Every automated test for this case passed.

**Evidence.**
- `FulfilmentTest::test_two_orders_cannot_both_have_the_last_units` — **PASS** (tests/Feature/Warehouse/FulfilmentTest.php:154, 4 assertions, 0.74 s)
- Benchmark: `php artisan wms:benchmark --doc=docs/warehouse/performance-results.md` on the deployment architecture (latest local run: docs/warehouse/performance-results.md).

**Status.** PASS (automated) — BENCHMARK on staging outstanding

### QA-004 — Receipt contains damaged and accepted items

**Setup.** A purchase order for 10.

**Steps.** Receive 12 accepted, 1 rejected and 1 damaged.

**Expected.** Quantities and stock states are correctly separated

**Actual.** Every automated test for this case passed.

**Evidence.**
- `InboundTest::test_over_delivery_outside_tolerance_stays_open_and_tells_the_buyer` — **PASS** (tests/Feature/Warehouse/InboundTest.php:212, 7 assertions, 0.97 s)
- `InboundTest::test_short_shelf_life_goes_to_quarantine_with_a_discrepancy` — **PASS** (tests/Feature/Warehouse/InboundTest.php:177, 2 assertions, 0.88 s)

**Status.** PASS

### QA-005 — Product is placed into a blocked bin

**Setup.** A bin blocked with a reason.

**Steps.** Receive into it; move into it; reopen it and retry; reverse a movement from it.

**Expected.** Movement rejected unless an authorised workflow permits it

**Actual.** Every automated test for this case passed.

**Evidence.**
- `WarehouseLayoutTest::test_stock_cannot_be_received_into_a_blocked_bin` — **PASS** (tests/Feature/Warehouse/WarehouseLayoutTest.php:107, 2 assertions, 0.93 s)
- `WarehouseLayoutTest::test_a_reopened_bin_accepts_stock_and_a_reversal_is_never_blocked` — **PASS** (tests/Feature/Warehouse/WarehouseLayoutTest.php:165, 1 assertions, 0.51 s)
- `MovesTest::test_a_bin_move_keeps_the_lot_and_refuses_a_prohibited_bin` — **PASS** (tests/Feature/Warehouse/MovesTest.php:68, 2 assertions, 1 s)

**Status.** PASS

### QA-006 — Transfer is dispatched but only partly received

**Setup.** 100 in a bulk bin; a transfer of 40 to another warehouse.

**Steps.** Dispatch 40, receive 35, try to receive 10 more, reconcile the 5 short with a reason.

**Expected.** In-transit and received quantities reconcile

**Actual.** Every automated test for this case passed.

**Evidence.**
- `MovesTest::test_a_transfer_runs_from_draft_to_reconciled_with_stock_in_transit_in_between` — **PASS** (tests/Feature/Warehouse/MovesTest.php:96, 13 assertions, 1.05 s)

**Status.** PASS

### QA-007 — User retries a posted inventory transaction

**Setup.** A posting with an idempotency key; an offline scan delivered twice.

**Steps.** Post twice with the same key; deliver the offline action again.

**Expected.** No duplicate stock movement

**Actual.** Every automated test for this case passed.

**Evidence.**
- `InventoryLedgerTest::test_a_retried_posting_with_the_same_key_moves_stock_once` — **PASS** (tests/Feature/Warehouse/InventoryLedgerTest.php:288, 2 assertions, 0.67 s)
- `FloorOfflineTest::test_an_offline_action_is_applied_once_however_often_it_arrives` — **PASS** (tests/Feature/Warehouse/FloorOfflineTest.php:92, 4 assertions, 1.05 s)
- `StocktakeTest::test_a_retried_entry_is_recorded_once_and_entries_cannot_be_edited` — **PASS** (tests/Feature/Warehouse/StocktakeTest.php:160, 3 assertions, 1.14 s)

**Status.** PASS

### QA-008 — User changes a posted stock transaction

**Setup.** A posted movement.

**Steps.** Update and delete it directly in the database; correct it by reversal instead.

**Expected.** Direct mutation blocked; authorised correction recorded

**Actual.** Every automated test for this case passed.

**Evidence.**
- `StockLedgerControlsTest::test_a_posted_movement_cannot_be_edited_or_deleted` — **PASS** (tests/Feature/Finance/StockLedgerControlsTest.php:340, 2 assertions, 0.4 s)
- `WarehouseLayoutTest::test_a_reopened_bin_accepts_stock_and_a_reversal_is_never_blocked` — **PASS** (tests/Feature/Warehouse/WarehouseLayoutTest.php:165, 1 assertions, 0.51 s)
- `GovernanceTest::test_the_audit_ledger_is_chained_append_only_and_shows_tampering` — **PASS** (tests/Feature/Warehouse/GovernanceTest.php:109, 17 assertions, 1.34 s)

**Status.** PASS

### QA-009 — Blind stocktake identifies a discrepancy

**Setup.** A blind count of bins holding stock.

**Steps.** Count short, recount by another counter, post above the adjustment limit.

**Expected.** Variance created; stock changes only after required approval

**Actual.** Every automated test for this case passed.

**Evidence.**
- `StocktakeTest::test_the_count_screens_carry_a_blind_count_from_opening_to_posting` — **PASS** (tests/Feature/Warehouse/StocktakeTest.php:311, 50 assertions, 2.04 s)
- `StocktakeTest::test_a_variance_above_the_adjustment_limit_waits_for_approval` — **PASS** (tests/Feature/Warehouse/StocktakeTest.php:236, 3 assertions, 0.84 s)

**Status.** PASS

### QA-010 — Expired or recalled stock is allocated to an order

**Setup.** Expired, blocked and recalled lots beside good stock.

**Steps.** Allocate an order; override rotation; recall a lot and try to release it.

**Expected.** Allocation blocked according to policy

**Actual.** Every automated test for this case passed.

**Evidence.**
- `FulfilmentTest::test_allocation_is_first_expiring_first_and_skips_expired_and_blocked_stock` — **PASS** (tests/Feature/Warehouse/FulfilmentTest.php:136, 3 assertions, 0.91 s)
- `BatchesExpiryTest::test_an_override_breaks_rotation_with_a_reason_but_never_sends_expired_stock` — **PASS** (tests/Feature/Warehouse/BatchesExpiryTest.php:141, 4 assertions, 1.17 s)
- `ProductMasterTest::test_a_recall_quarantines_the_stock_and_nothing_can_leave_or_be_released` — **PASS** (tests/Feature/Warehouse/ProductMasterTest.php:294, 7 assertions, 0.78 s)

**Status.** PASS

### QA-011 — Customer returns an item awaiting inspection

**Setup.** A dispatched order; a return authorised.

**Steps.** Receive the return; check availability before and after inspection.

**Expected.** Item remains unavailable when policy requires quarantine

**Actual.** Every automated test for this case passed.

**Evidence.**
- `ReturnsQualityRecallTest::test_a_return_is_received_into_quarantine_at_cost_and_inspected_before_it_is_stock` — **PASS** (tests/Feature/Warehouse/ReturnsQualityRecallTest.php:96, 11 assertions, 1.28 s)

**Status.** PASS

### QA-012 — Batch recall is initiated

**Setup.** One batch: 7 on the shelf, 2 on a transfer truck, 1 with a customer; a serial range partly shipped.

**Steps.** Open the recall, read its impact, hold everything.

**Expected.** Affected on-hand, in-transit and traceable downstream stock is identified

**Actual.** Every automated test for this case passed.

**Evidence.**
- `ReturnsQualityRecallTest::test_a_batch_recall_finds_stock_on_hand_in_transit_and_downstream` — **PASS** (tests/Feature/Warehouse/ReturnsQualityRecallTest.php:388, 6 assertions, 1.09 s)
- `ReturnsQualityRecallTest::test_a_serial_range_recall_holds_the_units_and_finds_the_customers_who_have_the_rest` — **PASS** (tests/Feature/Warehouse/ReturnsQualityRecallTest.php:297, 7 assertions, 1.13 s)

**Status.** PASS

### QA-013 — Accounting posting service is temporarily unavailable

**Setup.** The fiscal period closed; an accounting treatment suspended.

**Steps.** Receive stock; reopen; retry with the same key; reconcile stock against the ledger.

**Expected.** Inventory transaction and posting status remain consistent and recoverable

**Actual.** Every automated test for this case passed.

**Evidence.**
- `AcceptanceQaTest::test_qa013_when_accounting_cannot_post_the_stock_does_not_move_and_the_work_recovers` — **PASS** (tests/Feature/Warehouse/AcceptanceQaTest.php:50, 4 assertions, 0.9 s)
- `AccountingTest::test_an_adjustment_posts_to_the_mapped_account_and_a_suspended_treatment_stops_it` — **PASS** (tests/Feature/Warehouse/AccountingTest.php:63, 2 assertions, 1.21 s)
- `AccountingTest::test_reconciliation_finds_a_corrupted_balance_and_an_unposted_movement_and_clears_when_fixed` — **PASS** (tests/Feature/Warehouse/AccountingTest.php:124, 5 assertions, 0.67 s)

**Status.** PASS

### QA-014 — Posting request is retried after a timeout

**Setup.** A receipt with an idempotency key.

**Steps.** Post it twice.

**Expected.** No duplicate accounting journal

**Actual.** Every automated test for this case passed.

**Evidence.**
- `AcceptanceQaTest::test_qa014_a_retried_posting_makes_one_movement_and_one_journal` — **PASS** (tests/Feature/Warehouse/AcceptanceQaTest.php:69, 5 assertions, 0.65 s)

**Status.** PASS

### QA-015 — Integration receives an invalid or duplicate external event

**Setup.** An active ERP connection; a webhook-facing API with Idempotency-Key.

**Steps.** Send the same order twice, an order naming an unknown product, the same API command twice and with a changed body.

**Expected.** Event rejected or safely deduplicated with an auditable result

**Actual.** Every automated test for this case passed.

**Evidence.**
- `IntegrationsTest::test_erp_orders_and_stock_levels_go_through_the_integration_hub_and_events_are_exported_once` — **PASS** (tests/Feature/Warehouse/IntegrationsTest.php:254, 12 assertions, 0.83 s)
- `IntegrationsTest::test_the_api_feed_types_webhooks_idempotency_and_warehouse_scoped_tokens` — **PASS** (tests/Feature/Warehouse/IntegrationsTest.php:173, 25 assertions, 1.11 s)
- `FulfilmentTest::test_the_api_takes_an_order_once_and_reports_what_can_be_promised` — **PASS** (tests/Feature/Warehouse/FulfilmentTest.php:121, 8 assertions, 1.12 s)

**Status.** PASS

### QA-016 — User attempts to approve their own restricted adjustment

**Setup.** An adjustment needing approval; an approval policy with two levels.

**Steps.** The requester approves; a second level by the same approver; an emergency override.

**Expected.** Approval denied when segregation-of-duties policy prohibits it

**Actual.** Every automated test for this case passed.

**Evidence.**
- `InventoryLedgerTest::test_a_risky_adjustment_waits_and_nobody_approves_their_own` — **PASS** (tests/Feature/Warehouse/InventoryLedgerTest.php:198, 12 assertions, 1.15 s)
- `WorkflowTest::test_an_adjustment_passes_every_level_its_value_needs_by_different_people_and_a_delegate_can_act` — **PASS** (tests/Feature/Warehouse/WorkflowTest.php:101, 38 assertions, 2.38 s)
- `GovernanceTest::test_the_emergency_override_lets_a_person_approve_their_own_request_only_inside_it_and_is_reviewed_by_another` — **PASS** (tests/Feature/Warehouse/GovernanceTest.php:79, 16 assertions, 1.42 s)

**Status.** PASS

### QA-017 — Offline device submits a conflicting movement

**Setup.** A pick recorded offline, then picked by somebody else.

**Steps.** Sync the offline action.

**Expected.** Conflict detected and handled safely without silent stock corruption

**Actual.** Every automated test for this case passed.

**Evidence.**
- `FloorOfflineTest::test_an_offline_pick_that_no_longer_fits_is_held_for_review_not_forced_through` — **PASS** (tests/Feature/Warehouse/FloorOfflineTest.php:105, 5 assertions, 0.87 s)
- Drill: performed by a person on staging. Record date, who, outcome: ____

**Status.** PASS (automated) — DRILL on staging outstanding

### QA-018 — Barcode contains an invalid batch or serial attribute

**Setup.** GS1 scans with a bad check digit, a malformed date, a missing batch.

**Steps.** Scan them; receive a lot-tracked item without a batch.

**Expected.** Validation error shown; no incorrect posting

**Actual.** Every automated test for this case passed.

**Evidence.**
- `ProductMasterTest::test_an_invalid_gs1_scan_is_refused_with_the_reason` — **PASS** (tests/Feature/Warehouse/ProductMasterTest.php:240, 2 assertions, 0.57 s)
- `ProductMasterTest::test_a_barcode_with_a_wrong_check_digit_is_refused` — **PASS** (tests/Feature/Warehouse/ProductMasterTest.php:170, 1 assertions, 0.67 s)
- `InboundTest::test_a_lot_tracked_item_needs_its_batch_and_a_serial_tracked_one_a_serial_per_unit` — **PASS** (tests/Feature/Warehouse/InboundTest.php:160, 3 assertions, 1.09 s)

**Status.** PASS

### QA-019 — Large warehouse report is requested

**Setup.** A report above the synchronous row limit, or asked for in the background.

**Steps.** Request it; the queued job builds it; the requester is notified.

**Expected.** Export completes without blocking core inventory operations

**Actual.** Every automated test for this case passed.

**Evidence.**
- `InsightsTest::test_a_background_export_is_built_by_the_job_notifies_and_downloads_only_for_its_requester` — **PASS** (tests/Feature/Warehouse/InsightsTest.php:88, 12 assertions, 0.86 s)
- Benchmark: `php artisan wms:benchmark --doc=docs/warehouse/performance-results.md` on the deployment architecture (latest local run: docs/warehouse/performance-results.md).

**Status.** PASS (automated) — BENCHMARK on staging outstanding

### QA-020 — Application restarts during a multi-line transfer

**Setup.** A two-line transfer whose second line loses its stock after picking.

**Steps.** Dispatch: the second leg fails mid-way.

**Expected.** Transaction remains atomic; no partial posting

**Actual.** Every automated test for this case passed.

**Evidence.**
- `ReliabilityTest::test_a_multi_line_transfer_posts_whole_or_not_at_all` — **PASS** (tests/Feature/Warehouse/ReliabilityTest.php:78, 4 assertions, 0.93 s)
- Drill: performed by a person on staging. Record date, who, outcome: ____

**Status.** PASS (automated) — DRILL on staging outstanding

### QA-021 — Warehouse changes its local timezone

**Setup.** A receipt at 21:30 UTC in a Johannesburg warehouse.

**Steps.** Move the warehouse to Dubai time; read the stored instant and the EPCIS event.

**Expected.** Historic timestamps remain accurate

**Actual.** Every automated test for this case passed.

**Evidence.**
- `AcceptanceQaTest::test_qa021_changing_a_warehouses_time_zone_keeps_every_historic_instant` — **PASS** (tests/Feature/Warehouse/AcceptanceQaTest.php:81, 3 assertions, 0.63 s)

**Status.** PASS

### QA-022 — Product unit conversion changes

**Setup.** A movement made in cases.

**Steps.** Try to resize the case; use a unit with no conversion.

**Expected.** Historic quantities remain unchanged and new conversions are validated

**Actual.** Every automated test for this case passed.

**Evidence.**
- `ProductMasterTest::test_a_movement_keeps_the_unit_it_was_made_in_and_that_unit_then_cannot_be_resized` — **PASS** (tests/Feature/Warehouse/ProductMasterTest.php:138, 5 assertions, 0.74 s)
- `ProductMasterTest::test_a_unit_with_no_conversion_is_refused_rather_than_treated_as_one` — **PASS** (tests/Feature/Warehouse/ProductMasterTest.php:120, 1 assertions, 0.85 s)

**Status.** PASS

### QA-023 — User attempts an unauthorised export

**Setup.** A viewer without the investigator grant.

**Steps.** Ask for the loss-findings export; download a sensitive export as a permitted user.

**Expected.** Export denied and sensitive access logged as required

**Actual.** Every automated test for this case passed.

**Evidence.**
- `AcceptanceQaTest::test_qa023_an_unauthorised_export_is_refused_and_recorded` — **PASS** (tests/Feature/Warehouse/AcceptanceQaTest.php:98, 3 assertions, 0.71 s)
- `InsightsTest::test_an_export_carries_its_filters_and_time_shows_values_only_when_allowed_and_neutralises_formulas` — **PASS** (tests/Feature/Warehouse/InsightsTest.php:61, 27 assertions, 1.97 s)

**Status.** PASS

### QA-024 — Forecasting data is insufficient

**Setup.** An item with too little history.

**Steps.** Run the forecast; read the buying advice.

**Expected.** Recommendation displays the limitation instead of presenting unsupported certainty

**Actual.** Every automated test for this case passed.

**Evidence.**
- `PlanningTest::test_methods_follow_the_demand_and_too_little_history_gives_no_number` — **PASS** (tests/Feature/Warehouse/PlanningTest.php:63, 9 assertions, 1.01 s)

**Status.** PASS

### QA-025 — AI attempts to approve a financial write-off without authority

**Setup.** The warehouse assistant asked about write-offs.

**Steps.** Ask it to approve a write-off and to post an adjustment.

**Expected.** Action blocked and logged

**Actual.** Every automated test for this case passed.

**Evidence.**
- `AcceptanceQaTest::test_qa025_the_assistant_cannot_approve_a_write_off_and_the_attempt_is_recorded` — **PASS** (tests/Feature/Warehouse/AcceptanceQaTest.php:107, 4 assertions, 0.67 s)
- `AssistantTest::test_a_draft_is_made_once_only_within_the_askers_rights_and_can_be_withdrawn` — **PASS** (tests/Feature/Warehouse/AssistantTest.php:105, 6 assertions, 1.13 s)

**Status.** PASS

### QA-026 — Backup is restored to a test environment

**Setup.** A restored copy of production.

**Steps.** Run wms:verify-restore; a missing document reference is reported.

**Expected.** Ledger integrity and required references reconcile

**Actual.** Every automated test for this case passed.

**Evidence.**
- `ReliabilityTest::test_health_shows_stalled_scheduled_work_and_a_restore_check_finds_missing_documents` — **PASS** (tests/Feature/Warehouse/ReliabilityTest.php:109, 6 assertions, 0.93 s)
- Drill: performed by a person on staging. Record date, who, outcome: ____

**Status.** PASS (automated) — DRILL on staging outstanding

### QA-027 — Inventory balance projection is intentionally corrupted in testing

**Setup.** A balance edited directly in the database.

**Steps.** Run wms:verify-ledger and the accounting reconciliation.

**Expected.** Reconciliation identifies the discrepancy against the ledger

**Actual.** Every automated test for this case passed.

**Evidence.**
- `ReliabilityTest::test_the_ledger_check_finds_a_corrupted_projection_and_status_changes_are_not_false_alarms` — **PASS** (tests/Feature/Warehouse/ReliabilityTest.php:57, 17 assertions, 1.64 s)
- `AccountingTest::test_reconciliation_finds_a_corrupted_balance_and_an_unposted_movement_and_clears_when_fixed` — **PASS** (tests/Feature/Warehouse/AccountingTest.php:124, 5 assertions, 0.67 s)

**Status.** PASS

### QA-028 — High-volume concurrent stock operations run

**Setup.** 2,000 items, 3 warehouses, 600 bins; 8 concurrent processes.

**Steps.** Run wms:benchmark on the deployment architecture; the ledger is verified afterwards.

**Expected.** Agreed performance targets met without ledger corruption

**Actual.** Every automated test for this case passed.

**Evidence.**
- `FulfilmentTest::test_two_orders_cannot_both_have_the_last_units` — **PASS** (tests/Feature/Warehouse/FulfilmentTest.php:154, 4 assertions, 0.74 s)
- Benchmark: `php artisan wms:benchmark --doc=docs/warehouse/performance-results.md` on the deployment architecture (latest local run: docs/warehouse/performance-results.md).

**Status.** PASS (automated) — BENCHMARK on staging outstanding
