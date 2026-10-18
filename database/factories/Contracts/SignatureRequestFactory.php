<?php

namespace Database\Factories\Contracts;

use App\Core\User\Models\User;
use App\Modules\HR\Contracts\Models\Contract;
use App\Modules\HR\Contracts\Models\SignatureRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SignatureRequestFactory extends Factory
{
    protected $model = SignatureRequest::class;

    public function definition(): array
    {
        $rawToken = Str::random(64);

        return [
            'contract_id'      => Contract::factory(),
            'signatory_type'   => 'candidate',
            'user_id'          => null,
            'candidate_id'     => null,
            'token_hash'       => hash('sha256', $rawToken),
            'token_expires_at' => now()->addDays(7),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state([
            'signatory_type' => 'hr_manager',
            'user_id'        => $user->id,
            'candidate_id'   => null,
        ]);
    }

    public function withOtp(string $rawOtp = '654321'): static
    {
        return $this->state([
            'otp_hash'       => hash('sha256', $rawOtp),
            'otp_expires_at' => now()->addMinutes(15),
            'otp_attempts'   => 0,
        ]);
    }

    public function signed(): static
    {
        return $this->state([
            'signed_at'            => now(),
            'signature_ip'         => '127.0.0.1',
            'signature_user_agent' => 'PHPUnit',
        ]);
    }
}
