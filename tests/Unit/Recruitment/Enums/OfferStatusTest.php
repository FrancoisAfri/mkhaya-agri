<?php

namespace Tests\Unit\Recruitment\Enums;

use App\Modules\HR\Recruitment\Enums\OfferStatus;
use PHPUnit\Framework\TestCase;

class OfferStatusTest extends TestCase
{
    // ── labels ───────────────────────────────────────────────────────────────

    public function test_each_status_has_a_human_readable_label(): void
    {
        // [case, expected label] — enum instances can't be array keys.
        $expected = [
            [OfferStatus::Draft,           'Draft'],
            [OfferStatus::PendingApproval, 'Pending Approval'],
            [OfferStatus::Approved,        'Approved'],
            [OfferStatus::Sent,            'Sent'],
            [OfferStatus::Accepted,        'Accepted'],
            [OfferStatus::Declined,        'Declined'],
            [OfferStatus::Expired,         'Expired'],
            [OfferStatus::Withdrawn,       'Withdrawn'],
        ];

        foreach ($expected as [$status, $label]) {
            $this->assertSame($label, $status->label());
        }
    }

    // ── isTerminal ───────────────────────────────────────────────────────────

    public function test_accepted_declined_expired_and_withdrawn_are_terminal(): void
    {
        $this->assertTrue(OfferStatus::Accepted->isTerminal());
        $this->assertTrue(OfferStatus::Declined->isTerminal());
        $this->assertTrue(OfferStatus::Expired->isTerminal());
        $this->assertTrue(OfferStatus::Withdrawn->isTerminal());
    }

    public function test_draft_approved_pending_and_sent_are_not_terminal(): void
    {
        $this->assertFalse(OfferStatus::Draft->isTerminal());
        $this->assertFalse(OfferStatus::PendingApproval->isTerminal());
        $this->assertFalse(OfferStatus::Approved->isTerminal());
        $this->assertFalse(OfferStatus::Sent->isTerminal());
    }

    // ── allowedTransitions ───────────────────────────────────────────────────

    public function test_draft_can_move_to_pending_or_withdrawn(): void
    {
        $allowed = OfferStatus::Draft->allowedTransitions();

        $this->assertContains(OfferStatus::PendingApproval, $allowed);
        $this->assertContains(OfferStatus::Withdrawn, $allowed);
    }

    public function test_sent_can_move_to_accepted_declined_expired_or_withdrawn(): void
    {
        $allowed = OfferStatus::Sent->allowedTransitions();

        $this->assertContains(OfferStatus::Accepted, $allowed);
        $this->assertContains(OfferStatus::Declined, $allowed);
        $this->assertContains(OfferStatus::Expired, $allowed);
        $this->assertContains(OfferStatus::Withdrawn, $allowed);
    }

    public function test_terminal_statuses_have_no_transitions(): void
    {
        foreach ([OfferStatus::Accepted, OfferStatus::Declined, OfferStatus::Expired, OfferStatus::Withdrawn] as $t) {
            $this->assertEmpty($t->allowedTransitions(), "{$t->value} should have no transitions");
        }
    }

    // ── canTransitionTo ──────────────────────────────────────────────────────

    public function test_can_transition_returns_true_for_valid_next_status(): void
    {
        $this->assertTrue(OfferStatus::Draft->canTransitionTo(OfferStatus::PendingApproval));
        $this->assertTrue(OfferStatus::Approved->canTransitionTo(OfferStatus::Sent));
        $this->assertTrue(OfferStatus::Sent->canTransitionTo(OfferStatus::Accepted));
    }

    public function test_can_transition_returns_false_for_backwards_moves(): void
    {
        $this->assertFalse(OfferStatus::Sent->canTransitionTo(OfferStatus::Draft));
        $this->assertFalse(OfferStatus::Accepted->canTransitionTo(OfferStatus::Sent));
    }

    public function test_can_transition_returns_false_for_same_status(): void
    {
        foreach (OfferStatus::cases() as $status) {
            $this->assertFalse($status->canTransitionTo($status));
        }
    }
}
