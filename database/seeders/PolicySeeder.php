<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PolicySeeder extends Seeder
{
    public function run(): void
    {
        DB::table('policy')->insert([
            [
                'id' => 15,
                'name' => 'Staff Travel and Loan Request Policy',
                'description' => 'To establish a centralized process for managing staff travel and loan requests, ensuring consistency, accountability, and efficiency.',
                'document' => '1751526498_policy_documet.pdf',
                'status' => 'active',
                'date' => 1753912800,
                'category_id' => 3,
                'refresh_month' => 1,
                'created_at' => '2025-07-03 09:08:18',
                'updated_at' => '2025-07-03 09:21:35',
            ],
            [
                'id' => 5,
                'name' => 'Nexus Travel - House Rules',
                'description' => 'The house rules apply to all employees of the Nexus Travel.',
                'document' => '1716547532_policy_documet.pdf',
                'status' => 'active',
                'date' => 1719180000,
                'category_id' => 3,
                'refresh_month' => 1,
                'created_at' => '2024-05-24 12:45:32',
                'updated_at' => '2024-05-27 17:48:09',
            ],
            [
                'id' => 6,
                'name' => 'Time and Attendance Policy',
                'description' => 'Time and attendance policy applies to all employees.',
                'document' => '1717011783_policy_documet.pdf',
                'status' => 'active',
                'date' => 1719525600,
                'category_id' => 3,
                'refresh_month' => 1,
                'created_at' => '2024-05-29 21:43:03',
                'updated_at' => '2024-05-29 21:43:03',
            ],
            // add others similarly...
        ]);
    }
}
