<?php

namespace App\Modules\Workflow\Support;

use App\Modules\Workflow\Contracts\WorkflowProvider;

/** The modules that have registered with the engine (PRS §13.1). */
class ProviderRegistry
{
    /** @var array<string, class-string<WorkflowProvider>> */
    public const PROVIDERS = [
        'finance.claims' => \App\Modules\Workflow\Providers\ClaimsProvider::class,
        'hr.leave' => \App\Modules\Workflow\Providers\LeaveProvider::class,
        'procurement.requisition' => \App\Modules\Workflow\Providers\PurchaseRequestProvider::class,
    ];

    public function has(string $key): bool
    {
        return isset(self::PROVIDERS[$key]);
    }

    public function get(string $key): ?WorkflowProvider
    {
        return isset(self::PROVIDERS[$key]) ? app(self::PROVIDERS[$key]) : null;
    }

    /** @return array<string, WorkflowProvider> */
    public function all(): array
    {
        return array_map(fn ($c) => app($c), self::PROVIDERS);
    }

    /** The provider whose records of this type the engine runs on. */
    public function forSubjectType(?string $type): ?WorkflowProvider
    {
        return $type ? $this->get($type) : null;
    }
}
