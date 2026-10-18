<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run migrations.
     */
    public function up(): void
    {
        Schema::create('company_user_pivots', function (Blueprint $table) {
            $table->id();

            $table->smallInteger('type')->index();
            // 1 = manager
            // 2 = payroll officer

            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');

            $table->foreignId('company_id')
                ->constrained('company_identities')
                ->onDelete('cascade');

            $table->timestamps();

            $table->unique(['user_id', 'company_id']);

            $table->index('user_id');
            $table->index('company_id');
        });
    }

    /**
     * Reverse migrations.
     */
    public function down(): void
    {
        // Drop pivot table first if it exists
        if (Schema::hasTable('company_user_pivots')) {
            Schema::dropIfExists('company_user_pivots');
        }

        // Then drop parent table
        Schema::dropIfExists('company_identities');
    }
};
