<?php

namespace Database\Seeders;

use App\Modules\Admin\Setup\Models\CompanyIdentity;
use Illuminate\Database\Seeder;

class CompanyIdentitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        CompanyIdentity::updateOrCreate(
            ['id' => 1],
            [
                'company_name' => 'Mkhaya Hub',
                'full_company_name' => 'Mkhaya Hub Technologies',
                'header_name_bold' => 'MKHAYA',
                'header_name_regular' => 'Hub',
                'header_acronym_bold' => 'MH',
                'header_acronym_regular' => 'Tech',
                'company_logo' => null,

                'sys_theme_color' => '#020d31',

                'mailing_name' => 'Mkhaya Hub',
                'mailing_address' => 'Douala, Cameroun',

                'support_email' => 'support@mkhaya.com',
                'company_website' => 'https://mkhaya.com',

                'password_expiring_month' => 3,

                'system_background_image' => null,
                'login_background_image' => null,

                'brought_to_text' => 'Powered by Mkhaya Hub',
                'brought_to_text_image' => null,
            ]
        );
    }
}
