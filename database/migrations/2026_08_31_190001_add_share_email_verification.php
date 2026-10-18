<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proving that the person opening a link is the person it was sent to.
 *
 * A share can already carry a password, and a password does less than it looks like it does: it
 * travels in the same email as the link, so anybody who has the email has both. It defends against
 * a link pasted into a chat window and against nothing else — and a forwarded email is the ordinary
 * way a shared document reaches somebody it was never meant for.
 *
 * A code sent to the recorded address is a different claim. It proves control of that mailbox at
 * the moment of opening, which is the closest a link can get to knowing who is on the other end.
 *
 * The code is hashed like any other secret. A one-time code sitting in plaintext in a table is a
 * password with a shorter life, and the table it sits in is the one an attacker would already be
 * reading if they could read it at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_shares', function (Blueprint $table) {
            $table->boolean('require_email_code')->default(false)->after('password_hash');
            $table->string('code_hash', 255)->nullable()->after('require_email_code');
            $table->timestamp('code_expires_at')->nullable()->after('code_hash');
            $table->timestamp('code_sent_at')->nullable()->after('code_expires_at');
            // Counted so a code can be guessed a few times and no more. Six digits is a million
            // possibilities, which is plenty against a person and nothing at all against a script.
            $table->unsignedSmallInteger('code_attempts')->default(0)->after('code_sent_at');
            $table->timestamp('verified_at')->nullable()->after('code_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('document_shares', function (Blueprint $table) {
            $table->dropColumn([
                'require_email_code', 'code_hash', 'code_expires_at',
                'code_sent_at', 'code_attempts', 'verified_at',
            ]);
        });
    }
};
