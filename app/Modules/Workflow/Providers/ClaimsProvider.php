<?php

namespace App\Modules\Workflow\Providers;

use App\Core\User\Models\User;
use App\Modules\Finance\Claims\Models\Claim;
use App\Modules\Finance\Claims\Services\ClaimsService;
use App\Modules\Finance\Claims\Traits\ClaimsAuthTrait;
use App\Modules\Workflow\Contracts\WorkflowProvider;

/** Finance expense claims on the workflow engine. Claims keeps its checks, payment queue and records. */
class ClaimsProvider implements WorkflowProvider
{
    public function key(): string
    {
        return 'finance.claims';
    }

    public function label(): string
    {
        return 'Finance — expense claims';
    }

    public function submitEvent(): ?string
    {
        return 'finance.claims.submitted';
    }

    public function fields(): array
    {
        return [
            ['key' => 'reference', 'label' => 'Claim number', 'type' => 'text'],
            ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
            ['key' => 'amount', 'label' => 'Total amount', 'type' => 'currency'],
            ['key' => 'currency', 'label' => 'Currency', 'type' => 'text'],
            ['key' => 'category', 'label' => 'Category', 'type' => 'text'],
            ['key' => 'is_travel', 'label' => 'Travel claim', 'type' => 'yesno'],
            ['key' => 'claim_date', 'label' => 'Claim date', 'type' => 'date'],
            ['key' => 'risk_level', 'label' => 'AI risk level', 'type' => 'text'],
            ['key' => 'items', 'label' => 'Number of items', 'type' => 'number'],
        ];
    }

    public function find(string $id): ?object
    {
        // A malformed id is simply not found (the column is a uuid: querying it would be a database error).
        return \Illuminate\Support\Str::isUuid($id) ? Claim::query()->where('uuid', $id)->first() : null;
    }

    public function snapshot(object $record): array
    {
        $record->loadMissing('category');

        return [
            'reference' => $record->reference_number, 'title' => $record->title, 'amount' => (string) $record->total_amount,
            'currency' => $record->currency ?: 'ZAR', 'category' => $record->category?->name, 'is_travel' => (bool) ($record->category?->is_travel ?? false),
            'claim_date' => $record->claim_date?->toDateString(), 'risk_level' => $record->risk_level, 'items' => $record->items()->count(),
        ];
    }

    public function reference(object $record): string
    {
        return (string) $record->reference_number;
    }

    public function title(object $record): string
    {
        return 'Expense claim ' . $record->reference_number . ($record->title ? ' — ' . $record->title : '') . ' (R' . number_format((float) $record->total_amount, 2) . ')';
    }

    public function url(object $record): ?string
    {
        return url('/claims/my/' . $record->uuid);
    }

    public function requesterId(object $record): ?int
    {
        return $record->employee_id ? (int) $record->employee_id : null;
    }

    public function ownerId(object $record): ?int
    {
        return $this->requesterId($record);
    }

    public function budgetHolderId(object $record): ?int
    {
        return null;
    }

    public function canView(User $user, object $record): bool
    {
        // The Claims module's own rule, evaluated as the signed-in user.
        return (new class { use ClaimsAuthTrait; public function check(Claim $c): bool { return $this->canViewClaim($c); } })->check($record);
    }

    public function onOutcome(object $record, string $outcome, ?int $actorId, ?string $comment): void
    {
        app(ClaimsService::class)->finaliseByWorkflow($record, $outcome, $actorId, $comment);
    }
}
