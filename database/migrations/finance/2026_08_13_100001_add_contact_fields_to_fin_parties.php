<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contact detail a customer master file carries that we had nowhere to put, so it was
 * dropped on import: web address, fax, the named contact person, the assigned sales rep
 * and the customer's standing discount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_parties', function (Blueprint $table) {
            if (! Schema::hasColumn('fin_parties', 'website')) {
                $table->string('website')->nullable()->after('email');
            }
            if (! Schema::hasColumn('fin_parties', 'fax')) {
                $table->string('fax', 40)->nullable()->after('phone');
            }
            if (! Schema::hasColumn('fin_parties', 'contact_name')) {
                $table->string('contact_name')->nullable()->after('name');
            }
            if (! Schema::hasColumn('fin_parties', 'sales_rep')) {
                $table->string('sales_rep', 120)->nullable();
            }
            if (! Schema::hasColumn('fin_parties', 'default_discount_percent')) {
                $table->decimal('default_discount_percent', 5, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('fin_parties', function (Blueprint $table) {
            $table->dropColumn(array_values(array_filter(
                ['website', 'fax', 'contact_name', 'sales_rep', 'default_discount_percent'],
                fn ($c) => Schema::hasColumn('fin_parties', $c),
            )));
        });
    }
};
