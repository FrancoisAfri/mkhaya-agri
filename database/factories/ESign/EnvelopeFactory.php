<?php

namespace Database\Factories\ESign;

use App\Core\User\Models\User;
use App\Modules\ESign\Enums\DocumentType;
use App\Modules\ESign\Enums\EnvelopeStatus;
use App\Modules\ESign\Models\Envelope;
use Illuminate\Database\Eloquent\Factories\Factory;

class EnvelopeFactory extends Factory
{
    protected $model = Envelope::class;

    public function definition(): array
    {
        return [
            'title'         => $this->faker->sentence(4),
            'document_type' => $this->faker->randomElement(DocumentType::cases())->value,
            'status'        => EnvelopeStatus::Draft->value,
            'message'       => $this->faker->optional()->sentence(),
            'expires_at'    => now()->addDays(14),
            'created_by'    => User::factory(),
            // An envelope is about a document, and one without a path can no longer be sent — a
            // signer who opens an empty envelope will not sign and will not tell you why. Tests
            // that care about the bytes store them through EnvelopeDocumentService; the rest just
            // need the envelope to be in a sendable state.
            'document_path' => 'esign/factory/source.pdf',
            'document_hash' => hash('sha256', 'factory-document'),
        ];
    }

    public function sent(): static
    {
        return $this->state([
            'status'  => EnvelopeStatus::Sent->value,
            'sent_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state([
            'status'       => EnvelopeStatus::Completed->value,
            'sent_at'      => now()->subDay(),
            'completed_at' => now(),
        ]);
    }

    public function voided(): static
    {
        return $this->state([
            'status'        => EnvelopeStatus::Voided->value,
            'voided_at'     => now(),
            'voided_reason' => 'Voided for testing.',
        ]);
    }
}
