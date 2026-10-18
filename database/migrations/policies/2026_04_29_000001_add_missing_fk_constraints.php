<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add missing FK for violation_notes.author_id
        Schema::table('violation_notes', function (Blueprint $table) {
            $table->foreign('author_id')->references('id')->on('users')->onDelete('restrict');
        });

        // Add missing FKs for enforcement_actions
        Schema::table('enforcement_actions', function (Blueprint $table) {
            $table->foreign('violation_id')->references('id')->on('policy_violations')->onDelete('set null');
            $table->foreign('rule_id')->references('id')->on('policy_rules')->onDelete('restrict');
            $table->foreign('event_id')->references('id')->on('policy_events')->onDelete('set null');
        });

        // Add compound index for rule_conditions (efficient field lookups per rule)
        Schema::table('rule_conditions', function (Blueprint $table) {
            $table->index(['rule_id', 'field'], 'rule_conditions_rule_field_idx');
        });

        // Add missing FK for policy_violations.rule_id, user_id, investigator_id
        Schema::table('policy_violations', function (Blueprint $table) {
            $table->foreign('rule_id')->references('id')->on('policy_rules')->onDelete('restrict');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('investigator_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('policy_violations', function (Blueprint $table) {
            $table->dropForeign(['rule_id']);
            $table->dropForeign(['user_id']);
            $table->dropForeign(['investigator_id']);
        });

        Schema::table('rule_conditions', function (Blueprint $table) {
            $table->dropIndex('rule_conditions_rule_field_idx');
        });

        Schema::table('enforcement_actions', function (Blueprint $table) {
            $table->dropForeign(['violation_id']);
            $table->dropForeign(['rule_id']);
            $table->dropForeign(['event_id']);
        });

        Schema::table('violation_notes', function (Blueprint $table) {
            $table->dropForeign(['author_id']);
        });
    }
};
