<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a worker's access card has to print: which site, and which project.
 *
 * The card exists so a guard at a boom can match the person in front of them to the register.
 * A QR alone does that only if the guard scans every card; a printed name, trade, site and
 * project is what gets read when the queue is twenty deep and the scanner is flat.
 *
 * Both are per worker and both are nullable:
 *
 * - `site_id` because one contractor's people are routinely split across sites. The contractor
 *   row already carries a site and the worker falls back to it, so this only has to be set for
 *   the ones who differ.
 * - `project_id` points at Project Management, which is the system of record for projects. It is
 *   nullable and stays empty for a tenant without that module rather than SHEQ keeping a second
 *   list of projects that would drift from the real one.
 *
 * No foreign key on project_id on purpose: Project Management is an entitlement, its tables may
 * be absent on an installation that never licensed it, and a hard constraint would make this
 * migration fail there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheq_contractor_workers', function (Blueprint $table) {
            $table->unsignedBigInteger('site_id')->nullable()->after('trade');
            $table->unsignedBigInteger('project_id')->nullable()->after('site_id');

            $table->index(['company_id', 'site_id'], 'sheq_cw_company_site_idx');
            $table->index(['company_id', 'project_id'], 'sheq_cw_company_project_idx');
        });

        if (Schema::hasTable('sheq_sites')) {
            Schema::table('sheq_contractor_workers', function (Blueprint $table) {
                $table->foreign('site_id')->references('id')->on('sheq_sites')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('sheq_contractor_workers', function (Blueprint $table) {
            if (Schema::hasTable('sheq_sites')) {
                $table->dropForeign(['site_id']);
            }

            $table->dropIndex('sheq_cw_company_site_idx');
            $table->dropIndex('sheq_cw_company_project_idx');
            $table->dropColumn(['site_id', 'project_id']);
        });
    }
};
