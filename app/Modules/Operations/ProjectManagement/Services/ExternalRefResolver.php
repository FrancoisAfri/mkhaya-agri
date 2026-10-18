<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\ClientProvider;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\EmployeeProvider;
use App\Modules\Operations\ProjectManagement\Models\ExternalRef;
use App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord;

/**
 * Keeps links to other modules readable when those modules change underneath them (PM-1043, PM-1045).
 *
 * The hard case this exists for: a module was active, project records reference it, and it is then
 * deactivated — or the record is deleted there. The requirement is explicit: no crash, no blank
 * screen, no 500. A project that named its client through CRM must still show that client's name
 * after CRM is switched off.
 *
 * The mechanism is the cached `display_name`/`snapshot` on `pm_external_refs`. Resolution tries the
 * live source first; when that fails the cached values are served and the row is marked
 * `orphaned`, which is what the system-health view lists and what the convert-to-local action
 * fixes in one step.
 */
class ExternalRefResolver
{
    /** How the source_module maps onto the seam that can answer for it. */
    private const SEAMS = [
        'hr'  => EmployeeProvider::class,
        'crm' => ClientProvider::class,
    ];

    public function __construct(private readonly ProviderRegistry $providers)
    {
    }

    /**
     * Resolve a link, live if possible and from cache if not.
     *
     * Never throws and never returns null for a row that exists: a reference that cannot be
     * resolved still renders, marked unresolved, because a blank where a client's name should be
     * is a worse answer than a stale name.
     */
    public function resolve(ExternalRef $ref): ProvidedRecord
    {
        if ($ref->isLocal()) {
            return new ProvidedRecord(
                id: $ref->uuid,
                label: $ref->display_name ?: 'Unnamed',
                source: 'local',
                meta: $ref->snapshot ?? [],
            );
        }

        $seam = self::SEAMS[$ref->source_module] ?? null;

        // The module is not licensed (or PM has no seam for it): serve what we cached.
        if ($seam === null || ! $this->providers->isIntegrated($seam)) {
            return $this->fromCache($ref, markOrphaned: false);
        }

        try {
            $record = $this->providers->for($seam)->get((string) $ref->source_id);
        } catch (\Throwable) {
            // A source that errors is a source that is unavailable — degrade, do not propagate.
            return $this->fromCache($ref, markOrphaned: false);
        }

        if ($record === null) {
            // The module is here and says it has no such record: the link is genuinely broken.
            return $this->fromCache($ref, markOrphaned: true);
        }

        $this->refreshCache($ref, $record);

        return $record;
    }

    /**
     * Re-read every link of a kind so cached labels follow the source (PM-1045: within 60s, which
     * is a scheduled sweep rather than a read-path cost).
     *
     * @return array{refreshed:int, orphaned:int}
     */
    public function sync(?string $sourceModule = null): array
    {
        $refreshed = 0;
        $orphaned = 0;

        ExternalRef::query()
            ->where('source_module', '!=', 'local')
            ->when($sourceModule, fn ($q) => $q->where('source_module', $sourceModule))
            ->chunkById(200, function ($refs) use (&$refreshed, &$orphaned) {
                foreach ($refs as $ref) {
                    $before = $ref->sync_status;
                    $this->resolve($ref);
                    $ref->refresh();

                    $ref->sync_status === 'orphaned' ? $orphaned++ : $refreshed++;

                    // A link that came back from orphaned is worth noticing in the log the sweep
                    // writes; the status change itself is the record.
                    unset($before);
                }
            });

        return ['refreshed' => $refreshed, 'orphaned' => $orphaned];
    }

    /**
     * Convert an orphaned link into a PM-local record, in one action (PM-1043).
     *
     * The last-known name becomes the local record's name, so nothing is retyped and nothing is
     * lost — the project keeps the client it always had, PM just owns the row now.
     */
    public function convertToLocal(ExternalRef $ref): ExternalRef
    {
        $ref->update([
            'source_module'  => 'local',
            'source_id'      => null,
            'sync_status'    => 'ok',
            'last_synced_at' => now(),
        ]);

        return $ref->fresh();
    }

    /** Everything currently pointing at nothing — the system-health list (PM-1043). */
    public function orphaned(): \Illuminate\Support\Collection
    {
        return ExternalRef::query()->where('sync_status', 'orphaned')->get();
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function fromCache(ExternalRef $ref, bool $markOrphaned): ProvidedRecord
    {
        if ($markOrphaned && $ref->sync_status !== 'orphaned') {
            $ref->update(['sync_status' => 'orphaned']);
        } elseif (! $markOrphaned && $ref->sync_status === 'ok') {
            // The source is unreachable rather than missing — stale, not broken. The distinction
            // matters: 'orphaned' invites someone to convert the link, and a deactivated module
            // may well come back.
            $ref->update(['sync_status' => 'stale']);
        }

        return ProvidedRecord::orphaned(
            $ref->uuid,
            $ref->display_name ?: 'Unavailable record',
            $ref->source_module,
        );
    }

    private function refreshCache(ExternalRef $ref, ProvidedRecord $record): void
    {
        $changed = $ref->display_name !== $record->label;

        if ($changed || $ref->sync_status !== 'ok' || $ref->last_synced_at === null) {
            $ref->update([
                'display_name'   => $record->label,
                'snapshot'       => $record->meta,
                'sync_status'    => 'ok',
                'last_synced_at' => now(),
            ]);
        }
    }
}
