<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LeaveTypesSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('leave_types')->insert([
            [
                'id'            => 1,
                'name'          => 'Annual',
                'status'        => 1,
                'description'   => 'Annual Leave Type',
                'created_at'    => '2022-10-08 21:05:35',
                'updated_at'    => '2022-10-08 21:05:35',
                'special_leave' => null,
                'doc_required'  => false,
                'notes_required'=> false,
            ],
            [
                'id'            => 2,
                'name'          => 'Family',
                'status'        => 1,
                'description'   => 'Family Leave Type',
                'created_at'    => '2022-10-08 21:05:35',
                'updated_at'    => '2025-01-18 21:48:26',
                'special_leave' => null,
                'doc_required'  => true,
                'notes_required'=> true,
            ],
            [
                'id'            => 3,
                'name'          => 'Maternity',
                'status'        => 1,
                'description'   => 'Maternity Leave Type',
                'created_at'    => '2022-10-08 21:05:35',
                'updated_at'    => '2022-10-08 21:05:35',
                'special_leave' => null,
                'doc_required'  => false,
                'notes_required'=> false,
            ],
            [
                'id'            => 4,
                'name'          => 'Other/Special',
                'status'        => 1,
                'description'   => 'Other/Special Leave Type',
                'created_at'    => '2022-10-08 21:05:35',
                'updated_at'    => '2022-10-08 21:05:35',
                'special_leave' => null,
                'doc_required'  => false,
                'notes_required'=> false,
            ],
            [
                'id'            => 5,
                'name'          => 'Sick',
                'status'        => 1,
                'description'   => 'Sick Leave Type',
                'created_at'    => '2022-10-08 21:05:35',
                'updated_at'    => '2025-01-18 21:44:20',
                'special_leave' => null,
                'doc_required'  => true,
                'notes_required'=> true,
            ],
            [
                'id'            => 6,
                'name'          => 'Study',
                'status'        => 1,
                'description'   => 'Study Leave Type',
                'created_at'    => '2022-10-08 21:05:35',
                'updated_at'    => '2025-10-03 12:38:40',
                'special_leave' => null,
                'doc_required'  => true,
                'notes_required'=> true,
            ],
            [
                'id'            => 7,
                'name'          => 'Unpaid',
                'status'        => 1,
                'description'   => 'Unpaid Leave Type',
                'created_at'    => '2022-10-08 21:05:35',
                'updated_at'    => '2022-10-08 21:05:35',
                'special_leave' => null,
                'doc_required'  => false,
                'notes_required'=> false,
            ],
            [
                'id'            => 8,
                'name'          => 'Long Service Leave',
                'status'        => 1,
                'description'   => 'Long Service Leave',
                'created_at'    => '2022-10-08 21:05:35',
                'updated_at'    => '2023-03-23 15:43:40',
                'special_leave' => null,
                'doc_required'  => false,
                'notes_required'=> false,
            ],
            [
                'id'            => 9,
                'name'          => 'Paternity',
                'status'        => 1,
                'description'   => 'Paternity Leave',
                'created_at'    => '2022-10-08 21:05:35',
                'updated_at'    => '2022-10-08 21:05:35',
                'special_leave' => null,
                'doc_required'  => false,
                'notes_required'=> false,
            ],
            [
                'id'            => 10,
                'name'          => 'Birthday Leave',
                'status'        => 1,
                'description'   => 'Birthday Leave',
                'created_at'    => '2022-12-12 18:02:11',
                'updated_at'    => '2023-03-23 15:43:54',
                'special_leave' => null,
                'doc_required'  => false,
                'notes_required'=> false,
            ],
        ]);

        DB::statement("SELECT setval('leave_types_id_seq', (SELECT MAX(id) FROM leave_types))");
    }
}
