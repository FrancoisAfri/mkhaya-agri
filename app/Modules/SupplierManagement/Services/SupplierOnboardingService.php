<?php

namespace App\Modules\SupplierManagement\Services;

use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierOnboarding;
use App\Modules\SupplierManagement\Notifications\SupplierOnboardingInvitation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SupplierOnboardingService
{
    /**
     * Create an onboarding invite with a 24-hour token.
     */
    public function createOnboarding(array $data): SupplierOnboarding
    {
        $onboarding = SupplierOnboarding::create(array_merge($data, [
            'status'         => $data['status'] ?? 'pending',
            'current_status' => 0,
            'token'          => Str::random(48),
            'expires_at'     => now()->addHours((int) config('suppliers.onboarding_token_hours', 24)),
        ]));

        $this->sendInvitation($onboarding);

        return $onboarding;
    }

    /**
     * Send (or resend) the invitation, refreshing the token and its window.
     *
     * Resending deliberately mints a new token. The old link stops working, which is what "resend"
     * has to mean when the link is the credential — otherwise every resend widens the set of live
     * URLs for one supplier.
     */
    public function resend(SupplierOnboarding $onboarding): SupplierOnboarding
    {
        $onboarding->update([
            'token'      => Str::random(48),
            'expires_at' => now()->addHours((int) config('suppliers.onboarding_token_hours', 24)),
        ]);

        $this->sendInvitation($onboarding);

        return $onboarding;
    }

    public function hasExpired(SupplierOnboarding $onboarding): bool
    {
        return $onboarding->expires_at !== null && $onboarding->expires_at->isPast();
    }

    private function sendInvitation(SupplierOnboarding $onboarding): void
    {
        if (blank($onboarding->contact_person_email)) {
            return;
        }

        Notification::route('mail', $onboarding->contact_person_email)
            ->notify(new SupplierOnboardingInvitation($onboarding));
    }

    public function all(): Collection
    {
        return SupplierOnboarding::latest()->get();
    }

    /**
     * Duplicate check on registration number and/or contact email.
     */
    public function checkExistingSupplier(?string $registration, ?string $email): ?Supplier
    {
        return Supplier::query()
            ->when($registration, fn ($q) => $q->orWhere('company_registration_number', $registration))
            ->when($email, fn ($q) => $q->orWhere('email', $email))
            ->first();
    }
}
