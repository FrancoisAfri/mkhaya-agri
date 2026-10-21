<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When an alert was emailed, so each one is mailed once — and a person may turn the emails off. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farm_alerts', fn (Blueprint $t) => $t->timestamp('notified_at')->nullable());
        Schema::table('farm_memberships', fn (Blueprint $t) => $t->boolean('alert_emails')->default(true));
    }

    public function down(): void
    {
        Schema::table('farm_alerts', fn (Blueprint $t) => $t->dropColumn('notified_at'));
        Schema::table('farm_memberships', fn (Blueprint $t) => $t->dropColumn('alert_emails'));
    }
};
