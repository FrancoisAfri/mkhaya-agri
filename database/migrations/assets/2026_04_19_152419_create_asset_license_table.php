<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('asset_license', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->index();

            $table->string('name')->nullable();
            $table->string('details')->nullable();

            $table->string('serial')->nullable();

            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_cost', 8, 2)->nullable();

            $table->string('order_number'); // NOT NULL in dump

            $table->integer('total')->default(1);

            $table->text('notes')->nullable();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('asset_type_id')->nullable();

            $table->date('expiration_date')->nullable();

            $table->string('licence_image')->nullable();

            $table->smallInteger('status')->default(1);

            $table->string('licence_status')->nullable(); // allocated / un_allocated

            $table->timestamps();
            $table->softDeletes(); // deleted_at

            // Optional FKs (recommended)
            // $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            // $table->foreign('asset_type_id')->references('id')->on('asset_types')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_license');
    }
};
