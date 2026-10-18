<?php

namespace Database\Factories\Fleet;

use App\Modules\Fleet\Models\FleetDocument;
use App\Modules\Fleet\Models\FleetVehicle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class FleetDocumentFactory extends Factory
{
    protected $model = FleetDocument::class;

    public function definition(): array
    {
        return [
            'uuid'                  => (string) Str::uuid(),
            'vehicle_id'            => FleetVehicle::factory(),
            'document_type'         => fake()->randomElement(['licence_disk', 'roadworthy', 'insurance', 'permit', 'registration']),
            'document_number'       => strtoupper(fake()->unique()->bothify('DOC-######')),
            'issued_date'           => fake()->dateTimeBetween('-2 years', '-6 months')->format('Y-m-d'),
            'expiry_date'           => fake()->dateTimeBetween('+2 months', '+2 years')->format('Y-m-d'),
            'issuing_authority'     => fake()->company(),
            'file_path'             => null,
            'status'                => 'valid',
            'renewal_reminder_days' => 30,
            'notes'                 => null,
            'version'               => 1,
            'created_by'            => null,
            'updated_by'            => null,
        ];
    }

    public function expiringSoon(int $days = 25): static
    {
        return $this->state([
            'expiry_date' => now()->addDays($days)->toDateString(),
            'status'      => 'valid',
        ]);
    }

    public function expired(): static
    {
        return $this->state([
            'expiry_date' => fake()->dateTimeBetween('-6 months', '-1 day')->format('Y-m-d'),
            'status'      => 'expired',
        ]);
    }

    public function insurance(): static
    {
        return $this->state(['document_type' => 'insurance']);
    }

    public function licenceDisk(): static
    {
        return $this->state(['document_type' => 'licence_disk']);
    }
}
