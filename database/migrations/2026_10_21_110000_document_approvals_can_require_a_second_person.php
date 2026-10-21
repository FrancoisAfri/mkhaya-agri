<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A workflow can require that nobody approves a document they submitted or uploaded themselves —
 * the four-eyes rule farm compliance records (and many others) need. Off by default: existing
 * workflows behave exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_approval_workflows', fn (Blueprint $t) => $t->boolean('separate_from_submitter')->default(false));
    }

    public function down(): void
    {
        Schema::table('document_approval_workflows', fn (Blueprint $t) => $t->dropColumn('separate_from_submitter'));
    }
};
