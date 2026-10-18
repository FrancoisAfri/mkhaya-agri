<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('hr_people', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('title', 255)->nullable();
            $table->string('first_name', 255)->nullable();
            $table->string('surname', 255)->nullable();
            $table->string('employee_number', 255)->nullable();
            $table->string('middle_name', 255)->nullable();
            $table->string('maiden_name', 255)->nullable();
            $table->string('aka', 255)->nullable();
            $table->string('initial', 255)->nullable();
            $table->integer('position')->nullable();
            $table->string('email', 255)->nullable()->unique();
            $table->string('cell_number', 255)->nullable();
            $table->string('phone_number', 255)->nullable();
            $table->string('id_number', 255)->nullable();
            $table->string('res_address', 255)->nullable();
            $table->string('res_suburb', 255)->nullable();
            $table->string('res_city', 255)->nullable();
            $table->integer('res_postal_code')->nullable();
            $table->integer('res_province_id')->nullable();
            $table->integer('res_country_id')->nullable();
            $table->unsignedBigInteger('date_of_birth')->nullable(); // Unix timestamp
            $table->string('passport_number', 255)->nullable();
            $table->string('drivers_licence_number', 255)->nullable();
            $table->string('drivers_licence_code', 255)->nullable();
            $table->string('proof_drive_permit', 100)->nullable();
            $table->unsignedBigInteger('proof_drive_permit_exp_date')->nullable();
            $table->unsignedBigInteger('drivers_licence_exp_date')->nullable();
            $table->smallInteger('gender')->nullable();
            $table->smallInteger('own_transport')->nullable();
            $table->integer('marital_status')->nullable();
            $table->integer('ethnicity')->nullable();
            $table->string('profile_pic', 255)->nullable();
            $table->smallInteger('status')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('division_level_1')->nullable();
            $table->integer('division_level_2')->nullable();
            $table->integer('division_level_3')->nullable();
            $table->integer('division_level_4')->nullable();
            $table->integer('division_level_5')->nullable();
            $table->integer('leave_profile')->nullable();
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->unsignedBigInteger('date_joined')->nullable();
            $table->unsignedBigInteger('date_left')->nullable();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->unsignedBigInteger('second_manager_id')->default(0);
            $table->uuid('uuid')->nullable();
            $table->string('known_as', 255)->nullable();
            $table->string('alternate_number', 255)->nullable();
            $table->string('next_of_kin', 255)->nullable();
            $table->string('next_of_kin_number', 255)->nullable();
            $table->string('next_of_kin_work_number', 255)->nullable();
            $table->string('complex_name', 255)->nullable();
            $table->string('unit_number', 255)->nullable();
            $table->string('income_tax_number', 255)->nullable();
            $table->string('tax_office', 255)->nullable();
            $table->string('account_type', 255)->nullable();
            $table->string('account_holder_name', 255)->nullable();
            $table->string('bank_name', 255)->nullable();
            $table->string('branch_name', 255)->nullable();
            $table->string('account_number', 255)->nullable();
            $table->string('package_components', 255)->nullable();
            $table->double('monthly_package')->nullable();
            $table->double('package_cash')->nullable();
            $table->double('package_travel')->nullable();
            $table->double('package_incentive')->nullable();
            $table->double('package_cell_phone')->nullable();
            $table->double('package_med_aid')->nullable();
            $table->string('med_start_date', 255)->nullable();
            $table->string('med_split', 255)->nullable();
            $table->string('med_plan_name', 255)->nullable();
            $table->string('med_dep_spouse', 255)->nullable();
            $table->string('med_dep_adult', 255)->nullable();
            $table->string('med_dep_kids', 255)->nullable();
            $table->double('med_amount')->nullable();
            $table->string('provident_start_date', 255)->nullable();
            $table->string('provident_name', 255)->nullable();
            $table->double('provident_amount')->nullable();
            $table->integer('disabled')->nullable();
            $table->string('nature_of_disability', 255)->nullable();
            $table->integer('citizenship')->nullable();
            $table->string('other', 255)->nullable();
            $table->integer('employment_type')->nullable();
            $table->integer('occupational_level')->nullable();
            $table->integer('job_function')->nullable();
            $table->string('start_time', 255)->nullable();
            $table->string('end_time', 255)->nullable();
            $table->string('provident_fund_product', 255)->nullable();
            $table->integer('can_drive')->nullable();

            // Indexes (from dump)
            $table->index('can_drive');
            $table->index('division_level_1');
            $table->index('division_level_2');
            $table->index('division_level_3');
            $table->index('division_level_4');
            $table->index('division_level_5');
            $table->index('end_time');
            $table->index('leave_profile');
            $table->index('manager_id');
            $table->index('position');
            $table->index('provident_fund_product');
            $table->index('role_id');
            $table->index('start_time');
            $table->index('user_id');
            $table->index('uuid');

            // Foreign key (adjust table name if needed)
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('hr_people');
    }
};
