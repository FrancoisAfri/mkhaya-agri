<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 §16C: the numbers people use the farm assistant from (WhatsApp, SMS, voice). A number
 * acts for its person only once they have proved it is theirs, and always with their permissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agri_messaging_contacts', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('user_id');
            $t->string('channel', 10);                                       // whatsapp | sms | voice
            $t->string('address', 32);                                       // E.164 number
            $t->string('language', 5)->default('en');
            $t->string('code_hash', 64)->nullable();
            $t->timestamp('code_expires_at')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->boolean('alerts')->default(false);                           // send farm alerts here
            $t->timestamps();
            $t->unique(['channel', 'address']);
        });
        AgriSchema::tenantRls(['agri_messaging_contacts']);
    }

    public function down(): void
    {
        Schema::dropIfExists('agri_messaging_contacts');
    }
};
