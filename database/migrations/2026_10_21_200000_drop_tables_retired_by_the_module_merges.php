<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the tables the farm's merges into the host modules left behind.
 *
 * Each was copied into its new home by an earlier migration and is no longer read or written:
 *  - sales: buyers, price lists, quotes, disputes, orders → Accounting (fin_parties, fin_price_lists,
 *    fin_quotes, fin_disputes, fin_sales_orders);
 *  - farm documents → Document Management (2026_10_21_120000);
 *  - compliance packs, obligations and safety checklists → SHEQ;
 *  - farm machines and their usage, services, faults, checks, claims, documents → Fleet (2026_10_21_150000);
 *  - produce dispatches and recalls → Warehouse shipments and Manufacturing recalls (2026_10_21_170000);
 *  - shift templates, farm clock events and T&A's old department roster → the attendance engine
 *    (attendance_shifts, attendance_clockings, rostered shifts — attendance/2026_10_21_190000).
 *
 * CASCADE removes the foreign keys other tables still had to them; no other table is dropped.
 */
return new class extends Migration
{
    private const RETIRED = [
        'agri_sales_disputes', 'agri_sales_quote_lines', 'agri_sales_quotes', 'agri_price_list_items', 'agri_price_lists', 'agri_buyers',
        'produce_order_lines', 'produce_orders',
        'farm_documents',
        'agri_compliance_pack_items', 'agri_compliance_packs', 'agri_compliance_obligations', 'agri_safety_checklist_runs', 'agri_safety_checklists',
        'machine_documents', 'machine_warranty_claims', 'machine_prestart_checks', 'machine_faults', 'machine_services', 'machine_usage_logs', 'farm_machines',
        'produce_dispatch_items', 'produce_dispatches', 'produce_recalls',
        'farm_clock_events', 'shift_templates',
        'roster_optimization_logs', 'roster_assignments', 'roster_schedules',
    ];

    public function up(): void
    {
        if (Schema::hasColumn('attendance_rostered_shifts', 'roster_schedule_id')) {
            Schema::table('attendance_rostered_shifts', fn ($t) => $t->dropColumn('roster_schedule_id'));
        }
        foreach (self::RETIRED as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table} CASCADE");
        }
    }

    public function down(): void
    {
        // Their data lives in the host modules' tables; the old tables are not recreated.
    }
};
