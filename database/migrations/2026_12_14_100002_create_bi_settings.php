<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform-wide BI switch (App\Modules\BI\Support\BISettings), set by a platform administrator
 * on /bi/platform. One row; not company data, so no company_id and no row-level security.
 * Starts "included for every company".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bi_settings', function (Blueprint $t) {
            $t->id();
            $t->boolean('included')->default(true);
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });
        DB::table('bi_settings')->insert(['included' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_settings');
    }
};
