<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fleet_documents', function (Blueprint $table) {
            $table->string('contact_person', 100)->nullable()->after('supplier');
            $table->string('contact_number', 60)->nullable()->after('contact_person');
            $table->string('contact_email', 120)->nullable()->after('contact_number');
            $table->text('contact_address')->nullable()->after('contact_email');
            $table->decimal('value_covered', 14, 2)->nullable()->after('contact_address');
            $table->string('payment_frequency', 20)->nullable()->after('value_covered');
            $table->decimal('premium_amount', 12, 2)->nullable()->after('payment_frequency');
        });
    }

    public function down(): void
    {
        Schema::table('fleet_documents', function (Blueprint $table) {
            $table->dropColumn([
                'contact_person', 'contact_number', 'contact_email',
                'contact_address', 'value_covered', 'payment_frequency', 'premium_amount',
            ]);
        });
    }
};
