<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_integrations', function (Blueprint $table) {
            $table->id();
            $table->string('integration_type', 30);  // telematics | fuel_card | tollgate
            $table->string('provider', 60);           // mix_telematics | cartrack | geotab | custom | etc.
            $table->string('label', 100);             // display name
            $table->string('api_endpoint')->nullable();
            $table->text('api_key')->nullable();      // stored encrypted
            $table->text('api_secret')->nullable();   // stored encrypted
            $table->text('additional_config')->nullable(); // JSON for extra fields per provider
            $table->boolean('active')->default(false);
            $table->string('last_sync_status', 20)->nullable(); // success | error | pending
            $table->text('last_sync_message')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['integration_type', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_integrations');
    }
};
