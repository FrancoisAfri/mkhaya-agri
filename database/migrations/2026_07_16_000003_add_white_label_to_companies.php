<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * White-label branding entitlement. When ON (toggled by a platform admin), the company
 * may set its own in-system logo (shown in the app after login) via /settings/setup.
 * Free for now; no login-page branding. self_hosted installs are implicitly white-label.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'white_label')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('white_label')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'white_label')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('white_label');
            });
        }
    }
};
