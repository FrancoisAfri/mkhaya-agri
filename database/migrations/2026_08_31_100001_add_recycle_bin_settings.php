<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How long deleted documents stay recoverable.
 *
 * A deletion nobody can undo is the one mistake in a repository that cannot be talked about
 * afterwards. Thirty days is the default because it survives a holiday: somebody deletes the wrong
 * folder before going away and is still able to put it back when they return.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('recycle_bin_days')->default(30);

            // Off by default. Permanent destruction on a timer is the same argument as automatic
            // disposal, and the answer is the same: somebody should be able to say who did it.
            $table->boolean('recycle_bin_auto_purge')->default(false);
        });

        Schema::table('documents', function (Blueprint $table) {
            // Why it went, captured at the moment of deletion — "cleaning up duplicates" a month
            // later is the difference between restoring it and leaving it.
            $table->string('delete_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documents', fn (Blueprint $t) => $t->dropColumn('delete_reason'));
        Schema::table('document_settings', fn (Blueprint $t) => $t->dropColumn(['recycle_bin_days', 'recycle_bin_auto_purge']));
    }
};
