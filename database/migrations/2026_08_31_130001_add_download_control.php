<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Being allowed to read something is not being allowed to take a copy of it.
 *
 * Until now the two travelled together: anyone who could open a folder could download everything
 * in it. That is the wrong default for the documents people most want a repository for — a signed
 * contract, a board pack, a salary schedule — where the whole point is that it can be read and not
 * circulated.
 *
 * Three things are added, and they answer different questions. A folder or a document can be
 * *marked* as read-only, which is a property of the thing and applies to everybody. A folder grant
 * can allow or withhold downloading, which is a property of one grant. And a request can now be a
 * request to download rather than a request for access, so somebody refused a copy has somewhere
 * to go other than asking around.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('download_disabled')->default(false)->after('is_confidential');
        });

        Schema::table('document_folders', function (Blueprint $table) {
            $table->boolean('download_disabled')->default(false)->after('is_confidential');
        });

        Schema::table('document_folder_permissions', function (Blueprint $table) {
            /*
             * Defaulted to true so that every grant already saved keeps behaving exactly as it did
             * yesterday. A migration that silently withdrew downloading from everybody would be
             * indistinguishable, to the people it happened to, from the feature being broken.
             */
            $table->boolean('can_download')->default(true)->after('can_view');
        });

        Schema::table('document_access_requests', function (Blueprint $table) {
            $table->string('kind', 20)->default('access')->after('uuid');
        });

        Schema::table('document_settings', function (Blueprint $table) {
            // How long an approved download stays open. A copy approved once should not be an
            // approval that never expires.
            $table->unsignedSmallInteger('download_request_days')->default(7);
        });
    }

    public function down(): void
    {
        Schema::table('documents', fn (Blueprint $table) => $table->dropColumn('download_disabled'));
        Schema::table('document_folders', fn (Blueprint $table) => $table->dropColumn('download_disabled'));
        Schema::table('document_folder_permissions', fn (Blueprint $table) => $table->dropColumn('can_download'));
        Schema::table('document_access_requests', fn (Blueprint $table) => $table->dropColumn('kind'));
        Schema::table('document_settings', fn (Blueprint $table) => $table->dropColumn('download_request_days'));
    }
};
