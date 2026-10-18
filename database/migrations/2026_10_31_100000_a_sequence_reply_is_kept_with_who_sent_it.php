<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An enrolment remembers that its person replied, when, and what they said.
 *
 * Until now a reply left only a status and an AI intent behind, so "who replied to this sequence"
 * could be counted but not read: the words themselves were classified and thrown away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_sequence_enrollments', function (Blueprint $t) {
            if (! Schema::hasColumn('crm_sequence_enrollments', 'replied_at')) $t->timestamp('replied_at')->nullable();
            if (! Schema::hasColumn('crm_sequence_enrollments', 'last_reply')) $t->text('last_reply')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crm_sequence_enrollments', fn (Blueprint $t) => $t->dropColumn(['replied_at', 'last_reply']));
    }
};
