<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rule_conditions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rule_id')->index();
            $table->enum('type', ['numeric', 'role', 'date', 'status', 'department', 'custom']);
            $table->string('field');                         // e.g. 'claim_amount', 'user.role', 'leave.days'
            $table->enum('operator', ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'between', 'contains']);
            $table->json('value');                           // single value or array for 'in'/'between'
            $table->string('value_unit')->nullable();        // 'ZAR', 'days', 'hours'
            $table->enum('logical_operator', ['AND', 'OR'])->default('AND'); // join with next condition
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('rule_id')->references('id')->on('policy_rules')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_conditions');
    }
};
