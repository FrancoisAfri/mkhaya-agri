<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\AgriOrgSetting;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\CropType;
use App\Modules\Agri\Models\CropVariety;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Agri\Models\PackhouseOperation;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Models\TraceToken;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The consumer's side of traceability: a QR code on the pack opens a public page (/t/{token}) that
 * tells the produce's story — what it is, where and when it was grown, harvested and packed, and
 * the certifications the farm holds.
 *
 * The page is built from a whitelist, never from the internal trace: it can only ever contain the
 * fields named in story(), and of those only the ones the organisation allowed for the lot. Prices,
 * costs, quantities, customers, dispatches, people, growers' names and inputs are never read into it.
 * The token is 40 random characters (no lot id or code in it), can be revoked at any time, and a
 * revoked or unknown token is simply not found.
 */
class PublicTrace
{
    /** What a token may show; the organisation sets its defaults and each lot can differ. */
    public const PARTS = ['farm_name', 'region', 'field', 'crop_variety', 'harvest_date', 'packing_date', 'certifications'];

    public const DEFAULTS = ['farm_name' => true, 'region' => true, 'field' => false, 'crop_variety' => true, 'harvest_date' => true, 'packing_date' => true, 'certifications' => true];

    /** Lots whose produce can reach a consumer. */
    public const TRACEABLE = ['harvest', 'graded', 'packed', 'processed', 'collected'];

    public function __construct(private readonly TenantContext $tenants) {}

    public function defaults(int $companyId): array
    {
        $own = AgriOrgSetting::for($companyId)->trace_defaults ?? [];

        return array_map('boolval', array_intersect_key($own, self::DEFAULTS) + self::DEFAULTS);
    }

    public function saveSettings(int $companyId, array $defaults, array $certifications): AgriOrgSetting
    {
        $s = AgriOrgSetting::for($companyId);
        $s->update(['trace_defaults' => array_map('boolval', array_intersect_key($defaults, self::DEFAULTS) + self::DEFAULTS),
            'certifications' => array_values(array_filter(array_map(fn ($c) => mb_strimwidth(trim((string) $c), 0, 120), $certifications)))]);
        $this->forgetCompany($companyId);

        return $s;
    }

    /** The lot's active token, or a new one. */
    public function issue(ProduceLot $lot, User $actor, ?array $visibility = null): TraceToken
    {
        if (! in_array($lot->kind, self::TRACEABLE, true)) {
            throw ValidationException::withMessages(['lot' => 'Only produce that reaches a consumer gets a public trace page.']);
        }
        if (in_array($lot->status, ['written_off'], true)) {
            throw ValidationException::withMessages(['lot' => 'That lot was written off.']);
        }
        $active = TraceToken::query()->where('produce_lot_id', $lot->id)->where('status', TraceToken::ACTIVE)->first();
        if ($active) {
            return $visibility !== null ? $this->update($active, $visibility) : $active;
        }

        return TraceToken::create(['company_id' => $lot->company_id, 'farm_id' => $lot->farm_id, 'produce_lot_id' => $lot->id, 'token' => Str::random(40),
            'visibility' => $this->clean($visibility ?? $this->defaults((int) $lot->company_id)), 'status' => TraceToken::ACTIVE, 'created_by' => $actor->id]);
    }

    public function update(TraceToken $token, array $visibility): TraceToken
    {
        $token->update(['visibility' => $this->clean($visibility + ($token->visibility ?? []))]);
        Cache::forget($this->cacheKey($token->id));

        return $token;
    }

    public function revoke(TraceToken $token, User $actor, ?string $reason = null): TraceToken
    {
        if ($token->status !== TraceToken::ACTIVE) {
            throw ValidationException::withMessages(['token' => 'That code is already revoked.']);
        }
        $token->update(['status' => TraceToken::REVOKED, 'revoked_at' => now(), 'revoked_by' => $actor->id, 'revoke_reason' => $reason ? mb_strimwidth($reason, 0, 300) : null]);
        Cache::forget($this->cacheKey($token->id));

        return $token;
    }

    public function url(TraceToken $token): string
    {
        return route('public.trace', $token->token);
    }

    /** An SVG QR code for the token's public page. */
    public function qrSvg(TraceToken $token, int $size = 180): string
    {
        $writer = new \BaconQrCode\Writer(new \BaconQrCode\Renderer\ImageRenderer(new \BaconQrCode\Renderer\RendererStyle\RendererStyle($size, 1),
            new \BaconQrCode\Renderer\Image\SvgImageBackEnd()));

        return $writer->writeString($this->url($token));
    }

    // ── The public page ─────────────────────────────────────────────────────────────────────

    /**
     * The story behind a public token, or null when the token is unknown or revoked. The token is
     * looked up with no organisation active (the visitor has none), and the story is built inside the
     * owning organisation's tenant context. The story itself is cached; the token's status is not.
     */
    public function publicStory(string $token): ?array
    {
        if (! preg_match('/^[A-Za-z0-9]{24,64}$/', $token)) {
            return null;
        }

        return $this->tenants->run(null, function () use ($token) {
            $row = TraceToken::withoutGlobalScopes()->where('token', $token)->first(['id', 'company_id', 'status']);
            if (! $row || $row->status !== TraceToken::ACTIVE) {
                return null;
            }

            return $this->tenants->run((int) $row->company_id, function () use ($row) {
                $story = Cache::remember($this->cacheKey($row->id), now()->addMinutes(10), fn () => $this->story(TraceToken::withoutGlobalScopes()->findOrFail($row->id)));
                DB::table('agri_trace_tokens')->where('id', $row->id)->update(['views' => DB::raw('views + 1'), 'last_viewed_at' => now()]);

                return $story;
            });
        });
    }

    /**
     * The consumer story: built field by field from what the token allows. Nothing that is not
     * named here can reach the page.
     */
    public function story(TraceToken $token): array
    {
        $v = $this->clean($token->visibility ?? []);
        $lot = ProduceLot::withoutGlobalScopes()->findOrFail($token->produce_lot_id);
        $farm = Farm::withoutGlobalScopes()->find($lot->farm_id);
        $ancestors = ProduceLot::withoutGlobalScopes()->whereIn('id', $this->ancestors($lot->id))->get();
        $harvests = $ancestors->whereIn('kind', ['harvest', 'collected']);
        $planIds = $harvests->pluck('crop_plan_id')->filter()->unique();
        $plans = CropPlan::withoutGlobalScopes()->whereIn('id', $planIds)->get(['id', 'crop_type_id', 'variety_id', 'field_id']);
        $harvestedOn = HarvestRecord::withoutGlobalScopes()->whereIn('id', $harvests->pluck('harvest_record_id')->filter())->pluck('harvested_on')
            ->merge($harvests->pluck('produced_on'))->filter()->map(fn ($d) => $d->toDateString())->unique()->sort()->values();
        $packedOn = PackhouseOperation::withoutGlobalScopes()->whereIn('kind', ['packing', 'repacking', 'processing'])
            ->whereIn('id', DB::table('produce_lot_links')->whereIn('child_lot_id', $ancestors->pluck('id'))->pluck('operation_id')->filter())
            ->max('performed_on');
        if (! $packedOn && in_array($lot->kind, ['packed', 'processed'], true)) {
            $packedOn = $lot->produced_on;
        }
        $settings = AgriOrgSetting::for((int) $lot->company_id);
        $cropTypeId = $lot->crop_type_id ?? $plans->first()?->crop_type_id;

        $story = [
            'product' => $lot->product,
            'grade' => $lot->grade,
            'lot' => $lot->code,
            'best_before' => $lot->best_before?->toDateString(),
            'withdrawn' => in_array($lot->status, ['recalled'], true),
            'from_growers' => $harvests->contains('kind', 'collected'),
        ];
        if ($v['crop_variety']) {
            $story['crop'] = $cropTypeId ? CropType::withoutGlobalScopes()->whereKey($cropTypeId)->value('name') : null;
            $story['varieties'] = CropVariety::withoutGlobalScopes()->whereIn('id', $plans->pluck('variety_id')->filter())->pluck('name')->unique()->values()->all();
        }
        if ($v['farm_name'] && $farm) {
            $story['farm'] = $farm->name;
        }
        if ($v['region'] && $farm) {
            $story['region'] = trim((string) ($farm->settings['province'] ?? '')) ?: ($farm->location ?: null);
        }
        if ($v['field']) {
            $story['fields'] = FarmField::withoutGlobalScopes()->whereIn('id', $plans->pluck('field_id')->merge($harvests->pluck('field_id'))->filter()->unique())
                ->pluck('name')->unique()->values()->all();
        }
        if ($v['harvest_date']) {
            $story['harvested'] = $harvestedOn->isEmpty() ? null : ['from' => $harvestedOn->first(), 'to' => $harvestedOn->last()];
        }
        if ($v['packing_date']) {
            $story['packed_on'] = $packedOn ? \Illuminate\Support\Carbon::parse($packedOn)->toDateString() : null;
        }
        if ($v['certifications']) {
            $story['certifications'] = array_values($settings->certifications ?? []);
        }

        return $story;
    }

    /** The lot and every lot it was made from (grading, packing, processing), as ids. */
    public function ancestors(int $id): array
    {
        $all = [$id];
        $frontier = [$id];
        for ($depth = 0; $frontier && $depth < 25; $depth++) {
            $next = DB::table('produce_lot_links')->whereIn('child_lot_id', $frontier)->pluck('parent_lot_id')->map(fn ($x) => (int) $x)->diff($all)->values()->all();
            $all = array_merge($all, $next);
            $frontier = $next;
        }

        return $all;
    }

    private function clean(array $visibility): array
    {
        $out = [];
        foreach (self::DEFAULTS as $k => $default) {
            $out[$k] = array_key_exists($k, $visibility) ? (bool) $visibility[$k] : $default;
        }

        return $out;
    }

    private function cacheKey(int $id): string
    {
        return "agri_trace_story:{$id}";
    }

    private function forgetCompany(int $companyId): void
    {
        foreach (TraceToken::withoutGlobalScopes()->where('company_id', $companyId)->pluck('id') as $id) {
            Cache::forget($this->cacheKey((int) $id));
        }
    }
}
