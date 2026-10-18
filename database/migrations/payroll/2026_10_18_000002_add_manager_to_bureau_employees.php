<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a bureau employee reports to at the same client.
 *
 * In the Hub this column arrived with the Leave Bureau migrations; Mkhaya Agri ships the Payroll
 * Bureau without Leave Bureau, and the payroll screens still read and set the manager.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bureau_employees') || Schema::hasColumn('bureau_employees', 'manager_employee_id')) {
            return;
        }

        Schema::table('bureau_employees', function (Blueprint $table) {
            // Self-referencing, nullable, and deliberately not cascading: a manager leaving must not
            // delete their team.
            $table->foreignId('manager_employee_id')->nullable()->after('occupation')
                ->constrained('bureau_employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('bureau_employees', 'manager_employee_id')) {
            Schema::table('bureau_employees', fn (Blueprint $t) => $t->dropConstrainedForeignId('manager_employee_id'));
        }
    }
};
