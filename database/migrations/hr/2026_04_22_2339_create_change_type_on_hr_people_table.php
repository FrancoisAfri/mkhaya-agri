<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('hr_people', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->nullable()->change();

            $table->unsignedInteger('position')->nullable()->change();

            $table->bigInteger('date_of_birth')->nullable()->change();
            $table->bigInteger('proof_drive_permit_exp_date')->nullable()->change();
            $table->bigInteger('drivers_licence_exp_date')->nullable()->change();
            $table->bigInteger('date_joined')->nullable()->change();
            $table->bigInteger('date_left')->nullable()->change();

            $table->integer('division_level_1')->unsigned()->nullable()->change();
            $table->integer('division_level_2')->unsigned()->nullable()->change();
            $table->integer('division_level_3')->unsigned()->nullable()->change();
            $table->integer('division_level_4')->unsigned()->nullable()->change();
            $table->integer('division_level_5')->unsigned()->nullable()->change();

            $table->timestamp('created_at')->nullable(false)->change();
            $table->timestamp('updated_at')->nullable(false)->change();
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE hr_people ALTER COLUMN uuid TYPE uuid USING uuid::uuid;');
        }
    }

    public function down()
    {
        Schema::table('hr_people', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->integer('position')->nullable()->change();

            $table->unsignedBigInteger('date_of_birth')->nullable()->change();
            $table->unsignedBigInteger('proof_drive_permit_exp_date')->nullable()->change();
            $table->unsignedBigInteger('drivers_licence_exp_date')->nullable()->change();
            $table->unsignedBigInteger('date_joined')->nullable()->change();
            $table->unsignedBigInteger('date_left')->nullable()->change();

            $table->integer('division_level_1')->nullable()->change();
            $table->integer('division_level_2')->nullable()->change();
            $table->integer('division_level_3')->nullable()->change();
            $table->integer('division_level_4')->nullable()->change();
            $table->integer('division_level_5')->nullable()->change();

            $table->timestamp('created_at')->nullable()->change();
            $table->timestamp('updated_at')->nullable()->change();
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE hr_people ALTER COLUMN uuid TYPE VARCHAR(255);');
        }
    }
};
