<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE hr_people ALTER COLUMN profile_pic TYPE json USING profile_pic::json');
            DB::statement('ALTER TABLE hr_people ALTER COLUMN proof_drive_permit TYPE json USING to_json(proof_drive_permit)');
        }

        Schema::table('hr_people', function (Blueprint $table) {
            $table->string('res_postal_code')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Cast back to text — varchar(255) is too narrow for serialised JSON values
            DB::statement('ALTER TABLE hr_people ALTER COLUMN profile_pic TYPE text USING profile_pic::text');
            DB::statement('ALTER TABLE hr_people ALTER COLUMN proof_drive_permit TYPE text USING proof_drive_permit::text');

            DB::statement("
                UPDATE hr_people
                SET res_postal_code = NULL
                WHERE res_postal_code !~ '^[0-9]+$'
            ");

            DB::statement("
                ALTER TABLE hr_people
                ALTER COLUMN res_postal_code TYPE INTEGER
                USING res_postal_code::INTEGER
            ");
        } else {
            Schema::table('hr_people', function (Blueprint $table) {
                $table->integer('res_postal_code')->nullable()->change();
            });
        }
    }
};
