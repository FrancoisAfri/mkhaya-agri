<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The grower portal and grower self-service by WhatsApp / SMS.
 *
 *  - agri_grower_accounts: a contracted grower's own sign-in, on its own guard (never a staff user).
 *    Invited by the company to a mobile number and/or an email address; signs in with a one-time
 *    code sent there (or a password they set); revocable. A mobile proved by a code sign-in is the
 *    number the grower's WhatsApp / SMS commands are answered on.
 *  - agri_grower_portal_events: the audit of sign-ins (and failures), codes sent, revocations,
 *    statement downloads and message commands — what the staff screen shows as portal activity.
 *  - agri_grower_notices: messages from the company to one grower or to every grower of a farm.
 *  - agri_grower_visits: field visits and inspections planned with a grower (the inspection itself
 *    stays an agri_grower_inspections row once done).
 *  - agri_grower_reports: a planting report or an issue a grower sends in, with an optional photo
 *    kept in the database, for the outgrower officer to answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agri_grower_accounts', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('grower_id');
            $t->string('name', 160);
            $t->string('mobile', 20)->nullable();                            // +27… (E.164)
            $t->string('email', 190)->nullable();
            $t->string('password')->nullable();                               // optional; the code sign-in needs none
            $t->string('locale', 5)->default('en');                           // en | af
            $t->string('status', 10)->default('invited');                     // invited | active | revoked
            $t->string('otp_hash', 64)->nullable();
            $t->string('otp_channel', 10)->nullable();                        // whatsapp | sms | email
            $t->timestamp('otp_expires_at')->nullable();
            $t->unsignedSmallInteger('otp_attempts')->default(0);
            $t->timestamp('mobile_verified_at')->nullable();
            $t->timestamp('email_verified_at')->nullable();
            $t->unsignedSmallInteger('failed_attempts')->default(0);
            $t->timestamp('locked_until')->nullable();
            $t->timestamp('invited_at')->nullable();
            $t->unsignedBigInteger('invited_by')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->unsignedBigInteger('revoked_by')->nullable();
            $t->string('revoked_reason', 300)->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->string('last_login_ip', 45)->nullable();
            $t->rememberToken();
            $t->timestamps();
            $t->unique(['company_id', 'grower_id']);
            $t->index('mobile');
            $t->index('email');
        });
        Schema::create('agri_grower_portal_events', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('grower_id');
            $t->unsignedBigInteger('account_id')->nullable();
            $t->string('event', 30);                                          // invited | code_sent | signed_in | sign_in_failed | signed_out | revoked | restored | statement | command | report
            $t->string('channel', 10)->nullable();                            // web | whatsapp | sms | email
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 300)->nullable();
            $t->jsonb('detail')->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();                   // the staff member, for invite / revoke
            $t->timestamp('created_at')->nullable();
            $t->index(['grower_id', 'created_at']);
        });
        Schema::create('agri_grower_notices', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('farm_id');
            $t->unsignedBigInteger('grower_id')->nullable();                  // null: every grower of the farm
            $t->string('title', 160);
            $t->text('body');
            $t->unsignedBigInteger('sent_by')->nullable();
            $t->timestamps();
            $t->index(['farm_id', 'grower_id']);
        });
        Schema::create('agri_grower_visits', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('grower_id');
            $t->unsignedBigInteger('contract_id')->nullable();
            $t->date('planned_on');
            $t->string('purpose', 20)->default('inspection');                 // inspection | field_visit | collection | training
            $t->string('notes', 500)->nullable();
            $t->string('status', 10)->default('planned');                     // planned | done | cancelled
            $t->unsignedBigInteger('officer_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['grower_id', 'planned_on']);
        });
        Schema::create('agri_grower_reports', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('grower_id');
            $t->unsignedBigInteger('contract_id')->nullable();
            $t->unsignedBigInteger('account_id')->nullable();
            $t->string('kind', 10);                                           // planting | issue
            $t->date('planted_on')->nullable();
            $t->decimal('hectares', 10, 2)->nullable();
            $t->string('details', 2000);
            $t->string('photo_name', 200)->nullable();
            $t->string('photo_mime', 60)->nullable();
            $t->longText('photo_data')->nullable();                           // base64, in the database
            $t->string('status', 12)->default('open');                        // open | answered | closed
            $t->string('response', 1000)->nullable();
            $t->unsignedBigInteger('answered_by')->nullable();
            $t->timestamp('answered_at')->nullable();
            $t->timestamps();
            $t->index(['grower_id', 'status']);
        });
        AgriSchema::tenantRls(['agri_grower_accounts', 'agri_grower_portal_events', 'agri_grower_notices', 'agri_grower_visits', 'agri_grower_reports']);
    }

    public function down(): void
    {
        foreach (['agri_grower_reports', 'agri_grower_visits', 'agri_grower_notices', 'agri_grower_portal_events', 'agri_grower_accounts'] as $x) {
            Schema::dropIfExists($x);
        }
    }
};
