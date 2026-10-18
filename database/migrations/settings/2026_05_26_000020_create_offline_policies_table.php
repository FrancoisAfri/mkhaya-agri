<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_policies', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('max_offline_minutes')->default(120);
            $table->unsignedSmallInteger('warn_before_minutes')->default(15);
            $table->text('lockout_message')->nullable();
            $table->timestamps();
        });

        // Seed the single policy row
        DB::table('offline_policies')->insert([
            'enabled'             => true,
            'max_offline_minutes' => 120,
            'warn_before_minutes' => 15,
            'lockout_message'     => null,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_policies');
    }
};
