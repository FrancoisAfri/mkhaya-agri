<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_bank_accounts', function (Blueprint $t) {
            $t->string('branch', 80)->nullable()->after('bank_name');
            $t->string('branch_code', 20)->nullable()->after('branch');
        });

        // Reusable allocation rules — the "Sage way": a description pattern maps a
        // statement line to a GL account, a customer, or a supplier automatically.
        Schema::create('fin_bank_rules', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('bank_account_id')->nullable(); // null = all accounts
            $t->string('name', 120);
            $t->integer('priority')->default(100); // lower runs first
            $t->string('match_type', 12)->default('contains'); // contains|equals|starts|regex
            $t->string('match_value', 255);
            $t->string('direction', 4)->default('any'); // any|in|out
            $t->string('allocation_type', 12); // account|customer|supplier
            $t->unsignedBigInteger('contra_account_id')->nullable();
            $t->unsignedBigInteger('party_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['bank_account_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_bank_rules');
        Schema::table('fin_bank_accounts', function (Blueprint $t) {
            $t->dropColumn(['branch', 'branch_code']);
        });
    }
};
