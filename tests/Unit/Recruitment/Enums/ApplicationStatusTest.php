<?php

namespace Tests\Unit\Recruitment\Enums;

use App\Modules\HR\Recruitment\Enums\ApplicationStatus;
use PHPUnit\Framework\TestCase;

class ApplicationStatusTest extends TestCase
{
    // ── labels ───────────────────────────────────────────────────────────────

    public function test_each_status_has_a_human_readable_label(): void
    {
        // [case, expected label] — enum instances can't be array keys.
        $expected = [
            [ApplicationStatus::Applied,             'Applied'],
            [ApplicationStatus::Screening,           'Screening'],
            [ApplicationStatus::PhoneInterview,      'Phone Interview'],
            [ApplicationStatus::TechnicalAssessment, 'Technical Assessment'],
            [ApplicationStatus::Interview,           'Interview'],
            [ApplicationStatus::ReferenceCheck,      'Reference Check'],
            [ApplicationStatus::Offered,             'Offered'],
            [ApplicationStatus::Accepted,            'Accepted'],
            [ApplicationStatus::Assessment,          'Assessment'],
            [ApplicationStatus::Shortlisted,         'Shortlisted'],
            [ApplicationStatus::OfferSent,           'Offer Sent'],
            [ApplicationStatus::Hired,               'Hired'],
            [ApplicationStatus::OnHold,              'On Hold'],
            [ApplicationStatus::Rejected,            'Rejected'],
            [ApplicationStatus::Withdrawn,           'Withdrawn'],
        ];

        foreach ($expected as [$status, $label]) {
            $this->assertSame($label, $status->label(), "Label mismatch for {$status->value}");
        }
    }

    public function test_every_case_has_a_non_empty_label_and_color(): void
    {
        foreach (ApplicationStatus::cases() as $status) {
            $this->assertNotEmpty($status->label(), "{$status->value} missing label");
            $this->assertNotEmpty($status->color(), "{$status->value} missing color");
        }
    }

    // ── isTerminal ───────────────────────────────────────────────────────────

    public function test_accepted_hired_rejected_and_withdrawn_are_terminal(): void
    {
        $this->assertTrue(ApplicationStatus::Accepted->isTerminal());
        $this->assertTrue(ApplicationStatus::Hired->isTerminal());
        $this->assertTrue(ApplicationStatus::Rejected->isTerminal());
        $this->assertTrue(ApplicationStatus::Withdrawn->isTerminal());
    }

    public function test_non_terminal_statuses_are_not_terminal(): void
    {
        $nonTerminal = [
            ApplicationStatus::Applied,
            ApplicationStatus::Screening,
            ApplicationStatus::PhoneInterview,
            ApplicationStatus::TechnicalAssessment,
            ApplicationStatus::Interview,
            ApplicationStatus::ReferenceCheck,
            ApplicationStatus::Offered,
            ApplicationStatus::Assessment,
            ApplicationStatus::Shortlisted,
            ApplicationStatus::OfferSent,
            ApplicationStatus::OnHold,
        ];

        foreach ($nonTerminal as $status) {
            $this->assertFalse($status->isTerminal(), "{$status->value} should not be terminal");
        }
    }

    public function test_is_active_is_the_inverse_of_terminal(): void
    {
        foreach (ApplicationStatus::cases() as $status) {
            $this->assertSame(! $status->isTerminal(), $status->isActive());
        }
    }

    // ── allowedTransitions ───────────────────────────────────────────────────

    public function test_applied_can_progress_and_be_exited(): void
    {
        $allowed = ApplicationStatus::Applied->allowedTransitions();

        // Forward pipeline options
        $this->assertContains(ApplicationStatus::Screening, $allowed);
        $this->assertContains(ApplicationStatus::Assessment, $allowed);
        $this->assertContains(ApplicationStatus::PhoneInterview, $allowed);
        // Universal exits from any non-terminal state
        $this->assertContains(ApplicationStatus::OnHold, $allowed);
        $this->assertContains(ApplicationStatus::Rejected, $allowed);
        $this->assertContains(ApplicationStatus::Withdrawn, $allowed);
    }

    public function test_terminal_statuses_have_no_allowed_transitions(): void
    {
        foreach ([ApplicationStatus::Accepted, ApplicationStatus::Hired, ApplicationStatus::Rejected, ApplicationStatus::Withdrawn] as $terminal) {
            $this->assertEmpty(
                $terminal->allowedTransitions(),
                "{$terminal->value} should have no transitions"
            );
        }
    }

    public function test_allowed_transitions_are_consistent_with_can_transition_to(): void
    {
        foreach (ApplicationStatus::cases() as $from) {
            foreach (ApplicationStatus::cases() as $to) {
                $this->assertSame(
                    in_array($to, $from->allowedTransitions(), true),
                    $from->canTransitionTo($to),
                    "Inconsistent for {$from->value} -> {$to->value}"
                );
            }
        }
    }

    // ── canTransitionTo ──────────────────────────────────────────────────────

    public function test_can_transition_to_returns_true_for_allowed_next_status(): void
    {
        $this->assertTrue(ApplicationStatus::Applied->canTransitionTo(ApplicationStatus::Screening));
        $this->assertTrue(ApplicationStatus::Screening->canTransitionTo(ApplicationStatus::Interview));
        $this->assertTrue(ApplicationStatus::Interview->canTransitionTo(ApplicationStatus::Shortlisted));
        $this->assertTrue(ApplicationStatus::Shortlisted->canTransitionTo(ApplicationStatus::OfferSent));
        $this->assertTrue(ApplicationStatus::OfferSent->canTransitionTo(ApplicationStatus::Accepted));
    }

    public function test_can_transition_to_returns_false_for_backwards_moves(): void
    {
        $this->assertFalse(ApplicationStatus::Screening->canTransitionTo(ApplicationStatus::Applied));
        $this->assertFalse(ApplicationStatus::Hired->canTransitionTo(ApplicationStatus::Shortlisted));
        $this->assertFalse(ApplicationStatus::Interview->canTransitionTo(ApplicationStatus::Applied));
    }

    public function test_can_transition_to_returns_false_for_same_status(): void
    {
        foreach (ApplicationStatus::cases() as $status) {
            $this->assertFalse(
                $status->canTransitionTo($status),
                "Self-transition should not be allowed for {$status->value}"
            );
        }
    }

    public function test_rejected_from_every_non_terminal_status(): void
    {
        $canReject = [
            ApplicationStatus::Applied,
            ApplicationStatus::Screening,
            ApplicationStatus::Assessment,
            ApplicationStatus::Interview,
            ApplicationStatus::Shortlisted,
            ApplicationStatus::OfferSent,
        ];

        foreach ($canReject as $status) {
            $this->assertTrue(
                $status->canTransitionTo(ApplicationStatus::Rejected),
                "{$status->value} should allow rejection"
            );
        }
    }
}
