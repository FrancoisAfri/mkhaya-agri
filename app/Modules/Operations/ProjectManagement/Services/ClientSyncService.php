<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\ClientProvider;
use App\Modules\Operations\ProjectManagement\Models\ExternalRef;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord;
use Illuminate\Support\Facades\DB;

/**
 * Keeping PM's clients and CRM's customers as one list (PM-1043, PM-1044).
 *
 * The awkward case this exists for is the ordinary one: a tenant runs Project Management for a
 * year without CRM, types forty client names into projects, then buys CRM. Neither "ignore the
 * forty" nor "CRM now has forty rows nobody asked for" is acceptable, so:
 *
 *   adopt()    a PM-local client is matched to an existing CRM account by email first, then by
 *              name; if nothing matches, the account is created from what PM already knows and
 *              the ref is re-pointed at it. Either way the project keeps its client — the same
 *              ExternalRef row, so nothing that references it has to change.
 *
 *   refresh()  a ref already pointing at CRM has its display name and snapshot brought up to
 *              date, and is marked `orphaned` if the account has gone. Orphaned, not deleted: a
 *              project delivered for a customer somebody removed from CRM still had a customer,
 *              and blanking the field would erase that fact.
 *
 * Email before name, because a name match is a guess and an email match is not. Matching only on
 * name is how "Sasol" and "Sasol Ltd" become one account that belongs to neither.
 *
 * Nothing here runs implicitly on a page load. It is a command, `pm:sync-clients`, so the moment
 * forty projects change hands is a moment somebody chose and can find in a log.
 */
class ClientSyncService
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly AuditLogger $audit,
    ) {
    }

    /** True when clients are owned by another module right now, so adoption is meaningful. */
    public function isIntegrated(): bool
    {
        return $this->providers->for(ClientProvider::class)->isAuthoritative();
    }

    /**
     * Bring every PM-local client into the authoritative system.
     *
     * @return array{adopted:int, matched:int, skipped:int}
     */
    public function adoptAll(): array
    {
        if (! $this->isIntegrated()) {
            // Nothing to adopt into. Reported rather than thrown: the scheduled command runs on
            // every tenant, and most of them do not have CRM.
            return ['adopted' => 0, 'matched' => 0, 'skipped' => 0];
        }

        $counts = ['adopted' => 0, 'matched' => 0, 'skipped' => 0];

        ExternalRef::query()
            ->where('local_type', 'project_client')
            ->where('source_module', 'local')
            ->orderBy('id')
            ->chunkById(200, function ($refs) use (&$counts) {
                foreach ($refs as $ref) {
                    $result = $this->adopt($ref);
                    $counts[$result]++;
                }
            });

        return $counts;
    }

    /**
     * Point one local client at its counterpart in the authoritative system.
     *
     * @return 'adopted'|'matched'|'skipped'
     */
    public function adopt(ExternalRef $ref): string
    {
        $clients = $this->providers->for(ClientProvider::class);

        if (! $clients->isAuthoritative() || $ref->source_module !== 'local') {
            return 'skipped';
        }

        $snapshot = $ref->snapshot ?? [];
        $name = (string) $ref->display_name;

        if (trim($name) === '') {
            return 'skipped';
        }

        $match = $this->findCounterpart($clients, $name, $snapshot['email'] ?? null);
        $outcome = $match ? 'matched' : 'adopted';

        $record = $match ?? $clients->create([
            'name'  => $name,
            'email' => $snapshot['email'] ?? null,
            'phone' => $snapshot['phone'] ?? null,
            'city'  => $snapshot['city'] ?? null,
            'notes' => $snapshot['notes'] ?? null,
        ]);

        DB::transaction(function () use ($ref, $record, $snapshot) {
            $ref->update([
                'source_module'  => $record->source,
                'source_entity'  => 'customer',
                'source_id'      => $record->id,
                'display_name'   => $record->label,
                // The PM-typed details are kept under their own key rather than overwritten. If
                // the match turns out to be wrong, what was originally entered is still readable.
                'snapshot'       => $record->meta + ['adopted_from_local' => $snapshot],
                'sync_status'    => 'ok',
                'last_synced_at' => now(),
            ]);
        });

        $this->audit->recordAction($ref->refresh(), 'client.adopted', [
            'source'    => $record->source,
            'source_id' => $record->id,
            'outcome'   => $outcome,
        ]);

        return $outcome;
    }

    /**
     * Refresh every ref that already points at the authoritative system.
     *
     * @return array{refreshed:int, orphaned:int}
     */
    public function refreshAll(): array
    {
        $clients = $this->providers->for(ClientProvider::class);
        $counts = ['refreshed' => 0, 'orphaned' => 0];

        if (! $clients->isAuthoritative()) {
            return $counts;
        }

        ExternalRef::query()
            ->where('local_type', 'project_client')
            ->where('source_module', '!=', 'local')
            ->whereNotNull('source_id')
            ->orderBy('id')
            ->chunkById(200, function ($refs) use ($clients, &$counts) {
                foreach ($refs as $ref) {
                    $record = $clients->get((string) $ref->source_id);

                    if (! $record) {
                        // The account is gone. The last-known name stays, so the project still
                        // reads as having been delivered for somebody.
                        $ref->update(['sync_status' => 'orphaned', 'last_synced_at' => now()]);
                        $counts['orphaned']++;

                        continue;
                    }

                    $ref->update([
                        'display_name'   => $record->label,
                        'snapshot'       => $record->meta + array_filter(['email' => $record->email]),
                        'sync_status'    => 'ok',
                        'last_synced_at' => now(),
                    ]);

                    $counts['refreshed']++;
                }
            });

        return $counts;
    }

    /**
     * The ref a project should carry for a chosen client.
     *
     * Called when a project is saved. Idempotent on the source id, so picking the same client for
     * a second project reuses the row rather than accumulating one ref per project.
     */
    public function refFor(string $clientId): ?ExternalRef
    {
        $clients = $this->providers->for(ClientProvider::class);

        // A local client is already a ref; nothing to resolve.
        if ($existing = ExternalRef::where('uuid', $clientId)->where('local_type', 'project_client')->first()) {
            return $existing;
        }

        $record = $clients->get($clientId);

        if (! $record) {
            return null;
        }

        return ExternalRef::updateOrCreate(
            [
                'local_type'    => 'project_client',
                'source_module' => $record->source,
                'source_id'     => $record->id,
            ],
            [
                'source_entity'  => 'customer',
                'display_name'   => $record->label,
                'snapshot'       => $record->meta + array_filter(['email' => $record->email]),
                'sync_status'    => 'ok',
                'last_synced_at' => now(),
            ],
        );
    }

    /** How many projects would be touched by adoption — what the setup page shows before you press it. */
    public function pendingAdoptionCount(): int
    {
        return ExternalRef::query()
            ->where('local_type', 'project_client')
            ->where('source_module', 'local')
            ->count();
    }

    /** Clients that no project references, for the tidy-up the setup page offers. */
    public function unusedClientIds(): array
    {
        return ExternalRef::query()
            ->where('local_type', 'project_client')
            ->whereNotIn('id', Project::query()->whereNotNull('client_ref_id')->select('client_ref_id'))
            ->pluck('id')
            ->all();
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function findCounterpart(ClientProvider $clients, string $name, ?string $email): ?ProvidedRecord
    {
        // Email first: it is an identity, where a name is a label two customers may share.
        if ($email) {
            foreach ($clients->search($email, 5) as $candidate) {
                if ($candidate->email && mb_strtolower($candidate->email) === mb_strtolower($email)) {
                    return $candidate;
                }
            }
        }

        foreach ($clients->search($name, 10) as $candidate) {
            if (mb_strtolower(trim($candidate->label)) === mb_strtolower(trim($name))) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Keep the invoicing details PM owns, whoever owns the client.
     *
     * A CRM account has a name, an address and a contact; it has no VAT number and nobody to
     * address an invoice to. Those live on PM's link record so a CRM refresh cannot wipe them —
     * refreshing merges CRM's fields over the snapshot and leaves everything else alone.
     *
     * @param  array<string, mixed>  $details
     */
    public function rememberInvoicingDetails(ProvidedRecord $record, array $details): void
    {
        if ($details === []) {
            return;
        }

        /*
         * By source id or by the ref's own uuid, because a client is one or the other.
         *
         * A CRM-owned client is a ref pointing at an account, so `source_id` is the account's
         * uuid and matching on it works. A PM-local client *is* the ref — LocalClientProvider
         * reports the ref's own uuid as the record id and sets no source_id at all — so this
         * matched nothing and returned silently, and the VAT number somebody had just typed was
         * dropped on the floor. It looked like the field was not being saved; it was never being
         * found.
         */
        $ref = ExternalRef::query()
            ->where('local_type', 'project_client')
            ->where(fn ($query) => $query
                ->where('source_id', $record->id)
                ->orWhere('uuid', $record->id))
            ->first();

        if ($ref) {
            $ref->update(['snapshot' => array_merge($ref->snapshot ?? [], $details)]);
        }
    }
}
