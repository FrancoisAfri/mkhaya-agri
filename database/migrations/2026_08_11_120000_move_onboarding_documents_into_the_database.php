<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Onboarding documents move into the database, like every other document in the platform.
 *
 * They were written to `Storage::disk('local')` with a `file_path`, which is the one place in the
 * codebase still doing that — Contract Management, Tender Pursuit and the rest all go through
 * `App\Core\User\Trait\FileStorage` and hold the bytes in the row. The difference matters here more
 * than most: these are ID copies, passports, proof of address and banking details, so a stray file
 * on a disk that is not in the backup set, not covered by row-level security and not removed by the
 * POPIA erasure flows is a real exposure rather than a tidiness question.
 *
 * `file_path` is kept rather than dropped. Rows written before this migration still point at a file
 * on disk, and dropping the column would strand them with no way to find what they referred to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('onboarding_documents', 'file_data')) {
                // Base64 of the file, as FileStorage returns it. `binary` would be smaller, but the
                // trait's read path expects base64 and disagreeing with it here would mean every
                // caller converting by hand.
                $table->longText('file_data')->nullable();
                $table->string('file_mime')->nullable();
                $table->string('file_type')->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_documents', function (Blueprint $table) {
            foreach (['file_data', 'file_mime', 'file_type', 'file_size'] as $column) {
                if (Schema::hasColumn('onboarding_documents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
