<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Services\FarmAccess;
use App\Modules\Agri\Services\FarmRegistry;
use App\Modules\HR\Support\PicksTenantPeople;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * What every Mkhaya Agri screen needs: a permission check at the top of each action, the farm a
 * request is about (confined to the farms the user may reach, so another farm is a 404), and the
 * pickers the forms share.
 */
abstract class AgriController extends Controller
{
    use PicksTenantPeople;

    protected function allow(Request $request, string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            if ($request->user()?->can($permission)) {
                return;
            }
        }
        abort(403, 'You do not have permission to do that.');
    }

    /** A farm by its public id, within the farms the user reaches. */
    protected function farm(?string $uuid): Farm
    {
        abort_if(blank($uuid), 404);

        return Farm::query()->where('uuid', $uuid)->firstOrFail();
    }

    /**
     * The farm a change is being made on: reachable, writable by this person (a viewer membership is
     * read-only), and operational — an archived or inactive farm takes no new work.
     */
    protected function workingFarm(Request $request, ?string $uuid): Farm
    {
        $farm = $this->farm($uuid);
        abort_unless(app(FarmAccess::class)->canWrite($request->user(), $farm->id), 403, 'Your access to this farm is read-only.');
        app(FarmRegistry::class)->assertOperational($farm);

        return $farm;
    }

    protected function assertWritable(Request $request, int $farmId): void
    {
        abort_unless(app(FarmAccess::class)->canWrite($request->user(), $farmId), 403, 'Your access to this farm is read-only.');
    }

    /** A field that must belong to the given farm. */
    protected function fieldOn(Farm $farm, ?string $uuid): ?FarmField
    {
        if (blank($uuid)) {
            return null;
        }
        $field = FarmField::query()->where('uuid', $uuid)->where('farm_id', $farm->id)->first();
        if (! $field) {
            throw ValidationException::withMessages(['field_id' => 'That field is not on this farm.']);
        }

        return $field;
    }

    /** Reachable farms with their fields, for the farm/field pickers on every form. */
    protected function farmOptions(bool $operationalOnly = false): array
    {
        return Farm::query()->when($operationalOnly, fn ($q) => $q->operational())->orderBy('name')
            ->with(['fields' => fn ($q) => $q->where('status', '!=', 'archived')->orderBy('code')])
            ->get()
            ->map(fn (Farm $f) => [
                'id' => $f->uuid, 'code' => $f->code, 'name' => $f->name, 'status' => $f->status,
                'warehouse_id' => $f->warehouse_id,
                'fields' => $f->fields->map(fn (FarmField $x) => ['id' => $x->uuid, 'code' => $x->code, 'name' => $x->name,
                    'hectares' => $x->hectares()])->values(),
            ])->values()->all();
    }

    /** The farm uuid → name map used to label rows. */
    protected function farmFilter(Request $request): ?Farm
    {
        $uuid = $request->query('farm');

        return $uuid ? Farm::query()->where('uuid', $uuid)->first() : null;
    }

    protected static function num($v): ?float
    {
        return $v === null ? null : (float) $v;
    }
}
