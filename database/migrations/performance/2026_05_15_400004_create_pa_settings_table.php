<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pa_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // Insert sensible defaults
        DB::table('pa_settings')->insert([
            ['key' => 'default_cycle_type',            'value' => 'annual',    'created_at' => now(), 'updated_at' => now()],
            ['key' => 'allow_self_review',             'value' => '1',         'created_at' => now(), 'updated_at' => now()],
            ['key' => 'allow_peer_review',             'value' => '0',         'created_at' => now(), 'updated_at' => now()],
            ['key' => 'require_manager_approval',      'value' => '1',         'created_at' => now(), 'updated_at' => now()],
            ['key' => 'enable_ai_insights',            'value' => '0',         'created_at' => now(), 'updated_at' => now()],
            ['key' => 'notify_reviewee_on_submission', 'value' => '1',         'created_at' => now(), 'updated_at' => now()],
            ['key' => 'notify_manager_on_self_review', 'value' => '1',         'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('pa_settings');
    }
};
