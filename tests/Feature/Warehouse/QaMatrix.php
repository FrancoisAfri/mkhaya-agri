<?php

namespace Tests\Feature\Warehouse;

/**
 * The mandatory acceptance matrix (Part D §31): every case with its setup, steps, expected result
 * and the automated tests that prove it. `php artisan wms:qa-evidence` runs exactly these tests and
 * writes docs/warehouse/qa-matrix.md with the actual result of each. QaMatrixTest fails if a case
 * goes missing or a test it names is renamed or removed, so the matrix cannot quietly rot.
 *
 * Evidence kinds: TEST (automated, run on every build), BENCHMARK (wms:benchmark on the deployment
 * architecture), DRILL (a person performs it on staging and records the outcome).
 */
class QaMatrix
{
    public const W = 'Tests\\Feature\\Warehouse\\';

    /** @return array<string, array{scenario: string, required: string, setup: string, steps: string, tests: list<string>, evidence: list<string>}> */
    public static function cases(): array
    {
        $w = self::W;

        return [
            'QA-001' => ['scenario' => "User accesses another company's warehouse by changing an ID", 'required' => 'Access denied; no data disclosed',
                'setup' => 'Two companies; the second holds warehouses, items, lots, orders, carriers, webhooks, report runs and approval steps carrying marker values.',
                'steps' => 'A manager of the first company with every warehouse permission calls every warehouse web and API route with each of the second company\'s identifiers.',
                'tests' => ["{$w}TenantIsolationTest::test_another_companys_identifiers_reveal_and_change_nothing_on_any_route", "{$w}WarehouseLayoutTest::test_another_companys_warehouses_are_invisible_and_untouchable"],
                'evidence' => ['TEST']],
            'QA-002' => ['scenario' => 'Two users receive the same purchase order simultaneously', 'required' => 'No duplicate receipt posting',
                'setup' => 'An approved purchase order for 10; two receiving clerks.', 'steps' => 'Both receive the same delivery note; then the second receives again without one. Receipt is serialised by a row lock on the order.',
                'tests' => ["{$w}InboundTest::test_two_people_receiving_the_same_delivery_do_not_post_it_twice", "{$w}InboundTest::test_a_retried_receipt_does_not_receive_twice"],
                'evidence' => ['TEST']],
            'QA-003' => ['scenario' => 'Two orders attempt to reserve the last available unit', 'required' => 'Only valid available stock can be committed',
                'setup' => 'One unit on hand; two orders for it.', 'steps' => 'Allocate both; then run 8 concurrent processes allocating and receiving the same items.',
                'tests' => ["{$w}FulfilmentTest::test_two_orders_cannot_both_have_the_last_units"], 'evidence' => ['TEST', 'BENCHMARK']],
            'QA-004' => ['scenario' => 'Receipt contains damaged and accepted items', 'required' => 'Quantities and stock states are correctly separated',
                'setup' => 'A purchase order for 10.', 'steps' => 'Receive 12 accepted, 1 rejected and 1 damaged.',
                'tests' => ["{$w}InboundTest::test_over_delivery_outside_tolerance_stays_open_and_tells_the_buyer", "{$w}InboundTest::test_short_shelf_life_goes_to_quarantine_with_a_discrepancy"],
                'evidence' => ['TEST']],
            'QA-005' => ['scenario' => 'Product is placed into a blocked bin', 'required' => 'Movement rejected unless an authorised workflow permits it',
                'setup' => 'A bin blocked with a reason.', 'steps' => 'Receive into it; move into it; reopen it and retry; reverse a movement from it.',
                'tests' => ["{$w}WarehouseLayoutTest::test_stock_cannot_be_received_into_a_blocked_bin", "{$w}WarehouseLayoutTest::test_a_reopened_bin_accepts_stock_and_a_reversal_is_never_blocked",
                    "{$w}MovesTest::test_a_bin_move_keeps_the_lot_and_refuses_a_prohibited_bin"], 'evidence' => ['TEST']],
            'QA-006' => ['scenario' => 'Transfer is dispatched but only partly received', 'required' => 'In-transit and received quantities reconcile',
                'setup' => '100 in a bulk bin; a transfer of 40 to another warehouse.', 'steps' => 'Dispatch 40, receive 35, try to receive 10 more, reconcile the 5 short with a reason.',
                'tests' => ["{$w}MovesTest::test_a_transfer_runs_from_draft_to_reconciled_with_stock_in_transit_in_between"], 'evidence' => ['TEST']],
            'QA-007' => ['scenario' => 'User retries a posted inventory transaction', 'required' => 'No duplicate stock movement',
                'setup' => 'A posting with an idempotency key; an offline scan delivered twice.', 'steps' => 'Post twice with the same key; deliver the offline action again.',
                'tests' => ["{$w}InventoryLedgerTest::test_a_retried_posting_with_the_same_key_moves_stock_once", "{$w}FloorOfflineTest::test_an_offline_action_is_applied_once_however_often_it_arrives",
                    "{$w}StocktakeTest::test_a_retried_entry_is_recorded_once_and_entries_cannot_be_edited"], 'evidence' => ['TEST']],
            'QA-008' => ['scenario' => 'User changes a posted stock transaction', 'required' => 'Direct mutation blocked; authorised correction recorded',
                'setup' => 'A posted movement.', 'steps' => 'Update and delete it directly in the database; correct it by reversal instead.',
                'tests' => ['Tests\\Feature\\Finance\\StockLedgerControlsTest::test_a_posted_movement_cannot_be_edited_or_deleted', "{$w}WarehouseLayoutTest::test_a_reopened_bin_accepts_stock_and_a_reversal_is_never_blocked",
                    "{$w}GovernanceTest::test_the_audit_ledger_is_chained_append_only_and_shows_tampering"], 'evidence' => ['TEST']],
            'QA-009' => ['scenario' => 'Blind stocktake identifies a discrepancy', 'required' => 'Variance created; stock changes only after required approval',
                'setup' => 'A blind count of bins holding stock.', 'steps' => 'Count short, recount by another counter, post above the adjustment limit.',
                'tests' => ["{$w}StocktakeTest::test_the_count_screens_carry_a_blind_count_from_opening_to_posting", "{$w}StocktakeTest::test_a_variance_above_the_adjustment_limit_waits_for_approval"],
                'evidence' => ['TEST']],
            'QA-010' => ['scenario' => 'Expired or recalled stock is allocated to an order', 'required' => 'Allocation blocked according to policy',
                'setup' => 'Expired, blocked and recalled lots beside good stock.', 'steps' => 'Allocate an order; override rotation; recall a lot and try to release it.',
                'tests' => ["{$w}FulfilmentTest::test_allocation_is_first_expiring_first_and_skips_expired_and_blocked_stock", "{$w}BatchesExpiryTest::test_an_override_breaks_rotation_with_a_reason_but_never_sends_expired_stock",
                    "{$w}ProductMasterTest::test_a_recall_quarantines_the_stock_and_nothing_can_leave_or_be_released"], 'evidence' => ['TEST']],
            'QA-011' => ['scenario' => 'Customer returns an item awaiting inspection', 'required' => 'Item remains unavailable when policy requires quarantine',
                'setup' => 'A dispatched order; a return authorised.', 'steps' => 'Receive the return; check availability before and after inspection.',
                'tests' => ["{$w}ReturnsQualityRecallTest::test_a_return_is_received_into_quarantine_at_cost_and_inspected_before_it_is_stock"], 'evidence' => ['TEST']],
            'QA-012' => ['scenario' => 'Batch recall is initiated', 'required' => 'Affected on-hand, in-transit and traceable downstream stock is identified',
                'setup' => 'One batch: 7 on the shelf, 2 on a transfer truck, 1 with a customer; a serial range partly shipped.', 'steps' => 'Open the recall, read its impact, hold everything.',
                'tests' => ["{$w}ReturnsQualityRecallTest::test_a_batch_recall_finds_stock_on_hand_in_transit_and_downstream", "{$w}ReturnsQualityRecallTest::test_a_serial_range_recall_holds_the_units_and_finds_the_customers_who_have_the_rest"],
                'evidence' => ['TEST']],
            'QA-013' => ['scenario' => 'Accounting posting service is temporarily unavailable', 'required' => 'Inventory transaction and posting status remain consistent and recoverable',
                'setup' => 'The fiscal period closed; an accounting treatment suspended.', 'steps' => 'Receive stock; reopen; retry with the same key; reconcile stock against the ledger.',
                'tests' => ["{$w}AcceptanceQaTest::test_qa013_when_accounting_cannot_post_the_stock_does_not_move_and_the_work_recovers",
                    "{$w}AccountingTest::test_an_adjustment_posts_to_the_mapped_account_and_a_suspended_treatment_stops_it",
                    "{$w}AccountingTest::test_reconciliation_finds_a_corrupted_balance_and_an_unposted_movement_and_clears_when_fixed"], 'evidence' => ['TEST']],
            'QA-014' => ['scenario' => 'Posting request is retried after a timeout', 'required' => 'No duplicate accounting journal',
                'setup' => 'A receipt with an idempotency key.', 'steps' => 'Post it twice.',
                'tests' => ["{$w}AcceptanceQaTest::test_qa014_a_retried_posting_makes_one_movement_and_one_journal"], 'evidence' => ['TEST']],
            'QA-015' => ['scenario' => 'Integration receives an invalid or duplicate external event', 'required' => 'Event rejected or safely deduplicated with an auditable result',
                'setup' => 'An active ERP connection; a webhook-facing API with Idempotency-Key.', 'steps' => 'Send the same order twice, an order naming an unknown product, the same API command twice and with a changed body.',
                'tests' => ["{$w}IntegrationsTest::test_erp_orders_and_stock_levels_go_through_the_integration_hub_and_events_are_exported_once",
                    "{$w}IntegrationsTest::test_the_api_feed_types_webhooks_idempotency_and_warehouse_scoped_tokens", "{$w}FulfilmentTest::test_the_api_takes_an_order_once_and_reports_what_can_be_promised"],
                'evidence' => ['TEST']],
            'QA-016' => ['scenario' => 'User attempts to approve their own restricted adjustment', 'required' => 'Approval denied when segregation-of-duties policy prohibits it',
                'setup' => 'An adjustment needing approval; an approval policy with two levels.', 'steps' => 'The requester approves; a second level by the same approver; an emergency override.',
                'tests' => ["{$w}InventoryLedgerTest::test_a_risky_adjustment_waits_and_nobody_approves_their_own", "{$w}WorkflowTest::test_an_adjustment_passes_every_level_its_value_needs_by_different_people_and_a_delegate_can_act",
                    "{$w}GovernanceTest::test_the_emergency_override_lets_a_person_approve_their_own_request_only_inside_it_and_is_reviewed_by_another"], 'evidence' => ['TEST']],
            'QA-017' => ['scenario' => 'Offline device submits a conflicting movement', 'required' => 'Conflict detected and handled safely without silent stock corruption',
                'setup' => 'A pick recorded offline, then picked by somebody else.', 'steps' => 'Sync the offline action.',
                'tests' => ["{$w}FloorOfflineTest::test_an_offline_pick_that_no_longer_fits_is_held_for_review_not_forced_through"], 'evidence' => ['TEST', 'DRILL']],
            'QA-018' => ['scenario' => 'Barcode contains an invalid batch or serial attribute', 'required' => 'Validation error shown; no incorrect posting',
                'setup' => 'GS1 scans with a bad check digit, a malformed date, a missing batch.', 'steps' => 'Scan them; receive a lot-tracked item without a batch.',
                'tests' => ["{$w}ProductMasterTest::test_an_invalid_gs1_scan_is_refused_with_the_reason", "{$w}ProductMasterTest::test_a_barcode_with_a_wrong_check_digit_is_refused",
                    "{$w}InboundTest::test_a_lot_tracked_item_needs_its_batch_and_a_serial_tracked_one_a_serial_per_unit"], 'evidence' => ['TEST']],
            'QA-019' => ['scenario' => 'Large warehouse report is requested', 'required' => 'Export completes without blocking core inventory operations',
                'setup' => 'A report above the synchronous row limit, or asked for in the background.', 'steps' => 'Request it; the queued job builds it; the requester is notified.',
                'tests' => ["{$w}InsightsTest::test_a_background_export_is_built_by_the_job_notifies_and_downloads_only_for_its_requester"], 'evidence' => ['TEST', 'BENCHMARK']],
            'QA-020' => ['scenario' => 'Application restarts during a multi-line transfer', 'required' => 'Transaction remains atomic; no partial posting',
                'setup' => 'A two-line transfer whose second line loses its stock after picking.', 'steps' => 'Dispatch: the second leg fails mid-way.',
                'tests' => ["{$w}ReliabilityTest::test_a_multi_line_transfer_posts_whole_or_not_at_all"], 'evidence' => ['TEST', 'DRILL']],
            'QA-021' => ['scenario' => 'Warehouse changes its local timezone', 'required' => 'Historic timestamps remain accurate',
                'setup' => 'A receipt at 21:30 UTC in a Johannesburg warehouse.', 'steps' => 'Move the warehouse to Dubai time; read the stored instant and the EPCIS event.',
                'tests' => ["{$w}AcceptanceQaTest::test_qa021_changing_a_warehouses_time_zone_keeps_every_historic_instant"], 'evidence' => ['TEST']],
            'QA-022' => ['scenario' => 'Product unit conversion changes', 'required' => 'Historic quantities remain unchanged and new conversions are validated',
                'setup' => 'A movement made in cases.', 'steps' => 'Try to resize the case; use a unit with no conversion.',
                'tests' => ["{$w}ProductMasterTest::test_a_movement_keeps_the_unit_it_was_made_in_and_that_unit_then_cannot_be_resized", "{$w}ProductMasterTest::test_a_unit_with_no_conversion_is_refused_rather_than_treated_as_one"],
                'evidence' => ['TEST']],
            'QA-023' => ['scenario' => 'User attempts an unauthorised export', 'required' => 'Export denied and sensitive access logged as required',
                'setup' => 'A viewer without the investigator grant.', 'steps' => 'Ask for the loss-findings export; download a sensitive export as a permitted user.',
                'tests' => ["{$w}AcceptanceQaTest::test_qa023_an_unauthorised_export_is_refused_and_recorded", "{$w}InsightsTest::test_an_export_carries_its_filters_and_time_shows_values_only_when_allowed_and_neutralises_formulas"],
                'evidence' => ['TEST']],
            'QA-024' => ['scenario' => 'Forecasting data is insufficient', 'required' => 'Recommendation displays the limitation instead of presenting unsupported certainty',
                'setup' => 'An item with too little history.', 'steps' => 'Run the forecast; read the buying advice.',
                'tests' => ["{$w}PlanningTest::test_methods_follow_the_demand_and_too_little_history_gives_no_number"], 'evidence' => ['TEST']],
            'QA-025' => ['scenario' => 'AI attempts to approve a financial write-off without authority', 'required' => 'Action blocked and logged',
                'setup' => 'The warehouse assistant asked about write-offs.', 'steps' => 'Ask it to approve a write-off and to post an adjustment.',
                'tests' => ["{$w}AcceptanceQaTest::test_qa025_the_assistant_cannot_approve_a_write_off_and_the_attempt_is_recorded", "{$w}AssistantTest::test_a_draft_is_made_once_only_within_the_askers_rights_and_can_be_withdrawn"],
                'evidence' => ['TEST']],
            'QA-026' => ['scenario' => 'Backup is restored to a test environment', 'required' => 'Ledger integrity and required references reconcile',
                'setup' => 'A restored copy of production.', 'steps' => 'Run wms:verify-restore; a missing document reference is reported.',
                'tests' => ["{$w}ReliabilityTest::test_health_shows_stalled_scheduled_work_and_a_restore_check_finds_missing_documents"], 'evidence' => ['TEST', 'DRILL']],
            'QA-027' => ['scenario' => 'Inventory balance projection is intentionally corrupted in testing', 'required' => 'Reconciliation identifies the discrepancy against the ledger',
                'setup' => 'A balance edited directly in the database.', 'steps' => 'Run wms:verify-ledger and the accounting reconciliation.',
                'tests' => ["{$w}ReliabilityTest::test_the_ledger_check_finds_a_corrupted_projection_and_status_changes_are_not_false_alarms",
                    "{$w}AccountingTest::test_reconciliation_finds_a_corrupted_balance_and_an_unposted_movement_and_clears_when_fixed"], 'evidence' => ['TEST']],
            'QA-028' => ['scenario' => 'High-volume concurrent stock operations run', 'required' => 'Agreed performance targets met without ledger corruption',
                'setup' => '2,000 items, 3 warehouses, 600 bins; 8 concurrent processes.', 'steps' => 'Run wms:benchmark on the deployment architecture; the ledger is verified afterwards.',
                'tests' => ["{$w}FulfilmentTest::test_two_orders_cannot_both_have_the_last_units"], 'evidence' => ['BENCHMARK', 'TEST']],
        ];
    }
}
