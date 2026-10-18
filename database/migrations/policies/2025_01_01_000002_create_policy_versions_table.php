<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('policy_id')->index();
            $table->string('version_number');               // e.g. "1.0", "2.3"
            $table->text('change_summary')->nullable();
            $table->json('snapshot');                       // full policy state at version time
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->foreign('policy_id')->references('id')->on('policy')->onDelete('cascade');
            $table->unique(['policy_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_versions');
    }
};
