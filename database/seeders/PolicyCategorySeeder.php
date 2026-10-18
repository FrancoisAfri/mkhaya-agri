<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PolicyCategorySeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $categories = [
            ['name' => 'HR', 'description' => 'Human resources policies', 'status' => 1],
            ['name' => 'Finance', 'description' => 'Finance and accounting policies', 'status' => 1],
            ['name' => 'IT', 'description' => 'Information technology and security policies', 'status' => 1],
            ['name' => 'Fleet', 'description' => 'Fleet and transport policies', 'status' => 1],
            ['name' => 'Operations', 'description' => 'Operational and process policies', 'status' => 1],
            ['name' => 'Compliance', 'description' => 'Legal and regulatory compliance policies', 'status' => 1],
        ];

        foreach ($categories as $category) {
            DB::table('policy_category')->updateOrInsert(
                ['name' => $category['name']],
                [
                    'description' => $category['description'],
                    'status' => $category['status'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }
}
