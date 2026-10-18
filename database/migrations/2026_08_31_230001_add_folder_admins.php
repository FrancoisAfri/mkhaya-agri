<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somebody who administers one folder without administering the repository.
 *
 * Until now there were two states: you could manage every folder in the company, or none. That is
 * the wrong shape for how document libraries are actually run — the Finance manager should decide
 * who sees the Finance folder, and giving them that meant giving them Human Resources and Legal
 * too, so nobody gave it to them and every access request went to whoever holds the module
 * permission. That person then approves things they have no basis to judge.
 *
 * A folder admin decides who else may use their folder, and nothing beyond it. Deliberately not
 * granted by any of the defaults: a company folder is readable by everybody the module lets in, and
 * if that default extended to administering it then everybody would be an administrator of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_folder_permissions', function (Blueprint $table) {
            $table->boolean('can_manage')->default(false)->after('can_delete');
        });
    }

    public function down(): void
    {
        Schema::table('document_folder_permissions', fn (Blueprint $table) => $table->dropColumn('can_manage'));
    }
};
