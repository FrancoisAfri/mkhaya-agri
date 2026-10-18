<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employer particulars that statutory forms require (SHEQ-REG-6).
 *
 * A W.Cl.2 cannot be submitted without the employer's COIDA registration number, and the platform
 * held no such field anywhere — so the form would have carried a permanent, unfillable gap and could
 * never have been finalised. Found by generating the form rather than by reading the spec.
 *
 * These live in SHEQ settings rather than on `companies` because they are regulatory identifiers
 * specific to occupational health and safety: a tenant that never files a W.Cl.2 has no use for
 * them, and the payroll or billing view of a company should not grow columns on SHEQ's behalf.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheq_settings', function (Blueprint $table) {
            // Compensation Commissioner registration — the one that blocks a W.Cl.2 outright.
            $table->string('coida_registration_number', 60)->nullable()->after('company_id');
            // Some employers file under a trading name that differs from the registered one; the
            // form wants the registered employer.
            $table->string('registered_employer_name')->nullable()->after('coida_registration_number');
            $table->text('employer_postal_address')->nullable()->after('registered_employer_name');
            $table->string('nature_of_business')->nullable()->after('employer_postal_address');
            // The Provincial Director office an incident is notified to (OHS Act s24).
            $table->string('provincial_director_office', 120)->nullable()->after('nature_of_business');
        });
    }

    public function down(): void
    {
        Schema::table('sheq_settings', function (Blueprint $table) {
            $table->dropColumn([
                'coida_registration_number', 'registered_employer_name',
                'employer_postal_address', 'nature_of_business', 'provincial_director_office',
            ]);
        });
    }
};
