<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_depreciation_journals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('depreciation_id')->constrained('asset_depreciations')->cascadeOnDelete();
            $table->unsignedBigInteger('journal_entry_id')->nullable()->comment('FK to accounting module');
            
            $table->date('period_start');
            $table->date('period_end');
            
            $table->decimal('depreciation_expense', 12, 2);
            $table->decimal('accumulated_depreciation', 12, 2);
            
            $table->enum('track_type', ['accounting', 'tax']);
            
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users');
            
            $table->timestamps();
            
            $table->index(['depreciation_id', 'period_start']);
            $table->index('track_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_depreciation_journals');
    }
};
