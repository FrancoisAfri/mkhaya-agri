<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_people', function (Blueprint $table) {
            DB::statement('ALTER TABLE hr_people ALTER COLUMN profile_pic TYPE jsonb USING profile_pic::jsonb');
        });

        Schema::table('hr_people_changes', function (Blueprint $table) {
            DB::statement('ALTER TABLE hr_people_changes ALTER COLUMN profile_pic TYPE jsonb USING profile_pic::jsonb');
        });
    }

    public function down(): void
    {
        // Cast back to text — varchar(255) is too narrow for serialised JSON values
        DB::statement('ALTER TABLE hr_people ALTER COLUMN profile_pic TYPE text USING profile_pic::text');
        DB::statement('ALTER TABLE hr_people_changes ALTER COLUMN profile_pic TYPE text USING profile_pic::text');
    }
};
