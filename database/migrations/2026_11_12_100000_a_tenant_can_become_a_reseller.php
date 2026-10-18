<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A tenant company can be converted into a reseller.
 *
 * The company stops being a tenant: its profile is copied onto a new reseller, the users it keeps
 * move to the reseller portal (as owners or staff), its subscription ends and the company is
 * archived with a date after which its business data is deleted.
 *
 * - resellers gain the rest of a company profile (VAT, address, website, support email, logo) and
 *   a record of which company they came from.
 * - reseller_users lets a reseller have a team; until now a reseller had one login (resellers.user_id),
 *   which is carried over as that reseller's owner.
 * - companies gain archive and purge dates and a link to the reseller they became.
 *
 * Platform-level tables: no company_id, no RLS (like resellers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resellers', function (Blueprint $t) {
            $t->string('vat_number', 60)->nullable()->after('registration_number');
            $t->text('address')->nullable();
            $t->string('website')->nullable();
            $t->string('support_email')->nullable();
            $t->text('logo')->nullable();                                   // same JSON blob as company_identities.company_logo
            $t->unsignedBigInteger('converted_from_company_id')->nullable()->index();
            $t->timestamp('converted_at')->nullable();
            $t->unsignedBigInteger('converted_by')->nullable();
        });

        Schema::create('reseller_users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('reseller_id')->index();
            $t->unsignedBigInteger('user_id')->unique();                  // a login belongs to one reseller
            $t->string('role', 10)->default('staff');                       // owner | staff
            $t->string('first_name', 100)->nullable();
            $t->string('surname', 100)->nullable();
            $t->string('phone', 40)->nullable();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE reseller_users ADD CONSTRAINT reseller_users_role CHECK (role IN ('owner','staff'))");

        // The single login every existing reseller has becomes its owner.
        DB::statement("INSERT INTO reseller_users (reseller_id, user_id, role, created_at, updated_at)
            SELECT id, user_id, 'owner', now(), now() FROM resellers WHERE user_id IS NOT NULL
            ON CONFLICT (user_id) DO NOTHING");

        Schema::table('companies', function (Blueprint $t) {
            $t->timestamp('archived_at')->nullable();
            $t->date('purge_after')->nullable()->index();
            $t->timestamp('purged_at')->nullable();
            $t->unsignedBigInteger('converted_to_reseller_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropIndex(['purge_after']);
            $t->dropIndex(['converted_to_reseller_id']);
            $t->dropColumn(['archived_at', 'purge_after', 'purged_at', 'converted_to_reseller_id']);
        });
        Schema::dropIfExists('reseller_users');
        Schema::table('resellers', function (Blueprint $t) {
            $t->dropIndex(['converted_from_company_id']);
            $t->dropColumn(['vat_number', 'address', 'website', 'support_email', 'logo', 'converted_from_company_id', 'converted_at', 'converted_by']);
        });
    }
};
