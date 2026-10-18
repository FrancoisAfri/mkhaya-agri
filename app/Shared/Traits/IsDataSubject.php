<?php

namespace App\Shared\Traits;

use App\Core\Compliance\Models\PopiaConsent;

/**
 * Marks a model as a POPIA data subject with consent and retention helpers.
 *
 * Models using this trait should define:
 *   - $retentionYears (int)  — how many years records must be kept (HPCSA: 5)
 *   - $retentionFrom  (string) — the attribute name that marks retention start
 *     e.g. 'date_left' for an employee or 'last_consultation_at' for a patient
 */
trait IsDataSubject
{
    public function consents()
    {
        return $this->morphMany(PopiaConsent::class, 'subject');
    }

    public function hasActiveConsent(string $purpose): bool
    {
        return $this->consents()
            ->where('purpose', $purpose)
            ->where('status', 'granted')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->whereNull('revoked_at')
            ->exists();
    }

    /**
     * Returns true if the HPCSA / legal retention window has elapsed and the
     * record may be anonymised. Override $retentionYears and $retentionFrom.
     */
    public function retentionWindowElapsed(): bool
    {
        $years = property_exists($this, 'retentionYears') ? $this->retentionYears : 5;
        $fromField = property_exists($this, 'retentionFrom') ? $this->retentionFrom : 'updated_at';

        $anchor = $this->{$fromField};

        if (!$anchor) {
            return false;
        }

        return now()->diffInYears($anchor) >= $years;
    }
}
