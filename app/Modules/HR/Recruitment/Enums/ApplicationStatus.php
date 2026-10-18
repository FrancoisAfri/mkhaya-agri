<?php

namespace App\Modules\HR\Recruitment\Enums;

enum ApplicationStatus: string
{
    case Applied             = 'applied';
    case Screening           = 'screening';
    case PhoneInterview      = 'phone_interview';
    case TechnicalAssessment = 'technical_assessment';
    case Interview           = 'interview';
    case ReferenceCheck      = 'reference_check';
    case Offered             = 'offered';
    case Accepted            = 'accepted';
    case Rejected            = 'rejected';
    case Withdrawn           = 'withdrawn';
    case OnHold              = 'on_hold';
    case Assessment          = 'assessment';
    case Shortlisted         = 'shortlisted';
    case OfferSent           = 'offer_sent';
    case Hired               = 'hired';

    public function label(): string
    {
        return match($this) {
            self::Applied             => 'Applied',
            self::Screening           => 'Screening',
            self::PhoneInterview      => 'Phone Interview',
            self::TechnicalAssessment => 'Technical Assessment',
            self::Interview           => 'Interview',
            self::ReferenceCheck      => 'Reference Check',
            self::Offered             => 'Offered',
            self::Accepted            => 'Accepted',
            self::Rejected            => 'Rejected',
            self::Withdrawn           => 'Withdrawn',
            self::OnHold              => 'On Hold',
            self::Assessment          => 'Assessment',
            self::Shortlisted         => 'Shortlisted',
            self::OfferSent           => 'Offer Sent',
            self::Hired               => 'Hired',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Applied             => 'info',
            self::Screening           => 'warning',
            self::PhoneInterview      => 'warning',
            self::TechnicalAssessment => 'warning',
            self::Interview           => 'primary',
            self::ReferenceCheck      => 'primary',
            self::Offered             => 'success',
            self::Accepted            => 'success',
            self::Rejected            => 'error',
            self::Withdrawn           => 'secondary',
            self::OnHold              => 'warning',
            self::Assessment          => 'warning',
            self::Shortlisted         => 'info',
            self::OfferSent           => 'success',
            self::Hired               => 'success',
        };
    }

    public function isActive(): bool
    {
        return !$this->isTerminal();
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Accepted,
            self::Rejected,
            self::Withdrawn,
            self::Hired,
        ]);
    }

    /** The set of statuses this status may legally move to next. */
    public function allowedTransitions(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $target): bool => $this->canTransitionTo($target),
        ));
    }

    public function canTransitionTo(self $target): bool
    {
        if ($this->isTerminal()) return false;
        if ($this === $target)   return false;

        // Always allow any non-terminal → OnHold, Rejected, Withdrawn
        if (in_array($target, [self::OnHold, self::Rejected, self::Withdrawn])) return true;

        // Forward pipeline progression
        return match($this) {
            self::Applied             => in_array($target, [self::Screening, self::Assessment, self::PhoneInterview]),
            self::Screening           => in_array($target, [self::Assessment, self::PhoneInterview, self::Interview]),
            self::PhoneInterview      => in_array($target, [self::TechnicalAssessment, self::Assessment, self::Interview]),
            self::TechnicalAssessment => in_array($target, [self::Interview, self::Assessment]),
            self::Assessment          => in_array($target, [self::Interview, self::PhoneInterview, self::TechnicalAssessment]),
            self::Interview           => in_array($target, [self::ReferenceCheck, self::Shortlisted]),
            self::ReferenceCheck      => in_array($target, [self::Shortlisted, self::OfferSent, self::Offered]),
            self::Shortlisted         => in_array($target, [self::OfferSent, self::Offered, self::ReferenceCheck, self::Hired]),
            self::OfferSent           => in_array($target, [self::Offered, self::Accepted, self::Hired]),
            self::Offered             => in_array($target, [self::Accepted, self::Hired]),
            self::OnHold              => !$target->isTerminal(), // can resume from hold to any active status
            default                   => false,
        };
    }
}
