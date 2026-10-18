<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateEmployeeDocumentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
Schema::create('employee_documents', function (Blueprint $table) {
    $table->bigIncrements('id');

    $table->unsignedInteger('category_id')->nullable()->index();
    $table->unsignedInteger('division_level_2')->nullable()->index();
    $table->unsignedInteger('division_level_1')->nullable()->index();
    $table->unsignedInteger('hr_person_id')->nullable()->index();
    $table->unsignedInteger('hr_change_id')->nullable()->index();
    $table->unsignedInteger('qualification_type_id')->nullable()->index();

    $table->unsignedBigInteger('doc_type_id')->nullable();

    $table->string('doc_description')->nullable();

    $table->bigInteger('date_from')->nullable()->index();
    $table->bigInteger('expirydate')->nullable()->index();

    $table->json('supporting_docs')->nullable();

    $table->foreign('doc_type_id')
          ->references('id')
          ->on('doc_type')
          ->nullOnDelete();
              $table->foreign('hr_change_id')
          ->references('id')
          ->on('hr_people_changes')
          ->nullOnDelete();

    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('employee_documents');
    }
}
