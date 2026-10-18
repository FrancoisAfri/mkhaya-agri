<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
        // Remove any rows without a token before restoring NOT NULL
        \Illuminate\Support\Facades\DB::statement(
            'DELETE FROM esign_signers WHERE token IS NULL'
        );

        Schema::table('esign_signers', function (Blueprint $table) {
            $table->string('token', 64)->nullable(false)->change();
        });
    }
};
