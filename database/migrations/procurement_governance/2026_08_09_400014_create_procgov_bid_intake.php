<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Electronic bid intake: bidders submit their own bids, sealed until the tender closes (§1.1, §7).
 *
 * Until now a bid reached this system by a procurement officer typing it in. Everything downstream
 * is scrupulous — hash-chained audit, frozen criteria, conflict-of-interest gates — and all of it
 * rests on a number somebody keyed in from paper. The ledger can prove nobody altered the record
 * after capture. It cannot prove the captured figure is what the bidder sent, and it cannot prove
 * nobody read a competitor's price before the closing bell. That is the gap these tables close.
 *
 * Three properties are worth stating plainly, because two of them are commonly overclaimed.
 *
 * **The submission time is the server's.** `submitted_at` is stamped here, and lateness is derived
 * from it against the tender's closing time. A bidder cannot assert when they submitted, and an
 * officer cannot decide after the fact that a late bid arrived on time.
 *
 * **The payload is encrypted at rest under a per-tender key**, so a casual read of the database
 * shows ciphertext rather than every competitor's price. The key is itself encrypted under the
 * application key.
 *
 * **This is procedural sealing, not threshold cryptography.** Someone holding both the database and
 * APP_KEY could decrypt before opening. Closing that hole properly needs the key to live outside
 * the application — a KMS or HSM, or keys held by the bidders themselves — which is a deployment
 * decision, not a schema one. What this design does guarantee is that early access is *detectable*:
 * opening is a recorded ceremony requiring two named officers, the payload carries a digest so
 * tampering is provable, and the audit ledger is append-only. Tamper-evident is an honest claim
 * here; tamper-proof would not be.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * The per-tender envelope key, and the record of the ceremony that opened it.
         *
         * One row per tender. Opening is a property of the tender rather than of each bid, because
         * bids must all be opened at one moment: opening them one at a time would let whoever holds
         * the key read the first price and decide whether to continue.
         */
        Schema::create('procgov_tender_seals', function (Blueprint $t) {
            $t->id();
            $t->uuid()->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('review_id')->constrained('procgov_tender_reviews')->cascadeOnDelete();

            // The envelope key, encrypted under the application key.
            $t->text('key_ciphertext');

            // The ceremony. Two distinct people, because a single custodian is not a ceremony.
            $t->timestamp('opened_at')->nullable();
            $t->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('witnessed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedInteger('submissions_opened')->default(0);

            $t->timestamps();

            $t->unique(['company_id', 'review_id'], 'procgov_seal_unique');
        });

        /*
         * An invitation is the credential. Bidders are not platform users and must not be: creating
         * an account for every company that ever bids would make the supplier list a directory of
         * logins, and would put a password reset between a bidder and a closing deadline.
         */
        Schema::create('procgov_bid_invitations', function (Blueprint $t) {
            $t->id();
            $t->uuid()->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('review_id')->constrained('procgov_tender_reviews')->cascadeOnDelete();

            // Long and random. This is the whole credential, so it is indexed unique and never
            // reused across tenders — one token opens one tender for one bidder.
            $t->string('token', 64)->unique();

            $t->foreignId('supplier_id')->nullable();
            $t->string('supplier_name', 190);
            $t->string('contact_email', 190)->nullable();
            $t->string('contact_person', 190)->nullable();

            // INVITED → SUBMITTED → WITHDRAWN
            $t->string('status', 20)->default('INVITED');

            $t->timestamp('invited_at')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'review_id', 'status'], 'procgov_invite_lookup');
        });

        Schema::create('procgov_bid_submissions', function (Blueprint $t) {
            $t->id();
            $t->uuid()->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('review_id')->constrained('procgov_tender_reviews')->cascadeOnDelete();
            $t->foreignId('invitation_id')->constrained('procgov_bid_invitations')->cascadeOnDelete();

            // The sealed bid. Ciphertext until the opening ceremony.
            $t->text('sealed_payload');

            /*
             * SHA-256 of the plaintext as submitted.
             *
             * Held outside the ciphertext so it cannot be rewritten by whoever could rewrite the
             * ciphertext. On opening the decrypted payload is re-hashed and compared: a mismatch
             * means the stored bid is not the bid that was submitted, and the opening refuses rather
             * than quietly scoring an altered figure.
             */
            $t->char('payload_digest', 64);

            // Stamped by the server, never accepted from the client.
            $t->timestamp('submitted_at');
            $t->string('received_ip', 45)->nullable();
            $t->boolean('is_late')->default(false);

            $t->timestamp('opened_at')->nullable();
            $t->foreignId('bidder_id')->nullable();

            $t->timestamps();

            // One submission per invitation. A bidder replacing a bid withdraws and resubmits, which
            // leaves both acts in the ledger rather than silently overwriting a sealed price.
            $t->unique(['company_id', 'invitation_id'], 'procgov_submission_unique');
            $t->index(['company_id', 'review_id'], 'procgov_submission_lookup');
        });

        Schema::create('procgov_bid_submission_documents', function (Blueprint $t) {
            $t->id();
            $t->uuid()->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('submission_id')->constrained('procgov_bid_submissions')->cascadeOnDelete();

            $t->string('original_name', 255);
            $t->string('mime_type', 120)->nullable();
            $t->unsignedBigInteger('size_bytes')->default(0);

            // Path on a private disk. The bytes there are encrypted under the tender's envelope key,
            // because a bid document routinely restates the price the seal is protecting.
            $t->string('stored_path', 500);
            $t->char('content_digest', 64);

            $t->timestamps();

            $t->index(['company_id', 'submission_id'], 'procgov_submission_doc_lookup');
        });

        $this->applyTenantRls([
            'procgov_tender_seals',
            'procgov_bid_invitations',
            'procgov_bid_submissions',
            'procgov_bid_submission_documents',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('procgov_bid_submission_documents');
        Schema::dropIfExists('procgov_bid_submissions');
        Schema::dropIfExists('procgov_bid_invitations');
        Schema::dropIfExists('procgov_tender_seals');
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
