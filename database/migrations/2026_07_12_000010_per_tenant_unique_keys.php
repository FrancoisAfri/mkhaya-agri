<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make per-company business keys unique PER TENANT instead of globally. A global
 * unique on code/name/slug/*_number/email/etc. would stop a second company from
 * reusing a value the first company already has (e.g. both minting "INV-0001", or
 * employing people with the same email). Each such single-column unique is swapped
 * for a composite (company_id, <col>) unique. Random tokens/hashes and 1:1 foreign
 * keys are intentionally left globally unique (classified out). Postgres-only.
 */
return new class extends Migration
{
    /** @var array<array{t:string,c:string,old:string,new:string}> */
    private array $map = [
        ['t' => 'asset_procurements', 'c' => 'procurement_number', 'old' => 'asset_procurements_procurement_number_unique', 'new' => 'asset_procurements_procurement_number_ctuq'],
        ['t' => 'asset_purchase_orders', 'c' => 'po_number', 'old' => 'asset_purchase_orders_po_number_unique', 'new' => 'asset_purchase_orders_po_number_ctuq'],
        ['t' => 'asset_purchase_requests', 'c' => 'request_number', 'old' => 'asset_purchase_requests_request_number_unique', 'new' => 'asset_purchase_requests_request_number_ctuq'],
        ['t' => 'asset_settings', 'c' => 'key', 'old' => 'asset_settings_key_unique', 'new' => 'asset_settings_key_ctuq'],
        ['t' => 'asset_suppliers', 'c' => 'supplier_code', 'old' => 'asset_suppliers_supplier_code_unique', 'new' => 'asset_suppliers_supplier_code_ctuq'],
        ['t' => 'assets', 'c' => 'asset_number', 'old' => 'assets_asset_number_unique', 'new' => 'assets_asset_number_ctuq'],
        ['t' => 'attendance_discipline_cases', 'c' => 'case_number', 'old' => 'attendance_discipline_cases_case_number_unique', 'new' => 'attendance_discipline_cases_case_number_ctuq'],
        ['t' => 'attendance_notification_configs', 'c' => 'event', 'old' => 'attendance_notification_configs_event_unique', 'new' => 'attendance_notification_configs_event_ctuq'],
        ['t' => 'attendance_policy_rules', 'c' => 'code', 'old' => 'attendance_policy_rules_code_unique', 'new' => 'attendance_policy_rules_code_ctuq'],
        ['t' => 'attendance_shifts', 'c' => 'code', 'old' => 'attendance_shifts_code_unique', 'new' => 'attendance_shifts_code_ctuq'],
        ['t' => 'attendance_statuses', 'c' => 'code', 'old' => 'attendance_statuses_code_unique', 'new' => 'attendance_statuses_code_ctuq'],
        ['t' => 'budgets', 'c' => 'code', 'old' => 'budgets_code_unique', 'new' => 'budgets_code_ctuq'],
        ['t' => 'claim_categories', 'c' => 'code', 'old' => 'claim_categories_code_unique', 'new' => 'claim_categories_code_ctuq'],
        ['t' => 'claim_policies', 'c' => 'rule_key', 'old' => 'claim_policies_rule_key_unique', 'new' => 'claim_policies_rule_key_ctuq'],
        ['t' => 'claims', 'c' => 'reference_number', 'old' => 'claims_reference_number_unique', 'new' => 'claims_reference_number_ctuq'],
        ['t' => 'conference_certificates', 'c' => 'certificate_number', 'old' => 'conference_certificates_certificate_number_unique', 'new' => 'conference_certificates_certificate_number_ctuq'],
        ['t' => 'conference_invoices', 'c' => 'invoice_number', 'old' => 'conference_invoices_invoice_number_unique', 'new' => 'conference_invoices_invoice_number_ctuq'],
        ['t' => 'conference_payment_gateways', 'c' => 'provider', 'old' => 'conference_payment_gateways_provider_unique', 'new' => 'conference_payment_gateways_provider_ctuq'],
        ['t' => 'conference_registrations', 'c' => 'ticket_code', 'old' => 'conference_registrations_ticket_code_unique', 'new' => 'conference_registrations_ticket_code_ctuq'],
        ['t' => 'conferences', 'c' => 'slug', 'old' => 'conferences_slug_unique', 'new' => 'conferences_slug_ctuq'],
        ['t' => 'contract_requests', 'c' => 'request_number', 'old' => 'contract_requests_request_number_unique', 'new' => 'contract_requests_request_number_ctuq'],
        ['t' => 'contracts', 'c' => 'contract_number', 'old' => 'contracts_contract_number_unique', 'new' => 'contracts_contract_number_ctuq'],
        ['t' => 'crm_complaints', 'c' => 'reference', 'old' => 'crm_complaints_reference_unique', 'new' => 'crm_complaints_reference_ctuq'],
        ['t' => 'crm_email_templates', 'c' => 'key', 'old' => 'crm_email_templates_key_unique', 'new' => 'crm_email_templates_key_ctuq'],
        ['t' => 'crm_integrations', 'c' => 'provider', 'old' => 'crm_integrations_provider_unique', 'new' => 'crm_integrations_provider_ctuq'],
        ['t' => 'crm_object_types', 'c' => 'slug', 'old' => 'crm_object_types_slug_unique', 'new' => 'crm_object_types_slug_ctuq'],
        ['t' => 'crm_settings', 'c' => 'key', 'old' => 'crm_settings_key_unique', 'new' => 'crm_settings_key_ctuq'],
        ['t' => 'disciplinary_appeals', 'c' => 'appeal_number', 'old' => 'disciplinary_appeals_appeal_number_unique', 'new' => 'disciplinary_appeals_appeal_number_ctuq'],
        ['t' => 'disciplinary_cases', 'c' => 'case_number', 'old' => 'disciplinary_cases_case_number_unique', 'new' => 'disciplinary_cases_case_number_ctuq'],
        ['t' => 'disciplinary_categories', 'c' => 'name', 'old' => 'disciplinary_categories_name_unique', 'new' => 'disciplinary_categories_name_ctuq'],
        ['t' => 'disciplinary_categories', 'c' => 'code', 'old' => 'disciplinary_categories_code_unique', 'new' => 'disciplinary_categories_code_ctuq'],
        ['t' => 'disciplinary_corrective_actions', 'c' => 'action_number', 'old' => 'disciplinary_corrective_actions_action_number_unique', 'new' => 'disciplinary_corrective_actions_action_number_ctuq'],
        ['t' => 'disciplinary_hearings', 'c' => 'hearing_number', 'old' => 'disciplinary_hearings_hearing_number_unique', 'new' => 'disciplinary_hearings_hearing_number_ctuq'],
        ['t' => 'disciplinary_incidents', 'c' => 'incident_number', 'old' => 'disciplinary_incidents_incident_number_unique', 'new' => 'disciplinary_incidents_incident_number_ctuq'],
        ['t' => 'disciplinary_investigations', 'c' => 'investigation_number', 'old' => 'disciplinary_investigations_investigation_number_unique', 'new' => 'disciplinary_investigations_investigation_number_ctuq'],
        ['t' => 'disciplinary_outcomes', 'c' => 'name', 'old' => 'disciplinary_outcomes_name_unique', 'new' => 'disciplinary_outcomes_name_ctuq'],
        ['t' => 'disciplinary_sanctions', 'c' => 'name', 'old' => 'disciplinary_sanctions_name_unique', 'new' => 'disciplinary_sanctions_name_ctuq'],
        ['t' => 'disciplinary_settings', 'c' => 'key', 'old' => 'disciplinary_settings_key_unique', 'new' => 'disciplinary_settings_key_ctuq'],
        ['t' => 'disciplinary_statuses', 'c' => 'name', 'old' => 'disciplinary_statuses_name_unique', 'new' => 'disciplinary_statuses_name_ctuq'],
        ['t' => 'fleet_bookings', 'c' => 'reference', 'old' => 'fleet_bookings_reference_unique', 'new' => 'fleet_bookings_reference_ctuq'],
        ['t' => 'fleet_fuel_types', 'c' => 'name', 'old' => 'fleet_fuel_types_name_unique', 'new' => 'fleet_fuel_types_name_ctuq'],
        ['t' => 'fleet_settings', 'c' => 'key', 'old' => 'fleet_settings_key_unique', 'new' => 'fleet_settings_key_ctuq'],
        ['t' => 'fleet_vehicle_makes', 'c' => 'name', 'old' => 'fleet_vehicle_makes_name_unique', 'new' => 'fleet_vehicle_makes_name_ctuq'],
        ['t' => 'fleet_vehicle_types', 'c' => 'name', 'old' => 'fleet_vehicle_types_name_unique', 'new' => 'fleet_vehicle_types_name_ctuq'],
        ['t' => 'fleet_vehicles', 'c' => 'registration_number', 'old' => 'fleet_vehicles_registration_number_unique', 'new' => 'fleet_vehicles_registration_number_ctuq'],
        ['t' => 'fleet_vehicles', 'c' => 'vin_number', 'old' => 'fleet_vehicles_vin_number_unique', 'new' => 'fleet_vehicles_vin_number_ctuq'],
        ['t' => 'fleet_vehicles', 'c' => 'fleet_number', 'old' => 'fleet_vehicles_fleet_number_unique', 'new' => 'fleet_vehicles_fleet_number_ctuq'],
        ['t' => 'fleet_vehicles', 'c' => 'chassis_number', 'old' => 'fleet_vehicles_chassis_number_unique', 'new' => 'fleet_vehicles_chassis_number_ctuq'],
        ['t' => 'goods_received_notes', 'c' => 'grn_number', 'old' => 'goods_received_notes_grn_number_unique', 'new' => 'goods_received_notes_grn_number_ctuq'],
        ['t' => 'governance_resolutions', 'c' => 'resolution_number', 'old' => 'governance_resolutions_resolution_number_unique', 'new' => 'governance_resolutions_resolution_number_ctuq'],
        ['t' => 'hd_catalog_items', 'c' => 'code', 'old' => 'hd_catalog_items_code_unique', 'new' => 'hd_catalog_items_code_ctuq'],
        ['t' => 'hd_catalog_items', 'c' => 'slug', 'old' => 'hd_catalog_items_slug_unique', 'new' => 'hd_catalog_items_slug_ctuq'],
        ['t' => 'hd_categories', 'c' => 'slug', 'old' => 'hd_categories_slug_unique', 'new' => 'hd_categories_slug_ctuq'],
        ['t' => 'hd_changes', 'c' => 'change_number', 'old' => 'hd_changes_change_number_unique', 'new' => 'hd_changes_change_number_ctuq'],
        ['t' => 'hd_cis', 'c' => 'ci_number', 'old' => 'hd_cis_ci_number_unique', 'new' => 'hd_cis_ci_number_ctuq'],
        ['t' => 'hd_kb_articles', 'c' => 'slug', 'old' => 'hd_kb_articles_slug_unique', 'new' => 'hd_kb_articles_slug_ctuq'],
        ['t' => 'hd_kb_categories', 'c' => 'slug', 'old' => 'hd_kb_categories_slug_unique', 'new' => 'hd_kb_categories_slug_ctuq'],
        ['t' => 'hd_priorities', 'c' => 'slug', 'old' => 'hd_priorities_slug_unique', 'new' => 'hd_priorities_slug_ctuq'],
        ['t' => 'hd_problems', 'c' => 'problem_number', 'old' => 'hd_problems_problem_number_unique', 'new' => 'hd_problems_problem_number_ctuq'],
        ['t' => 'hd_releases', 'c' => 'release_number', 'old' => 'hd_releases_release_number_unique', 'new' => 'hd_releases_release_number_ctuq'],
        ['t' => 'hd_service_requests', 'c' => 'request_number', 'old' => 'hd_service_requests_request_number_unique', 'new' => 'hd_service_requests_request_number_ctuq'],
        ['t' => 'hd_statuses', 'c' => 'slug', 'old' => 'hd_statuses_slug_unique', 'new' => 'hd_statuses_slug_ctuq'],
        ['t' => 'hd_tickets', 'c' => 'ticket_number', 'old' => 'hd_tickets_ticket_number_unique', 'new' => 'hd_tickets_ticket_number_ctuq'],
        ['t' => 'hr_people', 'c' => 'email', 'old' => 'hr_people_email_unique', 'new' => 'hr_people_email_ctuq'],
        ['t' => 'hr_persons', 'c' => 'employee_number', 'old' => 'hr_persons_employee_number_unique', 'new' => 'hr_persons_employee_number_ctuq'],
        ['t' => 'leave_requests', 'c' => 'request_number', 'old' => 'leave_requests_request_number_unique', 'new' => 'leave_requests_request_number_ctuq'],
        ['t' => 'lms_aqps', 'c' => 'registration_number', 'old' => 'lms_aqps_registration_number_unique', 'new' => 'lms_aqps_registration_number_ctuq'],
        ['t' => 'lms_certificates', 'c' => 'certificate_number', 'old' => 'lms_certificates_certificate_number_unique', 'new' => 'lms_certificates_certificate_number_ctuq'],
        ['t' => 'pa_policies', 'c' => 'code', 'old' => 'pa_policies_code_unique', 'new' => 'pa_policies_code_ctuq'],
        ['t' => 'pa_settings', 'c' => 'key', 'old' => 'pa_settings_key_unique', 'new' => 'pa_settings_key_ctuq'],
        ['t' => 'payroll_runs', 'c' => 'ref_number', 'old' => 'payroll_runs_ref_number_unique', 'new' => 'payroll_runs_ref_number_ctuq'],
        ['t' => 'petty_cash_requests', 'c' => 'reference_number', 'old' => 'petty_cash_requests_reference_number_unique', 'new' => 'petty_cash_requests_reference_number_ctuq'],
        ['t' => 'policy', 'c' => 'slug', 'old' => 'policy_slug_unique', 'new' => 'policy_slug_ctuq'],
        ['t' => 'policy_leave_rules', 'c' => 'rule_code', 'old' => 'policy_leave_rules_rule_code_unique', 'new' => 'policy_leave_rules_rule_code_ctuq'],
        ['t' => 'procurement_requisitions', 'c' => 'requisition_number', 'old' => 'procurement_requisitions_requisition_number_unique', 'new' => 'procurement_requisitions_requisition_number_ctuq'],
        ['t' => 'purchase_orders', 'c' => 'po_number', 'old' => 'purchase_orders_po_number_unique', 'new' => 'purchase_orders_po_number_ctuq'],
        ['t' => 'purchase_requests', 'c' => 'request_number', 'old' => 'purchase_requests_request_number_unique', 'new' => 'purchase_requests_request_number_ctuq'],
        ['t' => 'recruitment_candidates', 'c' => 'email', 'old' => 'recruitment_candidates_email_unique', 'new' => 'recruitment_candidates_email_ctuq'],
        ['t' => 'recruitment_jobs', 'c' => 'reference_number', 'old' => 'recruitment_jobs_reference_number_unique', 'new' => 'recruitment_jobs_reference_number_ctuq'],
        ['t' => 'recruitment_requisitions', 'c' => 'requisition_number', 'old' => 'recruitment_requisitions_requisition_number_unique', 'new' => 'recruitment_requisitions_requisition_number_ctuq'],
        ['t' => 'recruitment_vacancies', 'c' => 'vacancy_number', 'old' => 'recruitment_vacancies_vacancy_number_unique', 'new' => 'recruitment_vacancies_vacancy_number_ctuq'],
        ['t' => 'rfx_events', 'c' => 'rfx_number', 'old' => 'rfx_events_rfx_number_unique', 'new' => 'rfx_events_rfx_number_ctuq'],
        ['t' => 'salary_advances', 'c' => 'reference_number', 'old' => 'salary_advances_reference_number_unique', 'new' => 'salary_advances_reference_number_ctuq'],
        ['t' => 'skills', 'c' => 'slug', 'old' => 'skills_slug_unique', 'new' => 'skills_slug_ctuq'],
        ['t' => 'staff_loans', 'c' => 'reference_number', 'old' => 'staff_loans_reference_number_unique', 'new' => 'staff_loans_reference_number_ctuq'],
        ['t' => 'supplier_scars', 'c' => 'scar_number', 'old' => 'supplier_scars_scar_number_unique', 'new' => 'supplier_scars_scar_number_ctuq'],
        ['t' => 'tenders', 'c' => 'tender_number', 'old' => 'tenders_tender_number_unique', 'new' => 'tenders_tender_number_ctuq'],
        ['t' => 'whistleblower_cases', 'c' => 'ref_number', 'old' => 'whistleblower_cases_ref_number_unique', 'new' => 'whistleblower_cases_ref_number_ctuq'],
        ['t' => 'whistleblower_settings', 'c' => 'key', 'old' => 'whistleblower_settings_key_unique', 'new' => 'whistleblower_settings_key_ctuq'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($this->map as $m) {
            // The map lists tables from modules this product does not ship.
            if (! \Illuminate\Support\Facades\Schema::hasTable($m['t'])) {
                continue;
            }
            // Drop the old global unique whether it is a constraint or a plain index.
            DB::statement('ALTER TABLE "' . $m['t'] . '" DROP CONSTRAINT IF EXISTS "' . $m['old'] . '"');
            DB::statement('DROP INDEX IF EXISTS "' . $m['old'] . '"');
            // Composite per-tenant unique.
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS "' . $m['new'] . '" ON "' . $m['t'] . '" (company_id, "' . $m['c'] . '")');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($this->map as $m) {
            DB::statement('DROP INDEX IF EXISTS "' . $m['new'] . '"');
            // Best-effort restore of the global unique (may fail if cross-tenant dupes exist).
            try {
                DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS "' . $m['old'] . '" ON "' . $m['t'] . '" ("' . $m['c'] . '")');
            } catch (\Throwable) {
            }
        }
    }
};