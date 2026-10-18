<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM — custom objects (spec section 11, extensibility / build-your-own CRM
 * objects). A metadata-driven object type carries its own field schema; records
 * store values generically as JSON, so new objects need no migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_object_types', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('icon', 60)->default('box');
            $table->string('record_label')->nullable();   // which field labels a record (display name)
            $table->json('fields');                        // [{name,label,type,options,required}]
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('crm_object_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('object_type_id')->constrained('crm_object_types')->cascadeOnDelete();
            $table->json('data');                          // {field_name: value}
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('object_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_object_records');
        Schema::dropIfExists('crm_object_types');
    }
};
