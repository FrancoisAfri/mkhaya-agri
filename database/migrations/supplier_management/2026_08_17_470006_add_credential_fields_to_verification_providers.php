<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each provider needs to be connected, so the registry screen can render a form instead of a
 * blank JSON box.
 *
 * The catalogue now carries several named providers per capability — five bureaux, five screening
 * houses, five account-verification services — and they do not want the same credentials. Asking
 * every customer to guess which keys their provider expects was tolerable with one generic slot and
 * is not with thirty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_providers', function (Blueprint $t) {
            $t->json('credential_fields')->nullable()->after('capabilities');
        });
    }

    public function down(): void
    {
        Schema::table('verification_providers', function (Blueprint $t) {
            $t->dropColumn('credential_fields');
        });
    }
};
