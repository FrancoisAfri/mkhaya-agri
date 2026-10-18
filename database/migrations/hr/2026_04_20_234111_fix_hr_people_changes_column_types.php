<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {


        // 1. Column type changes — PostgreSQL-specific syntax; skip on other drivers (e.g. SQLite in tests)
        if (DB::connection()->getDriverName() === 'pgsql') {
            if (Schema::hasColumn('hr_people_changes', 'disabled')) {
                DB::statement('ALTER TABLE hr_people_changes ALTER COLUMN disabled DROP DEFAULT');
                DB::statement('ALTER TABLE hr_people_changes ALTER COLUMN disabled TYPE boolean USING (CASE WHEN disabled::text IN (\'1\',\'true\',\'t\') THEN true ELSE false END)');
                DB::statement('ALTER TABLE hr_people_changes ALTER COLUMN disabled SET DEFAULT false');
            }

            if (Schema::hasColumn('hr_people_changes', 'med_split')) {
                DB::statement('ALTER TABLE hr_people_changes ALTER COLUMN med_split DROP DEFAULT');
                DB::statement('ALTER TABLE hr_people_changes ALTER COLUMN med_split TYPE boolean USING (CASE WHEN med_split = \'1\' OR med_split = \'true\' THEN true ELSE false END)');
                DB::statement('ALTER TABLE hr_people_changes ALTER COLUMN med_split SET DEFAULT false');
            }

            if (Schema::hasColumn('hr_people_changes', 'job_function')) {
                DB::statement('ALTER TABLE hr_people_changes ALTER COLUMN job_function TYPE varchar(100) USING (job_function::varchar)');
            }

            if (Schema::hasColumn('hr_people_changes', 'occupational_level')) {
                DB::statement('ALTER TABLE hr_people_changes ALTER COLUMN occupational_level TYPE varchar(100) USING (occupational_level::varchar)');
            }

            if (Schema::hasColumn('hr_people_changes', 'employment_type')) {
                DB::statement('ALTER TABLE hr_people_changes ALTER COLUMN employment_type TYPE varchar(50) USING (employment_type::varchar)');
            }

            if (Schema::hasColumn('hr_people_changes', 'nature_of_disability')) {
                DB::statement('ALTER TABLE hr_people_changes ALTER COLUMN nature_of_disability TYPE text USING (nature_of_disability::text)');
            }

            if (Schema::hasColumn('hr_people_changes', 'citizenship')) {
                DB::statement('ALTER TABLE hr_people_changes ALTER COLUMN citizenship TYPE varchar(100) USING (citizenship::varchar)');
            }

            if (Schema::hasColumn('hr_people_changes', 'leave_profile')) {
                DB::statement('ALTER TABLE hr_people_changes ALTER COLUMN leave_profile TYPE varchar(100) USING (leave_profile::varchar)');
            }

            if (Schema::hasColumn('hr_people_changes', 'med_dep_spouse')) {
                DB::statement('ALTER TABLE hr_people_changes ALTER COLUMN med_dep_spouse TYPE integer USING (med_dep_spouse::integer)');
            }

            if (Schema::hasColumn('hr_people_changes', 'med_dep_adult')) {
                DB::statement('ALTER TABLE hr_people_changes ALTER COLUMN med_dep_adult TYPE integer USING (med_dep_adult::integer)');
            }

            if (Schema::hasColumn('hr_people_changes', 'med_dep_kids')) {
                DB::statement('ALTER TABLE hr_people_changes ALTER COLUMN med_dep_kids TYPE integer USING (med_dep_kids::integer)');
            }

            if (Schema::hasColumn('hr_people_changes', 'known_as')) {
                DB::statement('ALTER TABLE hr_people_changes ALTER COLUMN known_as TYPE varchar(100) USING (known_as::varchar)');
            }
        }


        Schema::table('hr_people_changes', function (Blueprint $table) {
            if (!Schema::hasColumn('hr_people_changes', 'position_id')) {
                $table->unsignedBigInteger('position_id')->nullable()->after('position');
            }

            if (!Schema::hasColumn('hr_people_changes', 'company_unit_ids')) {
                $table->text('company_unit_ids')->nullable()->after('position_id');
            }

            if (!Schema::hasColumn('hr_people_changes', 'user_changes')) {
                $table->text('user_changes')->nullable()->after('company_unit_ids');
            }

            if (!Schema::hasColumn('hr_people_changes', 'other')) {
                $table->text('other')->nullable();
            }

            if (!Schema::hasColumn('hr_people_changes', 'provident_amount')) {
                $table->decimal('provident_amount', 15, 2)->nullable();
            }

            if (!Schema::hasColumn('hr_people_changes', 'provident_name')) {
                $table->string('provident_name')->nullable();
            }

            if (!Schema::hasColumn('hr_people_changes', 'med_amount')) {
                $table->decimal('med_amount', 15, 2)->nullable();
            }

            if (!Schema::hasColumn('hr_people_changes', 'package_med_aid')) {
                $table->decimal('package_med_aid', 15, 2)->nullable();
            }

            if (!Schema::hasColumn('hr_people_changes', 'package_cell_phone')) {
                $table->decimal('package_cell_phone', 15, 2)->nullable();
            }

            if (!Schema::hasColumn('hr_people_changes', 'package_incentive')) {
                $table->decimal('package_incentive', 15, 2)->nullable();
            }

            if (!Schema::hasColumn('hr_people_changes', 'package_travel')) {
                $table->decimal('package_travel', 15, 2)->nullable();
            }

            if (!Schema::hasColumn('hr_people_changes', 'monthly_package')) {
                $table->decimal('monthly_package', 15, 2)->nullable();
            }

            if (!Schema::hasColumn('hr_people_changes', 'package_cash')) {
                $table->decimal('package_cash', 15, 2)->nullable();
            }

            if (!Schema::hasColumn('hr_people_changes', 'package_components')) {
                $table->json('package_components')->nullable();
            }

            if (!Schema::hasColumn('hr_people_changes', 'second_manager_id')) {
                $table->unsignedInteger('second_manager_id')->nullable();
            }

            if (!Schema::hasColumn('hr_people_changes', 'role_id')) {
                $table->unsignedInteger('role_id')->nullable();
            }

            if (!Schema::hasColumn('hr_people_changes', 'res_address')) {
                $table->string('res_address')->nullable();
            }

            if (!Schema::hasColumn('hr_people_changes', 'res_suburb')) {
                $table->string('res_suburb')->nullable();
            }

            if (!Schema::hasColumn('hr_people_changes', 'res_postal_code')) {
                $table->string('res_postal_code', 20)->nullable();
            }

            if (!Schema::hasColumn('hr_people_changes', 'res_province_id')) {
                $table->integer('res_province_id')->nullable();
            }

            if (!Schema::hasColumn('hr_people_changes', 'res_country_id')) {
                $table->integer('res_country_id')->nullable();
            }
        });



        Schema::table('hr_people_changes', function (Blueprint $table) {
            $columnsToCheck = [
                'provident_fund_product',
                'start_time',
                'end_time',
            ];

            foreach ($columnsToCheck as $column) {
                if (Schema::hasColumn('hr_people_changes', $column)) {

                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hr_people_changes', function (Blueprint $table) {
            $addedColumns = [
                'position_id',
                'company_unit_ids',
                'user_changes',
                'other',
                'provident_amount',
                'provident_name',
                'med_amount',
                'package_med_aid',
                'package_cell_phone',
                'package_incentive',
                'package_travel',
                'monthly_package',
                'package_cash',
                'package_components',
                'second_manager_id',
                'role_id',
                'res_address',
                'res_suburb',
                'res_postal_code',
                'res_province_id',
                'res_country_id',
            ];

            foreach ($addedColumns as $column) {
                if (Schema::hasColumn('hr_people_changes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

    }
};
