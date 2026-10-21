<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** An individual's piecework is handed to payroll once, like a crew member's share. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('piecework_entries', fn (Blueprint $t) => $t->unsignedBigInteger('handoff_line_id')->nullable());
    }

    public function down(): void
    {
        Schema::table('piecework_entries', fn (Blueprint $t) => $t->dropColumn('handoff_line_id'));
    }
};
