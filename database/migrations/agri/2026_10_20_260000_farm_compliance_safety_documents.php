<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 §12: a farm is a SHEQ site (§12.2), and the farm documents gain versions, approval, review
 * and retention (§12.3). The compliance register, schemes and job-gating checklists are SHEQ's (see
 * the SHEQ migration for compliance schemes and inspection gates), and farm documents have since
 * moved to Document Management.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farms', fn (Blueprint $t) => $t->unsignedBigInteger('sheq_site_id')->nullable());

        Schema::table('farm_documents', function (Blueprint $t) {
            $t->unsignedSmallInteger('version')->default(1);
            $t->unsignedBigInteger('previous_id')->nullable();              // the version this one replaced
            $t->string('approval', 10)->default('approved');                // draft | approved (existing documents count as approved)
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->date('review_due')->nullable();
            $t->date('retain_until')->nullable();                           // may not be deleted before this date
            $t->string('checksum', 64)->nullable();                         // sha256 of the stored file: an altered file shows
        });
    }

    public function down(): void
    {
        Schema::table('farm_documents', fn (Blueprint $t) => $t->dropColumn(['version', 'previous_id', 'approval', 'approved_by', 'approved_at', 'review_due', 'retain_until', 'checksum']));
        Schema::table('farms', fn (Blueprint $t) => $t->dropColumn('sheq_site_id'));
    }
};
