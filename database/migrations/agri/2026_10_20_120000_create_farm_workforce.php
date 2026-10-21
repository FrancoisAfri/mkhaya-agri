<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 · Release 01 — workforce, rosters, attendance, piecework and the payroll handoff.
 *
 * A farm worker is an HR person (hr_people, which takes people without a login — seasonal and
 * casual labour) plus what the farm needs on top: category, crew, labour rules, a costing rate and
 * a QR badge. Personal details stay in HR; nothing here is a second employee register.
 *
 * Attendance keeps every raw event. A correction adds a new event and supersedes the old one; it
 * never edits it. Approved time is written to HR's attendance_records (which payroll already prices)
 * and piecework to the payroll run, each once, through a handoff that can be reconciled.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Rules: configuration, never assumed law ─────────────────────────────────────────
        Schema::create('labour_rule_sets', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->string('name', 120);
            $t->jsonb('applies_to')->nullable();               // worker categories; null = everybody
            $t->boolean('is_default')->default(false);
            $t->unsignedSmallInteger('daily_ot_after_minutes')->default(540);   // overtime after N worked minutes a day
            $t->unsignedSmallInteger('weekly_ot_after_minutes')->default(2700); // …or a week
            $t->decimal('ot_multiplier', 4, 2)->default(1.5);
            $t->decimal('sunday_multiplier', 4, 2)->default(2.0);
            $t->decimal('holiday_multiplier', 4, 2)->default(2.0);
            $t->time('night_starts')->default('18:00');
            $t->time('night_ends')->default('06:00');
            $t->decimal('night_allowance_per_hour', 8, 2)->default(0);
            $t->unsignedSmallInteger('late_grace_minutes')->default(5);
            $t->unsignedSmallInteger('min_rest_minutes')->default(720);  // between shifts
            $t->unsignedSmallInteger('max_shift_minutes')->default(720);
            $t->unsignedSmallInteger('fatigue_warn_minutes')->default(600);
            $t->string('overlap_policy', 8)->default('block');       // warn | block
            $t->string('certification_policy', 8)->default('block'); // warn | block
            $t->boolean('needs_review')->default(true);               // until somebody confirms it against the law and agreements
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        // ── People ──────────────────────────────────────────────────────────────────────────
        Schema::create('farm_crews', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->string('code', 30);
            $t->string('name', 120);
            $t->unsignedBigInteger('leader_worker_id')->nullable();
            $t->string('status', 12)->default('active');
            $t->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX farm_crews_farm_code_unique ON farm_crews (farm_id, lower(code))');

        Schema::create('farm_workers', function (Blueprint $t) {
            AgriSchema::farmOwned($t);                              // farm_id = home farm
            $t->unsignedBigInteger('hr_person_id');
            // permanent | seasonal | casual | contractor | supervisor | manager
            $t->string('category', 12);
            $t->foreignId('crew_id')->nullable()->constrained('farm_crews')->nullOnDelete();
            $t->foreignId('labour_rule_set_id')->nullable()->constrained('labour_rule_sets')->nullOnDelete();
            $t->decimal('hourly_cost_rate', 10, 2)->nullable();    // costing only; payroll decides pay
            $t->string('badge_token', 64)->unique();                // the QR badge
            $t->string('status', 12)->default('active');            // active | suspended | terminated
            $t->date('contract_start')->nullable();
            $t->date('contract_end')->nullable();
            $t->string('contract_type', 30)->nullable();
            $t->boolean('gps_allowed')->default(false);             // location only with consent
            $t->boolean('biometric_allowed')->default(false);
            $t->timestamp('consent_recorded_at')->nullable();
            $t->unsignedBigInteger('consent_recorded_by')->nullable();
            $t->string('status_reason', 300)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'hr_person_id']);
            $t->index(['company_id', 'farm_id', 'status']);
        });

        Schema::create('worker_unavailability', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->foreignId('worker_id')->constrained('farm_workers')->cascadeOnDelete();
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->string('reason', 200)->nullable();
            $t->timestamps();
        });

        // ── Rosters ─────────────────────────────────────────────────────────────────────────
        Schema::create('shift_templates', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->string('code', 20);
            $t->string('name', 80);
            // day | night | split | rotating | weekend | public_holiday | seasonal | emergency | custom
            $t->string('kind', 16)->default('day');
            $t->time('starts');
            $t->time('ends');                                       // before `starts` = ends the next day
            $t->unsignedSmallInteger('break_minutes')->default(30);
            $t->boolean('paid_break')->default(false);
            $t->jsonb('required_certifications')->nullable();
            $t->unsignedSmallInteger('min_staff')->default(1);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX shift_templates_company_code_unique ON shift_templates (company_id, lower(code))');

        Schema::create('farm_shifts', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('template_id')->nullable()->constrained('shift_templates')->nullOnDelete();
            $t->unsignedBigInteger('field_id')->nullable();
            $t->unsignedBigInteger('location_id')->nullable();
            $t->unsignedBigInteger('work_order_id')->nullable();
            $t->string('title', 120);
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->unsignedSmallInteger('break_minutes')->default(30);
            $t->boolean('paid_break')->default(false);
            $t->jsonb('required_certifications')->nullable();
            $t->unsignedSmallInteger('min_staff')->default(1);
            $t->string('status', 12)->default('draft');            // draft | published | cancelled
            $t->unsignedSmallInteger('revision')->default(0);
            $t->uuid('batch')->nullable();                          // generated together (a rotating roster)
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'farm_id', 'starts_at']);
        });

        Schema::create('farm_shift_assignments', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('shift_id')->constrained('farm_shifts')->cascadeOnDelete();
            $t->foreignId('worker_id')->constrained('farm_workers')->restrictOnDelete();
            $t->string('status', 12)->default('assigned');          // assigned | swapped | cancelled
            $t->string('warnings', 500)->nullable();                // accepted warnings, kept on record
            $t->unsignedBigInteger('assigned_by')->nullable();
            $t->timestamps();
            $t->index(['worker_id', 'status']);
        });

        // The schedule as it was before every change to a published shift (LAB-010).
        Schema::create('farm_shift_changes', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('shift_id')->constrained('farm_shifts')->cascadeOnDelete();
            $t->unsignedSmallInteger('revision');
            $t->string('change', 30);                               // edited | assigned | unassigned | swapped | cancelled
            $t->jsonb('before');
            $t->jsonb('after')->nullable();
            $t->string('reason', 300)->nullable();
            $t->unsignedBigInteger('changed_by')->nullable();
            $t->timestamps();
        });

        Schema::create('farm_shift_swaps', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('assignment_id')->constrained('farm_shift_assignments')->cascadeOnDelete();
            $t->foreignId('to_worker_id')->constrained('farm_workers')->restrictOnDelete();
            $t->string('status', 12)->default('requested');        // requested | approved | rejected
            $t->string('reason', 300)->nullable();
            $t->unsignedBigInteger('requested_by')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });

        // ── Attendance ──────────────────────────────────────────────────────────────────────
        Schema::create('farm_clock_events', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('worker_id')->constrained('farm_workers')->restrictOnDelete();
            $t->string('kind', 4);                                   // in | out
            $t->timestampTz('occurred_at');
            // mobile | kiosk | qr | biometric | supervisor | correction | import
            $t->string('method', 12);
            $t->string('device_ref', 80)->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->boolean('gps_verified')->nullable();
            $t->string('verification', 12)->default('verified');   // verified | unverified | suspicious
            $t->string('verification_note', 300)->nullable();
            $t->uuid('client_id')->nullable();                       // offline capture: applied once
            $t->boolean('captured_offline')->default(false);
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->boolean('supervisor_confirmed')->default(false);
            $t->unsignedBigInteger('superseded_by_id')->nullable();  // a correction replaced it; it is kept
            $t->timestamps();
            $t->index(['worker_id', 'occurred_at']);
        });
        DB::statement('CREATE UNIQUE INDEX farm_clock_events_client_unique ON farm_clock_events (company_id, client_id) WHERE client_id IS NOT NULL');

        Schema::create('farm_timesheets', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('worker_id')->constrained('farm_workers')->restrictOnDelete();
            $t->unsignedBigInteger('assignment_id')->nullable();
            $t->date('work_date');                                   // the day the shift (or first clock-in) started
            $t->timestampTz('scheduled_start')->nullable();
            $t->timestampTz('scheduled_end')->nullable();
            $t->unsignedBigInteger('in_event_id')->nullable();
            $t->unsignedBigInteger('out_event_id')->nullable();
            $t->timestampTz('clock_in_at')->nullable();
            $t->timestampTz('clock_out_at')->nullable();
            $t->unsignedInteger('worked_minutes')->default(0);       // after unpaid breaks
            $t->unsignedSmallInteger('break_minutes')->default(0);
            $t->unsignedSmallInteger('late_minutes')->default(0);
            $t->unsignedSmallInteger('early_minutes')->default(0);
            $t->unsignedInteger('overtime_minutes')->default(0);
            $t->unsignedInteger('sunday_minutes')->default(0);
            $t->unsignedInteger('holiday_minutes')->default(0);
            $t->unsignedInteger('night_minutes')->default(0);
            $t->jsonb('minutes_by_date')->nullable();                // an overnight shift split across dates
            $t->jsonb('flags')->nullable();                          // late, early, missing_out, unplanned_overtime, …
            $t->string('status', 12)->default('open');               // open | complete | approved | rejected
            $t->unsignedBigInteger('field_id')->nullable();
            $t->unsignedBigInteger('crop_plan_id')->nullable();
            $t->unsignedBigInteger('livestock_group_id')->nullable();
            $t->unsignedBigInteger('work_order_id')->nullable();
            $t->decimal('cost_amount', 12, 2)->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->unsignedBigInteger('handoff_line_id')->nullable();   // sent to payroll once
            $t->timestamps();
            $t->index(['company_id', 'farm_id', 'work_date']);
            $t->index(['worker_id', 'work_date']);
        });

        Schema::create('farm_attendance_corrections', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('timesheet_id')->constrained('farm_timesheets')->cascadeOnDelete();
            $t->string('field', 10);                                 // clock_in | clock_out
            $t->timestampTz('original_at')->nullable();
            $t->timestampTz('corrected_at');
            $t->string('reason', 500);
            $t->string('status', 12)->default('pending');            // pending | approved | rejected
            $t->unsignedBigInteger('requested_by')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->unsignedBigInteger('original_event_id')->nullable();
            $t->unsignedBigInteger('correction_event_id')->nullable();
            $t->timestamps();
        });

        Schema::create('farm_overtime_requests', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('worker_id')->constrained('farm_workers')->restrictOnDelete();
            $t->date('work_date');
            $t->unsignedSmallInteger('minutes');
            $t->string('reason', 300);
            $t->boolean('emergency')->default(false);
            $t->string('status', 12)->default('requested');         // requested | approved | rejected
            $t->unsignedBigInteger('requested_by')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });

        // ── Piecework ───────────────────────────────────────────────────────────────────────
        Schema::create('piece_rates', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('farm_id')->nullable();           // null = every farm
            // harvesting | packing | weeding | planting | pruning | processing | grading | other
            $t->string('activity', 16);
            $t->string('unit', 10);                                  // kg | crate | box | row | ha | tree | animal | unit
            $t->unsignedBigInteger('crop_type_id')->nullable();
            $t->decimal('rate', 10, 4);
            $t->decimal('daily_target', 12, 3)->nullable();
            $t->date('valid_from');
            $t->date('valid_to')->nullable();
            $t->string('status', 10)->default('draft');              // draft | approved | retired
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
        });

        Schema::create('piecework_entries', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->date('work_date');
            $t->string('activity', 16);
            $t->string('unit', 10);
            $t->foreignId('worker_id')->nullable()->constrained('farm_workers')->restrictOnDelete();
            $t->foreignId('crew_id')->nullable()->constrained('farm_crews')->restrictOnDelete();
            $t->string('allocation', 12)->default('individual');     // individual | equal | percentage | recorded
            $t->unsignedBigInteger('field_id')->nullable();
            $t->unsignedBigInteger('crop_plan_id')->nullable();
            $t->unsignedBigInteger('work_order_id')->nullable();
            $t->decimal('measured_quantity', 12, 3);                 // as submitted
            $t->decimal('approved_quantity', 12, 3)->nullable();     // as verified
            $t->decimal('rejected_quantity', 12, 3)->default(0);
            $t->decimal('rework_quantity', 12, 3)->default(0);
            $t->foreignId('piece_rate_id')->nullable()->constrained('piece_rates')->nullOnDelete();
            $t->decimal('rate', 10, 4)->nullable();                  // the approved rate, frozen at approval
            $t->decimal('amount', 12, 2)->nullable();
            $t->string('status', 10)->default('submitted');          // submitted | approved | rejected | queried
            $t->string('review_note', 500)->nullable();
            $t->uuid('client_id')->nullable();
            $t->unsignedBigInteger('submitted_by')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'farm_id', 'work_date']);
        });
        DB::statement('CREATE UNIQUE INDEX piecework_entries_client_unique ON piecework_entries (company_id, client_id) WHERE client_id IS NOT NULL');

        Schema::create('piecework_shares', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('entry_id')->constrained('piecework_entries')->cascadeOnDelete();
            $t->foreignId('worker_id')->constrained('farm_workers')->restrictOnDelete();
            $t->decimal('percent', 7, 4)->nullable();
            $t->decimal('quantity', 12, 3)->nullable();
            $t->decimal('amount', 12, 2)->default(0);
            $t->unsignedBigInteger('handoff_line_id')->nullable();
            $t->timestamps();
            $t->unique(['entry_id', 'worker_id']);
        });

        // ── Payroll handoff ─────────────────────────────────────────────────────────────────
        Schema::create('payroll_handoffs', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->string('reference', 40);
            $t->date('period_start');
            $t->date('period_end');
            $t->string('status', 12)->default('prepared');          // prepared | sent | reconciled
            $t->jsonb('totals')->nullable();
            $t->unsignedBigInteger('payroll_run_id')->nullable();
            $t->unsignedBigInteger('prepared_by')->nullable();
            $t->unsignedBigInteger('sent_by')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'reference']);
        });

        Schema::create('payroll_handoff_lines', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->foreignId('handoff_id')->constrained('payroll_handoffs')->cascadeOnDelete();
            $t->foreignId('worker_id')->constrained('farm_workers')->restrictOnDelete();
            $t->unsignedBigInteger('hr_person_id');
            $t->unsignedInteger('regular_minutes')->default(0);
            $t->unsignedInteger('overtime_minutes')->default(0);
            $t->unsignedInteger('sunday_minutes')->default(0);
            $t->unsignedInteger('holiday_minutes')->default(0);
            $t->unsignedInteger('night_minutes')->default(0);
            $t->decimal('piecework_amount', 12, 2)->default(0);
            $t->unsignedSmallInteger('timesheets')->default(0);
            $t->unsignedSmallInteger('piecework_lines')->default(0);
            // hr_attendance (+ payroll run) | export (no in-house payslip for this person)
            $t->string('delivered_to', 16)->nullable();
            $t->decimal('delivered_piecework', 12, 2)->nullable();
            $t->unsignedInteger('delivered_minutes')->nullable();
            $t->string('status', 12)->default('prepared');          // prepared | delivered | mismatch | exported
            $t->string('note', 300)->nullable();
            $t->timestamps();
            $t->unique(['handoff_id', 'worker_id']);
        });

        AgriSchema::tenantRls(['labour_rule_sets', 'farm_crews', 'farm_workers', 'worker_unavailability', 'shift_templates', 'farm_shifts',
            'farm_shift_assignments', 'farm_shift_changes', 'farm_shift_swaps', 'farm_clock_events', 'farm_timesheets',
            'farm_attendance_corrections', 'farm_overtime_requests', 'piece_rates', 'piecework_entries', 'piecework_shares',
            'payroll_handoffs', 'payroll_handoff_lines']);
    }

    public function down(): void
    {
        foreach (['payroll_handoff_lines', 'payroll_handoffs', 'piecework_shares', 'piecework_entries', 'piece_rates', 'farm_overtime_requests',
            'farm_attendance_corrections', 'farm_timesheets', 'farm_clock_events', 'farm_shift_swaps', 'farm_shift_changes',
            'farm_shift_assignments', 'farm_shifts', 'shift_templates', 'worker_unavailability', 'farm_workers', 'farm_crews', 'labour_rule_sets'] as $x) {
            Schema::dropIfExists($x);
        }
    }
};
