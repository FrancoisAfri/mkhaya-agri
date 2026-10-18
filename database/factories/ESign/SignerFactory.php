<?php

namespace Database\Factories\ESign;

use App\Modules\ESign\Enums\SignerRole;
use App\Modules\ESign\Enums\SignerStatus;
use App\Modules\ESign\Models\Envelope;
use App\Modules\ESign\Models\Signer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SignerFactory extends Factory
{
    protected $model = Signer::class;

    public function definition(): array
    {
        return [
            'envelope_id'   => Envelope::factory(),
            'name'          => $this->faker->name(),
            'email'         => $this->faker->safeEmail(),
            'phone'         => $this->faker->optional()->phoneNumber(),
            'role'          => SignerRole::Signer->value,
            'signing_order' => 1,
            'status'        => SignerStatus::Pending->value,
        ];
    }

    /*
     * Every state below forces its fields on rather than passing them through the factory's
     * attributes.
     *
     * Signer::$fillable deliberately excludes `token`, `status`, `signed_at` and the signature
     * fields — they belong to ESignService, not to a request payload. A factory builds its model
     * after creation, so those values were silently dropped: `signed()` produced a signer
     * still sitting at Pending, and the test asserting the envelope completed watched nothing
     * happen.
     */
    public function notified(): static
    {
        $token = Str::random(64);

        return $this->afterCreating(fn (Signer $signer) => $signer->forceFill([
            'status'           => SignerStatus::Notified->value,
            'token'            => $token,
            'token_expires_at' => now()->addDays(14),
        ])->save());
    }

    public function withToken(?string $rawToken = null): static
    {
        $token = $rawToken ?? Str::random(64);

        return $this->afterCreating(fn (Signer $signer) => $signer->forceFill([
            'token'            => $token,
            'token_expires_at' => now()->addDays(14),
            'status'           => SignerStatus::Notified->value,
        ])->save());
    }

    public function signed(): static
    {
        return $this->afterCreating(fn (Signer $signer) => $signer->forceFill([
            'status'         => SignerStatus::Signed->value,
            'signed_at'      => now(),
            'signature_data' => 'Test Signature',
            'signature_type' => 'typed',
            'ip_address'     => '127.0.0.1',
        ])->save());
    }
}
