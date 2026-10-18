<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Links that let somebody outside the organisation read one document.
 *
 * This is the only part of the module that serves a document to a request with no session, so it
 * is the part where a mistake is worst. Four decisions follow from that:
 *
 *   The token is stored hashed. It is a credential, and a credential kept in plaintext turns a
 *   read-only database leak into access to every shared document. The recipient's copy, in the
 *   emailed URL, is the only copy — which also means a lost link is reissued rather than looked up.
 *
 *   Every link expires. A share with no end date is one nobody revokes, because nobody remembers it
 *   exists; the column is not nullable for that reason.
 *
 *   Revoking sets a timestamp rather than deleting the row. "Was this document sent outside, and to
 *   whom" is asked long after the link stops working, and a deleted row answers it with silence.
 *
 *   Access is logged per view with the address it came from. A share is the one place where the
 *   person reading a document is not somebody the organisation can name afterwards, so the request
 *   itself is the only evidence there is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_shares', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('document_id');

            /*
             * SHA-256 of the token, and the only form of it kept. Unique and indexed because it is
             * how every public request finds its share — the lookup is the authentication.
             */
            $table->string('token_hash', 64)->unique();

            // Who it was meant for. Not a check — anybody with the link gets in — but the difference
            // between "shared with the auditors" and "shared" when somebody asks later.
            $table->string('recipient_email')->nullable();
            $table->string('note')->nullable();

            /*
             * A second factor for the times a link is forwarded, mailed to the wrong address, or
             * sits in an inbox that later gets breached. Hashed, like any other password.
             */
            $table->string('password_hash')->nullable();

            // Viewing is the default; downloading is a separate decision. Somebody sharing a signed
            // contract for review often does not intend to hand over a copy of it.
            $table->boolean('allow_download')->default(false);

            // Null means unlimited views, which is the ordinary case. A limit is for links that
            // should work once — a payslip, a settlement letter — and stop working afterwards.
            $table->unsignedInteger('max_downloads')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->unsignedInteger('view_count')->default(0);

            $table->timestamp('expires_at');
            $table->timestamp('last_accessed_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index(['document_id', 'revoked_at']);
            $table->index('expires_at');
        });

        /*
         * One row per time somebody opened the link.
         *
         * Separate from the share so a link opened forty times is forty rows rather than a counter
         * that cannot say when or from where. This is the evidence that a document left the
         * building, and a counter is not evidence.
         */
        Schema::create('document_share_accesses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('share_id');

            // 'viewed' or 'downloaded' — a distinction that matters when the share was view-only.
            $table->string('action', 20);

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 250)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['share_id', 'created_at']);
        });

        foreach (['document_shares', 'document_share_accesses'] as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT NULLIF(current_setting('app.company_id', true), '')::bigint");
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY {$table}_tenant ON {$table} USING (
                NULLIF(current_setting('app.company_id', true), '') IS NULL
                OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint
            )");
        }

        Schema::table('document_settings', function (Blueprint $table) {
            // Off until somebody turns it on. A repository that can publish its contents to the
            // internet should not acquire that ability silently on the day it is upgraded.
            $table->boolean('sharing_enabled')->default(false);

            // The ceiling on how long a link may live, so "expires in a year" is a decision the
            // organisation made once rather than one every sharer makes for themselves.
            $table->unsignedSmallInteger('share_max_days')->default(30);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_share_accesses');
        Schema::dropIfExists('document_shares');

        Schema::table('document_settings', function (Blueprint $table) {
            $table->dropColumn(['sharing_enabled', 'share_max_days']);
        });
    }
};
