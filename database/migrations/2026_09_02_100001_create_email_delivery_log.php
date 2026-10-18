<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every email this system tried to send, and what happened to it.
 *
 * There was no way to answer "did that go out?". An invitation, an invoice, a signing request — the
 * only evidence was the recipient saying it never arrived, and by then nobody could tell whether it
 * had been sent and lost, refused by the mail server, or never attempted at all. On an installation
 * whose SMTP was timing out, that is the difference between a five-minute fix and a fortnight of
 * guessing.
 *
 * A log, not a queue: it records attempts and never drives them. Nothing here re-sends anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_delivery_log', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();

            $t->string('to', 255)->index();
            $t->string('subject', 300)->nullable();
            // The Mailable or Notification behind it, so a whole category can be found at once —
            // "did any of yesterday's invitations go out?" is the question people actually ask.
            $t->string('mailable', 190)->nullable()->index();

            /*
             * pending | sent | failed.
             *
             * Deliberately not "delivered": SMTP accepting a message is not the recipient receiving
             * it, and claiming otherwise would make this log a liar. `pending` is written before the
             * transport is attempted and flipped on success — a row still pending is one whose send
             * never came back, which is exactly the case nobody could see before.
             */
            $t->string('status', 12)->index();
            $t->text('error')->nullable();

            // Correlates the two mail events, which are separate and carry no id of their own.
            $t->string('message_id', 190)->nullable()->index();

            $t->string('mailer', 40)->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamps();

            $t->index(['company_id', 'created_at']);
            $t->index(['status', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE email_delivery_log ADD CONSTRAINT email_delivery_log_status_chk CHECK (status IN ('pending','sent','failed'))");

            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

            DB::statement("ALTER TABLE email_delivery_log ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement('ALTER TABLE email_delivery_log ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE email_delivery_log FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON email_delivery_log');
            DB::statement("CREATE POLICY tenant_isolation ON email_delivery_log USING ({$policy}) WITH CHECK ({$policy})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_delivery_log');
    }
};
