<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A link the new hire can use before they have a login.
 *
 * Everything in onboarding was HR-side data entry: somebody in HR chased the ID copy by email and
 * typed it in themselves. Every comparable product gives the hire a page of their own before day
 * one, and the platform already has the shape for it — esign, helpdesk and the conference speaker
 * portal all work from a token in the URL.
 *
 * The token is the credential, so it expires. An onboarding link that still works two years later
 * is a standing route to somebody's ID document and banking details.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_persons', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_persons', 'portal_token')) {
                // Unique and indexed: it is looked up on every portal request and two people
                // sharing a token would be a cross-person data leak rather than a collision.
                $table->string('portal_token', 64)->nullable()->unique();
                $table->timestamp('portal_token_expires_at')->nullable();
                $table->timestamp('portal_last_seen_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_persons', function (Blueprint $table) {
            foreach (['portal_token', 'portal_token_expires_at', 'portal_last_seen_at'] as $column) {
                if (Schema::hasColumn('hr_persons', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
