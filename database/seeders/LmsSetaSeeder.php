<?php

namespace Database\Seeders;

use App\Modules\HR\LMS\Models\Qcto\LmsSeta;
use Illuminate\Database\Seeder;

class LmsSetaSeeder extends Seeder
{
    public function run(): void
    {
        $setas = [
            [
                'name'          => 'Agriculture Sector Education and Training Authority',
                'acronym'       => 'AgriSETA',
                'levy_code'     => '01',
                'website'       => 'www.agriseta.co.za',
                'contact_email' => 'info@agriseta.co.za',
            ],
            [
                'name'          => 'Banking Sector Education and Training Authority',
                'acronym'       => 'BANKSETA',
                'levy_code'     => '02',
                'website'       => 'www.bankseta.org.za',
                'contact_email' => 'info@bankseta.org.za',
            ],
            [
                'name'          => 'Culture, Arts, Tourism, Hospitality and Sport Sector Education and Training Authority',
                'acronym'       => 'CATHSSETA',
                'levy_code'     => '03',
                'website'       => 'www.cathsseta.org.za',
                'contact_email' => 'info@cathsseta.org.za',
            ],
            [
                'name'          => 'Construction Education and Training Authority',
                'acronym'       => 'CETA',
                'levy_code'     => '04',
                'website'       => 'www.ceta.org.za',
                'contact_email' => 'info@ceta.org.za',
            ],
            [
                'name'          => 'Chemical Industries Education and Training Authority',
                'acronym'       => 'CHIETA',
                'levy_code'     => '05',
                'website'       => 'www.chieta.org.za',
                'contact_email' => 'info@chieta.org.za',
            ],
            [
                'name'          => 'Education, Training and Development Practices Sector Education and Training Authority',
                'acronym'       => 'ETDP SETA',
                'levy_code'     => '06',
                'website'       => 'www.etdpseta.org.za',
                'contact_email' => 'info@etdpseta.org.za',
            ],
            [
                'name'          => 'Energy and Water Sector Education and Training Authority',
                'acronym'       => 'EWSETA',
                'levy_code'     => '07',
                'website'       => 'www.ewseta.org.za',
                'contact_email' => 'info@ewseta.org.za',
            ],
            [
                'name'          => 'Finance and Accounting Services Sector Education and Training Authority',
                'acronym'       => 'FASSET',
                'levy_code'     => '08',
                'website'       => 'www.fasset.org.za',
                'contact_email' => 'info@fasset.org.za',
            ],
            [
                'name'          => 'Food and Beverages Manufacturing Industry Sector Education and Training Authority',
                'acronym'       => 'FOODBEV SETA',
                'levy_code'     => '09',
                'website'       => 'www.foodbev.co.za',
                'contact_email' => 'info@foodbev.co.za',
            ],
            [
                'name'          => 'Health and Welfare Sector Education and Training Authority',
                'acronym'       => 'HWSETA',
                'levy_code'     => '10',
                'website'       => 'www.hwseta.org.za',
                'contact_email' => 'info@hwseta.org.za',
            ],
            [
                'name'          => 'Insurance Sector Education and Training Authority',
                'acronym'       => 'INSETA',
                'levy_code'     => '11',
                'website'       => 'www.inseta.org.za',
                'contact_email' => 'info@inseta.org.za',
            ],
            [
                'name'          => 'Local Government Sector Education and Training Authority',
                'acronym'       => 'LGSETA',
                'levy_code'     => '12',
                'website'       => 'www.lgseta.org.za',
                'contact_email' => 'info@lgseta.org.za',
            ],
            [
                'name'          => 'Manufacturing, Engineering and Related Services Sector Education and Training Authority',
                'acronym'       => 'MERSETA',
                'levy_code'     => '13',
                'website'       => 'www.merseta.org.za',
                'contact_email' => 'info@merseta.org.za',
            ],
            [
                'name'          => 'Media, Information and Communication Technologies Sector Education and Training Authority',
                'acronym'       => 'MICT SETA',
                'levy_code'     => '14',
                'website'       => 'www.mict.org.za',
                'contact_email' => 'info@mict.org.za',
            ],
            [
                'name'          => 'Mining Qualifications Authority',
                'acronym'       => 'MQA',
                'levy_code'     => '15',
                'website'       => 'www.mqa.org.za',
                'contact_email' => 'info@mqa.org.za',
            ],
            [
                'name'          => 'Public Service Sector Education and Training Authority',
                'acronym'       => 'PSETA',
                'levy_code'     => '16',
                'website'       => 'www.pseta.gov.za',
                'contact_email' => 'info@pseta.gov.za',
            ],
            [
                'name'          => 'Safety and Security Sector Education and Training Authority',
                'acronym'       => 'SASSETA',
                'levy_code'     => '17',
                'website'       => 'www.sasseta.org.za',
                'contact_email' => 'info@sasseta.org.za',
            ],
            [
                'name'          => 'Services Sector Education and Training Authority',
                'acronym'       => 'SERVICES SETA',
                'levy_code'     => '18',
                'website'       => 'www.serviceseta.org.za',
                'contact_email' => 'info@serviceseta.org.za',
            ],
            [
                'name'          => 'Transport Education and Training Authority',
                'acronym'       => 'TETA',
                'levy_code'     => '19',
                'website'       => 'www.teta.org.za',
                'contact_email' => 'info@teta.org.za',
            ],
            [
                'name'          => 'Wholesale and Retail Sector Education and Training Authority',
                'acronym'       => 'W&RSETA',
                'levy_code'     => '20',
                'website'       => 'www.wrseta.org.za',
                'contact_email' => 'info@wrseta.org.za',
            ],
            [
                'name'          => 'Fibre Processing and Manufacturing Sector Education and Training Authority',
                'acronym'       => 'FP&M SETA',
                'levy_code'     => '21',
                'website'       => 'www.fpmseta.org.za',
                'contact_email' => 'info@fpmseta.org.za',
            ],
        ];

        foreach ($setas as $data) {
            LmsSeta::updateOrCreate(
                ['acronym' => $data['acronym']],
                array_merge($data, ['is_active' => true])
            );
        }

        $this->command->info('Seeded ' . count($setas) . ' South African SETAs.');
    }
}
