<?php

namespace App\Modules\Agri\MachineData;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\FieldBoundaryVersion;
use App\Modules\Agri\Models\IntegrationEvent;
use App\Modules\Agri\Models\MachineConflict;
use App\Modules\Agri\Models\MachineConnection;
use App\Modules\Agri\Models\MachineLink;
use App\Modules\Agri\Models\MachineOperation;
use App\Modules\Agri\Models\Prescription;
use App\Modules\Agri\Services\FieldMaps;
use App\Modules\Agri\Services\FieldWork;
use App\Modules\Agri\Services\Integrations;
use App\Modules\Agri\Services\Machinery;
use App\Modules\Agri\Services\PrescriptionFiles;
use App\Modules\Agri\Support\Geo;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * What flows between the farm and John Deere Operations Center or CNH FieldOps.
 *
 * In: their fields are paired with ours (by name or code, or by a person) and a boundary changed
 * there becomes a new version of ours, with its source noted; their machines are paired with our
 * Fleet machines by serial number or VIN, and their engine hours and positions feed the machine's
 * meter through the ordinary telematics message, so hours drive service due as before; their
 * operations come in one by one — an application or seeding becomes (or fills) a field activity
 * and its inputs, a harvest its yield figures — each keyed on their operation id and kept with its
 * raw payload, so importing it again updates it and never doubles it.
 *
 * Out: our boundaries and approved prescriptions are sent, each as one integration message whose
 * link remembers the id it got there.
 *
 * A boundary changed on both sides since they last agreed is never overwritten either way: it is
 * held as a conflict for a person to resolve. Every call goes through the integration log
 * (machine_pull, machine_import, machine_push), with its back-off and retry.
 */
class MachineDataSync
{
    public function __construct(private readonly MachineConnections $connections) {}

    // ── running a sync ──────────────────────────────────────────────────────────────────────

    /** Queue (and run) a pull. $trigger: "now" for a person's Sync now, "schedule" for the hourly run. */
    public function sync(MachineConnection $c, string $trigger = 'schedule', ?User $actor = null): IntegrationEvent
    {
        $key = $trigger === 'schedule' ? 'hour:'.now()->format('YmdH') : 'now:'.Str::uuid();
        ['event' => $e] = app(Integrations::class)->receive((int) $c->company_id, 'machine_pull', "{$c->provider}:{$c->id}:{$key}",
            ['connection_id' => $c->id, 'provider' => $c->provider, 'actor_id' => $actor?->id]);

        return $e;
    }

    /** The machine_pull handler: everything in, then the status of what was sent. */
    public function pull(array $p, IntegrationEvent $e): array
    {
        $c = MachineConnection::withoutGlobalScopes()->where('company_id', $e->company_id)->findOrFail($p['connection_id'] ?? 0);
        if (! $c->isConnected()) {
            throw new MachineApiError($c->platformName().' is not connected'.($c->status === 'needs_org_access' ? ': the organisation has not granted the app access yet.' : '.'));
        }
        $actor = $this->actor($c, $p['actor_id'] ?? null);
        $connector = $this->connections->connector($c);
        $o = $c->options();
        $n = ['fields' => 0, 'fields_unmatched' => 0, 'boundaries_updated' => 0, 'conflicts' => 0, 'machines' => 0, 'machines_unmatched' => 0,
            'readings' => 0, 'operations' => 0, 'operations_waiting' => 0, 'prescriptions_checked' => 0];
        $started = now();
        try {
            if ($o['import_boundaries']) {
                foreach ($connector->fields() as $f) {
                    $r = DB::transaction(fn () => $this->importField($c, $f, $actor));
                    $n['fields']++;
                    $n['fields_unmatched'] += $r === 'unmatched' ? 1 : 0;
                    $n['boundaries_updated'] += $r === 'updated' ? 1 : 0;
                    $n['conflicts'] += $r === 'conflict' ? 1 : 0;
                }
            }
            if ($o['import_machines']) {
                foreach ($connector->machines() as $m) {
                    $linked = DB::transaction(fn () => $this->importMachine($c, $m));
                    $n['machines']++;
                    $n['machines_unmatched'] += $linked ? 0 : 1;
                }
            }
            if ($o['import_telemetry']) {
                $n['readings'] = $this->importReadings($c, $connector);
            }
            if ($o['import_operations']) {
                $since = isset($c->cursors['operations_since']) ? Carbon::parse($c->cursors['operations_since']) : now()->subDays((int) config('agri.machine_data.first_sync_days', 120));
                $fieldIds = MachineLink::withoutGlobalScopes()->where('connection_id', $c->id)->where('kind', MachineLink::FIELD)->whereIn('status', ['linked', 'unmatched'])->pluck('external_id')->all();
                foreach ($fieldIds ? $connector->operations($since, $fieldIds) : [] as $op) {
                    $ev = $this->queueOperation($c, $op, $actor);
                    $n['operations']++;
                    $row = MachineOperation::withoutGlobalScopes()->where('connection_id', $c->id)->where('external_id', (string) $op['id'])->first();
                    $n['operations_waiting'] += $ev->status !== 'processed' || in_array($row?->status, ['needs_field', 'needs_product'], true) ? 1 : 0;
                }
                // The next pull looks again over the last day: an operation still being uploaded from the cab comes complete later.
                $c->cursors = array_merge($c->cursors ?? [], ['operations_since' => $started->copy()->subDay()->toIso8601String()]);
            }
            $n['prescriptions_checked'] = $this->checkSent($c, $connector);
        } catch (\Throwable $x) {
            $c->forceFill(['last_error' => mb_strimwidth($x->getMessage(), 0, 500)])->saveQuietly();
            throw $x;
        }
        $c->forceFill(['last_sync_at' => now(), 'last_sync' => $n, 'last_error' => null])->saveQuietly();

        return $n;
    }

    // ── fields and boundaries ───────────────────────────────────────────────────────────────

    /** @return string linked | unmatched | updated | conflict | same | ignored */
    public function importField(MachineConnection $c, array $f, User $actor): string
    {
        $link = MachineLink::withoutGlobalScopes()->firstOrNew(['connection_id' => $c->id, 'kind' => MachineLink::FIELD, 'external_id' => (string) $f['id']],
            ['company_id' => $c->company_id, 'status' => 'unmatched']);
        $link->external_name = mb_strimwidth((string) $f['name'], 0, 200);
        $link->data = ['name' => $f['name'], 'area_ha' => $f['area_ha'] ?? null, 'boundary' => $f['boundary'] ?? null, 'modified_at' => $f['modified_at'] ?? null];
        if ($link->status === 'ignored') {
            $link->save();

            return 'ignored';
        }
        $field = $link->status === 'linked' && $link->local_id ? $this->fieldById($c, (int) $link->local_id) : null;
        if (! $field) {
            [$field, $by] = $this->matchField($c, (string) $f['name']);
            if (! $field) {
                $link->forceFill(['status' => 'unmatched', 'local_id' => null, 'matched_by' => null])->save();

                return 'unmatched';
            }
            $link->forceFill(['status' => 'linked', 'local_id' => $field->id, 'matched_by' => $by]);
        }
        $link->synced_at = now();
        $link->save();

        return $this->reconcile($c, $link, $field, $f['boundary'] ?? null, $actor);
    }

    /**
     * Their boundary against ours, against what each was when they last agreed. One side changed:
     * it wins (theirs becomes our next version; ours is sent if the farm chose that). Both changed,
     * or the first pairing of two different shapes: a conflict for a person.
     */
    public function reconcile(MachineConnection $c, MachineLink $link, FarmField $field, ?array $theirs, User $actor): string
    {
        $theirs = $theirs ? Geo::polygon($theirs) : null;
        $ours = Geo::polygon($field->boundary);
        $th = self::hash($theirs);
        $oh = self::hash($ours);
        if (! $theirs) {
            if ($ours && $c->options()['push_boundaries'] && $link->local_hash !== $oh) {
                $this->queueBoundary($c, $field, $actor);
            }

            return 'linked';
        }
        if ($th === $oh) {
            $link->forceFill(['external_hash' => $th, 'local_hash' => $oh])->save();
            $this->closeConflicts($link, 'took_theirs', null);

            return 'same';
        }
        $first = $link->external_hash === null && $link->local_hash === null;
        $theirsChanged = $link->external_hash !== $th;
        $oursChanged = $link->local_hash !== $oh;

        if (($first && ! $ours) || (! $first && $theirsChanged && ! $oursChanged)) {
            return $this->takeTheirs($c, $link, $field, $theirs, $actor, null) ? 'updated' : 'conflict';
        }
        if (! $first && ! $theirsChanged && $oursChanged) {
            if ($c->options()['push_boundaries']) {
                $this->queueBoundary($c, $field, $actor);
            }

            return 'linked';
        }
        // Both changed (or two different shapes meet for the first time): keep both, ask a person.
        $conflict = MachineConflict::withoutGlobalScopes()->firstOrNew(['connection_id' => $c->id, 'link_id' => $link->id, 'status' => MachineConflict::OPEN],
            ['company_id' => $c->company_id, 'kind' => 'boundary', 'field_id' => $field->id]);
        $conflict->forceFill(['ours' => $ours, 'theirs' => $theirs, 'ours_ha' => $ours ? Geo::hectares($ours) : null, 'theirs_ha' => Geo::hectares($theirs)])->save();

        return 'conflict';
    }

    /** Their boundary as our next version, its source noted. False when it is not a usable shape. */
    private function takeTheirs(MachineConnection $c, MachineLink $link, FarmField $field, array $theirs, User $actor, ?string $reason): bool
    {
        try {
            app(FieldMaps::class)->setFieldBoundary($field, $theirs, $actor, FieldBoundaryVersion::IMPORTED,
                mb_strimwidth($c->platformName().': '.($link->external_name ?? $link->external_id), 0, 200), $reason ?? 'Boundary changed in '.$c->platformName().'.');
        } catch (ValidationException $e) {
            $link->forceFill(['last_error' => mb_strimwidth('Their boundary was not taken: '.collect($e->errors())->flatten()->implode(' '), 0, 1000)])->save();

            return false;
        }
        $field->refresh();
        $link->forceFill(['external_hash' => self::hash($theirs), 'local_hash' => self::hash(Geo::polygon($field->boundary)), 'last_error' => null])->save();

        return true;
    }

    public function resolve(MachineConflict $conflict, string $choice, User $actor): MachineConflict
    {
        if ($conflict->status !== MachineConflict::OPEN) {
            throw ValidationException::withMessages(['conflict' => 'That conflict has already been resolved.']);
        }
        $c = MachineConnection::withoutGlobalScopes()->where('company_id', $conflict->company_id)->findOrFail($conflict->connection_id);
        $link = MachineLink::withoutGlobalScopes()->where('connection_id', $c->id)->findOrFail($conflict->link_id);
        $field = $this->fieldById($c, (int) $conflict->field_id) ?? throw ValidationException::withMessages(['conflict' => 'The field is no longer there.']);

        return DB::transaction(function () use ($conflict, $choice, $actor, $c, $link, $field) {
            if ($choice === 'theirs') {
                if (! $this->takeTheirs($c, $link, $field, $conflict->theirs, $actor, 'Conflict resolved by '.$actor->name.': took the boundary from '.$c->platformName().'.')) {
                    throw ValidationException::withMessages(['conflict' => $link->last_error]);
                }
                $this->closeConflicts($link, 'took_theirs', $actor);
            } else {
                // Ours stands; theirs as it is now is what we last saw, and ours goes to them.
                $link->forceFill(['external_hash' => self::hash($conflict->theirs), 'local_hash' => self::hash(Geo::polygon($field->boundary))])->save();
                $this->closeConflicts($link, 'kept_ours', $actor);
                if ($c->isConnected() && Geo::polygon($field->boundary)) {
                    $this->queueBoundary($c, $field, $actor);
                }
            }

            return $conflict->fresh();
        });
    }

    private function closeConflicts(MachineLink $link, string $status, ?User $actor): void
    {
        MachineConflict::withoutGlobalScopes()->where('link_id', $link->id)->where('status', MachineConflict::OPEN)
            ->update(['status' => $status, 'resolved_by' => $actor?->id, 'resolved_at' => now(), 'updated_at' => now()]);
    }

    /** A person pairs their field with ours; the boundaries are reconciled and waiting operations re-read. */
    public function linkField(MachineLink $link, FarmField $field, User $actor): string
    {
        $c = MachineConnection::withoutGlobalScopes()->where('company_id', $link->company_id)->findOrFail($link->connection_id);
        $taken = MachineLink::withoutGlobalScopes()->where('connection_id', $c->id)->where('kind', MachineLink::FIELD)->where('status', 'linked')
            ->where('local_id', $field->id)->whereKeyNot($link->id)->first();
        if ($taken) {
            throw ValidationException::withMessages(['field_id' => "{$field->code} is already paired with {$taken->external_name}."]);
        }
        $r = DB::transaction(function () use ($c, $link, $field, $actor) {
            $link->forceFill(['status' => 'linked', 'local_id' => $field->id, 'matched_by' => 'person', 'external_hash' => null, 'local_hash' => null])->save();

            return $this->reconcile($c, $link, $field, $link->data['boundary'] ?? null, $actor);
        });
        $this->reapplyWaiting($c);

        return $r;
    }

    /** Their field becomes a new field of ours on the given farm, with their boundary as version 1. */
    public function createField(MachineLink $link, Farm $farm, string $code, ?string $name, User $actor): FarmField
    {
        $c = MachineConnection::withoutGlobalScopes()->where('company_id', $link->company_id)->findOrFail($link->connection_id);
        $theirs = Geo::polygon($link->data['boundary'] ?? null);
        if (! $theirs) {
            throw ValidationException::withMessages(['field' => 'Their field has no boundary to make a field from; pair it with one of ours instead.']);
        }
        $field = DB::transaction(function () use ($c, $link, $farm, $code, $name, $theirs, $actor) {
            [$field] = app(FieldMaps::class)->createField($farm, ['code' => $code, 'name' => $name ?: ($link->external_name ?? $code)], $theirs, $actor,
                FieldBoundaryVersion::IMPORTED, mb_strimwidth($c->platformName().': '.($link->external_name ?? $link->external_id), 0, 200));
            $link->forceFill(['status' => 'linked', 'local_id' => $field->id, 'matched_by' => 'created', 'external_hash' => self::hash($theirs),
                'local_hash' => self::hash(Geo::polygon($field->boundary))])->save();

            return $field;
        });
        $this->reapplyWaiting($c);

        return $field;
    }

    /** Our boundary to their platform: an update of the field paired with it, or a new field there. */
    public function queueBoundary(MachineConnection $c, FarmField $field, ?User $actor = null): IntegrationEvent
    {
        $hash = self::hash(Geo::polygon($field->boundary));
        ['event' => $e] = app(Integrations::class)->receive((int) $c->company_id, 'machine_push', "{$c->provider}:boundary:{$field->id}:".substr((string) $hash, 0, 16),
            ['connection_id' => $c->id, 'type' => 'boundary', 'local_id' => $field->id, 'actor_id' => $actor?->id]);

        return $e;
    }

    // ── machines ─────────────────────────────────────────────────────────────────────────────

    /** @return bool paired */
    public function importMachine(MachineConnection $c, array $m): bool
    {
        $link = MachineLink::withoutGlobalScopes()->firstOrNew(['connection_id' => $c->id, 'kind' => MachineLink::MACHINE, 'external_id' => (string) $m['id']],
            ['company_id' => $c->company_id, 'status' => 'unmatched']);
        $link->external_name = mb_strimwidth((string) $m['name'], 0, 200);
        $link->data = array_intersect_key($m, array_flip(['name', 'serial', 'vin', 'make', 'model', 'type']));
        if ($link->status === 'ignored') {
            $link->save();

            return false;
        }
        $machine = $link->status === 'linked' && $link->local_id ? $this->machineById($c, (int) $link->local_id) : null;
        if (! $machine) {
            [$machine, $by] = $this->matchMachine($c, $m['serial'] ?? null, $m['vin'] ?? null);
            if (! $machine) {
                $link->forceFill(['status' => 'unmatched', 'local_id' => null, 'matched_by' => null, 'synced_at' => now()])->save();

                return false;
            }
            $link->forceFill(['status' => 'linked', 'local_id' => $machine->id, 'matched_by' => $by]);
        }
        $link->synced_at = now();
        $link->save();
        $this->adoptTelematics($c, $link, $machine);

        return true;
    }

    public function linkMachine(MachineLink $link, FarmMachine $machine): MachineLink
    {
        $c = MachineConnection::withoutGlobalScopes()->where('company_id', $link->company_id)->findOrFail($link->connection_id);
        $taken = MachineLink::withoutGlobalScopes()->where('connection_id', $c->id)->where('kind', MachineLink::MACHINE)->where('status', 'linked')
            ->where('local_id', $machine->id)->whereKeyNot($link->id)->first();
        if ($taken) {
            throw ValidationException::withMessages(['machine_id' => "{$machine->code} is already paired with {$taken->external_name}."]);
        }
        $link->forceFill(['status' => 'linked', 'local_id' => $machine->id, 'matched_by' => 'person'])->save();
        $this->adoptTelematics($c, $link, $machine);

        return $link;
    }

    /** Their machine registered as one of ours (a Fleet asset on the farm), with its serial and VIN. */
    public function createMachine(MachineLink $link, Farm $farm, string $code): FarmMachine
    {
        $d = $link->data ?? [];
        $type = strtolower((string) ($d['type'] ?? '').' '.($d['name'] ?? ''));
        $machineType = match (true) {
            str_contains($type, 'combine') || str_contains($type, 'harvest') => 'harvester',
            str_contains($type, 'spray') => 'sprayer',
            str_contains($type, 'plant') || str_contains($type, 'seed') || str_contains($type, 'drill') => 'planter',
            str_contains($type, 'tractor') => 'tractor',
            default => 'other',
        };

        return DB::transaction(function () use ($link, $farm, $code, $d, $machineType) {
            $m = app(Machinery::class)->register($farm, array_filter(['code' => $code, 'name' => mb_strimwidth((string) ($d['name'] ?? $code), 0, 160), 'machine_type' => $machineType,
                'make' => isset($d['make']) ? mb_strimwidth((string) $d['make'], 0, 60) : null, 'model' => isset($d['model']) ? mb_strimwidth((string) $d['model'], 0, 60) : null,
                'serial_number' => isset($d['serial']) ? mb_strimwidth((string) $d['serial'], 0, 80) : null, 'vin_number' => isset($d['vin']) ? mb_strimwidth((string) $d['vin'], 0, 30) : null,
                'hours_reading' => 0], fn ($v) => $v !== null));
            $link->forceFill(['matched_by' => 'created'])->save();
            $this->linkMachine($link, $m);
            $link->forceFill(['matched_by' => 'created'])->save();

            return $m;
        });
    }

    /** Readings go in as ordinary telematics messages, so the meter rules (forward only, a jump flagged) are the usual ones. */
    private function importReadings(MachineConnection $c, MachineConnector $connector): int
    {
        $n = 0;
        foreach (MachineLink::withoutGlobalScopes()->where('connection_id', $c->id)->where('kind', MachineLink::MACHINE)->where('status', 'linked')->get() as $link) {
            $machine = $this->machineById($c, (int) $link->local_id);
            if (! $machine || $machine->status === FarmMachine::DISPOSED) {
                continue;
            }
            $r = $connector->reading($link->external_id);
            if (! $r || ($r['engine_hours'] === null && $r['lat'] === null)) {
                continue;
            }
            $machine = $this->adoptTelematics($c, $link, $machine);
            $at = $r['recorded_at'] ?? now()->toIso8601String();
            app(Integrations::class)->receive((int) $c->company_id, 'telematics', "{$c->provider}:{$link->external_id}:{$at}",
                array_filter(['ref' => $machine->telematics_ref, 'engine_hours' => $r['engine_hours'], 'latitude' => $r['lat'], 'longitude' => $r['lng'], 'recorded_at' => $at], fn ($v) => $v !== null), null, (int) $machine->farm_id);
            $n++;
        }

        return $n;
    }

    /** A paired machine with no telematics reference of its own takes the platform's id as one. */
    private function adoptTelematics(MachineConnection $c, MachineLink $link, FarmMachine $machine): FarmMachine
    {
        if (blank($machine->telematics_ref)) {
            $machine->forceFill(['telematics_ref' => mb_strimwidth("{$c->provider}:{$link->external_id}", 0, 80)])->saveQuietly();
        }

        return $machine;
    }

    // ── operations ───────────────────────────────────────────────────────────────────────────

    /** One operation in, keyed on its id and content: the same version again is answered, a changed one updates. */
    public function queueOperation(MachineConnection $c, array $op, ?User $actor = null): IntegrationEvent
    {
        $hash = hash('sha256', json_encode($op['raw'] ?? $op));
        $field = MachineLink::withoutGlobalScopes()->where('connection_id', $c->id)->where('kind', MachineLink::FIELD)->where('external_id', (string) ($op['field_id'] ?? ''))->value('local_id');
        ['event' => $e] = app(Integrations::class)->receive((int) $c->company_id, 'machine_import', "{$c->provider}:op:{$op['id']}:".substr($hash, 0, 16),
            ['connection_id' => $c->id, 'actor_id' => $actor?->id, 'op' => $op], null,
            $field ? (int) FarmField::withoutGlobalScopes()->whereKey($field)->value('farm_id') : null);

        return $e;
    }

    /** The machine_import handler. */
    public function applyOperation(array $p, IntegrationEvent $e): array
    {
        $c = MachineConnection::withoutGlobalScopes()->where('company_id', $e->company_id)->findOrFail($p['connection_id'] ?? 0);
        $op = $p['op'];
        $actor = $this->actor($c, $p['actor_id'] ?? null);
        $row = MachineOperation::withoutGlobalScopes()->firstOrNew(['connection_id' => $c->id, 'external_id' => (string) $op['id']], ['company_id' => $c->company_id]);
        $h = $op['harvest'] ?? null;
        $row->fill([
            'operation_type' => $op['type'], 'external_field_id' => $op['field_id'] ?? null, 'external_machine_id' => $op['machine_id'] ?? null,
            'operator' => isset($op['operator']) ? mb_strimwidth((string) $op['operator'], 0, 160) : null,
            'started_at' => isset($op['started_at']) ? Carbon::parse($op['started_at']) : null, 'ended_at' => isset($op['ended_at']) ? Carbon::parse($op['ended_at']) : null,
            'area_ha' => $op['area_ha'] ?? null, 'products' => $op['products'] ?? [], 'rate_points' => ($op['rate_points'] ?? []) ?: null,
            'total' => $h['total'] ?? null, 'total_unit' => $h['unit'] ?? null, 'yield_per_ha' => $h['yield_per_ha'] ?? (($h['total'] ?? null) && ($op['area_ha'] ?? 0) > 0 ? round($h['total'] / $op['area_ha'], 4) : null),
            'moisture_pct' => $h['moisture_pct'] ?? null, 'zones' => $h['zones'] ?? null,
            'raw' => $op['raw'] ?? $op, 'payload_hash' => hash('sha256', json_encode($op['raw'] ?? $op)), 'event_id' => $e->id,
        ]);

        $fieldLink = MachineLink::withoutGlobalScopes()->where('connection_id', $c->id)->where('kind', MachineLink::FIELD)->where('external_id', (string) ($op['field_id'] ?? ''))->where('status', 'linked')->first();
        $field = $fieldLink ? $this->fieldById($c, (int) $fieldLink->local_id) : null;
        if (! $field) {
            $row->forceFill(['status' => 'needs_field', 'note' => 'Their field is not paired with one of ours yet; pair it and this operation is read again.'])->save();

            return ['operation' => $row->uuid, 'status' => $row->status];
        }
        $machineId = ($op['machine_id'] ?? null) ? MachineLink::withoutGlobalScopes()->where('connection_id', $c->id)->where('kind', MachineLink::MACHINE)
            ->where('external_id', (string) $op['machine_id'])->where('status', 'linked')->value('local_id') : null;
        $row->forceFill(['farm_id' => $field->farm_id, 'field_id' => $field->id, 'machine_id' => $machineId]);

        if ($op['type'] === 'harvest') {
            $row->forceFill(['status' => $row->harvest_record_id ? 'applied' : 'imported', 'note' => $row->harvest_record_id ? null : 'Yield figures recorded; record them as a harvest when the crop is weighed.'])->save();

            return ['operation' => $row->uuid, 'status' => $row->status, 'yield_per_ha' => $row->yield_per_ha];
        }

        // Application or seeding: every product must be one of our stock items.
        $products = [];
        $missing = [];
        foreach ($op['products'] ?? [] as $prod) {
            $item = $this->productItem($c, (string) $prod['name']);
            $item ? $products[] = $prod + ['stock_item_id' => $item->id] : $missing[] = $prod['name'];
        }
        $row->products = array_map(fn ($prod) => $prod + ['stock_item_id' => collect($products)->firstWhere('name', $prod['name'])['stock_item_id'] ?? null], $op['products'] ?? []);
        if ($missing || ! $products) {
            $row->forceFill(['status' => 'needs_product', 'note' => $missing ? 'Match their product(s) to our stock items: '.implode(', ', $missing).'.' : 'The operation names no product.'])->save();

            return ['operation' => $row->uuid, 'status' => $row->status];
        }

        $note = $this->intoActivity($c, $row, $field, $products, $op, $actor);
        $row->forceFill(['status' => 'applied', 'note' => $note])->save();

        return ['operation' => $row->uuid, 'status' => 'applied', 'activity' => FieldActivity::withoutGlobalScopes()->whereKey($row->activity_id)->value('uuid')];
    }

    /**
     * Create or fill the field activity an application or seeding is: the one it already filled, a
     * planned activity made from a prescription for the same product, or a new one. Inputs that
     * are still planned take the as-applied quantities; issued ones are part of the stock record
     * and stay as they are.
     */
    private function intoActivity(MachineConnection $c, MachineOperation $row, FarmField $field, array $products, array $op, User $actor): ?string
    {
        $farm = Farm::withoutGlobalScopes()->findOrFail($field->farm_id);
        $itemIds = array_column($products, 'stock_item_id');
        $activity = $row->activity_id ? FieldActivity::withoutGlobalScopes()->find($row->activity_id) : null;
        $rx = null;
        if (! $activity) {
            $rx = Prescription::withoutGlobalScopes()->where('company_id', $c->company_id)->where('field_id', $field->id)->whereIn('stock_item_id', $itemIds)
                ->whereIn('status', [Prescription::APPROVED, Prescription::SENT])->whereNotNull('activity_id')->orderByDesc('approved_at')->get()
                ->first(fn ($p) => FieldActivity::withoutGlobalScopes()->whereKey($p->activity_id)->whereIn('status', [FieldActivity::PLANNED, FieldActivity::IN_PROGRESS])->exists());
            $activity = $rx ? FieldActivity::withoutGlobalScopes()->find($rx->activity_id) : null;
        } elseif ($row->prescription_id) {
            $rx = Prescription::withoutGlobalScopes()->find($row->prescription_id);
        }
        $when = $row->started_at ?? now();
        if (! $activity) {
            $plan = CropPlan::withoutGlobalScopes()->where('field_id', $field->id)->whereIn('status', CropPlan::WORKABLE)->orderByDesc('id')->first();
            $first = $products[0];
            $type = $op['type'] === 'seeding' ? 'planting' : (($first['unit'] ?? null) === 'l_ha' ? 'spraying' : 'fertilising');
            $activity = app(FieldWork::class)->create($farm, $field, $plan, null, [
                'activity_type' => $type, 'title' => mb_strimwidth($first['name'].' ('.$c->platformName().')', 0, 200), 'planned_on' => $when->toDateString(),
                'notes' => 'Imported from '.$c->platformName()." operation {$op['id']}.",
            ], $actor);
        }
        $lines = [];
        foreach ($products as $prod) {
            $factor = $rx && (int) $rx->stock_item_id === (int) $prod['stock_item_id'] ? max(0.0001, (float) $rx->per_stock_unit) : 1.0;
            $total = $prod['total'] ?? (($prod['rate'] ?? 0) * ($prod['area_ha'] ?? $row->area_ha ?? 0));
            $qty = round((float) $total / $factor, 4);
            if ($qty <= 0) {
                continue;
            }
            $existing = $activity->inputs()->withoutGlobalScopes()->where('stock_item_id', $prod['stock_item_id'])->whereIn('status', ['planned', 'issued'])->get();
            $planned = $existing->firstWhere('status', 'planned');
            if ($planned) {
                $planned->update(['quantity' => $qty, 'rate_per_ha' => isset($prod['rate']) ? round($prod['rate'] / $factor, 4) : $planned->rate_per_ha]);
            } elseif ($existing->isEmpty()) {
                app(FieldWork::class)->addInput($activity, FinStockItem::withoutGlobalScopes()->findOrFail($prod['stock_item_id']),
                    ['quantity' => $qty, 'rate_per_ha' => isset($prod['rate']) ? round($prod['rate'] / $factor, 4) : null]);
            } else {
                $lines[] = "{$prod['name']} was already issued; the as-applied {$qty} is on the operation for comparison.";
            }
        }
        $activity->forceFill(array_filter([
            'status' => $activity->status === FieldActivity::PLANNED ? FieldActivity::IN_PROGRESS : null,
            'area_covered' => $row->area_ha, 'machine_id' => $row->machine_id,
            'notes' => str_contains((string) $activity->notes, (string) $op['id']) ? null : trim(($activity->notes ? $activity->notes."\n" : '').'As applied, from '.$c->platformName()." operation {$op['id']}."),
        ], fn ($v) => $v !== null))->save();
        if ($row->operator && ! str_contains((string) $activity->notes, 'Operator')) {
            $activity->forceFill(['notes' => trim($activity->notes."\nOperator: {$row->operator}.")])->save();
        }
        $row->forceFill(['activity_id' => $activity->id, 'prescription_id' => $rx?->id ?? $row->prescription_id]);
        if ($rx && in_array($rx->status, [Prescription::APPROVED, Prescription::SENT], true)) {
            $rx->forceFill(['status' => Prescription::APPLIED])->save();
        }
        if ($c->options()['complete_imported'] && $activity->status !== FieldActivity::COMPLETED) {
            try {
                app(FieldWork::class)->complete($activity->fresh(), ['completed_at' => ($row->ended_at ?? $when)->toIso8601String(), 'area_covered' => $row->area_ha], $actor);
            } catch (ValidationException $x) {
                $lines[] = 'Not completed automatically: '.collect($x->errors())->flatten()->implode(' ');
            }
        }

        return $lines ? mb_strimwidth(implode(' ', $lines), 0, 500) : null;
    }

    /** A product name of theirs as one of our stock items: as a person paired it, or by exact name or code. */
    private function productItem(MachineConnection $c, string $name): ?FinStockItem
    {
        $key = mb_strtolower(trim($name));
        $link = MachineLink::withoutGlobalScopes()->firstOrNew(['connection_id' => $c->id, 'kind' => MachineLink::PRODUCT, 'external_id' => mb_strimwidth($key, 0, 160)],
            ['company_id' => $c->company_id, 'status' => 'unmatched', 'external_name' => mb_strimwidth($name, 0, 200)]);
        if ($link->status === 'linked' && $link->local_id && ($item = FinStockItem::withoutGlobalScopes()->where('company_id', $c->company_id)->find($link->local_id))) {
            return $item;
        }
        $item = FinStockItem::withoutGlobalScopes()->where('company_id', $c->company_id)->where('is_active', true)
            ->where(fn ($q) => $q->whereRaw('lower(name) = ?', [$key])->orWhereRaw('lower(code) = ?', [$key]))->first();
        $link->forceFill($item ? ['status' => 'linked', 'local_id' => $item->id, 'matched_by' => 'name'] : ['status' => 'unmatched'])->save();

        return $item;
    }

    public function linkProduct(MachineLink $link, FinStockItem $item): MachineLink
    {
        $link->forceFill(['status' => 'linked', 'local_id' => $item->id, 'matched_by' => 'person'])->save();
        $this->reapplyWaiting(MachineConnection::withoutGlobalScopes()->findOrFail($link->connection_id));

        return $link;
    }

    /** Operations that waited for a field or a product, read again from the message that brought them. */
    public function reapplyWaiting(MachineConnection $c): int
    {
        $n = 0;
        foreach (MachineOperation::withoutGlobalScopes()->where('connection_id', $c->id)->whereIn('status', ['needs_field', 'needs_product'])->get() as $row) {
            $n += $this->reapply($row) ? 1 : 0;
        }

        return $n;
    }

    public function reapply(MachineOperation $row): bool
    {
        $e = IntegrationEvent::withoutGlobalScopes()->where('company_id', $row->company_id)->find($row->event_id);
        if (! $e) {
            return false;
        }
        DB::transaction(fn () => $this->applyOperation($e->payload, $e));

        return true;
    }

    /** Harvest figures from the combine recorded as a harvest on the field's crop. */
    public function recordHarvest(MachineOperation $row, array $data, User $actor): \App\Modules\Agri\Models\HarvestRecord
    {
        if ($row->operation_type !== 'harvest') {
            throw ValidationException::withMessages(['operation' => 'Only a harvest operation becomes a harvest record.']);
        }
        if ($row->harvest_record_id) {
            throw ValidationException::withMessages(['operation' => 'This harvest is already recorded.']);
        }
        if (! $row->field_id) {
            throw ValidationException::withMessages(['operation' => 'Pair their field with one of ours first.']);
        }
        $plan = CropPlan::query()->where('field_id', $row->field_id)->whereIn('status', [CropPlan::PLANTED, CropPlan::HARVESTING])->orderByDesc('id')->first()
            ?? throw ValidationException::withMessages(['crop_plan_id' => 'No planted crop on that field to harvest.']);
        $qty = (float) ($data['quantity'] ?? $row->total);
        if ($qty <= 0) {
            throw ValidationException::withMessages(['quantity' => 'The operation has no total yield; give the quantity.']);
        }

        return DB::transaction(function () use ($row, $data, $actor, $plan, $qty) {
            $record = app(\App\Modules\Agri\Services\Harvests::class)->record($plan, [
                'harvested_on' => ($row->ended_at ?? $row->started_at ?? now())->toDateString(), 'quantity' => $qty, 'unit' => $data['unit'] ?? $row->total_unit ?? $plan->yield_unit,
                'moisture_pct' => $row->moisture_pct, 'area_harvested' => $row->area_ha, 'destination' => $data['destination'] ?? 'store',
                'stock_item_id' => $data['stock_item_id'] ?? null, 'store_id' => $data['store_id'] ?? null, 'unit_value' => $data['unit_value'] ?? null,
                'measuring_method' => 'yield_monitor', 'vehicle_ref' => $row->machine_id ? FarmMachine::withoutGlobalScopes()->whereKey($row->machine_id)->value('fleet_number') : null,
                'notes' => 'From the yield monitor ('.MachineConnection::withoutGlobalScopes()->find($row->connection_id)?->platformName().' operation '.$row->external_id.').',
            ], $actor);
            $row->forceFill(['harvest_record_id' => $record->id, 'status' => 'applied', 'note' => null])->save();

            return $record;
        });
    }

    // ── out ──────────────────────────────────────────────────────────────────────────────────

    /** An approved prescription to the platform, as one message (sent again only if it failed). */
    public function queuePrescription(MachineConnection $c, Prescription $rx, User $actor): IntegrationEvent
    {
        if (! $rx->isApproved()) {
            throw ValidationException::withMessages(['prescription' => 'A prescription is sent to a machine only once somebody other than who drafted it has approved this version.']);
        }
        if (! $c->isConnected()) {
            throw ValidationException::withMessages(['provider' => 'Connect '.$c->platformName().' and choose its organisation first.']);
        }
        $local = "local:{$rx->uuid}:v{$rx->version}";
        $link = MachineLink::withoutGlobalScopes()->where('connection_id', $c->id)->where('kind', MachineLink::PRESCRIPTION)->where('local_id', $rx->id)
            ->where('data->version', $rx->version)->first()
            ?? MachineLink::withoutGlobalScopes()->create(['company_id' => $c->company_id, 'connection_id' => $c->id, 'kind' => MachineLink::PRESCRIPTION, 'external_id' => $local,
                'external_name' => mb_strimwidth("{$rx->code} {$rx->name}", 0, 200), 'local_id' => $rx->id, 'status' => 'pending', 'data' => ['version' => $rx->version]]);
        ['event' => $e] = app(Integrations::class)->receive((int) $c->company_id, 'machine_push', "{$c->provider}:prescription:{$rx->id}:v{$rx->version}",
            ['connection_id' => $c->id, 'type' => 'prescription', 'local_id' => $rx->id, 'version' => $rx->version, 'link_id' => $link->id, 'actor_id' => $actor->id], null, (int) $rx->farm_id);
        if ((int) $link->event_id !== (int) $e->id) {
            $link->forceFill(['event_id' => $e->id])->saveQuietly();
        }

        return $e;
    }

    /** The machine_push handler. A record that already has its id there is not sent again. */
    public function push(array $p, IntegrationEvent $e): array
    {
        $c = MachineConnection::withoutGlobalScopes()->where('company_id', $e->company_id)->findOrFail($p['connection_id'] ?? 0);
        if (! $c->isConnected()) {
            throw new MachineApiError($c->platformName().' is not connected.');
        }
        $connector = $this->connections->connector($c);

        if (($p['type'] ?? null) === 'boundary') {
            $field = $this->fieldById($c, (int) $p['local_id']) ?? throw new \RuntimeException('The field is no longer there.');
            $g = Geo::polygon($field->boundary) ?? throw new \RuntimeException("{$field->code} has no boundary to send.");
            $link = MachineLink::withoutGlobalScopes()->where('connection_id', $c->id)->where('kind', MachineLink::FIELD)->where('status', 'linked')->where('local_id', $field->id)->first();
            $name = trim("{$field->code} {$field->name}");
            if ($link) {
                $connector->putBoundary($link->external_id, $name, $g);
            } else {
                $id = $connector->createField($name, $g);
                $link = MachineLink::withoutGlobalScopes()->create(['company_id' => $c->company_id, 'connection_id' => $c->id, 'kind' => MachineLink::FIELD, 'external_id' => $id,
                    'external_name' => mb_strimwidth($name, 0, 200), 'local_id' => $field->id, 'status' => 'linked', 'matched_by' => 'created']);
            }
            $h = self::hash($g);
            $link->forceFill(['external_hash' => $h, 'local_hash' => $h, 'synced_at' => now(), 'last_error' => null])->save();

            return ['field' => $field->code, 'external_id' => $link->external_id];
        }

        $rx = Prescription::withoutGlobalScopes()->where('company_id', $c->company_id)->findOrFail($p['local_id'] ?? 0);
        $link = MachineLink::withoutGlobalScopes()->where('connection_id', $c->id)->findOrFail($p['link_id'] ?? 0);
        if ($link->status === 'sent') {
            return ['external_id' => $link->external_id, 'already' => true];
        }
        $link->forceFill(['event_id' => $e->id, 'attempts' => $link->attempts + 1])->saveQuietly();
        try {
            if ((int) $rx->version !== (int) ($p['version'] ?? 0) || ! $rx->isApproved()) {
                throw new \RuntimeException("Prescription {$rx->code} has changed since it was approved; approve the new version and send that.");
            }
            $format = (string) ($connector->config('prescription_format') ?: 'shapefile');
            $file = app(PrescriptionFiles::class)->ensure($rx, $format, $this->actor($c, $p['actor_id'] ?? null));
            $fieldExt = MachineLink::withoutGlobalScopes()->where('connection_id', $c->id)->where('kind', MachineLink::FIELD)->where('status', 'linked')->where('local_id', $rx->field_id)->value('external_id');
            $r = $connector->uploadPrescription($file->file_name, $file->bytes(), $file->mime, $format, $fieldExt);
        } catch (\Throwable $x) {
            $link->forceFill(['status' => 'failed', 'last_error' => mb_strimwidth($x->getMessage(), 0, 1000)])->saveQuietly();
            throw $x;
        }
        $link->forceFill(['status' => 'sent', 'external_id' => mb_strimwidth($r['id'], 0, 160), 'remote_status' => isset($r['status']) ? mb_strimwidth((string) $r['status'], 0, 60) : null,
            'last_error' => null, 'synced_at' => now(), 'data' => array_merge($link->data ?? [], ['format' => $format, 'file' => $file->file_name])])->saveQuietly();
        if ($rx->status === Prescription::APPROVED) {
            $rx->forceFill(['status' => Prescription::SENT])->saveQuietly();
        }

        return ['external_id' => $r['id'], 'status' => $r['status'] ?? null];
    }

    /** The platform's word on the prescriptions sent. */
    private function checkSent(MachineConnection $c, MachineConnector $connector): int
    {
        $n = 0;
        foreach (MachineLink::withoutGlobalScopes()->where('connection_id', $c->id)->where('kind', MachineLink::PRESCRIPTION)->where('status', 'sent')
            ->where('updated_at', '>=', now()->subDays(30))->get() as $link) {
            try {
                $s = $connector->fileStatus($link->external_id);
                $link->forceFill(['remote_status' => $s ? mb_strimwidth($s, 0, 60) : $link->remote_status])->saveQuietly();
                $n++;
            } catch (MachineApiError) {
                // A status we could not read is not a failed sync.
            }
        }

        return $n;
    }

    // ── comparison ───────────────────────────────────────────────────────────────────────────

    // (see Prescriptions::compare, which reads the operations linked here)

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /** A boundary's fingerprint, steady across rounding and ring closing. */
    public static function hash(?array $g): ?string
    {
        $g = $g ? Geo::polygon($g) : null;
        if (! $g) {
            return null;
        }
        $g = Geo::close($g);
        $round = fn ($ring) => array_map(fn ($c) => [round((float) $c[0], 6), round((float) $c[1], 6)], $ring);
        $coords = $g['type'] === 'Polygon' ? array_map($round, $g['coordinates']) : array_map(fn ($p) => array_map($round, $p), $g['coordinates']);

        return hash('sha256', $g['type'].json_encode($coords));
    }

    private function actor(MachineConnection $c, ?int $actorId): User
    {
        return User::withoutGlobalScopes()->find($actorId ?: $c->connected_by)
            ?? User::withoutGlobalScopes()->where('company_id', $c->company_id)->orderBy('id')->firstOrFail();
    }

    private function fieldById(MachineConnection $c, int $id): ?FarmField
    {
        return FarmField::withoutGlobalScope('farm_access')->where('company_id', $c->company_id)->where('status', '!=', 'archived')->find($id);
    }

    private function machineById(MachineConnection $c, int $id): ?FarmMachine
    {
        return FarmMachine::withoutGlobalScope('farm_access')->where('company_id', $c->company_id)->find($id);
    }

    /** @return array{0: ?FarmField, 1: ?string} */
    private function matchField(MachineConnection $c, string $name): array
    {
        $key = mb_strtolower(trim($name));
        if ($key === '') {
            return [null, null];
        }
        $taken = MachineLink::withoutGlobalScopes()->where('connection_id', $c->id)->where('kind', MachineLink::FIELD)->where('status', 'linked')->pluck('local_id')->all();
        $q = fn () => FarmField::withoutGlobalScope('farm_access')->where('company_id', $c->company_id)->where('status', '!=', 'archived')->whereNotIn('id', $taken);
        foreach (['code' => 'code', 'name' => 'name'] as $col => $by) {
            $hits = $q()->whereRaw("lower({$col}) = ?", [$key])->get();
            if ($hits->count() === 1) {
                return [$hits->first(), $by];
            }
        }
        // "L1 Langkamp": our code and name, the way we name fields when we send them.
        $hits = $q()->get()->filter(fn ($f) => mb_strtolower(trim("{$f->code} {$f->name}")) === $key);

        return $hits->count() === 1 ? [$hits->first(), 'code'] : [null, null];
    }

    /** @return array{0: ?FarmMachine, 1: ?string} */
    private function matchMachine(MachineConnection $c, ?string $serial, ?string $vin): array
    {
        $taken = MachineLink::withoutGlobalScopes()->where('connection_id', $c->id)->where('kind', MachineLink::MACHINE)->where('status', 'linked')->pluck('local_id')->all();
        foreach (array_filter(['serial' => $serial, 'vin' => $vin]) as $by => $value) {
            $v = mb_strtolower(trim((string) $value));
            if ($v === '') {
                continue;
            }
            $m = FarmMachine::withoutGlobalScope('farm_access')->where('company_id', $c->company_id)->whereNotIn('id', $taken)
                ->where(fn ($q) => $q->whereRaw('lower(trim(serial_number)) = ?', [$v])->orWhereRaw('lower(trim(vin_number)) = ?', [$v])->orWhereRaw('lower(trim(chassis_number)) = ?', [$v]))->first();
            if ($m) {
                return [$m, $by];
            }
        }

        return [null, null];
    }
}
