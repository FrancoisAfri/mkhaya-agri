<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Best-in-class 1:1 experience layered on top of pa_checkins:
 *  - collaborative agenda talking points (either party can add, tick off)
 *  - structured action items with owner, due date and carry-over
 *  - recurrence + private manager notes on the check-in itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pa_checkins', function (Blueprint $table) {
            if (! Schema::hasColumn('pa_checkins', 'recurrence')) {
                $table->string('recurrence')->nullable()->after('status'); // none|weekly|biweekly|monthly
            }
            if (! Schema::hasColumn('pa_checkins', 'private_manager_notes')) {
                $table->text('private_manager_notes')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('pa_checkins', 'mood')) {
                $table->string('mood', 16)->nullable()->after('status'); // employee self-reported mood emoji
            }
        });

        Schema::create('pa_checkin_talking_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkin_id')->constrained('pa_checkins')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('author_role')->default('employee'); // employee | manager
            $table->text('body');
            $table->boolean('is_discussed')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('pa_checkin_action_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkin_id')->constrained('pa_checkins')->cascadeOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description');
            $table->date('due_date')->nullable();
            $table->string('status')->default('open'); // open | done | carried_over
            $table->foreignId('carried_from_id')->nullable()->constrained('pa_checkin_action_items')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pa_checkin_action_items');
        Schema::dropIfExists('pa_checkin_talking_points');
        Schema::table('pa_checkins', function (Blueprint $table) {
            foreach (['recurrence', 'private_manager_notes', 'mood'] as $c) {
                if (Schema::hasColumn('pa_checkins', $c)) $table->dropColumn($c);
            }
        });
    }
};
