<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A monitored mailbox can capture sales leads or customer complaints. The poll
 * routes each message to the matching intake based on this purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_mailboxes', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_mailboxes', 'purpose')) {
                $table->string('purpose', 12)->default('lead')->after('name'); // lead | complaint
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_mailboxes', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
    }
};
