<?php

namespace App\Modules\Operations\ProjectManagement\Support\Evidence;

use App\Modules\Operations\ProjectManagement\Services\EvidenceIngestor;
use App\Modules\Operations\ProjectManagement\Services\IngestResult;
use App\Modules\Operations\ProjectManagement\Services\ModuleRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Base for an adapter that turns another module's domain events into evidence (PM-1005).
 *
 * Procurement receives goods; that is evidence that material arrived on a project, and it should
 * count towards progress and cost without anybody re-typing it. The adapter is what makes that
 * true, and it lives on PM's side of the boundary so the other module never learns PM exists.
 *
 * Three properties every adapter inherits:
 *
 *  - **Inert when its module is inactive.** `handle()` returns null and logs nothing alarming — a
 *    tenant without Procurement should see no errors from a Procurement adapter, because nothing
 *    is wrong (PM-1005).
 *  - **Idempotent by construction.** `sourceRef()` derives a stable key from the source record, so
 *    replaying a module's event stream — which adapters do — records each fact once.
 *  - **Documented mapping.** `mapping()` states, for every external event it accepts, which
 *    evidence type it becomes. That table is the answer to "where did this number come from?" and
 *    it is exported into the docs rather than living in someone's head.
 */
abstract class ModuleAdapter
{
    public function __construct(
        protected readonly EvidenceIngestor $ingestor,
        protected readonly ModuleRegistry $modules,
    ) {
    }

    /** The capability that makes this adapter live, e.g. `procurement.orders`. */
    abstract public function capability(): string;

    /** The `source_module` recorded on the evidence it writes. */
    abstract public function sourceModule(): string;

    /**
     * external event name => evidence type.
     *
     * @return array<string,string>
     */
    abstract public function mapping(): array;

    /**
     * Translate one external event into an evidence submission.
     *
     * Returning null means "not evidence" — plenty of a module's events are internal bookkeeping
     * and should not reach the ledger at all.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>|null
     */
    abstract protected function translate(string $externalEvent, array $payload): ?array;

    /**
     * Handle one event from the source module.
     *
     * @param  array<string,mixed>  $payload
     */
    public function handle(string $externalEvent, array $payload): ?IngestResult
    {
        // Not licensed: silently do nothing. This is the normal state for most tenants and must
        // not read as a failure anywhere.
        if (! $this->modules->has($this->capability())) {
            return null;
        }

        if (! isset($this->mapping()[$externalEvent])) {
            return null;
        }

        $submission = $this->translate($externalEvent, $payload);

        if ($submission === null) {
            return null;
        }

        $submission['source_module'] = $this->sourceModule();
        $submission['event_type'] ??= $this->mapping()[$externalEvent];
        $submission['actor_type'] ??= 'integration';

        // Adapters run inside the source module's transaction where possible; when they are driven
        // by a queued listener there is none, so one is opened here. Either way the evidence and
        // its outbox notification commit together.
        return DB::transactionLevel() > 0
            ? $this->ingestor->ingest($submission)
            : DB::transaction(fn () => $this->ingestor->ingest($submission));
    }

    /** Is this adapter currently doing anything? Used by the system-health view. */
    public function isActive(): bool
    {
        return $this->modules->has($this->capability());
    }
}
