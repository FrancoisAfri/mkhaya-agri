<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run migrations.
     */
    public function up(): void
    {
        Schema::create('company_identities', function (Blueprint $table) {
            $table->id();

            $table->string('company_name')->nullable();
            $table->string('full_company_name')->nullable();

            $table->string('header_name_bold')->nullable();
            $table->string('header_name_regular')->nullable();

            $table->string('header_acronym_bold')->nullable();
            $table->string('header_acronym_regular')->nullable();

            $table->string('company_logo')->nullable();

            $table->string('sys_theme_color')->nullable();

            $table->string('mailing_name')->nullable();
            $table->text('mailing_address')->nullable();

            $table->string('support_email')->nullable();
            $table->string('company_website')->nullable();

            $table->integer('password_expiring_month')->nullable();

            $table->string('system_background_image')->nullable();
            $table->string('login_background_image')->nullable();

            $table->string('brought_to_text')->nullable();
            $table->string('brought_to_text_image')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('company_user_pivots')) {
            Schema::table('company_user_pivots', function (Blueprint $table) {
                try {
                    $table->dropForeign(['company_id']);
                } catch (\Throwable $e) {
                    // Ignore if constraint already removed
                }
            });
        }

        Schema::dropIfExists('company_identities');
    }
};
