# Data model

Generated Mon, Oct 12, 2026 11:55 PM by `php artisan wms:handover-docs` -- do not edit by hand; regenerate.

Every table the warehouse owns (`wms_*`) and the Finance stock tables it extends (`fin_stock_*`, `fin_warehouses`). Finance's stock ledger is the only ledger; nothing here duplicates it. Columns, keys, constraints, triggers and row-level security are read from the database.

Tables: 88.

## fin_stock_balances

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('fin_stock_balances_id_seq'::reg... |
| company_id | bigint | yes | (NULLIF(current_setting('app.company_id'... |
| item_id | bigint | no |  |
| warehouse_id | bigint | no |  |
| qty | numeric | no | '0'::numeric |
| created_at | timestamp without time zone | yes |  |
| updated_at | timestamp without time zone | yes |  |
| entity_id | bigint | yes | → fin_entities (set null) |

- Unique: `(id)`
- Unique: `(item_id, warehouse_id)`
- Check `fin_stock_balances_tenant_row_has_a_business`: `CHECK (((company_id IS NULL) OR (entity_id IS NOT NULL)))`

## fin_stock_bins

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('fin_stock_bins_id_seq'::regclas... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| entity_id | bigint | yes | → fin_entities (set null) |
| warehouse_id | bigint | no | → fin_warehouses (cascade) |
| code | character varying | no |  |
| name | character varying | yes |  |
| kind | character varying | no | 'STORAGE'::character varying |
| is_active | boolean | no | true |
| created_at | timestamp without time zone | yes |  |
| updated_at | timestamp without time zone | yes |  |
| parent_id | bigint | yes | → fin_stock_bins (cascade) |
| level | character varying | no | 'BIN'::character varying |
| barcode | character varying | yes |  |
| status | character varying | no | 'OPEN'::character varying |
| status_reason | text | yes |  |
| restricted_until | timestamp with time zone | yes |  |
| max_units | numeric | yes |  |
| max_weight_kg | numeric | yes |  |
| max_volume_m3 | numeric | yes |  |
| max_pallets | integer | yes |  |
| length_cm | numeric | yes |  |
| width_cm | numeric | yes |  |
| height_cm | numeric | yes |  |
| requires_temperature_monitoring | boolean | no | false |
| temperature_min_c | numeric | yes |  |
| temperature_max_c | numeric | yes |  |
| hazmat_allowed | boolean | no | false |
| handling_notes | text | yes |  |
| pick_sequence | integer | yes |  |
| frozen_by_count_id | bigint | yes | → wms_counts (set null) |

- Unique: `(id)`
- Unique: `(uuid)`
- Unique: `(company_id, warehouse_id, code)`
- Unique: `(company_id, barcode) WHERE (barcode IS NOT NULL)`
- Check `fin_bins_level_chk`: `CHECK (((level)::text = ANY ((ARRAY['ZONE'::character varying, 'AISLE'::character varying, 'RACK'::character varying, 'SHELF'::character varying, 'BIN'::character varying])::text[])))`
- Check `fin_bins_status_chk`: `CHECK (((status)::text = ANY ((ARRAY['OPEN'::character varying, 'BLOCKED'::character varying, 'RESTRICTED'::character varying])::text[])))`
- Check `fin_stock_bins_tenant_row_has_a_business`: `CHECK (((company_id IS NULL) OR (entity_id IS NOT NULL))) NOT VALID`

## fin_stock_items

Row-level security: off.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('fin_stock_items_id_seq'::regcla... |
| uuid | uuid | no | gen_random_uuid() |
| company_id | bigint | yes |  |
| code | character varying | no |  |
| name | character varying | no |  |
| cost_method | character varying | no | 'weighted_avg'::character varying |
| uom | character varying | yes |  |
| standard_cost | numeric | no | '0'::numeric |
| qty_on_hand | numeric | no | '0'::numeric |
| avg_cost | numeric | no | '0'::numeric |
| inventory_account_id | bigint | yes |  |
| cogs_account_id | bigint | yes |  |
| is_active | boolean | no | true |
| created_at | timestamp without time zone | yes |  |
| updated_at | timestamp without time zone | yes |  |
| reorder_level | numeric | no | '0'::numeric |
| entity_id | bigint | yes | → fin_entities (set null) |
| status | character varying | no | 'ACTIVE'::character varying |
| status_reason | text | yes |  |
| status_changed_at | timestamp with time zone | yes |  |
| owner_party_id | bigint | yes | → fin_parties (set null) |
| is_valued | boolean | no | true |

- Unique: `(id)`
- Unique: `(uuid)`
- Unique: `(company_id, COALESCE(entity_id, (0)::bigint), code)`
- Check `fin_items_owned_not_valued`: `CHECK (((owner_party_id IS NULL) OR (is_valued = false)))`
- Check `fin_items_status_chk`: `CHECK (((status)::text = ANY ((ARRAY['ACTIVE'::character varying, 'DISCONTINUED'::character varying, 'BLOCKED'::character varying, 'RECALLED'::character varying, 'QUARANTINED'::character varying, 'OBS...`
- Check `fin_stock_items_tenant_row_has_a_business`: `CHECK (((company_id IS NULL) OR (entity_id IS NOT NULL)))`

## fin_stock_layers

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('fin_stock_layers_id_seq'::regcl... |
| item_id | bigint | no |  |
| received_at | date | no |  |
| qty_remaining | numeric | no |  |
| unit_cost | numeric | no |  |
| created_at | timestamp without time zone | yes |  |
| updated_at | timestamp without time zone | yes |  |
| company_id | bigint | yes | (NULLIF(current_setting('app.company_id'... |
| entity_id | bigint | yes | → fin_entities (set null) |

- Unique: `(id)`
- Check `fin_stock_layers_tenant_row_has_a_business`: `CHECK (((company_id IS NULL) OR (entity_id IS NOT NULL)))`

## fin_stock_lot_links

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('fin_stock_lot_links_id_seq'::re... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| entity_id | bigint | yes | → fin_entities (set null) |
| parent_lot_id | bigint | no | → fin_stock_lots (cascade) |
| child_lot_id | bigint | no | → fin_stock_lots (cascade) |
| kind | character varying | no |  |
| qty | numeric | no |  |
| movement_id | bigint | yes | → fin_stock_movements (set null) |
| created_by | bigint | yes | → users (set null) |
| created_at | timestamp without time zone | yes |  |
| updated_at | timestamp without time zone | yes |  |

- Unique: `(id)`
- Check `fin_stock_lot_links_tenant_row_has_a_business`: `CHECK (((company_id IS NULL) OR (entity_id IS NOT NULL)))`

## fin_stock_lots

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('fin_stock_lots_id_seq'::regclas... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| entity_id | bigint | yes | → fin_entities (set null) |
| item_id | bigint | no | → fin_stock_items (cascade) |
| lot_number | character varying | no |  |
| kind | character varying | no | 'LOT'::character varying |
| manufactured_on | date | yes |  |
| expires_on | date | yes |  |
| origin | character varying | no | 'RECEIPT'::character varying |
| supplier_id | bigint | yes |  |
| supplier_lot_number | character varying | yes |  |
| source_type | character varying | yes |  |
| source_id | bigint | yes |  |
| notes | text | yes |  |
| created_at | timestamp without time zone | yes |  |
| updated_at | timestamp without time zone | yes |  |

- Unique: `(id)`
- Unique: `(uuid)`
- Unique: `(company_id, item_id, lot_number)`
- Check `fin_stock_lots_tenant_row_has_a_business`: `CHECK (((company_id IS NULL) OR (entity_id IS NOT NULL))) NOT VALID`

## fin_stock_movements

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('fin_stock_movements_id_seq'::re... |
| uuid | uuid | no | gen_random_uuid() |
| item_id | bigint | no |  |
| type | character varying | no |  |
| qty | numeric | no |  |
| unit_cost | numeric | no | '0'::numeric |
| value | numeric | no |  |
| reference | character varying | yes |  |
| movement_date | date | no |  |
| journal_id | bigint | yes |  |
| created_by | bigint | yes |  |
| created_at | timestamp without time zone | yes |  |
| updated_at | timestamp without time zone | yes |  |
| company_id | bigint | yes | (NULLIF(current_setting('app.company_id'... |
| warehouse_id | bigint | yes |  |
| to_warehouse_id | bigint | yes |  |
| entity_id | bigint | yes | → fin_entities (set null) |
| lot_id | bigint | yes | → fin_stock_lots (set null) |
| bin_id | bigint | yes | → fin_stock_bins (set null) |
| to_bin_id | bigint | yes | → fin_stock_bins (set null) |
| stock_status | character varying | yes |  |
| to_stock_status | character varying | yes |  |
| source_type | character varying | yes |  |
| source_id | bigint | yes |  |
| idempotency_key | character varying | yes |  |
| reversal_of_id | bigint | yes | → fin_stock_movements (set null) |
| to_lot_id | bigint | yes | → fin_stock_lots (set null) |
| txn_uom_id | bigint | yes | → mfg_uoms (set null) |
| txn_qty | numeric | yes |  |
| reason_code | character varying | yes |  |
| occurred_at | timestamp with time zone | yes |  |
| approved_by | bigint | yes | → users (set null) |
| approval_reference | character varying | yes |  |
| handling_unit | character varying | yes |  |

- Unique: `(id)`
- Unique: `(uuid)`
- Unique: `(company_id, idempotency_key) WHERE (idempotency_key IS NOT NULL)`
- Unique: `(reversal_of_id) WHERE (reversal_of_id IS NOT NULL)`
- Check `fin_stock_movements_tenant_row_has_a_business`: `CHECK (((company_id IS NULL) OR (entity_id IS NOT NULL)))`
- Trigger: `fin_stock_movements_immutable`
- Trigger: `fin_stock_movements_meta_immutable`

## fin_stock_positions

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('fin_stock_positions_id_seq'::re... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| entity_id | bigint | yes | → fin_entities (set null) |
| item_id | bigint | no | → fin_stock_items (cascade) |
| warehouse_id | bigint | no | → fin_warehouses (cascade) |
| bin_id | bigint | yes | → fin_stock_bins (cascade) |
| lot_id | bigint | yes | → fin_stock_lots (cascade) |
| status | character varying | no | 'AVAILABLE'::character varying |
| qty | numeric | no | '0'::numeric |
| created_at | timestamp without time zone | yes |  |
| updated_at | timestamp without time zone | yes |  |

- Unique: `(id)`
- Unique: `(item_id, warehouse_id, COALESCE(bin_id, (0)::bigint), COALESCE(lot_id, (0)::bigint), status)`
- Check `fin_stock_positions_tenant_row_has_a_business`: `CHECK (((company_id IS NULL) OR (entity_id IS NOT NULL))) NOT VALID`
- Trigger: `wms_serial_single_unit`

## fin_stock_reservations

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('fin_stock_reservations_id_seq':... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| entity_id | bigint | yes | → fin_entities (set null) |
| item_id | bigint | no | → fin_stock_items (cascade) |
| warehouse_id | bigint | no | → fin_warehouses (cascade) |
| lot_id | bigint | yes | → fin_stock_lots (cascade) |
| qty | numeric | no |  |
| qty_consumed | numeric | no | '0'::numeric |
| source_type | character varying | no |  |
| source_id | bigint | no |  |
| status | character varying | no | 'OPEN'::character varying |
| release_reason | text | yes |  |
| released_at | timestamp with time zone | yes |  |
| created_by | bigint | yes | → users (set null) |
| created_at | timestamp without time zone | yes |  |
| updated_at | timestamp without time zone | yes |  |

- Unique: `(id)`
- Unique: `(uuid)`
- Unique: `(source_type, source_id, item_id, warehouse_id, COALESCE(lot_id, (0)::bigint)) WHERE ((status)::text = 'OPEN'::text)`
- Check `fin_stock_reservations_tenant_row_has_a_business`: `CHECK (((company_id IS NULL) OR (entity_id IS NOT NULL))) NOT VALID`

## fin_warehouses

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('fin_warehouses_id_seq'::regclas... |
| uuid | uuid | no | gen_random_uuid() |
| company_id | bigint | yes | (NULLIF(current_setting('app.company_id'... |
| code | character varying | no |  |
| name | character varying | no |  |
| address | character varying | yes |  |
| is_default | boolean | no | false |
| is_active | boolean | no | true |
| created_at | timestamp without time zone | yes |  |
| updated_at | timestamp without time zone | yes |  |
| entity_id | bigint | yes | → fin_entities (set null) |
| warehouse_type | character varying | no | 'GENERAL'::character varying |
| timezone | character varying | no | 'Africa/Johannesburg'::character varying |
| work_calendar_id | bigint | yes | → attendance_work_calendars (set null) |
| manager_user_id | bigint | yes | → users (set null) |
| sheq_site_id | bigint | yes | → sheq_sites (set null) |
| sync_from_site | boolean | no | false |
| site_synced_at | timestamp with time zone | yes |  |
| capacity_basis | character varying | yes |  |
| capacity_value | numeric | yes |  |
| opening_time | time without time zone | yes |  |
| closing_time | time without time zone | yes |  |
| dispatch_cutoff_time | time without time zone | yes |  |
| permitted_categories | jsonb | yes |  |
| policies | jsonb | yes |  |
| is_wms_managed | boolean | no | false |

- Unique: `(id)`
- Unique: `(uuid)`
- Unique: `(company_id, COALESCE(entity_id, (0)::bigint), code)`
- Check `fin_warehouses_tenant_row_has_a_business`: `CHECK (((company_id IS NULL) OR (entity_id IS NOT NULL)))`

## wms_accounting_issues

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_accounting_issues_id_seq'::... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| kind | character varying | no |  |
| severity | character varying | no | 'MEDIUM'::character varying |
| reference | character varying | no |  |
| message | text | no |  |
| amount | numeric | no | '0'::numeric |
| detail | jsonb | yes |  |
| status | character varying | no | 'OPEN'::character varying |
| resolution | text | yes |  |
| resolved_by | bigint | yes | → users (set null) |
| resolved_at | timestamp with time zone | yes |  |
| last_seen_at | timestamp with time zone | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(uuid)`
- Unique: `(company_id, reference) WHERE ((status)::text = 'OPEN'::text)`

## wms_accounting_rules

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_accounting_rules_id_seq'::r... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| event | character varying | no |  |
| owner | character varying | no |  |
| treatment | character varying | no |  |
| debit | character varying | yes |  |
| credit | character varying | yes |  |
| description | text | no |  |
| approved_by | bigint | yes | → users (set null) |
| approved_at | timestamp with time zone | yes |  |
| suspended_at | timestamp with time zone | yes |  |
| suspended_reason | character varying | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(company_id, event)`

## wms_allocations

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_allocations_id_seq'::regcla... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| order_line_id | bigint | no | → wms_order_lines (cascade) |
| reservation_id | bigint | yes | → fin_stock_reservations (set null) |
| bin_id | bigint | yes | → fin_stock_bins (set null) |
| lot_id | bigint | yes | → fin_stock_lots (set null) |
| qty | numeric | no |  |
| qty_picked | numeric | no | '0'::numeric |
| qty_dispatched | numeric | no | '0'::numeric |
| status | character varying | no | 'ACTIVE'::character varying |
| release_reason | text | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |
| override_reason | character varying | yes |  |
| override_by | bigint | yes | → users (set null) |

- Unique: `(id)`
- Check `wms_alloc_qty`: `CHECK (((qty > (0)::numeric) AND (qty_picked <= (qty + 0.0001))))`

## wms_approval_policies

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_approval_policies_id_seq'::... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| subject_type | character varying | no |  |
| levels | jsonb | no |  |
| due_hours | integer | no | 24 |
| escalate_to_permissions | jsonb | yes |  |
| is_active | boolean | no | true |
| updated_by | bigint | yes | → users (set null) |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(company_id, subject_type)`

## wms_approval_steps

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_approval_steps_id_seq'::reg... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| subject_type | character varying | no |  |
| subject_id | bigint | no |  |
| subject_ref | character varying | no |  |
| level | smallint | no |  |
| name | character varying | no |  |
| permission | character varying | no |  |
| value | numeric | no | '0'::numeric |
| status | character varying | no |  |
| requested_by | bigint | yes | → users (set null) |
| due_at | timestamp with time zone | no |  |
| escalated_at | timestamp with time zone | yes |  |
| decided_by | bigint | yes | → users (set null) |
| on_behalf_of | bigint | yes | → users (set null) |
| decided_at | timestamp with time zone | yes |  |
| note | character varying | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |
| override_id | bigint | yes | → wms_overrides (set null) |

- Unique: `(id)`
- Unique: `(subject_type, subject_id, level)`
- Check `wms_approval_step_status_chk`: `CHECK (((status)::text = ANY ((ARRAY['PENDING'::character varying, 'APPROVED'::character varying, 'REJECTED'::character varying, 'SKIPPED'::character varying])::text[])))`

## wms_assistant_actions

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_assistant_actions_id_seq'::... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| query_id | bigint | no | → wms_assistant_queries (cascade) |
| rec_index | smallint | no |  |
| kind | character varying | no |  |
| status | character varying | no | 'DRAFT'::character varying |
| summary | text | no |  |
| payload | jsonb | yes |  |
| result_type | character varying | yes |  |
| result_reference | character varying | yes |  |
| idempotency_key | character varying | no |  |
| created_by | bigint | yes | → users (set null) |
| decided_by | bigint | yes | → users (set null) |
| decided_at | timestamp with time zone | yes |  |
| notes | character varying | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(company_id, idempotency_key)`
- Unique: `(uuid)`

## wms_assistant_feedback

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_assistant_feedback_id_seq':... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| query_id | bigint | no | → wms_assistant_queries (cascade) |
| rec_index | smallint | yes |  |
| verdict | character varying | no |  |
| note | character varying | yes |  |
| user_id | bigint | yes | → users (set null) |
| created_at | timestamp with time zone | no | CURRENT_TIMESTAMP |

- Unique: `(id)`

## wms_assistant_queries

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_assistant_queries_id_seq'::... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| user_id | bigint | yes | → users (set null) |
| question | text | no |  |
| topic | character varying | yes |  |
| refused | boolean | no | false |
| answer | jsonb | no |  |
| narration | text | yes |  |
| narration_source | character varying | no | 'TEMPLATE'::character varying |
| latency_ms | integer | no | 0 |
| tokens | integer | no | 0 |
| cost | numeric | no | '0'::numeric |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(uuid)`

## wms_audit_events

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_audit_events_id_seq'::regcl... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| sequence | bigint | no |  |
| occurred_at | timestamp with time zone | no |  |
| actor_id | bigint | yes | → users (set null) |
| actor_label | character varying | yes |  |
| actor_type | character varying | no |  |
| action | character varying | no |  |
| entity_type | character varying | no |  |
| entity_ref | character varying | yes |  |
| summary | character varying | no |  |
| before | jsonb | yes |  |
| after | jsonb | yes |  |
| ip | character varying | yes |  |
| user_agent | character varying | yes |  |
| previous_hash | character | yes |  |
| hash | character | no |  |
| created_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(company_id, sequence)`
- Trigger: `wms_audit_append_only`

## wms_carrier_rates

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_carrier_rates_id_seq'::regc... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| carrier_id | bigint | no | → wms_carriers (cascade) |
| service | character varying | no |  |
| zone | character varying | yes |  |
| max_weight_kg | numeric | no |  |
| price | numeric | no |  |
| transit_days | smallint | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`

## wms_carriers

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_carriers_id_seq'::regclass) |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| code | character varying | no |  |
| name | character varying | no |  |
| driver | character varying | no | 'MANUAL'::character varying |
| account_ref | character varying | yes |  |
| credentials | text | yes |  |
| settings | jsonb | yes |  |
| tracking_url | character varying | yes |  |
| is_active | boolean | no | true |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(company_id, code)`
- Unique: `(uuid)`
- Check `wms_carrier_driver_chk`: `CHECK (((driver)::text = 'MANUAL'::text))`

## wms_client_access

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_client_access_id_seq'::regc... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| user_id | bigint | no | → users (cascade) |
| party_id | bigint | no | → fin_parties (cascade) |
| granted_by | bigint | yes | → users (set null) |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(user_id, party_id)`

## wms_count_entries

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_count_entries_id_seq'::regc... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| count_line_id | bigint | no | → wms_count_lines (cascade) |
| round | smallint | no |  |
| user_id | bigint | yes | → users (set null) |
| qty | numeric | no |  |
| serials | jsonb | yes |  |
| client_ref | character varying | yes |  |
| duplicate | boolean | no | false |
| expected_at_entry | numeric | yes |  |
| moved_since_snapshot | numeric | no | '0'::numeric |
| counted_at | timestamp with time zone | no |  |
| created_at | timestamp with time zone | no | CURRENT_TIMESTAMP |

- Unique: `(id)`
- Unique: `(company_id, client_ref) WHERE (client_ref IS NOT NULL)`
- Trigger: `wms_count_entries_append_only`

## wms_count_lines

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_count_lines_id_seq'::regcla... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| count_id | bigint | no | → wms_counts (cascade) |
| bin_id | bigint | yes | → fin_stock_bins (set null) |
| item_id | bigint | yes | → fin_stock_items (set null) |
| lot_id | bigint | yes | → fin_stock_lots (set null) |
| snapshot_qty | numeric | no | '0'::numeric |
| expected_qty | numeric | yes |  |
| counted_qty | numeric | yes |  |
| variance_qty | numeric | yes |  |
| unit_cost | numeric | no | '0'::numeric |
| variance_value | numeric | yes |  |
| round | smallint | no | '1'::smallint |
| status | character varying | no | 'OPEN'::character varying |
| unexpected | boolean | no | false |
| found_empty | boolean | no | false |
| causes | jsonb | yes |  |
| investigation | text | yes |  |
| adjustment_id | bigint | yes | → wms_stock_adjustments (set null) |
| decided_by | bigint | yes | → users (set null) |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`

## wms_count_plans

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_count_plans_id_seq'::regcla... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| name | character varying | no |  |
| warehouse_id | bigint | no | → fin_warehouses (cascade) |
| frequency | character varying | no | 'ONCE'::character varying |
| scope | character varying | no | 'WAREHOUSE'::character varying |
| scope_ids | jsonb | yes |  |
| abc_class | character varying | yes |  |
| min_value | numeric | yes |  |
| max_lines | smallint | yes |  |
| blind | boolean | no | true |
| freeze | boolean | no | false |
| recount_qty | numeric | yes |  |
| recount_pct | numeric | yes |  |
| recount_value | numeric | yes |  |
| deadline_days | smallint | no | '2'::smallint |
| team | jsonb | yes |  |
| next_run_at | timestamp with time zone | yes |  |
| last_run_at | timestamp with time zone | yes |  |
| is_active | boolean | no | true |
| created_by | bigint | yes | → users (set null) |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(uuid)`

## wms_counts

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_counts_id_seq'::regclass) |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| number | character varying | no |  |
| plan_id | bigint | yes | → wms_count_plans (set null) |
| warehouse_id | bigint | no | → fin_warehouses |
| reason | character varying | yes |  |
| trigger | character varying | no | 'MANUAL'::character varying |
| status | character varying | no | 'OPEN'::character varying |
| blind | boolean | no | true |
| freeze | boolean | no | false |
| recount_qty | numeric | yes |  |
| recount_pct | numeric | yes |  |
| recount_value | numeric | yes |  |
| team | jsonb | yes |  |
| snapshot_at | timestamp with time zone | no |  |
| deadline_at | timestamp with time zone | yes |  |
| completed_at | timestamp with time zone | yes |  |
| posted_at | timestamp with time zone | yes |  |
| created_by | bigint | yes | → users (set null) |
| approved_by | bigint | yes | → users (set null) |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(company_id, number)`
- Unique: `(uuid)`

## wms_customer_return_lines

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_customer_return_lines_id_se... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| return_id | bigint | no | → wms_customer_returns (cascade) |
| item_id | bigint | no | → fin_stock_items |
| order_line_id | bigint | yes | → wms_order_lines (set null) |
| lot_id | bigint | yes | → fin_stock_lots (set null) |
| serials | jsonb | yes |  |
| qty_authorised | numeric | no |  |
| qty_received | numeric | no | '0'::numeric |
| reason_code | character varying | yes |  |
| condition | character varying | yes |  |
| unit_cost | numeric | no | '0'::numeric |
| bin_id | bigint | yes | → fin_stock_bins (set null) |
| inspection_id | bigint | yes | → mfg_inspections (set null) |
| outcome | character varying | yes |  |
| movement_id | bigint | yes | → fin_stock_movements (set null) |
| notes | text | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`

## wms_customer_returns

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_customer_returns_id_seq'::r... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| number | character varying | no |  |
| warehouse_id | bigint | no | → fin_warehouses |
| order_id | bigint | yes | → wms_orders (set null) |
| invoice_id | bigint | yes | → fin_invoices (set null) |
| reference | character varying | yes |  |
| customer_party_id | bigint | yes | → fin_parties (set null) |
| customer_name | character varying | yes |  |
| status | character varying | no | 'AUTHORISED'::character varying |
| reason | character varying | yes |  |
| credit_note_id | bigint | yes | → fin_invoices (set null) |
| credit_amount | numeric | yes |  |
| created_by | bigint | yes | → users (set null) |
| received_by | bigint | yes | → users (set null) |
| received_at | timestamp with time zone | yes |  |
| completed_at | timestamp with time zone | yes |  |
| cancel_reason | character varying | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(company_id, number)`
- Unique: `(uuid)`

## wms_delegations

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_delegations_id_seq'::regcla... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| from_user_id | bigint | no | → users (cascade) |
| to_user_id | bigint | no | → users (cascade) |
| subject_types | jsonb | yes |  |
| starts_at | timestamp with time zone | no |  |
| ends_at | timestamp with time zone | no |  |
| reason | character varying | no |  |
| revoked_at | timestamp with time zone | yes |  |
| created_by | bigint | yes | → users (set null) |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Check `wms_delegation_self_chk`: `CHECK (((from_user_id <> to_user_id) AND (ends_at > starts_at)))`

## wms_dock_appointments

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_dock_appointments_id_seq'::... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| number | character varying | no |  |
| warehouse_id | bigint | no | → fin_warehouses |
| dock_bin_id | bigint | yes | → fin_stock_bins (set null) |
| purchase_order_id | bigint | yes | → purchase_orders (set null) |
| supplier_id | bigint | yes | → suppliers (set null) |
| slot_start | timestamp with time zone | no |  |
| slot_end | timestamp with time zone | no |  |
| carrier | character varying | yes |  |
| vehicle_reg | character varying | yes |  |
| driver_name | character varying | yes |  |
| driver_phone | character varying | yes |  |
| status | character varying | no | 'BOOKED'::character varying |
| arrived_at | timestamp with time zone | yes |  |
| unloading_started_at | timestamp with time zone | yes |  |
| departed_at | timestamp with time zone | yes |  |
| assigned_user_id | bigint | yes | → users (set null) |
| notes | text | yes |  |
| created_by | bigint | yes | → users (set null) |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(company_id, number)`
- Unique: `(uuid)`
- Check `wms_appt_slot_order`: `CHECK ((slot_end > slot_start))`

## wms_erp_reconciliations

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_erp_reconciliations_id_seq'... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| integration_id | bigint | no | → mfg_integrations (cascade) |
| integration_event_id | bigint | yes | → mfg_integration_events (set null) |
| compared | integer | no |  |
| differences | integer | no |  |
| unmapped | integer | no |  |
| lines | jsonb | no |  |
| created_at | timestamp with time zone | yes |  |

- Unique: `(id)`

## wms_evidence

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_evidence_id_seq'::regclass) |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| subject_type | character varying | no |  |
| subject_id | bigint | no |  |
| kind | character varying | no | 'PHOTO'::character varying |
| path | character varying | no |  |
| original_name | character varying | no |  |
| mime | character varying | yes |  |
| size | bigint | no | '0'::bigint |
| caption | character varying | yes |  |
| uploaded_by | bigint | yes | → users (set null) |
| created_at | timestamp with time zone | no | CURRENT_TIMESTAMP |

- Unique: `(id)`
- Unique: `(uuid)`
- Trigger: `wms_evidence_append_only`

## wms_expiry_alerts

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_expiry_alerts_id_seq'::regc... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| lot_id | bigint | no | → fin_stock_lots (cascade) |
| warehouse_id | bigint | no | → fin_warehouses (cascade) |
| threshold_days | smallint | no |  |
| days_left | integer | no |  |
| qty | numeric | no |  |
| at_risk_qty | numeric | no | '0'::numeric |
| exposure | numeric | no | '0'::numeric |
| alerted_at | timestamp with time zone | no | CURRENT_TIMESTAMP |

- Unique: `(id)`
- Unique: `(lot_id, warehouse_id, threshold_days)`

## wms_forecast_runs

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_forecast_runs_id_seq'::regc... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| warehouse_id | bigint | yes | → fin_warehouses (set null) |
| bucket | character varying | no | 'MONTH'::character varying |
| horizon | smallint | no |  |
| history_periods | smallint | no |  |
| params | jsonb | yes |  |
| items | integer | no | 0 |
| insufficient | integer | no | 0 |
| created_by | bigint | yes | → users (set null) |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(uuid)`

## wms_forecasts

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_forecasts_id_seq'::regclass... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| run_id | bigint | no | → wms_forecast_runs (cascade) |
| item_id | bigint | no | → fin_stock_items (cascade) |
| period_start | date | no |  |
| method | character varying | no |  |
| qty | numeric | yes |  |
| lower | numeric | yes |  |
| upper | numeric | yes |  |
| override_qty | numeric | yes |  |
| override_reason | character varying | yes |  |
| override_by | bigint | yes | → users (set null) |
| basis | jsonb | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(run_id, item_id, period_start)`

## wms_heartbeats

Row-level security: off.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| command | character varying | no |  |
| started_at | timestamp with time zone | yes |  |
| finished_at | timestamp with time zone | yes |  |
| ok | boolean | yes |  |
| runs | integer | no | 0 |
| failures | integer | no | 0 |
| details | jsonb | yes |  |

- Unique: `(command)`

## wms_idempotency_keys

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_idempotency_keys_id_seq'::r... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| user_id | bigint | no | → users (cascade) |
| key | character varying | no |  |
| method | character varying | no |  |
| path | character varying | no |  |
| request_hash | character | no |  |
| response_status | smallint | yes |  |
| response_body | text | yes |  |
| completed_at | timestamp with time zone | yes |  |
| created_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(company_id, user_id, key)`

## wms_item_barcodes

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_item_barcodes_id_seq'::regc... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| stock_item_id | bigint | no | → fin_stock_items (cascade) |
| barcode | character varying | no |  |
| symbology | character varying | no |  |
| source | character varying | no | 'SUPPLIER'::character varying |
| packaging_id | bigint | yes | → wms_item_packaging (set null) |
| supplier_id | bigint | yes | → suppliers (set null) |
| is_primary | boolean | no | false |
| is_active | boolean | no | true |
| duplicate_reason | text | yes |  |
| duplicate_approved_by | bigint | yes | → users (set null) |
| created_by | bigint | yes | → users (set null) |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(uuid)`
- Unique: `(stock_item_id, barcode)`

## wms_item_bundles

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_item_bundles_id_seq'::regcl... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| bundle_item_id | bigint | no | → fin_stock_items (cascade) |
| component_item_id | bigint | no | → fin_stock_items (cascade) |
| qty | numeric | no |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(bundle_item_id, component_item_id)`
- Check `wms_bundle_not_itself`: `CHECK ((bundle_item_id <> component_item_id))`
- Check `wms_bundle_qty_positive`: `CHECK ((qty > (0)::numeric))`

## wms_item_events

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_item_events_id_seq'::regcla... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| stock_item_id | bigint | no | → fin_stock_items (cascade) |
| action | character varying | no |  |
| changes | jsonb | yes |  |
| reason | text | yes |  |
| actor_id | bigint | yes | → users (set null) |
| created_at | timestamp with time zone | no | CURRENT_TIMESTAMP |

- Unique: `(id)`
- Trigger: `wms_item_events_append_only`

## wms_item_identifiers

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_item_identifiers_id_seq'::r... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| stock_item_id | bigint | no | → fin_stock_items (cascade) |
| kind | character varying | no |  |
| code | character varying | no |  |
| supplier_id | bigint | yes | → suppliers (set null) |
| party_name | character varying | yes |  |
| description | character varying | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(uuid)`
- Unique: `(company_id, kind, COALESCE(supplier_id, (0)::bigint), code)`

## wms_item_packaging

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_item_packaging_id_seq'::reg... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| stock_item_id | bigint | no | → fin_stock_items (cascade) |
| level | character varying | no |  |
| name | character varying | yes |  |
| uom_id | bigint | no | → mfg_uoms |
| qty_in_base | numeric | no |  |
| gtin | character varying | yes |  |
| length_cm | numeric | yes |  |
| width_cm | numeric | yes |  |
| height_cm | numeric | yes |  |
| gross_weight_kg | numeric | yes |  |
| is_active | boolean | no | true |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(stock_item_id, uom_id)`
- Unique: `(uuid)`
- Check `wms_pack_qty_positive`: `CHECK ((qty_in_base > (0)::numeric))`

## wms_item_profiles

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_item_profiles_id_seq'::regc... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| stock_item_id | bigint | no | → fin_stock_items (cascade) |
| description | text | yes |  |
| brand | character varying | yes |  |
| purchase_uom_id | bigint | yes | → mfg_uoms (set null) |
| storage_uom_id | bigint | yes | → mfg_uoms (set null) |
| issue_uom_id | bigint | yes | → mfg_uoms (set null) |
| is_variable_weight | boolean | no | false |
| safety_stock | numeric | yes |  |
| max_stock | numeric | yes |  |
| reorder_qty | numeric | yes |  |
| lead_time_days | integer | yes |  |
| min_remaining_shelf_life_days | integer | yes |  |
| expiry_warning_days | integer | yes |  |
| temperature_min_c | numeric | yes |  |
| temperature_max_c | numeric | yes |  |
| hazard_class | character varying | yes |  |
| requires_inspection | boolean | no | false |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |
| abc_class | character varying | yes |  |
| abc_computed_at | timestamp with time zone | yes |  |
| loss_risk | character varying | yes |  |
| rotation | character varying | yes |  |
| expiry_alert_days | jsonb | yes |  |

- Unique: `(id)`
- Unique: `(stock_item_id)`

## wms_kpi_baselines

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_kpi_baselines_id_seq'::regc... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| period | character | no |  |
| set_by | bigint | yes | → users (set null) |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(company_id)`

## wms_kpi_snapshots

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_kpi_snapshots_id_seq'::regc... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| period | character | no |  |
| kpi | character varying | no |  |
| definition_version | smallint | no |  |
| value | numeric | yes |  |
| numerator | numeric | yes |  |
| denominator | numeric | yes |  |
| note | character varying | yes |  |
| created_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(company_id, period, kpi, definition_version)`

## wms_label_templates

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_label_templates_id_seq'::re... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| kind | character varying | no |  |
| name | character varying | no |  |
| width_mm | numeric | no | '100'::numeric |
| height_mm | numeric | no | '50'::numeric |
| symbology | character varying | no | 'CODE128'::character varying |
| fields | jsonb | no |  |
| is_default | boolean | no | false |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(uuid)`

## wms_labour_standards

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_labour_standards_id_seq'::r... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| warehouse_id | bigint | yes | → fin_warehouses (cascade) |
| activity | character varying | no |  |
| minutes_per_task | numeric | no | '0'::numeric |
| minutes_per_unit | numeric | no | '0'::numeric |
| source | character varying | no | 'SET'::character varying |
| sample_size | integer | no | 0 |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(company_id, warehouse_id, activity)`

## wms_landed_cost_lines

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_landed_cost_lines_id_seq'::... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| landed_cost_id | bigint | no | → wms_landed_costs (cascade) |
| item_id | bigint | no | → fin_stock_items |
| movement_id | bigint | yes | → fin_stock_movements (set null) |
| qty | numeric | no |  |
| base_value | numeric | no | '0'::numeric |
| weight_kg | numeric | no | '0'::numeric |
| allocated | numeric | no | '0'::numeric |
| to_inventory | numeric | no | '0'::numeric |
| to_cogs | numeric | no | '0'::numeric |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`

## wms_landed_costs

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_landed_costs_id_seq'::regcl... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| number | character varying | no |  |
| grn_id | bigint | yes | → goods_received_notes (set null) |
| party_id | bigint | yes | → fin_parties (set null) |
| invoice_reference | character varying | yes |  |
| basis | character varying | no | 'VALUE'::character varying |
| components | jsonb | no |  |
| total | numeric | no |  |
| status | character varying | no | 'DRAFT'::character varying |
| journal_id | bigint | yes | → fin_journals (set null) |
| to_inventory | numeric | no | '0'::numeric |
| to_cogs | numeric | no | '0'::numeric |
| created_by | bigint | yes | → users (set null) |
| posted_by | bigint | yes | → users (set null) |
| posted_at | timestamp with time zone | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(company_id, number)`
- Unique: `(uuid)`

## wms_location_changes

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_location_changes_id_seq'::r... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| warehouse_id | bigint | no | → fin_warehouses (cascade) |
| bin_id | bigint | yes | → fin_stock_bins (set null) |
| action | character varying | no |  |
| changes | jsonb | yes |  |
| reason | text | yes |  |
| actor_id | bigint | yes | → users (set null) |
| created_at | timestamp with time zone | no | CURRENT_TIMESTAMP |

- Unique: `(id)`
- Trigger: `wms_location_changes_append_only`

## wms_loss_recoveries

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_loss_recoveries_id_seq'::re... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| adjustment_id | bigint | no | → wms_stock_adjustments (cascade) |
| kind | character varying | no |  |
| party_id | bigint | yes | → fin_parties (set null) |
| claim_reference | character varying | yes |  |
| amount_claimed | numeric | no |  |
| amount_recovered | numeric | no | '0'::numeric |
| status | character varying | no | 'OPEN'::character varying |
| notes | text | yes |  |
| created_by | bigint | yes | → users (set null) |
| settled_at | timestamp with time zone | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(uuid)`

## wms_notification_log

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_notification_log_id_seq'::r... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| rule_id | bigint | yes | → wms_notification_rules (set null) |
| event_type | character varying | no |  |
| dedupe_key | character varying | no |  |
| warehouse_id | bigint | yes | → fin_warehouses (set null) |
| recipient_id | bigint | no | → users (cascade) |
| level | smallint | no | '0'::smallint |
| reason | character varying | no |  |
| title | character varying | no |  |
| message | text | no |  |
| url | character varying | yes |  |
| delivery | jsonb | no |  |
| attempts | smallint | no | '0'::smallint |
| last_error | character varying | yes |  |
| next_attempt_at | timestamp with time zone | yes |  |
| occurrences | integer | no | 1 |
| first_seen_at | timestamp with time zone | no |  |
| last_seen_at | timestamp with time zone | no |  |
| sent_at | timestamp with time zone | yes |  |
| resolved_at | timestamp with time zone | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`

## wms_notification_rules

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_notification_rules_id_seq':... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| event_type | character varying | no |  |
| warehouse_id | bigint | yes | → fin_warehouses (cascade) |
| is_active | boolean | no | true |
| recipient_permissions | jsonb | no |  |
| recipient_user_ids | jsonb | yes |  |
| notify_responsible | boolean | no | true |
| channels | jsonb | no |  |
| thresholds | jsonb | yes |  |
| suppress_hours | integer | no | 24 |
| escalate_after_hours | integer | yes |  |
| escalate_to_permissions | jsonb | yes |  |
| updated_by | bigint | yes | → users (set null) |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(company_id, event_type, warehouse_id) WHERE (warehouse_id IS NOT NULL)`
- Unique: `(company_id, event_type) WHERE (warehouse_id IS NULL)`

## wms_offline_actions

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_offline_actions_id_seq'::re... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| user_id | bigint | yes | → users (set null) |
| client_ref | character varying | no |  |
| kind | character varying | no |  |
| payload | jsonb | no |  |
| recorded_at | timestamp with time zone | no |  |
| received_at | timestamp with time zone | no |  |
| status | character varying | no |  |
| message | text | yes |  |
| resolved_by | bigint | yes | → users (set null) |
| resolved_at | timestamp with time zone | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(company_id, client_ref)`

## wms_order_lines

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_order_lines_id_seq'::regcla... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| order_id | bigint | no | → wms_orders (cascade) |
| line_no | smallint | no |  |
| item_id | bigint | no | → fin_stock_items |
| qty | numeric | no |  |
| txn_uom_id | bigint | yes | → mfg_uoms (set null) |
| txn_qty | numeric | yes |  |
| qty_allocated | numeric | no | '0'::numeric |
| qty_picked | numeric | no | '0'::numeric |
| qty_packed | numeric | no | '0'::numeric |
| qty_dispatched | numeric | no | '0'::numeric |
| qty_cancelled | numeric | no | '0'::numeric |
| qty_short | numeric | no | '0'::numeric |
| lot_id | bigint | yes | → fin_stock_lots (set null) |
| substitute_of_line_id | bigint | yes | → wms_order_lines (set null) |
| source_line_id | bigint | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Check `wms_line_quantities`: `CHECK (((qty > (0)::numeric) AND (qty_dispatched <= (qty_picked + 0.0001)) AND (qty_packed <= (qty_picked + 0.0001))))`

## wms_orders

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_orders_id_seq'::regclass) |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| number | character varying | no |  |
| warehouse_id | bigint | no | → fin_warehouses |
| source | character varying | no |  |
| external_ref | character varying | no |  |
| source_id | bigint | yes |  |
| customer_party_id | bigint | yes | → fin_parties (set null) |
| customer_name | character varying | yes |  |
| ship_to | jsonb | yes |  |
| priority | smallint | no | '50'::smallint |
| required_by | timestamp with time zone | yes |  |
| sla_due_at | timestamp with time zone | yes |  |
| sla_at_risk_at | timestamp with time zone | yes |  |
| allow_partial | boolean | no | true |
| allow_substitution | boolean | no | false |
| status | character varying | no | 'NEW'::character varying |
| carrier | character varying | yes |  |
| notes | text | yes |  |
| cancel_reason | text | yes |  |
| created_by | bigint | yes | → users (set null) |
| released_at | timestamp with time zone | yes |  |
| pick_started_at | timestamp with time zone | yes |  |
| picked_at | timestamp with time zone | yes |  |
| packed_at | timestamp with time zone | yes |  |
| dispatched_at | timestamp with time zone | yes |  |
| delivered_at | timestamp with time zone | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(company_id, number)`
- Unique: `(company_id, source, external_ref)`
- Unique: `(uuid)`

## wms_outbox

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_outbox_id_seq'::regclass) |
| event_id | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| warehouse_id | bigint | yes | → fin_warehouses (set null) |
| event_type | character varying | no |  |
| schema_version | smallint | no |  |
| subject_type | character varying | no |  |
| subject_ref | character varying | no |  |
| payload | jsonb | no |  |
| occurred_at | timestamp with time zone | no |  |
| available_at | timestamp with time zone | no |  |
| published_at | timestamp with time zone | yes |  |
| attempts | smallint | no | '0'::smallint |
| last_error | text | yes |  |
| failed_at | timestamp with time zone | yes |  |
| replayed_at | timestamp with time zone | yes |  |
| created_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(event_id)`
- Trigger: `wms_outbox_immutable`

## wms_overrides

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_overrides_id_seq'::regclass... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| user_id | bigint | no | → users (cascade) |
| reason | character varying | no |  |
| subject_types | jsonb | no |  |
| starts_at | timestamp with time zone | no |  |
| expires_at | timestamp with time zone | no |  |
| ended_at | timestamp with time zone | yes |  |
| uses | integer | no | 0 |
| reviewed_by | bigint | yes | → users (set null) |
| reviewed_at | timestamp with time zone | yes |  |
| review_note | character varying | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Check `wms_override_review_chk`: `CHECK (((reviewed_by IS NULL) OR (reviewed_by <> user_id)))`
- Check `wms_override_window_chk`: `CHECK (((expires_at > starts_at) AND (expires_at <= (starts_at + '04:00:00'::interval))))`

## wms_package_contents

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_package_contents_id_seq'::r... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| package_id | bigint | no | → wms_packages (cascade) |
| order_line_id | bigint | no | → wms_order_lines (cascade) |
| item_id | bigint | no | → fin_stock_items |
| lot_id | bigint | yes | → fin_stock_lots (set null) |
| qty | numeric | no |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`

## wms_packages

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_packages_id_seq'::regclass) |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| order_id | bigint | no | → wms_orders (cascade) |
| sscc | character varying | no |  |
| type | character varying | no | 'CARTON'::character varying |
| weight_kg | numeric | yes |  |
| length_cm | numeric | yes |  |
| width_cm | numeric | yes |  |
| height_cm | numeric | yes |  |
| tracking_number | character varying | yes |  |
| status | character varying | no | 'OPEN'::character varying |
| packed_by | bigint | yes | → users (set null) |
| closed_at | timestamp with time zone | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(company_id, sscc)`
- Unique: `(uuid)`

## wms_pick_tasks

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_pick_tasks_id_seq'::regclas... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| wave_id | bigint | yes | → wms_pick_waves (set null) |
| order_id | bigint | no | → wms_orders (cascade) |
| order_line_id | bigint | no | → wms_order_lines (cascade) |
| allocation_id | bigint | no | → wms_allocations (cascade) |
| item_id | bigint | no | → fin_stock_items |
| lot_id | bigint | yes | → fin_stock_lots (set null) |
| bin_id | bigint | yes | → fin_stock_bins (set null) |
| to_bin_id | bigint | yes | → fin_stock_bins (set null) |
| qty | numeric | no |  |
| picked_qty | numeric | no | '0'::numeric |
| short_qty | numeric | no | '0'::numeric |
| sequence | integer | no | 0 |
| zone | character varying | yes |  |
| cluster_slot | smallint | yes |  |
| status | character varying | no | 'OPEN'::character varying |
| scan_errors | smallint | no | '0'::smallint |
| picked_by | bigint | yes | → users (set null) |
| started_at | timestamp with time zone | yes |  |
| completed_at | timestamp with time zone | yes |  |
| note | text | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(uuid)`
- Check `wms_pick_qty`: `CHECK (((qty > (0)::numeric) AND ((picked_qty + short_qty) <= (qty + 0.0001))))`

## wms_pick_waves

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_pick_waves_id_seq'::regclas... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| number | character varying | no |  |
| warehouse_id | bigint | no | → fin_warehouses |
| strategy | character varying | no |  |
| status | character varying | no | 'OPEN'::character varying |
| zone | character varying | yes |  |
| assigned_to | bigint | yes | → users (set null) |
| started_at | timestamp with time zone | yes |  |
| completed_at | timestamp with time zone | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(company_id, number)`
- Unique: `(uuid)`

## wms_policy_versions

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_policy_versions_id_seq'::re... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| warehouse_id | bigint | no | → fin_warehouses (cascade) |
| version | integer | no |  |
| policies | jsonb | no |  |
| changes | jsonb | no |  |
| status | character varying | no |  |
| note | character varying | yes |  |
| created_by | bigint | yes | → users (set null) |
| activated_by | bigint | yes | → users (set null) |
| activated_at | timestamp with time zone | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(warehouse_id, version)`
- Unique: `(warehouse_id) WHERE ((status)::text = 'ACTIVE'::text)`
- Check `wms_policy_status_chk`: `CHECK (((status)::text = ANY ((ARRAY['DRAFT'::character varying, 'ACTIVE'::character varying, 'SUPERSEDED'::character varying, 'DISCARDED'::character varying])::text[])))`

## wms_printers

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_printers_id_seq'::regclass) |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| warehouse_id | bigint | yes | → fin_warehouses (set null) |
| name | character varying | no |  |
| language | character varying | no | 'ZPL'::character varying |
| host | character varying | no |  |
| port | integer | no | 9100 |
| dpi | smallint | no | '203'::smallint |
| is_active | boolean | no | true |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(uuid)`

## wms_putaway_tasks

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_putaway_tasks_id_seq'::regc... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| number | character varying | no |  |
| warehouse_id | bigint | no | → fin_warehouses |
| item_id | bigint | no | → fin_stock_items |
| lot_id | bigint | yes | → fin_stock_lots (set null) |
| stock_status | character varying | no | 'AVAILABLE'::character varying |
| from_bin_id | bigint | yes | → fin_stock_bins (set null) |
| suggested_bin_id | bigint | yes | → fin_stock_bins (set null) |
| to_bin_id | bigint | yes | → fin_stock_bins (set null) |
| qty | numeric | no |  |
| done_qty | numeric | no | '0'::numeric |
| status | character varying | no | 'OPEN'::character varying |
| priority | smallint | no | '50'::smallint |
| source_type | character varying | yes |  |
| source_id | bigint | yes |  |
| assigned_to | bigint | yes | → users (set null) |
| completed_by | bigint | yes | → users (set null) |
| started_at | timestamp with time zone | yes |  |
| completed_at | timestamp with time zone | yes |  |
| exception_note | text | yes |  |
| suggestion_reasons | jsonb | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(company_id, number)`
- Unique: `(uuid)`
- Check `wms_putaway_qty_positive`: `CHECK (((qty > (0)::numeric) AND (done_qty >= (0)::numeric) AND (done_qty <= qty)))`

## wms_reason_codes

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_reason_codes_id_seq'::regcl... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| code | character varying | no |  |
| name | character varying | no |  |
| category | character varying | no |  |
| direction | character varying | no | 'BOTH'::character varying |
| approval_qty | numeric | yes |  |
| approval_value | numeric | yes |  |
| always_requires_approval | boolean | no | false |
| is_active | boolean | no | true |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |
| requires_evidence | boolean | no | false |
| approve_high_risk | boolean | no | true |

- Unique: `(id)`
- Unique: `(company_id, code)`
- Unique: `(uuid)`

## wms_receipt_attachments

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_receipt_attachments_id_seq'... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| grn_id | bigint | no | → goods_received_notes (cascade) |
| kind | character varying | no | 'DOCUMENT'::character varying |
| path | character varying | no |  |
| original_name | character varying | no |  |
| mime | character varying | yes |  |
| size | bigint | no | '0'::bigint |
| uploaded_by | bigint | yes | → users (set null) |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(uuid)`

## wms_receipt_discrepancies

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_receipt_discrepancies_id_se... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| grn_id | bigint | no | → goods_received_notes (cascade) |
| grn_item_id | bigint | yes | → goods_received_items (cascade) |
| kind | character varying | no |  |
| expected | numeric | yes |  |
| actual | numeric | yes |  |
| within_tolerance | boolean | no | false |
| detail | text | yes |  |
| status | character varying | no | 'OPEN'::character varying |
| resolution | text | yes |  |
| resolved_by | bigint | yes | → users (set null) |
| resolved_at | timestamp with time zone | yes |  |
| notified_at | timestamp with time zone | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(uuid)`

## wms_replenishment_rules

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_replenishment_rules_id_seq'... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| warehouse_id | bigint | no | → fin_warehouses (cascade) |
| item_id | bigint | no | → fin_stock_items (cascade) |
| pick_bin_id | bigint | yes | → fin_stock_bins (set null) |
| source | character varying | no | 'BULK'::character varying |
| source_warehouse_id | bigint | yes | → fin_warehouses (set null) |
| min_qty | numeric | no |  |
| max_qty | numeric | no |  |
| multiple | numeric | yes |  |
| is_active | boolean | no | true |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(warehouse_id, item_id, pick_bin_id)`
- Unique: `(uuid)`
- Check `wms_repl_min_max`: `CHECK (((min_qty >= (0)::numeric) AND (max_qty > min_qty)))`

## wms_replenishment_tasks

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_replenishment_tasks_id_seq'... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| number | character varying | no |  |
| rule_id | bigint | yes | → wms_replenishment_rules (set null) |
| warehouse_id | bigint | no | → fin_warehouses |
| item_id | bigint | no | → fin_stock_items |
| lot_id | bigint | yes | → fin_stock_lots (set null) |
| from_bin_id | bigint | yes | → fin_stock_bins (set null) |
| to_bin_id | bigint | yes | → fin_stock_bins (set null) |
| qty | numeric | no |  |
| done_qty | numeric | no | '0'::numeric |
| priority | smallint | no | '50'::smallint |
| priority_reasons | jsonb | yes |  |
| status | character varying | no | 'OPEN'::character varying |
| transfer_id | bigint | yes | → wms_transfer_orders (set null) |
| completed_by | bigint | yes | → users (set null) |
| completed_at | timestamp with time zone | yes |  |
| exception_note | text | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(company_id, number)`
- Unique: `(uuid)`
- Unique: `(rule_id) WHERE (((status)::text = 'OPEN'::text) AND (rule_id IS NOT NULL))`

## wms_report_runs

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_report_runs_id_seq'::regcla... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| report | character varying | no |  |
| title | character varying | no |  |
| parameters | jsonb | no |  |
| format | character varying | no |  |
| with_values | boolean | no | false |
| sensitive | boolean | no | false |
| status | character varying | no |  |
| rows | integer | yes |  |
| file_path | character varying | yes |  |
| failure_reason | character varying | yes |  |
| downloads | integer | no | 0 |
| requested_by | bigint | no | → users (cascade) |
| generated_at | timestamp with time zone | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(uuid)`
- Check `wms_report_format_chk`: `CHECK (((format)::text = ANY ((ARRAY['CSV'::character varying, 'XLSX'::character varying, 'PDF'::character varying])::text[])))`

## wms_revaluations

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_revaluations_id_seq'::regcl... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| number | character varying | no |  |
| item_id | bigint | no | → fin_stock_items |
| qty | numeric | no |  |
| old_cost | numeric | no |  |
| new_cost | numeric | no |  |
| delta | numeric | no |  |
| reason | text | no |  |
| status | character varying | no | 'PENDING_APPROVAL'::character varying |
| requested_by | bigint | yes | → users (set null) |
| decided_by | bigint | yes | → users (set null) |
| decided_at | timestamp with time zone | yes |  |
| decision_note | character varying | yes |  |
| journal_id | bigint | yes | → fin_journals (set null) |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |
| override_id | bigint | yes | → wms_overrides (set null) |

- Unique: `(id)`
- Unique: `(company_id, number)`
- Unique: `(uuid)`
- Check `wms_reval_four_eyes`: `CHECK (((decided_by IS NULL) OR (requested_by IS NULL) OR (decided_by <> requested_by) OR (override_id IS NOT NULL)))`

## wms_settings

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_settings_id_seq'::regclass) |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| settings | jsonb | no |  |
| updated_by | bigint | yes | → users (set null) |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(company_id)`

## wms_shipment_packages

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_shipment_packages_id_seq'::... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| shipment_id | bigint | no | → wms_shipments (cascade) |
| package_id | bigint | no | → wms_packages (cascade) |

- Unique: `(id)`
- Unique: `(package_id)`

## wms_shipments

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_shipments_id_seq'::regclass... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| number | character varying | no |  |
| order_id | bigint | no | → wms_orders (cascade) |
| warehouse_id | bigint | no | → fin_warehouses |
| carrier | character varying | yes |  |
| service | character varying | yes |  |
| vehicle_reg | character varying | yes |  |
| driver_name | character varying | yes |  |
| tracking_reference | character varying | yes |  |
| collection_at | timestamp with time zone | yes |  |
| dispatched_at | timestamp with time zone | yes |  |
| handover_name | character varying | yes |  |
| handover_signature_path | character varying | yes |  |
| delivery_note_id | bigint | yes |  |
| status | character varying | no | 'DISPATCHED'::character varying |
| delivered_at | timestamp with time zone | yes |  |
| pod_received_by | character varying | yes |  |
| pod_path | character varying | yes |  |
| pod_note | text | yes |  |
| dispatched_by | bigint | yes | → users (set null) |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |
| carrier_id | bigint | yes | → wms_carriers (set null) |
| booking_reference | character varying | yes |  |
| booked_at | timestamp with time zone | yes |  |
| quoted_price | numeric | yes |  |
| tracking_status | character varying | yes |  |
| tracked_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(company_id, number)`
- Unique: `(uuid)`

## wms_simulations

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_simulations_id_seq'::regcla... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| warehouse_id | bigint | yes | → fin_warehouses (set null) |
| kind | character varying | no |  |
| name | character varying | yes |  |
| inputs | jsonb | no |  |
| results | jsonb | no |  |
| trials | integer | no |  |
| seed | bigint | no |  |
| created_by | bigint | yes | → users (set null) |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(uuid)`

## wms_slotting_proposals

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_slotting_proposals_id_seq':... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| warehouse_id | bigint | no | → fin_warehouses (cascade) |
| status | character varying | no | 'DRAFT'::character varying |
| moves | jsonb | no |  |
| simulation | jsonb | yes |  |
| created_by | bigint | yes | → users (set null) |
| approved_by | bigint | yes | → users (set null) |
| approved_at | timestamp with time zone | yes |  |
| decision_note | character varying | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(uuid)`
- Check `wms_slotting_four_eyes`: `CHECK (((approved_by IS NULL) OR (created_by IS NULL) OR (approved_by <> created_by)))`

## wms_stock_adjustments

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_stock_adjustments_id_seq'::... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| number | character varying | no |  |
| warehouse_id | bigint | no | → fin_warehouses |
| item_id | bigint | no | → fin_stock_items |
| bin_id | bigint | yes | → fin_stock_bins |
| lot_id | bigint | yes | → fin_stock_lots |
| stock_status | character varying | no | 'AVAILABLE'::character varying |
| qty_delta | numeric | no |  |
| txn_uom_id | bigint | yes | → mfg_uoms |
| txn_qty | numeric | yes |  |
| unit_cost | numeric | no | '0'::numeric |
| value_estimate | numeric | no | '0'::numeric |
| reason_code_id | bigint | no | → wms_reason_codes |
| notes | text | yes |  |
| occurred_at | timestamp with time zone | no |  |
| status | character varying | no | 'PENDING_APPROVAL'::character varying |
| approval_reasons | jsonb | yes |  |
| requested_by | bigint | yes | → users (set null) |
| decided_by | bigint | yes | → users (set null) |
| decided_at | timestamp with time zone | yes |  |
| decision_note | text | yes |  |
| movement_id | bigint | yes | → fin_stock_movements (set null) |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |
| override_id | bigint | yes | → wms_overrides (set null) |

- Unique: `(id)`
- Unique: `(company_id, number)`
- Unique: `(uuid)`
- Check `wms_adj_four_eyes`: `CHECK (((decided_by IS NULL) OR (requested_by IS NULL) OR (decided_by <> requested_by) OR ((status)::text = 'CANCELLED'::text) OR (override_id IS NOT NULL)))`
- Check `wms_adj_qty_nonzero`: `CHECK ((qty_delta <> (0)::numeric))`
- Check `wms_adj_status_chk`: `CHECK (((status)::text = ANY ((ARRAY['PENDING_APPROVAL'::character varying, 'POSTED'::character varying, 'REJECTED'::character varying, 'CANCELLED'::character varying])::text[])))`

## wms_supplier_return_lines

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_supplier_return_lines_id_se... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| return_id | bigint | no | → wms_supplier_returns (cascade) |
| item_id | bigint | no | → fin_stock_items |
| grn_item_id | bigint | yes | → goods_received_items (set null) |
| bin_id | bigint | yes | → fin_stock_bins (set null) |
| lot_id | bigint | yes | → fin_stock_lots (set null) |
| stock_status | character varying | no | 'REJECTED'::character varying |
| qty | numeric | no |  |
| serial_numbers | jsonb | yes |  |
| movement_id | bigint | yes | → fin_stock_movements (set null) |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Check `wms_sret_qty_positive`: `CHECK ((qty > (0)::numeric))`

## wms_supplier_returns

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_supplier_returns_id_seq'::r... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| number | character varying | no |  |
| supplier_id | bigint | no | → suppliers |
| warehouse_id | bigint | no | → fin_warehouses |
| grn_id | bigint | yes | → goods_received_notes (set null) |
| status | character varying | no | 'DRAFT'::character varying |
| reason | text | no |  |
| carrier | character varying | yes |  |
| dispatch_reference | character varying | yes |  |
| dispatched_at | timestamp with time zone | yes |  |
| acknowledged_at | timestamp with time zone | yes |  |
| acknowledged_by_name | character varying | yes |  |
| outcome | character varying | yes |  |
| credit_reference | character varying | yes |  |
| created_by | bigint | yes | → users (set null) |
| approved_by | bigint | yes | → users (set null) |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(company_id, number)`
- Unique: `(uuid)`

## wms_supplier_terms

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_supplier_terms_id_seq'::reg... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| supplier_id | bigint | no | → suppliers (cascade) |
| stock_item_id | bigint | no | → fin_stock_items (cascade) |
| min_order_qty | numeric | yes |  |
| order_multiple | numeric | yes |  |
| lead_time_days | smallint | yes |  |
| unit_price | numeric | yes |  |
| notes | character varying | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(company_id, supplier_id, stock_item_id)`

## wms_task_assignments

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_task_assignments_id_seq'::r... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| task_type | character varying | no |  |
| task_ref | character varying | no |  |
| user_id | bigint | yes | → users (set null) |
| priority | character varying | yes |  |
| due_at | timestamp with time zone | yes |  |
| assigned_by | bigint | yes | → users (set null) |
| is_current | boolean | no | true |
| note | character varying | yes |  |
| created_at | timestamp with time zone | no | CURRENT_TIMESTAMP |

- Unique: `(id)`

## wms_tracking_events

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_tracking_events_id_seq'::re... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| shipment_id | bigint | no | → wms_shipments (cascade) |
| status | character varying | no |  |
| occurred_at | timestamp with time zone | no |  |
| location | character varying | yes |  |
| detail | character varying | yes |  |
| source | character varying | no |  |
| external_id | character varying | yes |  |
| recorded_by | bigint | yes | → users (set null) |
| created_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(shipment_id, external_id)`
- Check `wms_tracking_status_chk`: `CHECK (((status)::text = ANY ((ARRAY['BOOKED'::character varying, 'COLLECTED'::character varying, 'IN_TRANSIT'::character varying, 'OUT_FOR_DELIVERY'::character varying, 'DELIVERED'::character varying...`

## wms_transfer_lines

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_transfer_lines_id_seq'::reg... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| transfer_id | bigint | no | → wms_transfer_orders (cascade) |
| item_id | bigint | no | → fin_stock_items |
| lot_id | bigint | yes | → fin_stock_lots (set null) |
| from_bin_id | bigint | yes | → fin_stock_bins (set null) |
| to_bin_id | bigint | yes | → fin_stock_bins (set null) |
| qty | numeric | no |  |
| picked_qty | numeric | no | '0'::numeric |
| dispatched_qty | numeric | no | '0'::numeric |
| received_qty | numeric | no | '0'::numeric |
| variance_qty | numeric | no | '0'::numeric |
| variance_note | text | yes |  |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Check `wms_transfer_line_qty`: `CHECK (((qty > (0)::numeric) AND (received_qty <= dispatched_qty) AND (dispatched_qty <= qty)))`

## wms_transfer_orders

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_transfer_orders_id_seq'::re... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| number | character varying | no |  |
| from_warehouse_id | bigint | no | → fin_warehouses |
| to_warehouse_id | bigint | no | → fin_warehouses |
| status | character varying | no | 'DRAFT'::character varying |
| reason | text | yes |  |
| priority | smallint | no | '50'::smallint |
| needed_by | date | yes |  |
| carrier | character varying | yes |  |
| vehicle_reg | character varying | yes |  |
| tracking_reference | character varying | yes |  |
| created_by | bigint | yes | → users (set null) |
| approved_by | bigint | yes | → users (set null) |
| approved_at | timestamp with time zone | yes |  |
| picked_at | timestamp with time zone | yes |  |
| dispatched_at | timestamp with time zone | yes |  |
| received_at | timestamp with time zone | yes |  |
| reconciled_at | timestamp with time zone | yes |  |
| cancel_reason | text | yes |  |
| source | character varying | no | 'MANUAL'::character varying |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(company_id, number)`
- Unique: `(uuid)`
- Check `wms_transfer_two_places`: `CHECK ((from_warehouse_id <> to_warehouse_id))`

## wms_warehouse_staff

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_warehouse_staff_id_seq'::re... |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| warehouse_id | bigint | no | → fin_warehouses (cascade) |
| user_id | bigint | no | → users (cascade) |
| shift_id | bigint | yes | → attendance_shifts (set null) |
| activities | jsonb | no |  |
| is_active | boolean | no | true |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(warehouse_id, user_id)`
- Unique: `(uuid)`

## wms_webhook_deliveries

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_webhook_deliveries_id_seq':... |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| webhook_id | bigint | no | → wms_webhooks (cascade) |
| outbox_id | bigint | no | → wms_outbox (cascade) |
| attempt | smallint | no |  |
| response_status | smallint | yes |  |
| error | character varying | yes |  |
| duration_ms | integer | yes |  |
| delivered | boolean | no | false |
| created_at | timestamp with time zone | yes |  |

- Unique: `(id)`

## wms_webhooks

Row-level security: on, forced.

| Column | Type | Null | Default / reference |
|---|---|---|---|
| id | bigint | no | nextval('wms_webhooks_id_seq'::regclass) |
| uuid | uuid | no |  |
| company_id | bigint | yes | → companies (cascade) (NULLIF(current_setting('app.company_id'... |
| name | character varying | no |  |
| url | character varying | no |  |
| secret | text | no |  |
| secret_hint | character varying | no |  |
| event_types | jsonb | no |  |
| warehouse_ids | jsonb | yes |  |
| is_active | boolean | no | true |
| consecutive_failures | integer | no | 0 |
| disabled_at | timestamp with time zone | yes |  |
| disabled_reason | character varying | yes |  |
| last_delivered_at | timestamp with time zone | yes |  |
| created_by | bigint | yes | → users (set null) |
| created_at | timestamp with time zone | yes |  |
| updated_at | timestamp with time zone | yes |  |

- Unique: `(id)`
- Unique: `(uuid)`

