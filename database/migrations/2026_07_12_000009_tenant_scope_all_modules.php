<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-scope every module table (multi-tenancy retrofit, RLS-everywhere).
 * For each table: add nullable company_id + index; backfill existing rows to the
 * default company (id 1); DEFAULT company_id from the app.company_id session var
 * so ANY insert (Eloquent or raw) during a tenant request auto-tags the tenant;
 * enable + FORCE row-level security with the same policy as fin_invoices — rows
 * are visible/writable only for the current tenant, and ALL rows when
 * app.company_id is unset (console, seeders, tests, platform admin, and the
 * self-hosted single-company edition), so nothing breaks in those contexts.
 *
 * Table list classified by the tenant-scope workflow. Idempotent; RLS is
 * Postgres-only.
 */
return new class extends Migration
{
    /** @var array<string> */
    private array $tables = [
        'ai_anomaly_flags', 'ai_chat_messages', 'ai_chat_sessions', 'asset_approval_comments', 'asset_approval_steps', 'asset_approvals',
        'asset_assignments', 'asset_behavior_logs', 'asset_book_values', 'asset_categories', 'asset_compliance_checks', 'asset_compliance_rules',
        'asset_compliance_runs', 'asset_components', 'asset_custody_logs', 'asset_depreciation_entries', 'asset_depreciation_journals', 'asset_depreciation_schedules',
        'asset_depreciations', 'asset_digital_twins', 'asset_document_extractions', 'asset_documents', 'asset_exit_clearance_items', 'asset_exit_clearances',
        'asset_failure_predictions', 'asset_files', 'asset_financial_lifecycle', 'asset_images', 'asset_incidents', 'asset_invoice_data',
        'asset_liabilities', 'asset_license', 'asset_license_dates', 'asset_licenses', 'asset_locations', 'asset_log',
        'asset_maintenance_logs', 'asset_maintenance_schedules', 'asset_maintenances', 'asset_makes', 'asset_misuse_detection_rules', 'asset_misuse_events',
        'asset_misuse_flags', 'asset_models', 'asset_movements', 'asset_performance_logs', 'asset_policy_enforcement_logs', 'asset_policy_exceptions',
        'asset_policy_violations', 'asset_procurements', 'asset_productivity_scores', 'asset_purchase_orders', 'asset_purchase_request_items', 'asset_purchase_requests',
        'asset_replacement_predictions', 'asset_risk_profiles', 'asset_risk_scores', 'asset_roi_reports', 'asset_scheduled_reports', 'asset_settings',
        'asset_supplier_performance', 'asset_suppliers', 'asset_total_cost_ownership', 'asset_type', 'asset_utilization_logs', 'asset_utilization_reports',
        'asset_utilization_snapshots', 'asset_warranty_documents', 'assets', 'assets_files', 'assets_transfer_images', 'attendance_absent_users',
        'attendance_anomalies', 'attendance_audit_logs', 'attendance_behavior_patterns', 'attendance_biometric_logs', 'attendance_breaks', 'attendance_calendar_shift',
        'attendance_clockings', 'attendance_compliance_scores', 'attendance_department_wellness_scores', 'attendance_device_logs', 'attendance_discipline_cases', 'attendance_discipline_offenders',
        'attendance_discipline_packs', 'attendance_employee_shifts', 'attendance_gps_logs', 'attendance_notification_configs', 'attendance_policy_exceptions', 'attendance_policy_rules',
        'attendance_policy_violations', 'attendance_productivity_scores', 'attendance_productivity_trends', 'attendance_records', 'attendance_risk_scores', 'attendance_scheduled_reports',
        'attendance_settings', 'attendance_shifts', 'attendance_statuses', 'attendance_wellness_alerts', 'attendance_wellness_scores', 'attendance_work_calendars',
        'attestation_campaigns', 'audit_ai_calls', 'behavior_patterns', 'budget_adjustments', 'budget_allocations', 'budget_approvals',
        'budget_categories', 'budget_commitments', 'budget_consumptions', 'budget_overrides', 'budget_owners', 'budget_periods',
        'budget_reservations', 'budgets', 'bureau_companies', 'bureau_company_user', 'bureau_components', 'bureau_disbursements',
        'bureau_employee_components', 'bureau_employees', 'bureau_pay_runs', 'bureau_payment_integrations', 'bureau_payrun_inputs', 'bureau_payslip_lines',
        'bureau_payslips', 'burnout_risk_profiles', 'candidate_ai_profiles', 'candidate_certifications', 'candidate_consents', 'candidate_documents',
        'candidate_experience', 'candidate_fit_explanations', 'candidate_fit_scores', 'candidate_qualifications', 'candidate_shortlist_items', 'candidate_shortlist_overrides',
        'candidate_skills', 'ceo_messages', 'certification_expiry_logs', 'claim_approval_delegations', 'claim_approval_steps', 'claim_approvals',
        'claim_attachments', 'claim_categories', 'claim_items', 'claim_payment_processors', 'claim_policies', 'claim_policy_violations',
        'claim_risk_scores', 'claim_scheduled_reports', 'claim_travel_trips', 'claims', 'communication_campaign_recipients', 'communication_email_campaign_templates',
        'communication_email_campaigns', 'company_identities', 'conference_api_keys', 'conference_attendance_predictions', 'conference_attendee_interests', 'conference_attendee_join_links',
        'conference_certificates', 'conference_chatbot_conversations', 'conference_check_ins', 'conference_exhibitors', 'conference_feedback', 'conference_files',
        'conference_integrations', 'conference_invoice_sequences', 'conference_invoices', 'conference_meetings', 'conference_payment_gateways', 'conference_payments',
        'conference_points', 'conference_poll_votes', 'conference_polls', 'conference_refunds', 'conference_registration_groups', 'conference_registrations',
        'conference_reminder_logs', 'conference_rooms', 'conference_saved_reports', 'conference_sentiment_scores', 'conference_session_media', 'conference_session_messages',
        'conference_session_questions', 'conference_session_recommendations', 'conference_session_recordings', 'conference_session_summaries', 'conference_session_transcripts', 'conference_sessions',
        'conference_sponsors', 'conference_submissions', 'conference_ticket_payments', 'conference_transcript_translations', 'conference_travel_bookings', 'conference_virtual_sessions',
        'conference_webhooks', 'conferences', 'contacts_companies', 'contacts_contacts', 'contents', 'contract_ai',
        'contract_appraisal_responses', 'contract_appraisal_template_questions', 'contract_appraisal_templates', 'contract_appraisals', 'contract_approval_step_users', 'contract_approval_steps',
        'contract_categories', 'contract_clause_analyses', 'contract_clauses', 'contract_milestones', 'contract_notifications', 'contract_obligations',
        'contract_requests', 'contract_reviews', 'contract_status_histories', 'contract_step_statuses', 'contract_types', 'contracts',
        'contracts_templates', 'copilot_sessions', 'corporate_brandings', 'course_skills', 'crm_accounts', 'crm_activities',
        'crm_ai_invocations', 'crm_approvals', 'crm_campaign_recipients', 'crm_campaigns', 'crm_comments', 'crm_complaint_updates',
        'crm_complaints', 'crm_contacts', 'crm_custom_field_values', 'crm_custom_fields', 'crm_deals', 'crm_email_templates',
        'crm_flow_runs', 'crm_imports', 'crm_integrations', 'crm_kpis', 'crm_leads', 'crm_mailboxes',
        'crm_meetings', 'crm_notifications', 'crm_object_records', 'crm_object_types', 'crm_pipelines', 'crm_products',
        'crm_saved_views', 'crm_scheduled_reports', 'crm_sequence_enrollments', 'crm_sequence_sends', 'crm_sequences', 'crm_settings',
        'crm_stages', 'crm_suppressions', 'crm_survey_plans', 'crm_survey_recipients', 'crm_survey_responses', 'crm_surveys',
        'crm_targets', 'crm_webhook_deliveries', 'crm_webhooks', 'crm_workflow_runs', 'crm_workflows', 'data_breach_incidents',
        'delivery_notes', 'department_behavior_risk', 'department_efficiency_scores', 'disciplinary_accused', 'disciplinary_ai_evidence_reports', 'disciplinary_ai_summaries',
        'disciplinary_appeal_evidence', 'disciplinary_appeal_outcomes', 'disciplinary_appeals', 'disciplinary_behavior_patterns', 'disciplinary_behavior_profiles', 'disciplinary_case_timelines',
        'disciplinary_cases', 'disciplinary_categories', 'disciplinary_coaching_sessions', 'disciplinary_corrective_actions', 'disciplinary_escalations', 'disciplinary_evidence',
        'disciplinary_evidence_analysis', 'disciplinary_hearing_attendees', 'disciplinary_hearing_minutes', 'disciplinary_hearing_outcomes', 'disciplinary_hearings', 'disciplinary_heatmaps',
        'disciplinary_incidents', 'disciplinary_investigation_notes', 'disciplinary_investigation_reports', 'disciplinary_investigations', 'disciplinary_outcomes', 'disciplinary_policy_enforcement_logs',
        'disciplinary_policy_exceptions', 'disciplinary_policy_violations', 'disciplinary_rehabilitation_progress', 'disciplinary_risk_predictions', 'disciplinary_risk_scores', 'disciplinary_sanctions',
        'disciplinary_scheduled_reports', 'disciplinary_settings', 'disciplinary_statuses', 'disciplinary_suspensions', 'disciplinary_warning_expiry', 'disciplinary_warnings',
        'disciplinary_witnesses', 'division_level_fives', 'doc_type', 'employee_certifications', 'employee_documents', 'employee_leave_overrides',
        'employee_point_balances', 'employee_skill_gaps', 'employee_skill_profiles', 'employee_skills', 'employee_spotlights', 'enforcement_actions',
        'esign_audit_entries', 'esign_envelopes', 'esign_otp_challenges', 'esign_signers', 'event_rule_maps', 'fin_asset_depreciation',
        'fin_bank_feeds', 'fin_bank_matches', 'fin_bank_statement_lines', 'fin_budget_lines', 'fin_collection_activities', 'fin_dashboard_prefs',
        'fin_delivery_note_lines', 'fin_exchange_rates', 'fin_groups', 'fin_invoice_lines', 'fin_payment_allocations', 'fin_payment_batch_lines',
        'fin_postings', 'fin_price_list_items', 'fin_quote_lines', 'fin_recurring_invoice_lines', 'fin_sales_order_lines', 'fin_stock_layers',
        'fin_stock_movements', 'finance_activity_logs', 'finance_approval_requests', 'finance_approval_steps', 'financial_years', 'fleet_booking_approval_configs',
        'fleet_booking_approval_delegations', 'fleet_booking_approval_tokens', 'fleet_booking_approvals', 'fleet_booking_incidents', 'fleet_booking_inspections', 'fleet_booking_tollgates',
        'fleet_bookings', 'fleet_card_transactions', 'fleet_card_types', 'fleet_cards', 'fleet_document_suppliers', 'fleet_documents',
        'fleet_drivers', 'fleet_filling_stations', 'fleet_fines', 'fleet_fire_extinguishers', 'fleet_fuel_anomalies', 'fleet_fuel_logs',
        'fleet_fuel_types', 'fleet_incident_types', 'fleet_inspection_analyses', 'fleet_integrations', 'fleet_key_safes', 'fleet_licence_types',
        'fleet_maintenance', 'fleet_notes', 'fleet_notification_preferences', 'fleet_odometer_logs', 'fleet_projects', 'fleet_push_subscriptions',
        'fleet_scheduled_reports', 'fleet_settings', 'fleet_telematics_events', 'fleet_telematics_positions', 'fleet_vehicle_change_logs', 'fleet_vehicle_general_costs',
        'fleet_vehicle_images', 'fleet_vehicle_keys', 'fleet_vehicle_makes', 'fleet_vehicle_models', 'fleet_vehicle_predictions', 'fleet_vehicle_reminders',
        'fleet_vehicle_status_changes', 'fleet_vehicle_types', 'fleet_vehicles', 'goods_received_items', 'goods_received_notes', 'governance_action_evidence',
        'governance_agenda_items', 'governance_agenda_templates', 'governance_agenda_versions', 'governance_ai_invocations', 'governance_ai_queries', 'governance_ai_systems',
        'governance_board_appointments', 'governance_board_packs', 'governance_coi_declarations', 'governance_committee_members', 'governance_committees', 'governance_director_history',
        'governance_director_skills', 'governance_directors', 'governance_doc_embeddings', 'governance_document_access_logs', 'governance_documents', 'governance_entity_filings',
        'governance_gifts', 'governance_king_evidence', 'governance_legal_entities', 'governance_meeting_attendees', 'governance_meeting_insights', 'governance_meeting_notices',
        'governance_meeting_transcripts', 'governance_meetings', 'governance_minute_items', 'governance_minutes', 'governance_minutes_action_items', 'governance_minutes_versions',
        'governance_news_items', 'governance_offline_packs', 'governance_org_units', 'governance_pack_annotations', 'governance_pack_distributions', 'governance_pack_items',
        'governance_pack_prereads', 'governance_push_subscriptions', 'governance_questionnaire_assignments', 'governance_questionnaire_questions', 'governance_questionnaire_responses', 'governance_questionnaires',
        'governance_resolution_actions', 'governance_resolutions', 'governance_risk_flags', 'governance_role_delegations', 'governance_scheduled_reports', 'governance_sessions',
        'governance_skill_targets', 'governance_vote_casts', 'governance_votes', 'hd_agent_group_members', 'hd_agent_group_messages', 'hd_agent_groups',
        'hd_agent_messages', 'hd_agent_presences', 'hd_agent_schedules', 'hd_agent_thread_participants', 'hd_agent_threads', 'hd_announcements',
        'hd_attachments', 'hd_automation_runs', 'hd_automation_templates', 'hd_automations', 'hd_business_hours', 'hd_canned_responses',
        'hd_catalog_items', 'hd_categories', 'hd_change_participants', 'hd_changes', 'hd_chat_flows', 'hd_chat_ip_rules',
        'hd_ci_links', 'hd_ci_relationships', 'hd_cis', 'hd_comments', 'hd_conversation_messages', 'hd_conversations',
        'hd_device_tokens', 'hd_event_rules', 'hd_events', 'hd_faq_categories', 'hd_faqs', 'hd_flow_runs',
        'hd_holidays', 'hd_inboxes', 'hd_instagram_settings', 'hd_kb_articles', 'hd_kb_categories', 'hd_kb_comments',
        'hd_kb_votes', 'hd_notification_preferences', 'hd_notification_settings', 'hd_priorities', 'hd_problem_incidents', 'hd_problems',
        'hd_rating_tokens', 'hd_release_change', 'hd_releases', 'hd_routing_rules', 'hd_scheduled_reports', 'hd_service_request_participants',
        'hd_service_requests', 'hd_status_components', 'hd_status_incidents', 'hd_statuses', 'hd_ticket_agents', 'hd_tickets',
        'hd_visitors', 'hd_webhooks', 'hd_whatsapp_settings', 'hr_people', 'hr_people_changes', 'hr_persons',
        'hr_positions', 'internal_mobility_matches', 'interview_questions', 'interview_responses', 'interview_scores', 'invitations',
        'invoice_match_results', 'it_manager', 'job_categories', 'job_title_skills', 'leave_anomaly_flags', 'leave_applications',
        'leave_approval_levels', 'leave_balance_transactions', 'leave_configuration', 'leave_credit', 'leave_customs', 'leave_cycles',
        'leave_delegations', 'leave_documents', 'leave_history', 'leave_payout_records', 'leave_profile', 'leave_profile_allocations',
        'leave_requests', 'leave_scheduled_reports', 'leave_types', 'license_allocation', 'linked_ethics_cases', 'lms_ai_artefacts',
        'lms_ai_recommendations', 'lms_ai_sessions', 'lms_aqps', 'lms_assessment_scores', 'lms_atr_items', 'lms_atr_reports',
        'lms_bbee_training_spend', 'lms_categories', 'lms_certificate_templates', 'lms_certificates', 'lms_certifications', 'lms_compliance_assignments',
        'lms_compliance_escalations', 'lms_compliance_reports', 'lms_compliance_rules', 'lms_course_contents', 'lms_course_materials', 'lms_course_modules',
        'lms_course_prerequisites', 'lms_courses', 'lms_development_training_plans', 'lms_effectiveness_reports', 'lms_effectiveness_snapshots', 'lms_eisa_schedules',
        'lms_enrollments', 'lms_learnership_enrolments', 'lms_learnership_logbook', 'lms_learnerships', 'lms_learning_gap_reports', 'lms_learning_path_courses',
        'lms_learning_paths', 'lms_learning_plan_items', 'lms_learning_plans', 'lms_lesson_completions', 'lms_lessons', 'lms_manual_marking',
        'lms_moderation_reports', 'lms_nlrd_submissions', 'lms_onboarding_training_assignments', 'lms_onboarding_training_rules', 'lms_pa_training_recommendations', 'lms_payroll_snapshots',
        'lms_poe_evidence', 'lms_poe_portfolios', 'lms_poe_section_revisions', 'lms_poe_sections', 'lms_policy_enforcement_logs', 'lms_policy_exceptions',
        'lms_policy_violations', 'lms_promotion_readiness', 'lms_question_banks', 'lms_question_options', 'lms_question_responses', 'lms_questionnaire_assignments',
        'lms_questionnaire_attempts', 'lms_questionnaires', 'lms_questions', 'lms_quiz_attempts', 'lms_quiz_options', 'lms_quiz_questions',
        'lms_registered_persons', 'lms_revision_plans', 'lms_scheduled_reports', 'lms_sdfs', 'lms_training_costs', 'lms_training_reminders',
        'lms_understanding_scores', 'lms_weak_learning_areas', 'lms_wsp_item_demographics', 'lms_wsp_items', 'lms_wsp_plans', 'marketplace_cart_items',
        'marketplace_carts', 'marketplace_integrations', 'marketplace_orders', 'marketplace_products', 'marketplace_sync_logs', 'nl_rule_drafts',
        'notification_settings', 'offboarding_asset_returns', 'offboarding_clearances', 'offboarding_exit_interviews', 'offboarding_plans', 'offboarding_tasks',
        'offboarding_template_tasks', 'offboarding_templates', 'onboarding_documents', 'onboarding_plans', 'onboarding_provisioning_tasks', 'onboarding_tasks',
        'onboarding_template_tasks', 'onboarding_templates', 'pa_360_questions', 'pa_360_raters', 'pa_360_responses', 'pa_360_reviews',
        'pa_ai_insights', 'pa_approval_actions', 'pa_approval_requests', 'pa_approval_workflow_steps', 'pa_approval_workflows', 'pa_behavior_signals',
        'pa_calibration_participants', 'pa_calibration_sessions', 'pa_checkin_action_items', 'pa_checkin_talking_points', 'pa_checkins', 'pa_coaching_sessions',
        'pa_comp_recommendations', 'pa_comp_reviews', 'pa_competencies', 'pa_competency_anchors', 'pa_compliance_scores', 'pa_cycles',
        'pa_development_activities', 'pa_development_plans', 'pa_employee_competencies', 'pa_employee_kpis', 'pa_employee_skills', 'pa_escalations',
        'pa_feedback', 'pa_goals', 'pa_high_potentials', 'pa_kpi_evidence', 'pa_kpi_progress_logs', 'pa_kpi_scores',
        'pa_learning_recommendations', 'pa_nine_box_placements', 'pa_nudges', 'pa_performance_snapshots', 'pa_pip_objectives', 'pa_pip_plans',
        'pa_pip_progress_logs', 'pa_pip_reviews', 'pa_policies', 'pa_policy_exceptions', 'pa_policy_violations', 'pa_probation_checkins',
        'pa_probation_feedback', 'pa_probation_objectives', 'pa_probation_reviews', 'pa_probation_risk_scores', 'pa_rating_scales', 'pa_rating_values',
        'pa_recognition_comments', 'pa_recognition_reactions', 'pa_recognitions', 'pa_role_competencies', 'pa_scheduled_reports', 'pa_settings',
        'pa_skills', 'pa_succession_candidates', 'pa_succession_plans', 'pa_talent_profiles', 'pa_template_questions', 'pa_template_sections',
        'pa_templates', 'pa_wellness_scores', 'parental_leave_pools', 'payroll_anomalies', 'payroll_approval_comments', 'payroll_approval_steps',
        'payroll_approvals', 'payroll_audit_logs', 'payroll_change_logs', 'payroll_change_risks', 'payroll_corrections', 'payroll_earnings_lines',
        'payroll_periods', 'payroll_risk_scores', 'payroll_runs', 'payroll_scheduled_reports', 'payroll_settings', 'payroll_simulation_items',
        'payroll_simulations', 'payslips', 'petty_cash_approvals', 'petty_cash_attachments', 'petty_cash_floats', 'petty_cash_policy_violations',
        'petty_cash_reconciliations', 'petty_cash_requests', 'petty_cash_transactions', 'phi_access_logs', 'policy', 'policy_ai_extractions',
        'policy_approval_overrides', 'policy_attestation_reminders', 'policy_attestations', 'policy_audit_logs', 'policy_category', 'policy_clauses',
        'policy_conflicts', 'policy_department_violations', 'policy_documents', 'policy_events', 'policy_exceptions', 'policy_investigation_evidence',
        'policy_investigation_notes', 'policy_leave_rules', 'policy_near_misses', 'policy_policy_violations_by_user', 'policy_quality_analyses', 'policy_questions',
        'policy_quiz_attempts', 'policy_quiz_settings', 'policy_refreshed', 'policy_repeat_violations', 'policy_reviewers', 'policy_risk_profiles',
        'policy_rule_audit_log', 'policy_rule_violations_by_user', 'policy_rules', 'policy_scheduled_reports', 'policy_simulations', 'policy_temporal_patterns',
        'policy_users', 'policy_versions', 'policy_violation_investigations', 'policy_violation_notifications', 'policy_violation_patterns', 'policy_violations',
        'popia_consents', 'pre_boarding_plans', 'pre_boarding_tasks', 'probation_periods', 'probation_reviews', 'procurement_approval_comments',
        'procurement_approval_steps', 'procurement_approvals', 'procurement_asset_links', 'procurement_escalations', 'procurement_insights', 'procurement_inventory_links',
        'procurement_policy_enforcement_logs', 'procurement_policy_exceptions', 'procurement_policy_rules', 'procurement_policy_violations', 'procurement_requisition_attachments', 'procurement_requisition_history',
        'procurement_requisition_items', 'procurement_requisitions', 'procurement_risk_scores', 'procurement_scheduled_reports', 'promo_codes', 'purchase_order_amendments',
        'purchase_order_items', 'purchase_orders', 'purchase_request_attachments', 'purchase_request_history', 'purchase_request_items', 'purchase_requests',
        'question_skill_mapping', 'quick_access_users', 'reaction_user', 'reactions', 'recognition_categories', 'recognition_comments',
        'recognition_reactions', 'recognitions', 'recruitment_applications', 'recruitment_approval_decisions', 'recruitment_approval_stages', 'recruitment_approval_workflows',
        'recruitment_candidate_messages', 'recruitment_candidate_references', 'recruitment_candidates', 'recruitment_contracts', 'recruitment_interviews', 'recruitment_jobs',
        'recruitment_offers', 'recruitment_pipeline_stages', 'recruitment_requisitions', 'recruitment_shortlists', 'recruitment_signature_requests', 'recruitment_social_accounts',
        'recruitment_social_posts', 'recruitment_vacancies', 'registration_sessions', 'resources', 'rfx_events', 'rfx_invitations',
        'rfx_responses', 'roster_assignments', 'roster_optimization_logs', 'roster_schedules', 'rule_actions', 'rule_conditions',
        'salary_advance_repayments', 'salary_advances', 'scheduled_reports', 'service_milestones', 'session_speaker', 'sick_leave_patterns',
        'skills', 'speakers', 'staff_loan_affordability_checks', 'staff_loan_approvals', 'staff_loan_policy_violations', 'staff_loan_repayments',
        'staff_loan_types', 'staff_loans', 'succession_candidates', 'supplier_appraisal_responses', 'supplier_appraisals', 'supplier_bbbee',
        'supplier_categories', 'supplier_category_supplier', 'supplier_contacts', 'supplier_directors', 'supplier_document_types', 'supplier_documents',
        'supplier_industry', 'supplier_industry_types', 'supplier_invoice_items', 'supplier_invoices', 'supplier_kyc_approvals', 'supplier_onboardings',
        'supplier_performance_management', 'supplier_performance_templates', 'supplier_products', 'supplier_question_libraries', 'supplier_risk_flags', 'supplier_scars',
        'supplier_screenings', 'supplier_setting_user', 'supplier_settings', 'suppliers', 'survey_360_assignments', 'survey_action_plans',
        'survey_answers', 'survey_documents', 'survey_question_conditions', 'survey_question_options', 'survey_questions', 'survey_reminders',
        'survey_responses', 'survey_scheduled_reports', 'survey_schedules', 'survey_template_questions', 'survey_templates', 'survey_translations',
        'surveys', 'system_doc_types', 'tender_bid_decisions', 'tender_branding', 'tender_comments', 'tender_company_documents',
        'tender_competitors', 'tender_compliance_items', 'tender_discoveries', 'tender_documents', 'tender_knowledge_items', 'tender_library_documents',
        'tender_pricing_items', 'tender_pricings', 'tender_proposal_sections', 'tender_sbd_forms', 'tender_signing_requests', 'tender_tasks',
        'tender_user_signatures', 'tenders', 'tickets', 'user_module_access', 'user_theme_settings', 'violation_notes',
        'waitlist_entries', 'whistleblower_activity_logs', 'whistleblower_ai_summaries', 'whistleblower_case_timelines', 'whistleblower_cases', 'whistleblower_categories',
        'whistleblower_classification_rules', 'whistleblower_compliance_owners', 'whistleblower_escalation_rules', 'whistleblower_evidence', 'whistleblower_evidence_analysis', 'whistleblower_findings',
        'whistleblower_followup_tokens', 'whistleblower_investigation_notes', 'whistleblower_investigations', 'whistleblower_messages', 'whistleblower_notes', 'whistleblower_policy_enforcement_logs',
        'whistleblower_policy_exceptions', 'whistleblower_policy_violations', 'whistleblower_protection_measures', 'whistleblower_report_attachments', 'whistleblower_reports', 'whistleblower_retaliation_risks',
        'whistleblower_risk_scores', 'whistleblower_secure_sessions', 'whistleblower_settings', 'whistleblower_status_history', 'whistleblower_witnesses', 'workforce_pressure_predictions',
    ];

    /** SQL fragment: read the tenant id from the session var (empty => NULL). */
    private function sessionExpr(): string
    {
        $q = chr(39); // single quote

        return "NULLIF(current_setting({$q}app.company_id{$q}, true), {$q}{$q})";
    }

    private function policyExpr(): string
    {
        $s = $this->sessionExpr();

        return "{$s} IS NULL OR company_id = {$s}::bigint";
    }

    public function up(): void
    {
        $pg = DB::getDriverName() === 'pgsql';
        $default = $this->sessionExpr() . '::bigint';
        $policy = $this->policyExpr();

        foreach ($this->tables as $t) {
            if (! Schema::hasTable($t) || Schema::hasColumn($t, 'company_id')) {
                continue;
            }
            Schema::table($t, function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->nullable()->index();
            });
            DB::table($t)->whereNull('company_id')->update(['company_id' => 1]);
            if ($pg) {
                DB::statement("ALTER TABLE \"{$t}\" ALTER COLUMN company_id SET DEFAULT {$default}");
                DB::statement("ALTER TABLE \"{$t}\" ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE \"{$t}\" FORCE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation ON \"{$t}\"");
                DB::statement("CREATE POLICY tenant_isolation ON \"{$t}\" USING ({$policy}) WITH CHECK ({$policy})");
            }
        }
    }

    public function down(): void
    {
        $pg = DB::getDriverName() === 'pgsql';
        foreach ($this->tables as $t) {
            if (! Schema::hasTable($t) || ! Schema::hasColumn($t, 'company_id')) {
                continue;
            }
            if ($pg) {
                DB::statement("DROP POLICY IF EXISTS tenant_isolation ON \"{$t}\"");
                DB::statement("ALTER TABLE \"{$t}\" NO FORCE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE \"{$t}\" DISABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE \"{$t}\" ALTER COLUMN company_id DROP DEFAULT");
            }
            Schema::table($t, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }
    }
};