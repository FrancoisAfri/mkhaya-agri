<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where signatures go on the page, and the link between a document and its signing.
 *
 * The signing engine already exists — envelopes, signing order, one-time passcodes, an audit trail
 * and a completion certificate. What it had no way to express is *where* on the page each person
 * signs, which is the difference between a signing request and an instruction.
 *
 * Coordinates are stored as fractions of the page rather than points. A PDF rendered at a different
 * DPI, or a page in a different size, moves every absolute coordinate; a fraction of the way across
 * the page is the same place on any rendering of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esign_fields', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('envelope_id');

            // Whose field it is. Null is a field nobody has to fill — a date stamped automatically.
            $table->unsignedBigInteger('signer_id')->nullable();

            // signature | initials | date | text
            $table->string('type', 20);

            $table->unsignedInteger('page')->default(1);

            /*
             * Fractions of the page, 0–1 from the top-left.
             *
             * Absolute points would be wrong the moment the same document is rendered at another
             * size, and a signature block half off the page is the kind of thing nobody notices
             * until a customer receives it.
             */
            $table->decimal('x', 6, 5)->default(0);
            $table->decimal('y', 6, 5)->default(0);
            $table->decimal('width', 6, 5)->default(0.2);
            $table->decimal('height', 6, 5)->default(0.05);

            $table->string('label')->nullable();
            $table->boolean('is_required')->default(true);

            // What was actually entered. A signature's image lives on the signer, since one person
            // signs once however many places their mark appears.
            $table->text('value')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'envelope_id', 'page']);
            $table->index(['envelope_id', 'signer_id']);
        });

        DB::statement("ALTER TABLE esign_fields ALTER COLUMN company_id SET DEFAULT NULLIF(current_setting('app.company_id', true), '')::bigint");
        DB::statement('ALTER TABLE esign_fields ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE esign_fields FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY esign_fields_tenant ON esign_fields USING (
            NULLIF(current_setting('app.company_id', true), '') IS NULL
            OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint
        )");

        Schema::table('documents', function (Blueprint $table) {
            // The signing in progress, if any. One at a time: two open envelopes for the same
            // document would each produce a signed copy, and nothing would say which is the
            // executed one.
            $table->unsignedBigInteger('signature_envelope_id')->nullable();
            $table->string('signature_status', 30)->nullable();

            $table->index(['company_id', 'signature_status']);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['signature_envelope_id', 'signature_status']);
        });

        Schema::dropIfExists('esign_fields');
    }
};
