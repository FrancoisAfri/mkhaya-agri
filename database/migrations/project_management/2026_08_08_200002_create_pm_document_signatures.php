<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who has to sign a document, and who has (EPIC-050, PM-1502).
 *
 * A signature is only worth keeping if it says *what* was signed. `content_sha256` is the hash of
 * the exact bytes at the moment of signing, copied from the blob rather than recomputed later — so
 * if revision C is uploaded afterwards, the signature still points at the revision B bytes it was
 * given, and the mismatch is visible instead of silent. Without that column an e-signature is a
 * claim that somebody clicked a button on a document that may since have changed underneath them.
 *
 * The envelope groups the signatories of one round. It lives on the blob (`esign_envelope_uuid`)
 * and is regenerated on each send, so re-sending after a revision does not inherit the previous
 * round's completed signatures.
 *
 * Signatories are recorded as a user id where the signer holds an account and as a name plus email
 * where they do not — a client's quantity surveyor signing a payment certificate rarely has one,
 * and refusing to record them would push that signature onto paper.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_document_signatures', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();

            // The stable document id (the external ref uuid), so history survives a new revision …
            $t->uuid('document_uuid')->index();
            // … and the blob actually signed, which does not.
            $t->unsignedBigInteger('blob_id');
            $t->string('envelope_uuid', 40)->index();

            $t->unsignedSmallInteger('sign_order')->default(1);
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('signer_name', 190);
            $t->string('signer_email', 190)->nullable();
            $t->string('role', 60)->nullable();          // what they are signing as

            // pending | signed | declined | withdrawn. Withdrawn rather than deleted when a round is
            // superseded: that somebody was asked, and the ask was cancelled, is part of the record.
            $t->string('status', 20)->default('pending');
            $t->timestamp('signed_at')->nullable();
            $t->string('typed_name', 190)->nullable();    // what they typed to sign
            $t->string('decline_reason', 500)->nullable();

            // The evidence that this signature belongs to these bytes and that request.
            $t->string('content_sha256', 64)->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'envelope_uuid', 'sign_order']);
        });

        $this->applyTenantRls(['pm_document_signatures']);
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_document_signatures');
    }

    private function applyTenantRls(array $tables): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
        }
    }
};
