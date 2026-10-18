<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboarding_plans', function (Blueprint $table) {
            $table->boolean('rehire_eligible')->nullable()->after('notes');
            $table->text('rehire_notes')->nullable()->after('rehire_eligible');
            $table->boolean('counteroffer_made')->nullable()->after('rehire_notes');
            $table->enum('counteroffer_outcome', ['accepted', 'declined'])->nullable()->after('counteroffer_made');
        });
    }

    public function down(): void
    {
        Schema::table('offboarding_plans', function (Blueprint $table) {
            $table->dropColumn(['rehire_eligible', 'rehire_notes', 'counteroffer_made', 'counteroffer_outcome']);
        });
    }
};
