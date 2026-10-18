<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things a repository needs once documents start leaving it by other means.
 *
 * A watermark naming the person looking at it. Read-only stops the download; it does nothing about
 * a photograph of the screen, which is how sensitive documents actually leave — and the only
 * defence against that is making the copy identify whoever was looking at it. Stamped on the
 * preview rather than on the download: somebody who may download has the real thing, and corrupting
 * their legitimate copy with a stamp would be a different feature and a worse one.
 *
 * And superseding. A policy replaced by next year's policy is not a new version of it — both exist,
 * both are referred to, and the old one has to stay readable while saying plainly that it is not the
 * one in force. Without this the only options were leaving it looking current or archiving it, and
 * archiving hides the document somebody was sent a link to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedBigInteger('superseded_by_id')->nullable()->after('download_disabled');
            $table->timestamp('superseded_at')->nullable()->after('superseded_by_id');
        });

        Schema::table('document_settings', function (Blueprint $table) {
            /*
             * Off by default. Stamping every preview is real work per view, and most documents in
             * most companies do not need it — switching it on for everything would make the
             * repository feel slow for the sake of documents nobody would photograph.
             */
            $table->boolean('watermark_previews')->default(false);
            // On by default, and separately: the confidential ones are exactly the ones worth
            // stamping, and a company that never opens the settings should still get that.
            $table->boolean('watermark_confidential_previews')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('documents', fn (Blueprint $table) => $table->dropColumn(['superseded_by_id', 'superseded_at']));
        Schema::table('document_settings', fn (Blueprint $table) => $table->dropColumn(['watermark_previews', 'watermark_confidential_previews']));
    }
};
