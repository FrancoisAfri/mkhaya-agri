<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signer tokens are only generated when an envelope is *sent* (ESignService::send),
 * not when it is created as a draft. The original migration made `token` NOT NULL,
 * which broke draft creation. Make it nullable to match the intended workflow.
 * The unique index is retained — PostgreSQL permits multiple NULLs under a unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('esign_signers', function (Blueprint $table) {
            $table->string('token', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('esign_signers', function (Blueprint $table) {
            $table->string('token', 64)->nullable(false)->change();
        });
    }
};
