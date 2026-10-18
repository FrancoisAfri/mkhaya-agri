<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LeaveCustomSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('leave_customs')->insert([
            [
                'id' => 2,
                'hr_id' => 100,
                'number_of_days' => 20,
                'status' => 1,
                'created_at' => '2020-01-22 18:29:23',
                'updated_at' => '2020-01-22 18:29:23',
            ],
            [
                'id' => 3,
                'hr_id' => 18,
                'number_of_days' => 20,
                'status' => 1,
                'created_at' => '2020-01-22 18:29:40',
                'updated_at' => '2020-01-22 18:29:40',
            ],
            [
                'id' => 1,
                'hr_id' => 37,
                'number_of_days' => 8,
                'status' => 0,
                'created_at' => '2020-01-22 18:28:48',
                'updated_at' => '2025-09-18 14:11:56',
            ],
            [
                'id' => 4,
                'hr_id' => 573,
                'number_of_days' => 21,
                'status' => 1,
                'created_at' => '2025-10-07 15:23:05',
                'updated_at' => '2025-10-07 15:23:05',
            ],
        ]);
    }
}
