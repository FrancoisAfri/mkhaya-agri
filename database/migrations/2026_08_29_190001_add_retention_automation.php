<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Letting a retention schedule act on its own.
 *
 * Archiving and deleting are treated as two different kinds of decision here, because they are.
 *
 * Archiving is reversible and nothing is lost, so a schedule may do it unattended: a document that
 * has passed its useful life stops cluttering the library and can be restored by anybody who needs
 * it back. That is housekeeping.
 *
 * Deleting is not housekeeping, and the automatic version of it is switched off twice — once for
 * the whole company and again per category — because "the cron deleted it" is not an answer anybody
 * wants to give. Even switched on it stops at the recoverable delete: permanent destruction stays
 * where it was, behind a person with the purge permission, since nothing automatic should be able
 * to make a document unrecoverable.
 *
 * And a grace period sits between the approval and the act, so somebody who approves a disposal by
 * mistake on a Friday has until the following week to notice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_categories', function (Blueprint $table) {
            /*
             * When to archive, counted from the same basis as the retention period.
             *
             * Separate from retention_years because they answer different questions: "when does this
             * stop being day-to-day" and "when does it stop being worth keeping at all". A policy
             * superseded last year is archived long before it may be destroyed.
             */
            $table->unsignedSmallInteger('archive_after_years')->nullable();

            // Do it without asking. Safe because archiving is reversible and the document stays
            // searchable — it leaves the default listing, it does not leave the repository.
            $table->boolean('auto_archive')->default(false);

            /*
             * Delete without asking, once a disposal has been approved and the grace period has run.
             *
             * Off by default and meaningless unless the company-wide switch is also on. Approval
             * remains a human act; this only carries out what a person already agreed to.
             */
            $table->boolean('auto_delete')->default(false);
        });

        Schema::table('document_settings', function (Blueprint $table) {
            // The company-wide half of the double switch. Without this no category's auto_delete
            // does anything, so turning it on is a decision somebody makes once, deliberately.
            $table->boolean('auto_delete_enabled')->default(false);

            // Days between approving a disposal and the sweep acting on it.
            $table->unsignedSmallInteger('disposal_grace_days')->default(7);
        });

        Schema::table('documents', function (Blueprint $table) {
            // When the schedule archived it, as distinct from archived_at, which is also set when a
            // person archives something by hand. Knowing which of the two happened matters when
            // somebody asks why a document left their listing.
            $table->timestamp('auto_archived_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('auto_archived_at');
        });

        Schema::table('document_settings', function (Blueprint $table) {
            $table->dropColumn(['auto_delete_enabled', 'disposal_grace_days']);
        });

        Schema::table('document_categories', function (Blueprint $table) {
            $table->dropColumn(['archive_after_years', 'auto_archive', 'auto_delete']);
        });
    }
};
