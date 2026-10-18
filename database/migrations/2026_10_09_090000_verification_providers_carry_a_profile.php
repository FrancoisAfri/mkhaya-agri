<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shipped profile, so a customer supplies credentials and nothing else.
 *
 * The catalogue described what a source can answer and what credentials it needs; it said nothing
 * about where to call or how to read the reply. That left the endpoint and the response field map
 * to the customer — a developer task on a settings screen — so "enable CIPC and paste your key"
 * produced an enabled provider that called nothing.
 *
 * A profile is the half we can know once: the endpoint, the method, the placeholders, and the map
 * from the provider's response into our fields, per check type. It sits UNDER whatever the tenant
 * configures rather than over it, because the shape of a commercial contract varies by tier and the
 * customer's own documentation beats ours whenever the two disagree.
 *
 * Shipped empty on purpose. A profile can only be written against a provider's real documentation,
 * and inventing one would produce a connector that returns plausible answers — the single most
 * dangerous thing this module could contain. Adding one is a data change, not a release.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_providers', function (Blueprint $table) {
            if (! Schema::hasColumn('verification_providers', 'profile')) {
                $table->jsonb('profile')->nullable()->after('capabilities');
            }
            if (! Schema::hasColumn('verification_providers', 'profile_source')) {
                // Which document this was written from, so a reader can tell a profile taken from
                // published documentation from one somebody reverse-engineered from a sample.
                $table->string('profile_source', 255)->nullable()->after('profile');
            }
        });
    }

    public function down(): void
    {
        Schema::table('verification_providers', function (Blueprint $table) {
            foreach (['profile', 'profile_source'] as $column) {
                if (Schema::hasColumn('verification_providers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
