<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import reversibility: stamp every record an import creates with the import id
 * so a corrupted upload can be reversed (soft-deleted) in one click, and record
 * when/by whom an import was reversed.
 */
return new class extends Migration
{
    private array $entityTables = ['crm_leads', 'crm_contacts', 'crm_accounts', 'crm_deals'];

    public function up(): void
    {
        foreach ($this->entityTables as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'crm_import_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedBigInteger('crm_import_id')->nullable()->index();
                });
            }
        }

        if (Schema::hasTable('crm_imports')) {
            Schema::table('crm_imports', function (Blueprint $t) {
                if (! Schema::hasColumn('crm_imports', 'reversed_at')) {
                    $t->timestamp('reversed_at')->nullable();
                }
                if (! Schema::hasColumn('crm_imports', 'reversed_by')) {
                    $t->unsignedBigInteger('reversed_by')->nullable();
                }
            });
        }

        // Personal tasks/reminders needn't be tied to a record — allow nulls.
        if (Schema::hasTable('crm_activities')) {
            Schema::table('crm_activities', function (Blueprint $t) {
                $t->string('related_type')->nullable()->change();
                $t->unsignedBigInteger('related_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->entityTables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'crm_import_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('crm_import_id'));
            }
        }

        if (Schema::hasTable('crm_imports')) {
            Schema::table('crm_imports', function (Blueprint $t) {
                $t->dropColumn(['reversed_at', 'reversed_by']);
            });
        }
    }
};
