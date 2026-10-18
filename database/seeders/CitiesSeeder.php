<?php

namespace Database\Seeders;

use App\Core\User\Models\City;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds a curated set of cities linked to countries — comprehensive for South
 * Africa (the primary market) plus the major cities of countries businesses
 * commonly trade with. Idempotent: re-running only adds what's missing.
 */
class CitiesSeeder extends Seeder
{
    public function run(): void
    {
        // Keep the canonical (lowest) id per country name — the countries table
        // can contain duplicate names, and pluck() would otherwise keep the last.
        $countries = [];
        foreach (DB::table('countries')->orderBy('id')->get(['id', 'name']) as $row) {
            $countries[$row->name] ??= $row->id;
        }

        foreach ($this->cities() as $countryName => $cities) {
            $countryId = $countries[$countryName] ?? null;
            if (! $countryId) {
                continue;
            }
            foreach ($cities as $city) {
                City::firstOrCreate(['country_id' => $countryId, 'name' => $city]);
            }
        }
    }

    /** @return array<string, array<int, string>> country name => city names */
    private function cities(): array
    {
        return [
            'South Africa' => [
                'Johannesburg', 'Cape Town', 'Durban', 'Pretoria', 'Gqeberha (Port Elizabeth)',
                'Bloemfontein', 'East London', 'Pietermaritzburg', 'Polokwane', 'Mbombela (Nelspruit)',
                'Kimberley', 'Rustenburg', 'George', 'Soweto', 'Centurion', 'Sandton', 'Midrand',
                'Roodepoort', 'Benoni', 'Boksburg', 'Vereeniging', 'Vanderbijlpark', 'Welkom',
                'eMalahleni (Witbank)', 'Newcastle', 'Mthatha', 'Stellenbosch', 'Paarl', 'Upington',
                'Richards Bay', 'Klerksdorp', 'Potchefstroom', 'Krugersdorp', 'Springs', 'Tembisa',
                'Mamelodi', 'Randburg', 'Kempton Park', 'Germiston', 'Tzaneen', 'Thohoyandou',
            ],
            'Zimbabwe'      => ['Harare', 'Bulawayo', 'Chitungwiza', 'Mutare', 'Gweru'],
            'Botswana'      => ['Gaborone', 'Francistown', 'Molepolole', 'Maun'],
            'Namibia'       => ['Windhoek', 'Walvis Bay', 'Swakopmund', 'Oshakati'],
            'Mozambique'    => ['Maputo', 'Matola', 'Beira', 'Nampula'],
            'Zambia'        => ['Lusaka', 'Kitwe', 'Ndola', 'Kabwe'],
            'Eswatini'      => ['Mbabane', 'Manzini'],
            'Lesotho'       => ['Maseru'],
            'Kenya'         => ['Nairobi', 'Mombasa', 'Kisumu', 'Nakuru'],
            'Nigeria'       => ['Lagos', 'Abuja', 'Kano', 'Ibadan', 'Port Harcourt'],
            'Ghana'         => ['Accra', 'Kumasi', 'Tamale'],
            'Egypt'         => ['Cairo', 'Alexandria', 'Giza'],
            'United Kingdom' => ['London', 'Manchester', 'Birmingham', 'Leeds', 'Glasgow', 'Edinburgh'],
            'United States' => ['New York', 'Los Angeles', 'Chicago', 'Houston', 'Phoenix', 'San Francisco', 'Atlanta', 'Boston', 'Seattle', 'Miami'],
            'Canada'        => ['Toronto', 'Vancouver', 'Montreal', 'Calgary', 'Ottawa'],
            'Australia'     => ['Sydney', 'Melbourne', 'Brisbane', 'Perth', 'Adelaide'],
            'India'         => ['Mumbai', 'Delhi', 'Bengaluru', 'Hyderabad', 'Chennai', 'Pune', 'Kolkata'],
            'United Arab Emirates' => ['Dubai', 'Abu Dhabi', 'Sharjah'],
            'Germany'       => ['Berlin', 'Munich', 'Frankfurt', 'Hamburg', 'Cologne'],
            'France'        => ['Paris', 'Marseille', 'Lyon', 'Toulouse'],
            'Netherlands'   => ['Amsterdam', 'Rotterdam', 'The Hague', 'Utrecht'],
            'China'         => ['Beijing', 'Shanghai', 'Shenzhen', 'Guangzhou'],
            'Brazil'        => ['São Paulo', 'Rio de Janeiro', 'Brasília'],
        ];
    }
}
