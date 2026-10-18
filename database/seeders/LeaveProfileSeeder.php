<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LeaveProfileSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('leave_profile')->insert([
            [
                'id' => 1,
                'name' => 'Employee with no leave',
                'description' => 'Employee with no leave',
                'created_at' => '2022-10-08 21:05:35',
                'updated_at' => '2022-10-08 21:05:35',
            ],
            [
                'id' => 2,
                'name' => '5 Day Employee',
                'description' => '5 Day Employee leave',
                'created_at' => '2022-10-08 21:05:35',
                'updated_at' => '2022-10-08 21:05:35',
            ],
            [
                'id' => 3,
                'name' => '6 Day Employee',
                'description' => '6 Day Employee leave',
                'created_at' => '2022-10-08 21:05:35',
                'updated_at' => '2022-10-08 21:05:35',
            ],
            [
                'id' => 4,
                'name' => 'Shift Worker',
                'description' => 'Shift Worker Employee leave',
                'created_at' => '2022-10-08 21:05:35',
                'updated_at' => '2022-10-08 21:05:35',
            ],
        ]);
        DB::statement("SELECT setval('leave_profile_id_seq', (SELECT MAX(id) FROM leave_profile))");
    }
}
